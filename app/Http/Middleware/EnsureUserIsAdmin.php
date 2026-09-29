<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side admin boundary. Hiding admin surfaces in the UI is not access
 * control: this middleware is the gate for every admin route group.
 *
 * It is intended to run after `auth:sanctum`, so a guest never reaches it
 * (401 comes from auth) and an authenticated non-admin receives 403.
 */
class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->isAdmin()) {
            abort(403, 'Admin access required.');
        }

        return $next($request);
    }
}
