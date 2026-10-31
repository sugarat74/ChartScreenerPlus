<?php

namespace Database\Seeders;

use App\Support\ListingMarket;

/**
 * Seeds the DAX Screener universe (docs/specs/multi-market-universes.md,
 * Feature 6) from the committed, dated snapshot `database/data/dax.csv`.
 * Source, retrieval date, hash, classification rules and the rebalance
 * procedure are in `database/data/dax.md`.
 *
 * XETR (Xetra) / EUR, `.DE` tickers. The label is "DAX" (never "DAX 40").
 * No member overlaps another universe (`MRK.DE` is a different Instrument
 * from the S&P 500 `MRK`), so every Instrument is created from the snapshot.
 */
class DaxUniverseSeeder extends IndexUniverseSeeder
{
    public const UNIVERSE_SLUG = 'dax';

    protected function slug(): string
    {
        return self::UNIVERSE_SLUG;
    }

    protected function universeName(): string
    {
        return 'DAX';
    }

    protected function market(): string
    {
        return ListingMarket::XETR;
    }

    protected function position(): int
    {
        return 3;
    }
}
