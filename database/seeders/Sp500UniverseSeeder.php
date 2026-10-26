<?php

namespace Database\Seeders;

use App\Models\Instrument;
use App\Models\Universe;
use App\Services\Screener\ScreenerRowBuilder;
use App\Support\ConstituentCsv;
use App\Support\ListingMarket;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the S&P 500 universe from the committed dataset.
 *
 * Dataset: `database/data/sp500.csv` with the header
 * `ticker,company,sector,industry,country,exchange` (UTF-8, one row per constituent).
 *
 * Source: Wikipedia, "List of S&P 500 companies"
 * (https://en.wikipedia.org/wiki/List_of_S%26P_500_companies).
 * Generated once at implementation time (2026-09-29): 503 constituents taken
 * from the component table's Symbol / Security / GICS Sector columns. The
 * 2026-10-24 industry/country snapshot and its exceptions are documented in
 * `database/data/sp500-classification.md`.
 * `exchange` value is derived from each symbol's listing link (NYSE / NASDAQ /
 * CBOE) and is left empty when the source does not identify an exchange.
 *
 * The seeder is offline (reads only the committed CSV, never the network) and
 * idempotent: the universe is upserted by slug, instruments by unique ticker,
 * and membership is attached with `syncWithoutDetaching` so re-running adds no
 * duplicates and never removes an instrument that already belongs to the
 * universe.
 */
class Sp500UniverseSeeder extends Seeder
{
    public const UNIVERSE_SLUG = 'sp500';

    public const UNIVERSE_NAME = 'S&P 500';

    public const DATASET_PATH = 'database/data/sp500.csv';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rows = $this->readDataset();

        DB::transaction(function () use ($rows): void {
            $universe = Universe::query()->updateOrCreate(
                ['slug' => self::UNIVERSE_SLUG],
                [
                    'name' => self::UNIVERSE_NAME,
                    'market' => ListingMarket::US,
                    'currency' => 'USD',
                    'is_screener' => true,
                    'position' => 0,
                ],
            );

            $instrumentIds = [];

            foreach ($rows as $row) {
                $instrument = Instrument::query()->updateOrCreate(
                    ['ticker' => $row['ticker']],
                    [
                        'company' => $row['company'],
                        'sector' => $row['sector'] === '' ? null : $row['sector'],
                        'industry' => $row['industry'] === '' ? null : $row['industry'],
                        'country' => $row['country'] === '' ? null : $row['country'],
                        'exchange' => $row['exchange'] === '' ? null : $row['exchange'],
                        'active' => true,
                    ],
                );

                $instrumentIds[] = $instrument->getKey();
            }

            // Add-only: keep existing memberships, attach any that are missing.
            $universe->instruments()->syncWithoutDetaching($instrumentIds);
        });

        // Company, sector and active flags are copied into the Screener rows.
        app(ScreenerRowBuilder::class)->refresh();
    }

    /**
     * Read the committed constituent dataset.
     *
     * @return list<array{ticker: string, company: string, sector: string, industry: string, country: string, exchange: string}>
     */
    private function readDataset(): array
    {
        return ConstituentCsv::read(base_path(self::DATASET_PATH), 'S&P 500');
    }
}
