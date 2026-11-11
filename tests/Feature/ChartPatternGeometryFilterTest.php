<?php

namespace Tests\Feature;

use App\Models\ChartPattern;
use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChartPatternGeometryFilterTest extends TestCase
{
    use RefreshDatabase;

    private Universe $universe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->universe = Universe::factory()->create(['slug' => 'sp500']);
        config(['ingestion.universe' => 'sp500']);
    }

    private function member(string $ticker): Instrument
    {
        $instrument = Instrument::factory()->create(['ticker' => $ticker]);
        $this->universe->instruments()->attach($instrument->id);
        DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => '2026-11-10', 'close' => 100]);
        IndicatorSnapshot::factory()->create(['instrument_id' => $instrument->id, 'date' => '2026-11-10']);

        return $instrument;
    }

    public function test_each_new_geometry_type_can_filter_screener_candidates(): void
    {
        $types = [
            'head_and_shoulders',
            'inverse_head_and_shoulders',
            'ascending_triangle',
            'descending_triangle',
            'symmetrical_triangle',
        ];

        foreach ($types as $index => $type) {
            $instrument = $this->member('GEO'.($index + 1));
            ChartPattern::factory()->create([
                'instrument_id' => $instrument->id,
                'type' => $type,
                'status' => 'forming',
                'direction' => null,
                'breakout_level' => null,
            ]);
        }

        foreach ($types as $index => $type) {
            $candidates = $this->refreshScreenerRows()
                ->getJson('/api/screener?pattern='.$type)
                ->assertOk()
                ->json('candidates');

            $this->assertSame(['GEO'.($index + 1)], array_column($candidates, 'ticker'), $type);
        }
    }

    public function test_inverse_cup_direction_is_bearish_when_engine_omits_direction(): void
    {
        $instrument = $this->member('INV');
        DailyBar::factory()->count(2)->sequence(
            ['date' => Carbon::parse('2026-11-08'), 'close' => 98],
            ['date' => Carbon::parse('2026-11-09'), 'close' => 99],
        )->create(['instrument_id' => $instrument->id]);

        Http::fake(['*/patterns/detect' => Http::response(['patterns' => [[
            'type' => 'inverse_cup_with_handle',
            'status' => 'forming',
            'as_of_date' => '2026-11-10',
            'start_date' => '2026-10-01',
            'end_date' => '2026-11-10',
            'breakout_level' => 100,
            'points' => [['date' => '2026-11-10', 'price' => 90, 'role' => 'handle_low']],
            'metadata' => [],
        ]]])]);

        $this->artisan('patterns:detect', ['--ticker' => 'INV'])->assertExitCode(0);

        $this->assertSame(
            'bearish',
            ChartPattern::query()->where('instrument_id', $instrument->id)->where('type', 'inverse_cup_with_handle')->sole()->direction,
        );
    }

    public function test_geometry_migration_backfills_legacy_pattern_directions(): void
    {
        $instrument = $this->member('OLD');
        $inverseCup = ChartPattern::factory()->create([
            'instrument_id' => $instrument->id,
            'type' => 'inverse_cup_with_handle',
        ]);
        $doubleBottom = ChartPattern::factory()->create([
            'instrument_id' => $instrument->id,
            'type' => 'double_bottom',
        ]);
        // Simulate rows created before the nullable direction column existed.
        DB::table('chart_patterns')->whereIn('id', [$inverseCup->id, $doubleBottom->id])->update(['direction' => null]);

        $migration = require database_path('migrations/2026_11_10_000002_extend_chart_patterns_geometry.php');
        $migration->down();
        $migration->up();

        $this->assertSame('bearish', ChartPattern::query()->findOrFail($inverseCup->id)->direction);
        $this->assertSame('bullish', ChartPattern::query()->findOrFail($doubleBottom->id)->direction);
    }
}
