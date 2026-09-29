<?php

namespace Tests\Feature;

use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ComputeIndicatorsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create an instrument with `$count` consecutive stored daily bars.
     */
    private function instrumentWithBars(string $ticker, int $count): Instrument
    {
        $instrument = Instrument::factory()->create(['ticker' => $ticker]);

        for ($index = 0; $index < $count; $index++) {
            DailyBar::factory()->create([
                'instrument_id' => $instrument->id,
                'date' => Carbon::parse('2026-09-01')->addDays($index),
                'volume' => 1_000_000 + $index,
            ]);
        }

        return $instrument;
    }

    /**
     * Build a fake engine response for the given snapshots.
     *
     * @param  array<int, array<string, mixed>>  $snapshots
     * @return array{snapshots: array<int, array<string, mixed>>}
     */
    private function snapshotsResponse(array $snapshots): array
    {
        return ['snapshots' => $snapshots];
    }

    /**
     * A complete engine snapshot; every indicator defaults to null.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function snapshot(string $date, array $overrides = []): array
    {
        return array_merge([
            'date' => $date,
            'sma20' => null,
            'sma50' => null,
            'sma200' => null,
            'ema21' => null,
            'ema55' => null,
            'rsi14' => null,
            'adx' => null,
            'macd' => null,
            'macd_signal' => null,
            'macd_hist' => null,
            'bb_upper' => null,
            'bb_middle' => null,
            'bb_lower' => null,
            'rvol' => null,
        ], $overrides);
    }

    public function test_it_stores_one_snapshot_per_bar_with_the_engine_values(): void
    {
        $instrument = $this->instrumentWithBars('NVDA', 2);

        Http::fake([
            '*/indicators/compute' => Http::response($this->snapshotsResponse([
                $this->snapshot('2026-09-01', ['sma20' => 101.25, 'rvol' => 1.5]),
                $this->snapshot('2026-09-02', ['sma20' => 102.5, 'rvol' => 1.75]),
            ])),
        ]);

        $this->artisan('indicators:compute', ['--ticker' => 'NVDA'])
            ->expectsOutputToContain('Computed 2 snapshot(s) for NVDA')
            ->expectsOutputToContain('Processed 1 instrument(s); wrote 2 snapshot(s).')
            ->assertExitCode(0);

        $this->assertDatabaseCount('indicator_snapshots', 2);

        $first = IndicatorSnapshot::query()
            ->where('instrument_id', $instrument->id)
            ->orderBy('date')
            ->firstOrFail();

        $this->assertSame('2026-09-01', $first->date->toDateString());
        $this->assertSame('101.2500', $first->sma20);
        $this->assertSame('1.5000', $first->rvol);
        $this->assertNull($first->sma50);

        // The stored bars were sent to the engine, ordered by date.
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return str_ends_with($request->url(), '/indicators/compute')
                && $request->method() === 'POST'
                && ($body['bars'][0]['date'] ?? null) === '2026-09-01'
                && ($body['bars'][1]['date'] ?? null) === '2026-09-02';
        });
    }

    public function test_re_running_is_idempotent_and_does_not_duplicate_snapshots(): void
    {
        $this->instrumentWithBars('NVDA', 2);

        Http::fake([
            '*/indicators/compute' => Http::response($this->snapshotsResponse([
                $this->snapshot('2026-09-01', ['sma20' => 101.25]),
                $this->snapshot('2026-09-02', ['sma20' => 102.5]),
            ])),
        ]);

        $this->artisan('indicators:compute', ['--ticker' => 'NVDA'])->assertExitCode(0);
        $this->assertDatabaseCount('indicator_snapshots', 2);

        $this->artisan('indicators:compute', ['--ticker' => 'NVDA'])->assertExitCode(0);
        $this->assertDatabaseCount('indicator_snapshots', 2);
    }

    public function test_insufficient_history_is_stored_as_null_not_a_wrong_value(): void
    {
        $instrument = $this->instrumentWithBars('NVDA', 1);

        Http::fake([
            '*/indicators/compute' => Http::response($this->snapshotsResponse([
                $this->snapshot('2026-09-01'),
            ])),
        ]);

        $this->artisan('indicators:compute', ['--ticker' => 'NVDA'])->assertExitCode(0);

        $snapshot = IndicatorSnapshot::query()->sole();

        $this->assertSame($instrument->id, $snapshot->instrument_id);

        foreach ([
            'sma20', 'sma50', 'sma200', 'ema21', 'ema55', 'rsi14', 'adx',
            'macd', 'macd_signal', 'macd_hist', 'bb_upper', 'bb_middle', 'bb_lower', 'rvol',
        ] as $column) {
            $this->assertNull(
                $snapshot->getAttribute($column),
                "{$column} must be stored as null when history is insufficient",
            );
        }
    }

    public function test_the_engine_error_is_handled_without_writing(): void
    {
        $this->instrumentWithBars('NVDA', 2);

        Http::fake([
            '*/indicators/compute' => Http::response(['detail' => 'Engine exploded.'], 502),
        ]);

        $this->artisan('indicators:compute', ['--ticker' => 'NVDA'])
            ->expectsOutputToContain('Engine failed for NVDA')
            ->assertExitCode(1);

        $this->assertDatabaseCount('indicator_snapshots', 0);
    }

    public function test_an_unknown_ticker_fails_without_calling_the_engine(): void
    {
        Http::fake();

        $this->artisan('indicators:compute', ['--ticker' => 'ZZZZ'])
            ->expectsOutputToContain('No instrument found for [ZZZZ]')
            ->assertExitCode(1);

        $this->assertDatabaseCount('indicator_snapshots', 0);
        Http::assertNothingSent();
    }

    public function test_universe_mode_processes_only_instruments_with_stored_bars(): void
    {
        $universe = Universe::factory()->create(['slug' => 'sp500']);
        $withBars = $this->instrumentWithBars('AAA', 1);
        $withoutBars = Instrument::factory()->create(['ticker' => 'BBB']);

        $universe->instruments()->attach([$withBars->id, $withoutBars->id]);

        Http::fake([
            '*/indicators/compute' => Http::response($this->snapshotsResponse([
                $this->snapshot('2026-09-01', ['sma20' => 99.5]),
            ])),
        ]);

        $this->artisan('indicators:compute', ['--universe' => 'sp500'])
            ->expectsOutputToContain('Processed 1 instrument(s); wrote 1 snapshot(s).')
            ->assertExitCode(0);

        $this->assertDatabaseCount('indicator_snapshots', 1);
        $this->assertDatabaseHas('indicator_snapshots', ['instrument_id' => $withBars->id]);
        $this->assertDatabaseMissing('indicator_snapshots', ['instrument_id' => $withoutBars->id]);
        Http::assertSentCount(1);
    }

    public function test_an_unknown_universe_fails_without_calling_the_engine(): void
    {
        Http::fake();

        $this->artisan('indicators:compute', ['--universe' => 'nope'])
            ->expectsOutputToContain('No universe found for [nope]')
            ->assertExitCode(1);

        $this->assertDatabaseCount('indicator_snapshots', 0);
        Http::assertNothingSent();
    }
}
