<?php

namespace Tests\Feature;

use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\Universe;
use App\Services\CompanyCard\CompanyCardContextBuilder;
use App\Services\Forecast\ForecastStateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `indicators-expansion-storage`: new snapshot columns, ingestion, the history
 * endpoint and the proof that forecast, Company Card and `show` are unchanged.
 */
class IndicatorsExpansionStorageTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_COLUMNS = ['atr14', 'plus_di', 'minus_di', 'stoch_k', 'stoch_d'];

    private const OLD_KEYS = [
        'date', 'sma20', 'sma50', 'sma200', 'ema21', 'ema55', 'rsi14', 'adx',
        'macd', 'macd_signal', 'macd_hist', 'bb_upper', 'bb_middle', 'bb_lower', 'rvol',
    ];

    /** @return list<string> */
    private function seriesKeys(): array
    {
        return ['date', 'bb_upper', 'bb_middle', 'bb_lower', 'adx', 'plus_di', 'minus_di', 'atr14', 'stoch_k', 'stoch_d'];
    }

    private function snapshots(Instrument $instrument, int $count, array $overrides = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            IndicatorSnapshot::factory()->create($overrides + [
                'instrument_id' => $instrument->id,
                'date' => Carbon::parse('2026-01-01')->addDays($i)->format('Y-m-d'),
            ]);
        }
    }

    public function test_the_migration_adds_and_drops_the_five_columns(): void
    {
        foreach (self::NEW_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('indicator_snapshots', $column), $column);
        }

        $migration = require database_path('migrations/2026_10_29_000001_add_expansion_indicators_to_indicator_snapshots_table.php');
        $migration->down();

        foreach (self::NEW_COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('indicator_snapshots', $column), $column);
        }
        $this->assertTrue(Schema::hasColumn('indicator_snapshots', 'adx'));

        $migration->up();

        foreach (self::NEW_COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('indicator_snapshots', $column), $column);
        }
    }

    public function test_compute_stores_the_new_values_idempotently_and_null_for_invalid_ones(): void
    {
        $universe = Universe::factory()->create(['slug' => 'sp500']);
        $instrument = Instrument::factory()->create(['ticker' => 'NVDA']);
        $universe->instruments()->syncWithoutDetaching([$instrument->id]);

        foreach (['2026-09-01', '2026-09-02'] as $date) {
            DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => $date]);
        }

        $base = array_fill_keys(
            ['sma20', 'sma50', 'sma200', 'ema21', 'ema55', 'rsi14', 'adx', 'macd', 'macd_signal', 'macd_hist', 'bb_upper', 'bb_middle', 'bb_lower', 'rvol'],
            null,
        );

        Http::fake([
            '*/indicators/compute' => Http::response(['snapshots' => [
                ['date' => '2026-09-01'] + $base + ['atr14' => 2.5, 'plus_di' => 30.125, 'minus_di' => 10.5, 'stoch_k' => 80.0, 'stoch_d' => 'oops'],
                ['date' => '2026-09-02'] + $base,
            ]]),
        ]);

        $this->artisan('indicators:compute', ['--universe' => 'sp500'])->assertExitCode(0);
        $this->artisan('indicators:compute', ['--universe' => 'sp500'])->assertExitCode(0);

        $this->assertDatabaseCount('indicator_snapshots', 2);

        $first = IndicatorSnapshot::query()->orderBy('date')->firstOrFail();
        $this->assertEqualsWithDelta(2.5, (float) $first->atr14, 0.0001);
        $this->assertEqualsWithDelta(30.125, (float) $first->plus_di, 0.0001);
        $this->assertEqualsWithDelta(10.5, (float) $first->minus_di, 0.0001);
        $this->assertEqualsWithDelta(80.0, (float) $first->stoch_k, 0.0001);
        $this->assertNull($first->stoch_d);

        $second = IndicatorSnapshot::query()->orderByDesc('date')->firstOrFail();
        foreach (self::NEW_COLUMNS as $column) {
            $this->assertNull($second->getAttribute($column), $column);
        }
    }

    public function test_the_series_endpoint_is_ascending_bounded_and_keeps_warm_up_null(): void
    {
        $instrument = Instrument::factory()->create(['ticker' => 'NVDA']);
        // Inserted newest first on purpose.
        foreach (['2026-09-03', '2026-09-01', '2026-09-02'] as $date) {
            IndicatorSnapshot::factory()->create([
                'instrument_id' => $instrument->id,
                'date' => $date,
                'atr14' => $date === '2026-09-01' ? null : 1.5,
                'stoch_k' => 55.5,
            ]);
        }

        $response = $this->getJson('/api/instruments/nvda/indicators')->assertOk();

        $response
            ->assertJsonPath('ticker', 'NVDA')
            ->assertJsonCount(3, 'series')
            ->assertJsonPath('series.0.date', '2026-09-01')
            ->assertJsonPath('series.2.date', '2026-09-03')
            ->assertJsonPath('series.0.atr14', null)
            ->assertJsonPath('meta.point_count', 3)
            ->assertJsonPath('meta.latest_date', '2026-09-03');

        $this->assertSame($this->seriesKeys(), array_keys($response->json('series.1')));
        $this->assertEqualsWithDelta(1.5, $response->json('series.1.atr14'), 0.0001);
        $this->assertEqualsWithDelta(55.5, $response->json('series.2.stoch_k'), 0.0001);

        // Newest `limit` dates, still ascending.
        $this->getJson('/api/instruments/NVDA/indicators?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'series')
            ->assertJsonPath('series.0.date', '2026-09-02')
            ->assertJsonPath('series.1.date', '2026-09-03');
    }

    public function test_the_series_limit_is_clamped_silently(): void
    {
        $instrument = Instrument::factory()->create(['ticker' => 'NVDA']);
        $this->snapshots($instrument, 3);

        $this->getJson('/api/instruments/NVDA/indicators?limit=0')
            ->assertOk()->assertJsonPath('meta.limit', 1)->assertJsonCount(1, 'series');
        $this->getJson('/api/instruments/NVDA/indicators?limit=999999')
            ->assertOk()->assertJsonPath('meta.limit', 2000)->assertJsonCount(3, 'series');
        $this->getJson('/api/instruments/NVDA/indicators?limit=abc')
            ->assertOk()->assertJsonPath('meta.limit', 252);
    }

    public function test_the_series_endpoint_404s_for_an_unknown_ticker_and_works_for_etfs_and_other_universes(): void
    {
        $this->getJson('/api/instruments/ZZZZ/indicators')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Instrument not found.']);

        $etf = Instrument::factory()->create(['ticker' => 'SPY', 'kind' => Instrument::KIND_ETF]);
        $this->snapshots($etf, 1);
        $this->getJson('/api/instruments/SPY/indicators')->assertOk()->assertJsonCount(1, 'series');

        $ibex = Instrument::factory()->create(['ticker' => 'SAN.MC']);
        $this->snapshots($ibex, 2);
        $this->getJson('/api/instruments/SAN.MC/indicators')->assertOk()->assertJsonCount(2, 'series');
    }

    public function test_an_instrument_without_snapshots_returns_an_empty_series(): void
    {
        Instrument::factory()->create(['ticker' => 'NVDA']);

        $this->getJson('/api/instruments/NVDA/indicators')
            ->assertOk()
            ->assertExactJson([
                'ticker' => 'NVDA',
                'series' => [],
                'meta' => ['limit' => 252, 'point_count' => 0, 'latest_date' => null],
            ]);
    }

    public function test_show_keeps_the_old_snapshot_keys_and_adds_the_five_new_ones(): void
    {
        $instrument = Instrument::factory()->create(['ticker' => 'NVDA']);
        IndicatorSnapshot::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => '2026-09-01',
            'atr14' => 2.5,
            'plus_di' => null,
        ]);

        $snapshot = $this->getJson('/api/instruments/NVDA')->assertOk()->json('snapshot');

        $this->assertSame([...self::OLD_KEYS, ...self::NEW_COLUMNS], array_keys($snapshot));
        $this->assertEqualsWithDelta(2.5, $snapshot['atr14'], 0.0001);
        $this->assertNull($snapshot['plus_di']);
        $this->assertIsFloat($snapshot['stoch_k']);
    }

    public function test_the_forecast_state_is_identical_whether_the_new_columns_are_filled_or_null(): void
    {
        $instrument = Instrument::factory()->create(['ticker' => 'TEST']);
        $date = Carbon::parse('2026-01-05');
        for ($i = 0; $i < 50; $i++) {
            DailyBar::factory()->create([
                'instrument_id' => $instrument->id,
                'date' => $date->format('Y-m-d'),
                'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 1000,
            ]);
            $date->addDay();
            while ($date->isWeekend()) {
                $date->addDay();
            }
        }
        $latest = $instrument->dailyBars()->orderByDesc('date')->first()->date->format('Y-m-d');
        $snapshot = IndicatorSnapshot::factory()->create([
            'instrument_id' => $instrument->id, 'date' => $latest, 'rsi14' => 55, 'sma50' => 100,
            'atr14' => null, 'plus_di' => null, 'minus_di' => null, 'stoch_k' => null, 'stoch_d' => null,
        ]);

        $builder = app(ForecastStateBuilder::class);
        $before = $builder->build($instrument);

        $snapshot->update(['atr14' => 3.2, 'plus_di' => 22.0, 'minus_di' => 11.0, 'stoch_k' => 70.0, 'stoch_d' => 65.0]);
        $after = $builder->build($instrument->fresh());

        $this->assertNotNull($before);
        $this->assertSame($before['input_hash'], $after['input_hash']);
        $this->assertSame($before['state'], $after['state']);
        $this->assertSame(
            ['sma20', 'sma50', 'sma200', 'ema21', 'ema55', 'rsi14', 'adx', 'macd', 'macd_signal', 'macd_hist', 'bb_upper', 'bb_middle', 'bb_lower', 'rvol'],
            array_keys($after['state']['indicators']),
        );
    }

    public function test_the_company_card_context_is_identical_whether_the_new_columns_are_filled_or_null(): void
    {
        config()->set('ai_card.rights_verified', true);
        $instrument = Instrument::factory()->create(['ticker' => 'NVDA']);
        DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => '2026-10-13', 'close' => 100]);
        DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => '2026-10-14', 'close' => 102.5]);
        $snapshot = IndicatorSnapshot::factory()->create([
            'instrument_id' => $instrument->id, 'date' => '2026-10-14', 'rsi14' => 61.25,
            'atr14' => null, 'plus_di' => null, 'minus_di' => null, 'stoch_k' => null, 'stoch_d' => null,
        ]);

        $builder = app(CompanyCardContextBuilder::class);
        $before = $builder->build($instrument);

        $snapshot->update(['atr14' => 3.2, 'plus_di' => 22.0, 'minus_di' => 11.0, 'stoch_k' => 70.0, 'stoch_d' => 65.0]);
        $after = $builder->build($instrument->fresh());

        $this->assertSame($before, $after);
        $this->assertSame(
            ['sma20', 'sma50', 'sma200', 'rsi14', 'rvol', 'macd_hist', 'adx'],
            array_keys($after['context']['technical']['indicators']),
        );
    }
}
