<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Instrument;
use App\Models\Universe;
use App\Services\Market\MarketCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only freshness of every Screener universe's Daily Bars against the
 * latest session its Listing Market should already have published
 * (docs/specs/multi-market-universes.md, Feature 2). Read-only.
 *
 * Per universe, over its active stock members:
 * - `latest_date`: the most common latest bar date (ties: the newer date);
 * - `expected_date`: `MarketCalendar::latestSession` of the market now;
 * - `with_bar` / `without_bar`: members whose latest bar is on or after the
 *   expected date, and the rest (members without any bar included);
 * - `status`: `stale` when `latest_date` is missing or older than expected,
 *   otherwise `fresh` when every member is on the expected date, else `partial`.
 */
class UniverseFreshnessController extends Controller
{
    public const FRESH = 'fresh';

    public const PARTIAL = 'partial';

    public const STALE = 'stale';

    public function index(MarketCalendar $calendar): JsonResponse
    {
        $universes = Universe::query()
            ->screener()
            ->get()
            ->map(fn (Universe $universe): array => $this->row($universe, $calendar))
            ->all();

        return response()->json(['universes' => $universes]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Universe $universe, MarketCalendar $calendar): array
    {
        $market = (string) ($universe->market ?? 'US');
        $expected = $calendar->latestSession($market, now())->toDateString();

        // One latest bar date (or null) per active stock member.
        $latestByMember = Instrument::query()
            ->join('instrument_universe', 'instrument_universe.instrument_id', '=', 'instruments.id')
            ->where('instrument_universe.universe_id', $universe->id)
            ->where('instruments.kind', Instrument::KIND_STOCK)
            ->where('instruments.active', true)
            ->leftJoin('daily_bars', 'daily_bars.instrument_id', '=', 'instruments.id')
            ->groupBy('instruments.id')
            ->select('instruments.id', DB::raw('MAX(daily_bars.date) as latest'))
            ->pluck('latest')
            ->map(fn (mixed $latest): ?string => $latest === null ? null : substr((string) $latest, 0, 10));

        $members = $latestByMember->count();
        $withBar = $latestByMember->filter(fn (?string $latest): bool => $latest !== null && $latest >= $expected)->count();

        $counts = $latestByMember->filter()->countBy()->all();
        $latestDate = null;
        $best = 0;

        foreach ($counts as $date => $count) {
            $date = (string) $date;

            if ($count > $best || ($count === $best && $date > (string) $latestDate)) {
                $best = $count;
                $latestDate = $date;
            }
        }

        if ($latestDate === null || $latestDate < $expected) {
            $status = self::STALE;
        } elseif ($withBar === $members) {
            $status = self::FRESH;
        } else {
            $status = self::PARTIAL;
        }

        return [
            'slug' => $universe->slug,
            'name' => $universe->name,
            'market' => $market,
            'currency' => $universe->currency ?? 'USD',
            'members' => $members,
            'latest_date' => $latestDate,
            'expected_date' => $expected,
            'with_bar' => $withBar,
            'without_bar' => $members - $withBar,
            'status' => $status,
        ];
    }
}
