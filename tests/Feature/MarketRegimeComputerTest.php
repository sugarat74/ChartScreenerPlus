<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\MacroSeries;
use App\Models\MarketRegimeSnapshot;
use App\Models\Universe;
use App\Services\MarketRegime\MarketRegimeComputer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `MarketRegimeComputer` (docs/specs/market-regime.md, Scenarios 1-7).
 * Offline fixtures written straight into the snapshot and macro tables with
 * `startOfDay()` dates, like the real writers. Every figure is computed by
 * hand in the comments.
 *
 * Fixture A (`d` = 2026-10-15, a Thursday):
 * - Breadth of `sp500`: 10-14 (58.0, mixed), 10-15 (63.5, uptrend), 10-16
 *   (30.0, downtrend).
 * - 11 sector ETFs: on 10-15, 8 uptrend, 2 mixed, 1 downtrend; on 10-16 all
 *   downtrend.
 * - DGS10 10-14 4.10, 10-15 4.50; DGS3MO 10-14 3.95, 10-15 3.90.
 * - WALCL / WDTGAL / WLRRAOL (millions): 2026-07-15 6,850,000 / 820,000 /
 *   30,000 -> net liquidity (6,850,000 - 820,000 - 30,000) / 1000 = 6000.0 bn;
 *   2026-10-14 6,950,000 / 800,000 / 20,000 -> 6130.0 bn. 2026-07-15 is
 *   exactly 91 days before 2026-10-14.
 *
 * `macro.enabled` is true (except Scenario 6); NFCI and VIXCLS keep their
 * default `enabled = false` (except Scenario 5).
 */
class MarketRegimeComputerTest extends TestCase
{
    use RefreshDatabase;

    private const D = '2026-10-15';

    private Universe $main;

    /** @var list<Instrument> */
    private array $etfs = [];

    /** Rule 6 example: the expected components of fixture A on 10-15. */
    private const FIXTURE_A_COMPONENTS = [
        'breadth' => ['status' => 'ok', 'score' => 1, 'value' => 63.5, 'as_of' => '2026-10-15', 'stale' => false],
        'index_trend' => ['status' => 'ok', 'score' => 1, 'value' => 'uptrend', 'as_of' => '2026-10-15', 'stale' => false],
        'sector_participation' => ['status' => 'ok', 'score' => 1, 'value' => 8, 'as_of' => '2026-10-15', 'stale' => false],
        'yield_curve' => ['status' => 'ok', 'score' => 0, 'value' => 0.15, 'as_of' => '2026-10-14', 'stale' => false],
        'financial_conditions' => ['status' => 'disabled', 'score' => null, 'value' => null, 'as_of' => null, 'stale' => false],
        'liquidity' => ['status' => 'ok', 'score' => 1, 'value' => 2.1667, 'as_of' => '2026-10-14', 'stale' => false],
        'volatility' => ['status' => 'disabled', 'score' => null, 'value' => null, 'as_of' => null, 'stale' => false],
    ];

    private const MISSING = ['status' => 'missing', 'score' => null, 'value' => null, 'as_of' => null, 'stale' => false];

