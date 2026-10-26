<?php

namespace App\Http\Controllers;

use App\Models\Instrument;
use App\Models\Universe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public list of the Screener universes the caller can see
 * (docs/specs/multi-market-universes.md, D4/D9).
 *
 * Anonymous like the Screener itself: it exposes only what `GET /api/screener`
 * already serves. Universes outside `SCREENER_UNIVERSES_PUBLIC` appear only to
 * an Admin (preview); Market Context is never listed.
 */
class UniverseController extends Controller
{
    /**
     * Visible Screener universes in selector order.
     */
    public function index(Request $request): JsonResponse
    {
        $default = Universe::defaultSlug();

        $universes = Universe::query()
            ->visibleTo($request->user('sanctum'))
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (Universe $universe): array => [
                'slug' => $universe->slug,
                'name' => $universe->name,
                'market' => $universe->market,
                'currency' => $universe->currency,
                'members' => $this->members($universe),
                'latest_date' => $this->latestDate($universe),
                'is_default' => $universe->slug === $default,
            ])
            ->all();

        return response()->json(['universes' => $universes]);
    }

    /**
     * Active stock members.
     */
    private function members(Universe $universe): int
    {
        return $universe->instruments()
            ->where('instruments.kind', Instrument::KIND_STOCK)
            ->where('instruments.active', true)
            ->count();
    }

    /**
     * The most recent Screener row date among the members, or null.
     */
    private function latestDate(Universe $universe): ?string
    {
        $latest = DB::table('screener_rows')
            ->join('instrument_universe', 'instrument_universe.instrument_id', '=', 'screener_rows.instrument_id')
            ->where('instrument_universe.universe_id', $universe->id)
            ->max('screener_rows.date');

        // The raw stored value: SQLite keeps "Y-m-d H:i:s", PostgreSQL "Y-m-d".
        return $latest === null ? null : substr((string) $latest, 0, 10);
    }
}
