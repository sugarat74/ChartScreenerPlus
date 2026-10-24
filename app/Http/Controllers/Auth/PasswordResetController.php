<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordAddress;
use App\Models\User;
use App\Services\Admin\SessionDirectory;
use App\Support\OutboundMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

/** Public, session/CSRF-backed password recovery without account enumeration. */
class PasswordResetController extends Controller
{
    public function forgot(Request $request): JsonResponse
    {
        $this->requireStatefulSession($request);
        $validated = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        $email = Str::lower(trim($validated['email']));
        $user = OutboundMail::ready() ? User::query()->where('email', $email)->first() : null;

        if ($user !== null) {
            try {
                $broker = Password::broker();
                if (! $broker->getRepository()->recentlyCreatedToken($user)) {
                    $token = $broker->createToken($user);
                    $url = rtrim((string) config('app.url'), '/').'/restablecer-contrasena?'.http_build_query([
                        'email' => $user->email,
                        'token' => $token,
                    ], '', '&', PHP_QUERY_RFC3986);
                    Mail::to($user->email)->locale(app()->getLocale())
                        ->queue(new ResetPasswordAddress($url, $user->getKey()));
                }
            } catch (Throwable $exception) {
                Log::warning('Password reset email could not be queued.', [
                    'user_id' => $user->getKey(),
                    'error' => $exception::class,
                ]);
            }
        }

        return response()->json(['message' => __('messages.password_reset.requested')])
            ->header('Cache-Control', 'no-store');
    }

    public function reset(Request $request, SessionDirectory $sessions): JsonResponse
    {
        $this->requireStatefulSession($request);
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);
        $validated['email'] = Str::lower(trim($validated['email']));

        $status = Password::broker()->reset($validated, function (User $user, string $password) use ($sessions): void {
            DB::transaction(function () use ($user, $password, $sessions): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $sessions->deleteForUser($user->getKey(), null);
            });
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'code' => 'password_reset.invalid_link',
                'message' => __('messages.password_reset.invalid_link'),
            ], 422)->header('Cache-Control', 'no-store');
        }

        // A signed-in caller must not keep an authenticated row through the
        // session middleware's end-of-request write.
        if ($request->user() !== null) {
            Auth::guard('web')->logout();
            Auth::guard('sanctum')->forgetUser();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => __('messages.password_reset.changed')])
            ->header('Cache-Control', 'no-store');
    }

    private function requireStatefulSession(Request $request): void
    {
        abort_unless($request->hasSession(), 400, __('messages.stateful_session_required'));
    }
}