    private const DISABLED = ['status' => 'disabled', 'score' => null, 'value' => null, 'as_of' => null, 'stale' => false];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['ingestion.universe' => 'sp500', 'macro.enabled' => true]);
        $this->main = Universe::factory()->create(['slug' => 'sp500', 'name' => 'S&P 500']);

        for ($i = 1; $i <= 11; $i++) {
            $this->etfs[] = Instrument::factory()->etf()->create(['ticker' => sprintf('XS%02d', $i), 'sector' => "Sector {$i}"]);
        }
    }

    private function breadth(string $date, ?float $pct, ?string $trend): void
    {
        DB::table('market_breadth_snapshots')->insert([
            'universe_id' => $this->main->id,
            'date' => Carbon::parse($date)->startOfDay(),
            'members' => 500,
            'pct_above_sma50' => null,
            'pct_above_sma200' => $pct === null ? null : sprintf('%.4F', $pct),
            'new_highs' => null,
            'new_lows' => null,
            'advancers' => 250,
            'decliners' => 250,
            'spy_trend' => $trend,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * One sector row per trend, on the first `count($trends)` ETFs.
     *
     * @param  list<string|null>  $trends
     */
    private function sectors(string $date, array $trends): void
    {
        foreach ($trends as $i => $trend) {
            DB::table('market_sector_snapshots')->insert([
                'date' => Carbon::parse($date)->startOfDay(),
                'instrument_id' => $this->etfs[$i]->id,
                'sector' => $this->etfs[$i]->sector,
                'trend' => $trend,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @param  array<string, float|int>  $values  date => value */
    private function observe(string $code, array $values): void
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
    }

    /** 8 uptrend, 2 mixed, 1 downtrend. @return list<string> */
    private static function eightUp(): array
    {
        return [...array_fill(0, 8, 'uptrend'), 'mixed', 'mixed', 'downtrend'];
    }

    private function fixtureA(bool $breadth = true, bool $curve = true, bool $liquidity = true): void
    {
        if ($breadth) {
            $this->breadth('2026-10-14', 58.0, 'mixed');
            $this->breadth('2026-10-15', 63.5, 'uptrend');
            $this->breadth('2026-10-16', 30.0, 'downtrend');
        }

        $this->sectors('2026-10-15', self::eightUp());
        $this->sectors('2026-10-16', array_fill(0, 11, 'downtrend'));

        if ($curve) {
            $this->observe('DGS10', ['2026-10-14' => 4.10, '2026-10-15' => 4.50]);
            $this->observe('DGS3MO', ['2026-10-14' => 3.95, '2026-10-15' => 3.90]);
        }

        if ($liquidity) {
            $this->observe('WALCL', ['2026-07-15' => 6_850_000, '2026-10-14' => 6_950_000]);
            $this->observe('WDTGAL', ['2026-07-15' => 820_000, '2026-10-14' => 800_000]);
            $this->observe('WLRRAOL', ['2026-07-15' => 30_000, '2026-10-14' => 20_000]);
        }
    }

    private function compute(string ...$dates): void
    {
        app(MarketRegimeComputer::class)->compute($dates);
    }

    private function row(string $date): MarketRegimeSnapshot
    {
        return MarketRegimeSnapshot::query()->whereDate('date', $date)->sole();
    }

    public function test_scenario_1_fixture_a_is_favourable_with_the_rule_6_components(): void
    {
        $this->fixtureA();

        $result = app(MarketRegimeComputer::class)->compute([self::D]);

        // breadth 63.5 >= 60 -> +1; index uptrend -> +1; sectors 8 >= 7 -> +1;
        // curve: cutoff 10-14 (lag 1), 4.10 - 3.95 = 0.15 -> 0 (the 10-15
        // spread 4.50 - 3.90 = 0.60 would score +1 but is not yet published);
        // liquidity: (6130 / 6000 - 1) * 100 = 2.1667 >= 2 -> +1.
        // Sum 4 over 5 ok components -> favourable. The 10-16 rows are ignored.
        $row = $this->row(self::D);
        $this->assertSame('favourable', $row->regime);
        $this->assertSame(4, $row->score);
        $this->assertSame(5, $row->available);
        $this->assertSame(1, $row->rules_version);
        $this->assertSame(self::FIXTURE_A_COMPONENTS, $row->components);
        $this->assertSame(
            ['rows' => 1, 'latest' => ['date' => self::D, 'regime' => 'favourable', 'score' => 4, 'available' => 5]],
            $result,
        );

        // The stored JSON keeps the float/int types (63.5, 8, 0.15).
        $raw = (string) DB::table('market_regime_snapshots')->value('components');
        $this->assertStringContainsString('"value":63.5', $raw);
        $this->assertStringContainsString('"value":8,', $raw);
    }

    public function test_scenario_2_a_past_date_reads_no_later_data(): void
    {
        $this->fixtureA();

        $this->compute('2026-10-14');

        // breadth 58.0 -> 0 and index mixed -> 0 (10-14 row); no complete
        // sector date <= 10-14; curve cutoff 10-13 has no observation;
        // liquidity cutoff 10-13: only the 07-15 week is published and it
        // has no week 91 days earlier (04-15). 2 available -> insufficient.
        $row = $this->row('2026-10-14');
        $this->assertSame('insufficient', $row->regime);
        $this->assertNull($row->score);
        $this->assertSame(2, $row->available);
        $this->assertSame([
            'breadth' => ['status' => 'ok', 'score' => 0, 'value' => 58.0, 'as_of' => '2026-10-14', 'stale' => false],
            'index_trend' => ['status' => 'ok', 'score' => 0, 'value' => 'mixed', 'as_of' => '2026-10-14', 'stale' => false],
            'sector_participation' => self::MISSING,
            'yield_curve' => self::MISSING,
            'financial_conditions' => self::DISABLED,
            'liquidity' => self::MISSING,
            'volatility' => self::DISABLED,
        ], $row->components);
    }

    public function test_scenario_3_a_market_reading_of_age_7_counts(): void
    {
        $this->fixtureA(breadth: false);
        $this->breadth('2026-10-08', 63.5, 'uptrend');

        $this->compute(self::D);

        // 10-15 - 10-08 = 7 days, not more than 7 -> ok.
        $components = $this->row(self::D)->components;
        $this->assertSame(['status' => 'ok', 'score' => 1, 'value' => 63.5, 'as_of' => '2026-10-08', 'stale' => false], $components['breadth']);
        $this->assertSame(['status' => 'ok', 'score' => 1, 'value' => 'uptrend', 'as_of' => '2026-10-08', 'stale' => false], $components['index_trend']);
        $this->assertSame(5, $this->row(self::D)->available);
    }

    public function test_scenario_3_stale_readings_keep_their_value_but_never_count(): void
    {
        $this->fixtureA(breadth: false, curve: false);
        $this->breadth('2026-10-07', 63.5, 'uptrend');
        $this->observe('DGS10', ['2026-10-07' => 4.50]);
        $this->observe('DGS3MO', ['2026-10-07' => 3.90]);

        $this->compute(self::D);

        // Breadth age 8 > 7; curve 4.50 - 3.90 = 0.60 dated 10-07, age 8 > 7
        // (daily limit) -> stale, no score. Only sectors (+1) and liquidity
        // (+1) count: 2 available -> insufficient.
        $row = $this->row(self::D);
        $this->assertSame(['status' => 'stale', 'score' => null, 'value' => 63.5, 'as_of' => '2026-10-07', 'stale' => true], $row->components['breadth']);
        $this->assertSame(['status' => 'stale', 'score' => null, 'value' => 'uptrend', 'as_of' => '2026-10-07', 'stale' => true], $row->components['index_trend']);
        $this->assertSame(['status' => 'stale', 'score' => null, 'value' => 0.6, 'as_of' => '2026-10-07', 'stale' => true], $row->components['yield_curve']);
        $this->assertSame(2, $row->available);
        $this->assertSame('insufficient', $row->regime);
        $this->assertNull($row->score);
    }

    public function test_scenario_4_partial_sectors_are_missing_and_three_components_are_insufficient(): void
    {
        $this->breadth('2026-10-15', 63.5, 'uptrend');
        $this->sectors('2026-10-15', array_slice(self::eightUp(), 0, 10));
        $this->observe('DGS10', ['2026-10-14' => 4.50]);
        $this->observe('DGS3MO', ['2026-10-14' => 3.90]);

        $this->compute(self::D);

        // Only 10 of 11 sector trends: never scored on a partial count.
        // Breadth +1, index +1, curve 0.60 >= 0.5 +1: sum 3, but only 3
        // available -> insufficient, score null.
        $row = $this->row(self::D);
        $this->assertSame(self::MISSING, $row->components['sector_participation']);
        $this->assertSame(self::MISSING, $row->components['liquidity']);
        $this->assertSame(1, $row->components['yield_curve']['score']);
        $this->assertSame(0.6, $row->components['yield_curve']['value']);
        $this->assertSame(3, $row->available);
        $this->assertSame('insufficient', $row->regime);
        $this->assertNull($row->score);
    }

    public function test_scenario_4_an_earlier_complete_sector_date_is_used_instead_of_a_partial_one(): void
    {
        $this->fixtureA();
        // 10-16 is replaced by a partial day; computing 10-16 reads 10-15's 8.
        DB::table('market_sector_snapshots')->whereDate('date', '2026-10-16')->delete();
        $this->sectors('2026-10-16', array_fill(0, 10, 'downtrend'));

        $this->compute('2026-10-16');

        $this->assertSame(
            ['status' => 'ok', 'score' => 1, 'value' => 8, 'as_of' => '2026-10-15', 'stale' => false],
            $this->row('2026-10-16')->components['sector_participation'],
        );
    }

    public function test_scenario_5_restricted_series_when_enabled_score_point_in_time(): void
    {
        config(['macro.series.NFCI.enabled' => true, 'macro.series.VIXCLS.enabled' => true]);
        $this->fixtureA();
        // NFCI lag 5: the 10-09 week is visible from 10-14; -0.30 < -0.25 -> +1.
        // VIX lag 1: 10-14 visible on 10-15; 16.5 < 18 -> +1.
        $this->observe('NFCI', ['2026-10-09' => -0.30]);
        $this->observe('VIXCLS', ['2026-10-14' => 16.5, '2026-10-15' => 35.0]);

        $this->compute(self::D);

        // 4 (fixture A) + 1 + 1 = 6 over 7 components -> favourable.
        $row = $this->row(self::D);
        $this->assertSame(['status' => 'ok', 'score' => 1, 'value' => -0.3, 'as_of' => '2026-10-09', 'stale' => false], $row->components['financial_conditions']);
        $this->assertSame(['status' => 'ok', 'score' => 1, 'value' => 16.5, 'as_of' => '2026-10-14', 'stale' => false], $row->components['volatility']);
        $this->assertSame(7, $row->available);
        $this->assertSame(6, $row->score);
        $this->assertSame('favourable', $row->regime);
    }

    public function test_scenario_6_macro_disabled_disables_the_four_macro_components(): void
    {
        config(['macro.enabled' => false]);
        $this->fixtureA();

        $this->compute(self::D);

        // The observations exist but a switched-off feed never scores:
        // breadth, index and sectors only -> 3 available -> insufficient.
        $row = $this->row(self::D);
        foreach (['yield_curve', 'financial_conditions', 'liquidity', 'volatility'] as $key) {
            $this->assertSame(self::DISABLED, $row->components[$key], $key);
        }
        $this->assertSame(3, $row->available);
        $this->assertSame('insufficient', $row->regime);
        $this->assertNull($row->score);
    }

    public function test_scenario_7_every_compute_reads_fresh_macro_observations(): void
    {
        $this->fixtureA(curve: false);
        $computer = app(MarketRegimeComputer::class);

        $computer->compute([self::D]);
        $this->assertSame(self::MISSING, $this->row(self::D)->components['yield_curve']);

        $this->observe('DGS10', ['2026-10-14' => 4.10]);
        $this->observe('DGS3MO', ['2026-10-14' => 3.95]);
        $computer->compute([self::D]);

        $this->assertSame('ok', $this->row(self::D)->components['yield_curve']['status']);
        $this->assertSame(1, MarketRegimeSnapshot::query()->count());
    }

    public function test_every_component_key_is_present_in_order_and_no_text_is_stored(): void
    {
        $this->compute(self::D);

        // No data at all: every key, market ones missing, NFCI/VIX disabled.
        $row = $this->row(self::D);
        $this->assertSame(
            ['breadth', 'index_trend', 'sector_participation', 'yield_curve', 'financial_conditions', 'liquidity', 'volatility'],
            array_keys($row->components),
        );
        $this->assertSame(0, $row->available);
        $this->assertSame('insufficient', $row->regime);
        foreach ($row->components as $component) {
            $this->assertSame(['status', 'score', 'value', 'as_of', 'stale'], array_keys($component));
        }
    }
}
