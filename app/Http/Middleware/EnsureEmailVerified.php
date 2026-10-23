<?php

namespace App\Http\Middleware;

use App\Support\EmailVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `verified` alias (docs/specs/auth-email-verification.md, D4). It
 * replaces the framework's EnsureEmailIsVerified, which redirects: an account
 * that still has to confirm its address always gets a JSON 403 with the code
 * `email_verification.required`, never a redirect or an HTML page.
 *
 * It runs after `auth:sanctum` and reads only `EmailVerification::pending()`
 * (Admins are never pending; nothing is pending while verification is off).
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (EmailVerification::pending($request->user())) {
            return response()->json([
                'code' => 'email_verification.required',
                'message' => __('messages.email_verification.required'),
            ], 403);
        }

        return $next($request);
    }
}
