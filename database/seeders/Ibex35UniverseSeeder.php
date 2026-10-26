<?php

namespace Database\Seeders;

use App\Support\ListingMarket;

/**
 * Seeds the IBEX 35 Screener universe (docs/specs/multi-market-universes.md,
 * Feature 5) from the committed, dated snapshot `database/data/ibex35.csv`.
 * Source, retrieval date, hash, classification rules and the rebalance
 * procedure are in `database/data/ibex35.md`.
 *
 * XMAD (Bolsa de Madrid) / EUR, `.MC` tickers. No member overlaps another
 * universe, so every Instrument is created from the snapshot.
 */
class Ibex35UniverseSeeder extends IndexUniverseSeeder
{
    public const UNIVERSE_SLUG = 'ibex35';

    protected function slug(): string
    {
        return self::UNIVERSE_SLUG;
    }

    protected function universeName(): string
    {
        return 'IBEX 35';
    }

    protected function market(): string
    {
        return ListingMarket::XMAD;
    }

    protected function position(): int
    {
        return 2;
    }
}
