<?php

namespace Database\Seeders;

use App\Models\Instrument;
use App\Models\Universe;
use App\Services\Screener\ScreenerRowBuilder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the Market Context universe: SPY and the 11 Select Sector SPDR ETFs
 * (docs/specs/market-context-sectors.md, Domain Rules 1-2).
 *
 * Source: State Street Global Advisors fund pages for the SPDR S&P 500 ETF
 * Trust and the eleven Select Sector SPDR Funds (ssga.com), checked
 * 2026-10-22. Each sector ETF's `sector` is the exact GICS sector string used
 * in `database/data/sp500.csv`, so every S&P 500 stock maps to exactly one
 * sector ETF; SPY is the benchmark and has no sector.
 *
 * Like `Sp500UniverseSeeder` it is offline (in-code list, no network) and
 * idempotent: the universe is upserted by slug, instruments by ticker (always
 * `kind = etf`), and membership is attached with `syncWithoutDetaching`. It is
 * run on every deploy; the Screener rows are refreshed so an ETF row can never
 * survive.
 */
class MarketContextUniverseSeeder extends Seeder
{
    public const UNIVERSE_NAME = 'Market Context';

    public const EXCHANGE = 'NYSE Arca';

    /**
     * Ticker => [fund name, GICS sector or null for the benchmark].
     *
     * @var array<string, array{0: string, 1: string|null}>
     */
    public const ETFS = [
        'SPY' => ['SPDR S&P 500 ETF Trust', null],
        'XLB' => ['Materials Select Sector SPDR Fund', 'Materials'],
        'XLC' => ['Communication Services Select Sector SPDR Fund', 'Communication Services'],
        'XLE' => ['Energy Select Sector SPDR Fund', 'Energy'],
        'XLF' => ['Financial Select Sector SPDR Fund', 'Financials'],
        'XLI' => ['Industrial Select Sector SPDR Fund', 'Industrials'],
        'XLK' => ['Technology Select Sector SPDR Fund', 'Information Technology'],
        'XLP' => ['Consumer Staples Select Sector SPDR Fund', 'Consumer Staples'],
        'XLRE' => ['Real Estate Select Sector SPDR Fund', 'Real Estate'],
        'XLU' => ['Utilities Select Sector SPDR Fund', 'Utilities'],
        'XLV' => ['Health Care Select Sector SPDR Fund', 'Health Care'],
        'XLY' => ['Consumer Discretionary Select Sector SPDR Fund', 'Consumer Discretionary'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $universe = Universe::query()->updateOrCreate(
                ['slug' => (string) config('market_context.universe')],
                ['name' => self::UNIVERSE_NAME, 'is_screener' => false],
            );

            $instrumentIds = [];

            foreach (self::ETFS as $ticker => [$company, $sector]) {
                $instrument = Instrument::query()->updateOrCreate(
                    ['ticker' => $ticker],
                    [
                        'company' => $company,
                        'sector' => $sector,
                        'exchange' => self::EXCHANGE,
                        'active' => true,
                        'kind' => Instrument::KIND_ETF,
                    ],
                );

                $instrumentIds[] = $instrument->getKey();
            }

            // Add-only: keep existing memberships, attach any that are missing.
            $universe->instruments()->syncWithoutDetaching($instrumentIds);
        });

        // Screener rows hold stocks only; a refresh drops any ETF row.
        app(ScreenerRowBuilder::class)->refresh();
    }
}
