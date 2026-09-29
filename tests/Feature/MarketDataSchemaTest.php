<?php

namespace Tests\Feature;

use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\Signal;
use App\Models\Universe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketDataSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_instrument_round_trips_with_its_market_data(): void
    {
        $instrument = Instrument::factory()->create([
            'ticker' => 'NVDA',
            'company' => 'NVIDIA Corporation',
            'sector' => 'Information Technology',
            'exchange' => 'NASDAQ',
        ]);

        $bar = DailyBar::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => '2026-09-25',
            'open' => 180.1234,
            'high' => 185.5678,
            'low' => 179.0001,
            'close' => 184.4321,
            'volume' => 123456789,
        ]);

        $snapshot = IndicatorSnapshot::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => '2026-09-25',
            'sma20' => 178.1234,
            'rsi14' => 61.5678,
            'rvol' => 2.5432,
        ]);

        $signal = Signal::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => '2026-09-25',
            'type' => 'golden_cross',
            'metadata' => ['fast' => 20, 'slow' => 50],
        ]);

        $fresh = Instrument::query()->findOrFail($instrument->id);

        $this->assertSame('NVDA', $fresh->ticker);
        $this->assertTrue($fresh->active);

        $this->assertCount(1, $fresh->dailyBars);
        $this->assertCount(1, $fresh->indicatorSnapshots);
        $this->assertCount(1, $fresh->signals);

        $freshBar = $fresh->dailyBars->first();
        $this->assertSame('184.4321', $freshBar->close);
        $this->assertSame('2026-09-25', $freshBar->date->toDateString());
        $this->assertSame(123456789, (int) $freshBar->volume);
        $this->assertTrue($freshBar->instrument->is($fresh));
        $this->assertTrue($freshBar->is($bar));

        $freshSnapshot = $fresh->indicatorSnapshots->first();
        $this->assertSame('178.1234', $freshSnapshot->sma20);
        $this->assertSame('61.5678', $freshSnapshot->rsi14);
        $this->assertSame('2.5432', $freshSnapshot->rvol);
        $this->assertTrue($freshSnapshot->instrument->is($fresh));
        $this->assertTrue($freshSnapshot->is($snapshot));

        $freshSignal = $fresh->signals->first();
        $this->assertSame('golden_cross', $freshSignal->type);
        $this->assertSame(['fast' => 20, 'slow' => 50], $freshSignal->metadata);
        $this->assertTrue($freshSignal->instrument->is($fresh));
        $this->assertTrue($freshSignal->is($signal));
    }

    public function test_daily_bars_are_unique_per_instrument_and_date(): void
    {
        $bar = DailyBar::factory()->create(['date' => '2026-09-25']);

        $this->expectException(QueryException::class);

        DailyBar::factory()->create([
            'instrument_id' => $bar->instrument_id,
            'date' => '2026-09-25',
        ]);
    }

    public function test_indicator_snapshots_are_unique_per_instrument_and_date(): void
    {
        $snapshot = IndicatorSnapshot::factory()->create(['date' => '2026-09-25']);

        $this->expectException(QueryException::class);

        IndicatorSnapshot::factory()->create([
            'instrument_id' => $snapshot->instrument_id,
            'date' => '2026-09-25',
        ]);
    }

    public function test_universe_and_instrument_pivot_resolves_both_ways(): void
    {
        $universe = Universe::factory()->create(['name' => 'S&P 500', 'slug' => 'sp-500']);
        $instrument = Instrument::factory()->create(['ticker' => 'AAPL']);

        $universe->instruments()->attach($instrument);

        $this->assertCount(1, $universe->fresh()->instruments);
        $this->assertTrue($universe->fresh()->instruments->first()->is($instrument));
        $this->assertCount(1, $instrument->fresh()->universes);
        $this->assertTrue($instrument->fresh()->universes->first()->is($universe));
    }
}
