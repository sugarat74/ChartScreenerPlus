<?php

namespace Tests\Feature;

use App\Models\ChartPattern;
use App\Models\DailyBar;
use App\Models\Instrument;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `patterns:detect` (chart-patterns-detect): replace semantics per instrument,
 * idempotent, failure-safe, strict normalization of the engine payload.
 */
class DetectPatternsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function instrumentWithBars(string $ticker, int $count = 3): Instrument
    {
        $instrument = Instrument::factory()->create(['ticker' => $ticker]);

        for ($index = 0; $index < $count; $index++) {
            DailyBar::factory()->create([
                'instrument_id' => $instrument->id,
                'date' => Carbon::parse('2026-09-01')->addDays($index),
            ]);
        }

        return $instrument;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function pattern(string $type = 'double_bottom', string $status = 'forming', array $overrides = []): array
    {
        return array_merge([
            'type' => $type,
            'status' => $status,
            'as_of_date' => '2026-09-03',
            'start_date' => '2026-07-01',
            'end_date' => '2026-08-20',
            'breakout_level' => 115.25,
            'points' => [
                ['date' => '2026-07-01', 'price' => 100.0, 'role' => 'left_low'],
                ['date' => '2026-07-30', 'price' => 115.25, 'role' => 'peak'],
                ['date' => '2026-08-20', 'price' => 100.5, 'role' => 'right_low'],
            ],
            'metadata' => ['rise_pct' => 14.6, 'note' => 'dropped'],
        ], $overrides);
    }

    public function test_it_stores_the_active_patterns(): void
    {
        $instrument = $this->instrumentWithBars('NVDA');

        Http::fake(['*/patterns/detect' => Http::response(['patterns' => [
            $this->pattern(),
            $this->pattern('bull_flag', 'confirmed', ['breakout_level' => 120.5]),
        ]])]);

        $this->artisan('patterns:detect', ['--ticker' => 'NVDA'])
            ->expectsOutputToContain('Stored 2 pattern(s) for NVDA')
            ->expectsOutputToContain('Processed 1 instrument(s); stored 2 pattern(s).')
            ->assertExitCode(0);

        $stored = ChartPattern::query()->where('instrument_id', $instrument->id)->where('type', 'double_bottom')->sole();
        $this->assertSame('forming', $stored->status);
        $this->assertSame('2026-09-03', $stored->as_of_date->toDateString());
        $this->assertSame('2026-07-01', $stored->start_date->toDateString());
        $this->assertEqualsWithDelta(115.25, $stored->breakout_level, 0.0001);
        $this->assertSame(['left_low', 'peak', 'right_low'], array_column($stored->points, 'role'));
        $this->assertSame(['rise_pct' => 14.6], $stored->metadata);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/patterns/detect')
            && count($request['bars']) === 3);
    }

    public function test_re_running_is_idempotent_and_status_changes_replace_the_row(): void
    {
        $this->instrumentWithBars('NVDA');

        Http::fakeSequence('*/patterns/detect')
            ->push(['patterns' => [$this->pattern()]])
            ->push(['patterns' => [$this->pattern()]])
            ->push(['patterns' => [$this->pattern(status: 'confirmed')]]);

        $this->artisan('patterns:detect', ['--ticker' => 'NVDA'])->assertExitCode(0);
        $this->artisan('patterns:detect', ['--ticker' => 'NVDA'])->assertExitCode(0);
        $this->assertDatabaseCount('chart_patterns', 1);

        $this->artisan('patterns:detect', ['--ticker' => 'NVDA'])->assertExitCode(0);
        $this->assertSame('confirmed', ChartPattern::query()->sole()->status);
    }

    public function test_a_pattern_that_is_no_longer_active_disappears(): void
    {
        $this->instrumentWithBars('NVDA');

        Http::fakeSequence('*/patterns/detect')
            ->push(['patterns' => [$this->pattern()]])
            ->push(['patterns' => []]);

        $this->artisan('patterns:detect', ['--ticker' => 'NVDA'])->assertExitCode(0);
        $this->assertDatabaseCount('chart_patterns', 1);

        $this->artisan('patterns:detect', ['--ticker' => 'NVDA'])->assertExitCode(0);
        $this->assertDatabaseCount('chart_patterns', 0);
    }

    public function test_an_engine_failure_keeps_the_previous_set_and_exits_one(): void
    {
        $instrument = $this->instrumentWithBars('NVDA');
        ChartPattern::factory()->create(['instrument_id' => $instrument->id]);

        Http::fake(['*/patterns/detect' => Http::response(['detail' => 'boom'], 500)]);

        $this->artisan('patterns:detect', ['--ticker' => 'NVDA'])
            ->expectsOutputToContain('Engine failed for NVDA')
            ->assertExitCode(1);

        $this->assertDatabaseCount('chart_patterns', 1);
    }

    public function test_malformed_patterns_are_dropped(): void
    {
        $this->instrumentWithBars('NVDA');

        Http::fake(['*/patterns/detect' => Http::response(['patterns' => [
            $this->pattern('triangle'),
            $this->pattern('double_top', 'maybe'),
            $this->pattern('cup_with_handle', overrides: ['breakout_level' => 'high']),
            $this->pattern('bull_flag', overrides: ['as_of_date' => '03/09/2026']),
            $this->pattern('double_top', overrides: ['points' => [['date' => '2026-07-01', 'price' => 'x', 'role' => 'left_peak']]]),
            $this->pattern('double_bottom', overrides: ['points' => []]),
            'not-a-pattern',
        ]])]);

        $this->artisan('patterns:detect', ['--ticker' => 'NVDA'])
            ->expectsOutputToContain('stored 0 pattern(s)')
            ->assertExitCode(0);

        $this->assertDatabaseCount('chart_patterns', 0);
    }

    public function test_universe_mode_processes_members_with_bars_and_unknown_targets_fail(): void
    {
        $universe = Universe::factory()->create(['slug' => 'sp500']);
        $withBars = $this->instrumentWithBars('AAA');
        $withoutBars = Instrument::factory()->create(['ticker' => 'BBB']);
        $universe->instruments()->attach([$withBars->id, $withoutBars->id]);

        Http::fake(['*/patterns/detect' => Http::response(['patterns' => [$this->pattern()]])]);

        $this->artisan('patterns:detect')->expectsOutputToContain('Processed 1 instrument(s); stored 1 pattern(s).')->assertExitCode(0);
        Http::assertSentCount(1);

        $this->artisan('patterns:detect', ['--ticker' => 'ZZZ'])->expectsOutputToContain('No instrument found for [ZZZ].')->assertExitCode(1);
        $this->artisan('patterns:detect', ['--universe' => 'nope'])->expectsOutputToContain('No universe found for [nope].')->assertExitCode(1);
    }
}
