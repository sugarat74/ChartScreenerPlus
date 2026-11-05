<?php

namespace App\Http\Controllers;

use App\Models\Universe;
use App\Services\Market\MarketHoursStatus;
use App\Support\ListingMarket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Public open/closed status of the Listing Markets of the visible Screener
 * universes (docs/specs/market-hours-status.md). Anonymous like the Screener:
 * it exposes only static schedule facts, computed from the committed calendars
 * and the server clock; nothing is fetched from an exchange or a data source.
 * A market whose universes are hidden (`SCREENER_UNIVERSES_PUBLIC`) is absent.
 */
class MarketStatusController extends Controller
{
    public function index(Request $request, MarketHoursStatus $status): JsonResponse
    {
        $visible = Universe::query()
            ->visibleTo($request->user('sanctum'))
            ->pluck('market')
            ->unique()
            ->all();

        $now = Carbon::now();

        $markets = collect(ListingMarket::codes())
            ->filter(fn (string $code): bool => in_array($code, $visible, true))
            ->map(fn (string $code): array => $status->forMarket($code, $now))
            ->values()
            ->all();

        return response()->json([
            'generated_at' => $now->copy()->utc()->toIso8601ZuluString(),
            'markets' => $markets,
        ]);
    }
}
