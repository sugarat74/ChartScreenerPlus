<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * First-party SPA authentication backed by Sanctum's session cookie + CSRF
 * flow. This controller issues no API tokens; the browser session is the
 * credential.
 */
class AuthController extends Controller
{
    /**
     * Create an account and start an authenticated session.
     */
    public function register(Request $request): JsonResponse
    {
        $this->requireStatefulSession($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        // Keep the write and the session write atomic so a failed session step
        // can never leave an orphaned user row behind.
        return DB::transaction(function () use ($request, $validated): JsonResponse {
            $user = User::create($validated);

            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return response()->json(['user' => $user], 201);
        });
    }

    /**
     * Authenticate an existing account against the web guard.
     */
    public function login(Request $request): JsonResponse
    {
        $this->requireStatefulSession($request);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($credentials)) {
            return response()->json([
                'message' => __('auth.failed'),
                'errors' => ['email' => [__('auth.failed')]],
            ], 422);
        }

        $request->session()->regenerate();

        return response()->json(['user' => $request->user()]);
    }

    /**
     * Invalidate the authenticated session.
     */
    public function logout(Request $request): Response
    {
        $this->requireStatefulSession($request);

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /**
     * Return the currently authenticated user.
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    /**
     * These endpoints are session-only: Sanctum attaches the session/CSRF
     * middleware only to requests that look like the first-party SPA. Fail fast
     * with a 4xx (not a 500 from the missing session store) so a non-stateful
     * caller can never reach validation or a database write.
     */
    private function requireStatefulSession(Request $request): void
    {
        abort_unless($request->hasSession(), 400, 'A stateful session is required.');
    }
}
