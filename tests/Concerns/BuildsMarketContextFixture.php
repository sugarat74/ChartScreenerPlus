<?php

namespace Tests\Concerns;

use App\Models\Instrument;
use App\Models\MacroSeries;
use App\Models\Universe;
use Database\Seeders\MarketContextUniverseSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The "production-like" Market Context fixture of
 * docs/specs/market-context-ui.md (Acceptance Scenarios), written straight
 * into the tables with `startOfDay()` dates like the real writers.
 *
 * - Today 2026-10-23 (New York). Main Universe `sp500`; the seeded
 *   `market-context` Universe (SPY + 11 SPDR sector ETFs).
 * - Regime 2026-10-22 `neutral`, score 1, available 5 (REGIME_COMPONENTS).
 * - 11 sector rows on 2026-10-22 ranked 1..11 by `rs_60` (SECTOR_ROWS),
 *   6 in uptrend; one Breadth row on 2026-10-22.
 * - Macro (enabled; NFCI and VIXCLS off):
 *   DGS10 on 23 weekdays ending 2026-10-21 (4.00 + 0.005 * i, last 4.11 ...
 *   see macroFixture()); DGS3MO 3.80 and DGS2 3.60 on 10-20/10-21;
 *   DFEDTARL/U 4.00/4.25 on 09-16, 3.75/4.00 on 09-17 and 10-22 (a cut);
 *   ECBDFR 2.75 on 06-10, 2.50 on 06-11 and 10-22 (a cut);
 *   WALCL/WDTGAL/WLRRAOL (millions) on Wednesdays 07-15, 09-16 and 10-14;
 *   ICSA on four consecutive Saturdays ending 10-17;
 *   PERMIT 06-01 and 09-01; OECD CLI 08-01 and 09-01 (monthly, dated the 1st).
 */
trait BuildsMarketContextFixture
{
    protected const TODAY = '2026-10-23';

    protected const LATEST = '2026-10-22';

    /** The stored components of the 2026-10-22 regime row, in stored order. */
    protected const REGIME_COMPONENTS = [
        'breadth' => ['status' => 'ok', 'score' => 0, 'value' => 55.2, 'as_of' => '2026-10-22', 'stale' => false],
        'index_trend' => ['status' => 'ok', 'score' => 1, 'value' => 'uptrend', 'as_of' => '2026-10-22', 'stale' => false],
        'sector_participation' => ['status' => 'ok', 'score' => 0, 'value' => 6, 'as_of' => '2026-10-22', 'stale' => false],
        'yield_curve' => ['status' => 'ok', 'score' => 0, 'value' => 0.31, 'as_of' => '2026-10-21', 'stale' => false],
        'financial_conditions' => ['status' => 'disabled', 'score' => null, 'value' => null, 'as_of' => null, 'stale' => false],
        'liquidity' => ['status' => 'ok', 'score' => 0, 'value' => -0.85, 'as_of' => '2026-10-15', 'stale' => false],
        'volatility' => ['status' => 'disabled', 'score' => null, 'value' => null, 'as_of' => null, 'stale' => false],
    ];

    /**
     * ticker => [rs_rank, ret_5, ret_20, ret_60, rs_60, trend, breadth_200].
     *
     * @var array<string, array{0: int, 1: float, 2: float, 3: float, 4: float, 5: string, 6: float}>
     */
    protected const SECTOR_ROWS = [
        'XLK' => [1, 1.23, 4.5, 9.87, 3.21, 'uptrend', 71.43],
        'XLC' => [2, 0.9, 3.1, 8.2, 2.5, 'uptrend', 65.0],
        'XLY' => [3, 0.4, 2.2, 6.1, 1.4, 'uptrend', 60.0],
        'XLI' => [4, 0.3, 1.9, 5.5, 0.8, 'uptrend', 58.0],
        'XLF' => [5, 0.2, 1.5, 4.9, 0.2, 'uptrend', 55.0],
        'XLV' => [6, 0.1, 1.1, 4.0, -0.7, 'uptrend', 52.0],
        'XLB' => [7, -0.1, 0.5, 3.2, -1.5, 'mixed', 48.0],
        'XLRE' => [8, -0.4, -0.2, 2.1, -2.6, 'mixed', 45.0],
        'XLP' => [9, -0.6, -0.9, 1.0, -3.7, 'mixed', 40.0],
        'XLU' => [10, -0.8, -1.4, 0.3, -4.4, 'downtrend', 35.0],
        'XLE' => [11, -1.5, -3.0, -2.2, -6.9, 'downtrend', 20.0],
    ];

    protected Universe $mainUniverse;

