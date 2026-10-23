<?php

namespace App\Http\Controllers;

use App\Services\MarketContext\MarketContextCache;
use App\Services\MarketContext\MarketContextHistory;
use App\Services\MarketContext\MarketContextPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Market Context API (docs/specs/market-context-ui.md, C1-C2). Both actions
 * sit behind the `market-context.public` release gate; the history is in the
 * `auth:sanctum` group (Registered Users only, enforced by the route). Bodies
 * come from {@see MarketContextCache} and hold no user data.
 */
class MarketContextController extends Controller
{
    public function __construct(
        private readonly MarketContextPayload $payload,
        private readonly MarketContextHistory $history,
    ) {}

    /** `GET /api/market-context` */
    public function show(): JsonResponse
    {
        $asOf = MarketContextPayload::today();
        $body = MarketContextCache::latest($asOf, fn (): array => $this->payload->build($asOf));

        // `public` follows the live flag, never the cached copy.
        return response()->json(
            ['as_of' => $body['as_of'], 'public' => (bool) config('market_context.public')] + $body,
        );
    }

    /** `GET /api/market-context/history?metric=&range=` */
    public function history(Request $request): JsonResponse
    {
        $metric = $request->query('metric');
        $range = $request->query('range', '1y');
        $errors = [];

        if (! is_string($metric) || ! $this->history->isValidMetric($metric)) {
            $errors['metric'] = [__('market_context.metric_invalid')];
        }

        if (! is_string($range) || ! MarketContextHistory::isValidRange($range)) {
            $errors['range'] = [__('market_context.range_invalid')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        /** @var string $metric */
        /** @var string $range */
        return response()->json(
            MarketContextCache::history($metric, $range, fn (): array => $this->history->build($metric, $range)),
        );
    }
}
