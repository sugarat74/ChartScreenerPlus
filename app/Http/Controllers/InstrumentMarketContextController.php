<?php

namespace App\Http\Controllers;

use App\Models\Instrument;
use App\Services\MarketContext\InstrumentMarketContext;
use App\Services\MarketContext\MarketContextCache;
use App\Services\MarketContext\MarketContextPayload;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/instruments/{ticker}/market-context` (docs/specs/market-context-patterns.md,
 * C3): a stock's sector ETF, the sector's trend and relative-strength rank and
 * the Market Regime, each with its own date and stale flag. Descriptive data
 * only. The route sits in `auth:sanctum` behind the `view-market-context`
 * ability; the public instrument detail never carries this context.
 */
class InstrumentMarketContextController extends Controller
{
    public function __construct(private readonly InstrumentMarketContext $context) {}

    public function show(string $ticker): JsonResponse
    {
        // Stocks only: an ETF (SPY, a sector SPDR) is the context itself.
        $instrument = Instrument::query()->stocks()->where('ticker', strtoupper(trim($ticker)))->first(['id', 'ticker', 'sector']);

        if ($instrument === null) {
            return response()->json(['message' => __('messages.instrument_not_found')], 404);
        }

        $asOf = MarketContextPayload::today();
        $shared = MarketContextCache::instrumentContext($asOf, fn (): array => $this->context->build());

        $sector = $instrument->sector === null ? null : ($shared['sectors'][$instrument->sector] ?? null);
        $regime = $shared['regime'];

        return response()->json([
            'ticker' => $instrument->ticker,
            'as_of' => $asOf,
            'sector' => $sector === null ? null : [
                'date' => $sector['date'],
                'stale' => MarketContextPayload::stale($sector['date'], $asOf),
                'etf' => $sector['etf'],
                'sector_key' => $sector['sector_key'],
                'trend' => $sector['trend'],
                'rs_rank' => $sector['rs_rank'],
                'rs_rank_of' => $sector['rs_rank_of'],
            ],
            'regime' => $regime === null ? null : [
                'date' => $regime['date'],
                'stale' => MarketContextPayload::stale($regime['date'], $asOf),
                'regime' => $regime['regime'],
            ],
        ]);
    }
}
