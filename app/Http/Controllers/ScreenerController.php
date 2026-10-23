<?php

namespace App\Http\Controllers;

use App\Models\Universe;
use App\Services\Screener\CandidateQuery;
use App\Services\Screener\ScreenerFilterParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Public, read-only screener over a Universe's Instruments.
 *
 * The route is anonymous by design: browsing the Screener requires no session
 * (`docs/user-and-access-model.md`) and the data is system-owned public market
 * data, so there is no ownership or authorization branch, except the sector
 * context filters (`sector_trend`, `sector_rs_rank_max`), which need the
 * `view-market-context` ability (401 for a guest, 403 without it; checked
 * after validation; docs/specs/market-context-patterns.md, C5). Filtering and
 * ordering happen server-side over the ~503 universe members (bounded), so the
 * SPA never re-implements ranking.
 *
 * `limit` and `offset` are the only silently clamped params (they page through
 * the result set without changing it);
 * every other param can change which rows or the order, so a bad value is a
 * `422` (see `CONSTRAINTS.md` -> Public API). Filter resolution lives in
 * `ScreenerFilterParser`, shared with the natural-language interpreter.
 */
class ScreenerController extends Controller
{
    /**
     * Default candidate count when `?limit` is absent.
     */
    private const DEFAULT_LIMIT = 50;

    /**
     * Hard cap on the candidate count, whatever `?limit` says.
     */
    private const MAX_LIMIT = 500;

    /**
     * Return the ranked Candidate list for a Universe.
     */
    public function index(Request $request, CandidateQuery $query): JsonResponse
    {
        $universe = $this->resolveUniverse($request);

        if ($universe === null) {
            return response()->json(['message' => __('messages.universe_not_found')], 404);
        }

        $filters = ScreenerFilterParser::fromArray($request->query());

        // The one documented access check of this public route
        // (market-context-patterns, C5): the sector context filters are for
        // Registered Users with the `view-market-context` ability. It runs
        // after validation, so a bad value is a 422 for any caller, and a
        // request without those filters stays anonymous and unchanged.
        if (ScreenerFilterParser::usesMarketContext($filters)) {
            $user = $request->user('sanctum');

            if ($user === null) {
                return response()->json(['message' => __('market_context.sign_in_required')], 401);
            }

            if (Gate::forUser($user)->denies('view-market-context')) {
                return response()->json(['message' => __('market_context.filter_unavailable')], 403);
            }
        }

        $limit = $this->resolveLimit($request);
        $offset = $this->resolveOffset($request);

        // Matching, ranking and paging run in SQL over the precomputed rows in
        // `CandidateQuery`, shared with the Alerts evaluator so both always
        // agree on the Candidate list.
        ['candidates' => $page, 'total' => $total] = $query->page($universe, $filters, $limit, $offset);

        return response()->json([
            'universe' => [
                'slug' => $universe->slug,
                'name' => $universe->name,
            ],
            'sort' => $filters['sort'],
            'candidates' => $page,
            'meta' => [
                'limit' => $limit,
                'offset' => $offset,
                'returned' => count($page),
                'total' => $total,
            ],
        ]);
    }

    /**
     * Resolve the `universe` slug, defaulting to `config('ingestion.universe')`.
     *
     * An unknown slug is a bad request (JSON `404`), not an empty list; a valid
     * universe that matches nothing is a `200` with an empty candidate list.
     */
    private function resolveUniverse(Request $request): ?Universe
    {
        $slug = $request->query('universe');

        if ($slug === null || $slug === '') {
            $slug = (string) config('ingestion.universe');
        }

        if (! is_string($slug)) {
            return null;
        }

        return Universe::query()->where('slug', $slug)->first();
    }

    /**
     * Resolve `?offset` (rows to skip before the page) to a non-negative int.
     *
     * Like `limit` it pages through the ranked set without changing it, so a
     * missing, non-numeric or negative value is silently `0` (never a `422`);
     * an offset past the end simply returns an empty page with the real
     * `meta.total`.
     */
    private function resolveOffset(Request $request): int
    {
        if (! is_numeric($request->query('offset'))) {
            return 0;
        }

        return max((int) $request->query('offset'), 0);
    }

    /**
     * Resolve `?limit` to a bounded integer.
     *
     * A non-numeric value falls back to the default and the value is clamped
     * silently to `1..500`; it is never a validation error.
     */
    private function resolveLimit(Request $request): int
    {
        if (! is_numeric($request->query('limit'))) {
            return self::DEFAULT_LIMIT;
        }

        return min(max((int) $request->query('limit'), 1), self::MAX_LIMIT);
    }
}
