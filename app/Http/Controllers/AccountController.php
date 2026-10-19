<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Account\AccountDeleter;
use App\Services\Account\AccountExporter;
use App\Services\Admin\SessionDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Account self-service for the signed-in user (account-self-service): change
 * the display name and password, download a copy of their data and delete the
 * account. Every endpoint acts only on `$request->user()` (no endpoint accepts
 * a user id) and is session-only, like `AuthController`.
 */
class AccountController extends Controller
{
    /** Typed confirmation words accepted for deletion (one per locale). */
    public const CONFIRMATION_WORDS = ['DELETE', 'ELIMINAR'];

    public function update(Request $request): JsonResponse
    {
        $this->requireStatefulSession($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['name' => $validated['name']])->save();

        return response()->json(['user' => $user]);
    }

    /**
     * Change the password, end every other session of the account and keep
     * the current one under a fresh session id.
     */
    public function updatePassword(Request $request, SessionDirectory $sessions): Response
    {
        $this->requireStatefulSession($request);

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill([
            'password' => $validated['password'],
            'remember_token' => Str::random(60),
        ])->save();

        $sessions->deleteForUser($user->id, $request->session()->getId());
        // `true` also destroys the old id's row; the new id is written at the end of the request.
        $request->session()->regenerate(true);

        return response()->noContent();
    }

    public function export(Request $request, AccountExporter $exporter): JsonResponse
    {
        $this->requireStatefulSession($request);

        $filename = 'chartiko-account-'.now()->toDateString().'.json';

        return response()
            ->json($exporter->export($request->user()), 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"')
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Permanently delete the account after the password and a typed
     * confirmation word. Admins are refused: the role is removed out of band
     * first (this also keeps their backtest runs and the last Admin).
     */
    public function destroy(Request $request, AccountDeleter $deleter): JsonResponse|Response
    {
        $this->requireStatefulSession($request);

        /** @var User $user */
        $user = $request->user();

        if ($user->isAdmin()) {
            return response()->json([
                'code' => 'account.admin_cannot_delete',
                'message' => __('messages.account.admin_cannot_delete'),
                'errors' => ['account' => [__('messages.account.admin_cannot_delete')]],
            ], 422);
        }

        $request->validate([
            'password' => ['required', 'string', 'current_password:web'],
            'confirmation' => ['required', 'string', 'in:'.implode(',', self::CONFIRMATION_WORDS)],
        ], [
            'confirmation.in' => __('messages.account.confirmation_word'),
        ]);

        $deleter->delete($user);

        // `logoutCurrentDevice` (not `logout`) so no `logout` event is recorded
        // for a user that no longer exists and the deleted model is never saved
        // again by the remember-token rotation.
        Auth::guard('web')->logoutCurrentDevice();
        // `auth:sanctum` made the cached Sanctum guard the default one; without
        // this the database session handler would write the new, empty session
        // row with the deleted user's id.
        Auth::guard('sanctum')->forgetUser();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /** Same session-only guard as `AuthController`: 400 for a non-stateful caller. */
    private function requireStatefulSession(Request $request): void
    {
        abort_unless($request->hasSession(), 400, __('messages.stateful_session_required'));
    }
}