    protected function setUpMarketContextFixture(): void
    {
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 12:00:00', 'America/New_York'));
        config([
            'ingestion.universe' => 'sp500',
            'ingestion.timezone' => 'America/New_York',
            'market_context.universe' => 'market-context',
            'macro.enabled' => true,
        ]);

        $this->mainUniverse = Universe::factory()->create(['slug' => 'sp500', 'name' => 'S&P 500']);
        $this->seed(MarketContextUniverseSeeder::class);
    }

    protected function sectorRows(string $date = self::LATEST, array $rows = self::SECTOR_ROWS): void
    {
        foreach ($rows as $ticker => [$rank, $ret5, $ret20, $ret60, $rs60, $trend, $breadth]) {
            $instrument = Instrument::query()->where('ticker', $ticker)->firstOrFail();
            DB::table('market_sector_snapshots')->insert([
                'date' => Carbon::parse($date)->startOfDay(),
                'instrument_id' => $instrument->id,
                'sector' => $instrument->sector,
                'ret_5' => $ret5,
                'ret_20' => $ret20,
                'ret_60' => $ret60,
                'rs_20' => null,
                'rs_60' => $rs60,
                'rs_rank' => $rank,
                'trend' => $trend,
                'breadth_200' => $breadth,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function breadthRow(string $date = self::LATEST, ?float $above200 = 55.2): void
    {
        DB::table('market_breadth_snapshots')->insert([
            'universe_id' => $this->mainUniverse->id,
            'date' => Carbon::parse($date)->startOfDay(),
            'members' => 503,
            'pct_above_sma50' => 58.1,
            'pct_above_sma200' => $above200,
            'new_highs' => 21,
            'new_lows' => 4,
            'advancers' => 301,
            'decliners' => 195,
            'spy_trend' => 'uptrend',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $components */
    protected function regimeRow(
        string $date = self::LATEST,
        string $regime = 'neutral',
        ?int $score = 1,
        int $available = 5,
        array $components = self::REGIME_COMPONENTS,
    ): void {
        DB::table('market_regime_snapshots')->insert([
            'date' => Carbon::parse($date)->startOfDay(),
            'regime' => $regime,
            'score' => $score,
            'available' => $available,
            'components' => json_encode($components, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'rules_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param  array<string, float|int>  $values  date => value */
    protected function observe(string $code, array $values): void
    {
        $config = config("macro.series.{$code}");
        $series = MacroSeries::query()->firstOrCreate(['code' => $code], [
            'source' => 'fred',
            'category' => $config['category'],
            'frequency' => $config['frequency'],
        ]);

        foreach ($values as $date => $value) {
            DB::table('macro_observations')->insert([
                'macro_series_id' => $series->id,
                'date' => Carbon::parse($date)->startOfDay(),
                'value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $latest = array_key_last($values);
        if ($latest !== null && ($series->last_observation_date === null || $series->last_observation_date->toDateString() < $latest)) {
            $series->forceFill(['last_observation_date' => $latest])->save();
        }
    }

    /**
     * The macro observations of the fixture. DGS10 rises 0.005 per weekday
     * from 4.00 on 2026-09-21 (i = 0) to 4.11 on 2026-10-21 (i = 22):
     * dgs10_change_20 = 4.11 - 4.01 = 0.10; spread_10y3m = 4.11 - 3.80 = 0.31;
     * spread_10y2y = 4.11 - 3.60 = 0.51.
     * Net liquidity (bn): 07-15 (6,850,000 - 820,000 - 30,000) / 1000 = 6000;
     * 09-16 (6,900,000 - 810,000 - 25,000) / 1000 = 6065;
     * 10-14 (6,950,000 - 800,000 - 20,000) / 1000 = 6130 -> 4w +1.0717 %,
     * 13w +2.1667 %. ICSA 4-week average (220k, 225k, 230k, 235k) = 227,500.
     * PERMIT 1,400 -> 1,450 = +3.5714 %; OECD CLI 100.1 -> 100.3 = +0.2.
     */
    protected function macroFixture(): void
    {
        $dgs10 = [];
        $day = Carbon::parse('2026-09-21');
        for ($i = 0; count($dgs10) < 23; $day->addDay()) {
            if ($day->isWeekend()) {
                continue;
            }
            $dgs10[$day->toDateString()] = round(4.0 + 0.005 * $i, 4);
            $i++;
        }

        $this->observe('DGS10', $dgs10);
        $this->observe('DGS3MO', ['2026-10-20' => 3.80, '2026-10-21' => 3.80]);
        $this->observe('DGS2', ['2026-10-20' => 3.60, '2026-10-21' => 3.60]);
        $this->observe('DFEDTARL', ['2026-09-16' => 4.00, '2026-09-17' => 3.75, '2026-10-22' => 3.75]);
        $this->observe('DFEDTARU', ['2026-09-16' => 4.25, '2026-09-17' => 4.00, '2026-10-22' => 4.00]);
        $this->observe('ECBDFR', ['2026-06-10' => 2.75, '2026-06-11' => 2.50, '2026-10-22' => 2.50]);
        $this->observe('WALCL', ['2026-07-15' => 6850000, '2026-09-16' => 6900000, '2026-10-14' => 6950000]);
        $this->observe('WDTGAL', ['2026-07-15' => 820000, '2026-09-16' => 810000, '2026-10-14' => 800000]);
        $this->observe('WLRRAOL', ['2026-07-15' => 30000, '2026-09-16' => 25000, '2026-10-14' => 20000]);
        $this->observe('ICSA', ['2026-09-26' => 220000, '2026-10-03' => 225000, '2026-10-10' => 230000, '2026-10-17' => 235000]);
        $this->observe('PERMIT', ['2026-06-01' => 1400, '2026-09-01' => 1450]);
        $this->observe('USALOLITOAASTSAM', ['2026-08-01' => 100.1, '2026-09-01' => 100.3]);
    }

    protected function productionLikeFixture(): void
    {
        $this->regimeRow();
        $this->sectorRows();
        $this->breadthRow();
        $this->macroFixture();
    }

    /**
     * The "context" sector rows of docs/specs/market-context-patterns.md:
     * XLK uptrend rank 1, XLE downtrend rank 10, XLU mixed with a null rank and
     * `rs_60` (so `rs_rank_of` is 10), eight more rows ranked 2..9.
     *
     * @var array<string, array{0: ?int, 1: float, 2: float, 3: float, 4: ?float, 5: string, 6: float}>
     */
    protected const CONTEXT_SECTOR_ROWS = [
        'XLK' => [1, 1.23, 4.5, 9.87, 3.21, 'uptrend', 71.43],
        'XLC' => [2, 0.9, 3.1, 8.2, 2.5, 'uptrend', 65.0],
        'XLY' => [3, 0.4, 2.2, 6.1, 1.4, 'uptrend', 60.0],
        'XLI' => [4, 0.3, 1.9, 5.5, 0.8, 'uptrend', 58.0],
        'XLF' => [5, 0.2, 1.5, 4.9, 0.2, 'uptrend', 55.0],
        'XLV' => [6, 0.1, 1.1, 4.0, -0.7, 'uptrend', 52.0],
        'XLB' => [7, -0.1, 0.5, 3.2, -1.5, 'mixed', 48.0],
        'XLRE' => [8, -0.4, -0.2, 2.1, -2.6, 'mixed', 45.0],
        'XLP' => [9, -0.6, -0.9, 1.0, -3.7, 'mixed', 40.0],
        'XLE' => [10, -1.5, -3.0, -2.2, -6.9, 'downtrend', 20.0],
        'XLU' => [null, -0.8, -1.4, 0.3, null, 'mixed', 35.0],
    ];

    /**
     * The "context" stocks (market-context-patterns): AAPL (Information
     * Technology), XOM (Energy), NEE (Utilities), ZZZ (no sector) and ODD
     * (an unmapped sector), members of the main Universe with a bar on
     * `$barDate` and the day before and a snapshot on `$barDate`.
     *
     * @return array<string, Instrument>
     */
    protected function contextStocks(string $barDate = self::LATEST): array
    {
        $stocks = [];

        foreach (['AAPL' => 'Information Technology', 'XOM' => 'Energy', 'NEE' => 'Utilities', 'ZZZ' => null, 'ODD' => 'Conglomerates'] as $ticker => $sector) {
            $instrument = Instrument::factory()->create(['ticker' => $ticker, 'company' => "{$ticker} Inc", 'sector' => $sector]);
            $this->mainUniverse->instruments()->attach($instrument->id);

            foreach ([Carbon::parse($barDate)->subDay()->toDateString() => 99.0, $barDate => 100.0] as $date => $close) {
                DB::table('daily_bars')->insert([
                    'instrument_id' => $instrument->id,
                    'date' => Carbon::parse($date)->startOfDay(),
                    'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close, 'volume' => 1000000,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('indicator_snapshots')->insert([
                'instrument_id' => $instrument->id,
                'date' => Carbon::parse($barDate)->startOfDay(),
                'sma20' => 98, 'sma50' => 95, 'sma200' => 90, 'rsi14' => 55, 'rvol' => 1.2,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $stocks[$ticker] = $instrument;
        }

        return $stocks;
    }

    /** The whole "context" fixture: sector rows, regime and the five stocks. */
    protected function contextFixture(): void
    {
        $this->sectorRows(self::LATEST, self::CONTEXT_SECTOR_ROWS);
        $this->regimeRow();
        $this->contextStocks();
    }
}
