<?php

namespace Database\Seeders;

use App\Support\ListingMarket;

/**
 * Seeds the Nasdaq-100 Screener universe (docs/specs/multi-market-universes.md,
 * Feature 4) from the committed, dated snapshot `database/data/nasdaq100.csv`.
 * Source, retrieval date, hash, classification rules and the rebalance
 * procedure are in `database/data/nasdaq100.md`.
 *
 * US / USD. Members that are also S&P 500 Instruments only gain membership
 * (the S&P 500 dataset owns their fields and their single set of bars); the
 * others are created with the classification of the snapshot. Run it after
 * `Sp500UniverseSeeder`.
 */
class Nasdaq100UniverseSeeder extends IndexUniverseSeeder
{
    public const UNIVERSE_SLUG = 'nasdaq100';

    protected function slug(): string
    {
        return self::UNIVERSE_SLUG;
    }

    protected function universeName(): string
    {
        return 'Nasdaq-100';
    }

    protected function market(): string
    {
        return ListingMarket::US;
    }

    protected function position(): int
    {
        return 1;
    }
}
