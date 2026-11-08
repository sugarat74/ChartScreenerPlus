<?php

namespace Database\Seeders;

use App\Support\ListingMarket;

/**
 * Seeds the CAC 40 Screener universe (docs/specs/universes-cac40-ftse100.md,
 * Feature 1) from the committed, dated snapshot `database/data/cac40.csv`.
 * Source, retrieval date, hash, classification rules, the ArcelorMittal `.AS`
 * exception and the rebalance procedure are in `database/data/cac40.md`.
 *
 * XPAR (Euronext Paris) / EUR, `.PA` tickers (plus `MT.AS`, decision C3). No
 * member overlaps another universe (`AIR.PA` is a different Instrument from
 * the DAX `AIR.DE`), so every Instrument is created from the snapshot.
 */
class CacUniverseSeeder extends IndexUniverseSeeder
{
    public const UNIVERSE_SLUG = 'cac40';

    protected function slug(): string
    {
        return self::UNIVERSE_SLUG;
    }

    protected function universeName(): string
    {
        return 'CAC 40';
    }

    protected function market(): string
    {
        return ListingMarket::XPAR;
    }

    protected function position(): int
    {
        return 4;
    }
}
