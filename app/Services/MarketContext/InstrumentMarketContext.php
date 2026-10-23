<?php

namespace App\Services\MarketContext;

use App\Models\MarketRegimeSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * The shared part of `GET /api/instruments/{ticker}/market-context`
 * (docs/specs/market-context-patterns.md, C3): every sector of the latest
 * Sector Snapshot date keyed by its stored GICS sector name, and the latest
 * Market Regime label. Plain arrays with no user data, cached once for every
 * caller ({@see MarketContextCache::instrumentContext()}); the controller
 * picks the stock's sector and adds the stale flags.
 */
class InstrumentMarketContext
{
    /**
     * @return array{sectors: array<string, array{date: string, etf: string, sector_key: ?string, trend: ?string, rs_rank: ?int, rs_rank_of: int}>, regime: array{date: string, regime: string}|null}
     */
    public function build(): array
    {
        return ['sectors' => $this->sectors(), 'regime' => $this->regime()];
    }

    /**
     * @return array<string, array{date: string, etf: string, sector_key: ?string, trend: ?string, rs_rank: ?int, rs_rank_of: int}>
     */
    private function sectors(): array
    {
        // The raw stored value (SQLite "Y-m-d H:i:s", PostgreSQL "Y-m-d"),
        // matched as stored and normalised for display, as MarketContextPayload does.
        $latest = DB::table('market_sector_snapshots')->max('date');

        if ($latest === null) {
            return [];
        }

        $rows = DB::table('market_sector_snapshots')
            ->join('instruments', 'instruments.id', '=', 'market_sector_snapshots.instrument_id')
            ->where('market_sector_snapshots.date', $latest)
            ->orderBy('instruments.ticker')
            ->get([
                'instruments.ticker',
                'market_sector_snapshots.sector',
                'market_sector_snapshots.trend',
                'market_sector_snapshots.rs_rank',
            ]);

        $date = substr((string) $latest, 0, 10);
        $rankOf = $rows->filter(fn (object $row): bool => $row->rs_rank !== null)->count();
        $sectors = [];

        foreach ($rows as $row) {
            if ($row->sector === null || isset($sectors[(string) $row->sector])) {
                continue;
            }

            $sectors[(string) $row->sector] = [
                'date' => $date,
                'etf' => (string) $row->ticker,
                'sector_key' => MarketContextPayload::sectorKey($row->sector),
                'trend' => $row->trend === null ? null : (string) $row->trend,
                'rs_rank' => $row->rs_rank === null ? null : (int) $row->rs_rank,
                'rs_rank_of' => $rankOf,
            ];
        }

        return $sectors;
    }

    /** @return array{date: string, regime: string}|null */
    private function regime(): ?array
    {
        $row = MarketRegimeSnapshot::query()->orderByDesc('date')->first();

        return $row === null ? null : ['date' => $row->date->toDateString(), 'regime' => (string) $row->regime];
    }
}
