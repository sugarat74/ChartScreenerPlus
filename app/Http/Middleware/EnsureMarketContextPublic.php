<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Release gate of the Market Context API (docs/specs/market-context-ui.md,
 * C6): the request passes while `config('market_context.public')` is on, or
 * when the caller is an Admin (a preview of the production data before the
 * owner's go-live). Otherwise the endpoint does not exist yet: a JSON 404.
 *
 * This is a release switch, not authorization; it is the single documented
 * exception to "no controller authorization branch" (CONSTRAINTS.md ->
 * Public API). On routes outside `auth:sanctum` the user is resolved through
 * the `sanctum` guard, so the stateful SPA session counts.
 */
class EnsureMarketContextPublic
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((bool) config('market_context.public') || $request->user('sanctum')?->isAdmin() === true) {
            return $next($request);
        }

        return response()->json(['message' => __('market_context.not_available')], 404);
    }
}
