<?php

namespace Database\Seeders;

use App\Support\ListingMarket;

/**
 * Seeds the FTSE 100 Screener universe (docs/specs/universes-cac40-ftse100.md,
 * Feature 3) from the committed, dated snapshot `database/data/ftse100.csv`.
 * Source, retrieval date, hashes, classification rules, the excluded
 * constituents and the rebalance procedure are in `database/data/ftse100.md`.
 *
 * XLON (London Stock Exchange) / GBX (pence as quoted, decision P1), `.L`
 * tickers as Yahoo writes them (`BT-A.L`). Only the 97 constituents quoted
 * in pence are members; Compass Group, IHG and Metlen (quoted in USD/EUR)
 * are left out (decision P2). No member overlaps another universe (`IAG.L`
 * is a different Instrument from the IBEX 35 `IAG.MC`).
 */
class Ftse100UniverseSeeder extends IndexUniverseSeeder
{
    public const UNIVERSE_SLUG = 'ftse100';

    protected function slug(): string
    {
        return self::UNIVERSE_SLUG;
    }

    protected function universeName(): string
    {
        return 'FTSE 100';
    }

    protected function market(): string
    {
        return ListingMarket::XLON;
    }

    protected function position(): int
    {
        return 5;
    }
}
