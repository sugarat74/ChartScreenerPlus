<?php

namespace Tests\Feature;

use App\Models\DailyBar;
use App\Models\Instrument;
use App\Models\Signal;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DetectSignalsCommandTest extends TestCase
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
     * Build a fake engine response for the given signals.
     *
     * @param  array<int, array<string, mixed>>  $signals
     * @return array{signals: array<int, array<string, mixed>>}
     */
    private function signalsResponse(array $signals): array
    {
        return ['signals' => $signals];
    }

    /**
     * An engine signal payload.
     *
     * @param  array<string, float>  $metadata
     * @return array<string, mixed>
     */
    private function signal(string $date, string $type, array $metadata = []): array
    {
        return ['date' => $date, 'type' => $type, 'metadata' => $metadata];
    }

    public function test_it_stores_the_signals_detected_on_the_as_of_bar(): void
    {
        $instrument = $this->instrumentWithBars('NVDA', 2);

        Http::fake([
            '*/signals/detect' => Http::response($this->signalsResponse([
                $this->signal('2026-09-02', 'golden_cross', ['sma50' => 101.25, 'sma200' => 99.5]),
                $this->signal('2026-09-02', 'rsi_overbought', ['rsi14' => 72.5]),
            ])),
        ]);

        $this->artisan('signals:detect', ['--ticker' => 'NVDA'])
            ->expectsOutputToContain('Stored 2 signal(s) for NVDA')
            ->expectsOutputToContain('Processed 1 instrument(s); stored 2 signal(s).')
            ->assertExitCode(0);

        $this->assertDatabaseCount('signals', 2);

        $golden = Signal::query()
            ->where('instrument_id', $instrument->id)
            ->where('type', 'golden_cross')
            ->sole();

        // Date-stamped with the as-of bar date the engine reported.
        $this->assertSame('2026-09-02', $golden->date->toDateString());
        $this->assertSame(['sma50' => 101.25, 'sma200' => 99.5], $golden->metadata);

        // The stored bars were sent to the engine, ordered by date.
        Http::assertSent(function (Request $request): bool {
            $body = $request->data();

            return str_ends_with($request->url(), '/signals/detect')
                && $request->method() === 'POST'
                && ($body['bars'][0]['date'] ?? null) === '2026-09-01'
                && ($body['bars'][1]['date'] ?? null) === '2026-09-02';
        });
    }

    public function test_re_running_is_idempotent_and_does_not_duplicate_signals(): void
    {
        $this->instrumentWithBars('NVDA', 2);

        Http::fake([
            '*/signals/detect' => Http::response($this->signalsResponse([
                $this->signal('2026-09-02', 'golden_cross', ['sma50' => 101.25]),
            ])),
        ]);

        $this->artisan('signals:detect', ['--ticker' => 'NVDA'])->assertExitCode(0);
        $this->assertDatabaseCount('signals', 1);

        $this->artisan('signals:detect', ['--ticker' => 'NVDA'])->assertExitCode(0);
        $this->assertDatabaseCount('signals', 1);
    }

    public function test_a_signal_that_no_longer_holds_disappears_on_the_next_run(): void
    {
        $instrument = $this->instrumentWithBars('NVDA', 1);

        Http::fakeSequence('*/signals/detect')
            ->push($this->signalsResponse([
                $this->signal('2026-09-01', 'golden_cross', ['sma50' => 101.0, 'sma200' => 100.0]),
            ]), 200)
            ->push($this->signalsResponse([]), 200);

        $this->artisan('signals:detect', ['--ticker' => 'NVDA'])->assertExitCode(0);
        $this->assertDatabaseCount('signals', 1);

        // The condition no longer holds: the engine now returns nothing.
        $this->artisan('signals:detect', ['--ticker' => 'NVDA'])
            ->expectsOutputToContain('Stored 0 signal(s) for NVDA')
            ->assertExitCode(0);

        $this->assertDatabaseCount('signals', 0);
        $this->assertDatabaseMissing('signals', ['instrument_id' => $instrument->id]);
    }

    public function test_the_engine_failure_leaves_the_previous_signal_set_intact(): void
    {
        $instrument = $this->instrumentWithBars('NVDA', 1);

        Http::fakeSequence('*/signals/detect')
            ->push($this->signalsResponse([
                $this->signal('2026-09-01', 'macd_bullish_cross', ['macd' => 0.5, 'macd_signal' => 0.2]),
            ]), 200)
            ->push(['detail' => 'Engine exploded.'], 502);

        $this->artisan('signals:detect', ['--ticker' => 'NVDA'])->assertExitCode(0);
        $this->assertDatabaseCount('signals', 1);

        // Engine failure: nothing may be deleted or written.
        $this->artisan('signals:detect', ['--ticker' => 'NVDA'])
            ->expectsOutputToContain('Engine failed for NVDA')
            ->assertExitCode(1);

        $this->assertDatabaseCount('signals', 1);
        $this->assertDatabaseHas('signals', [
            'instrument_id' => $instrument->id,
            'type' => 'macd_bullish_cross',
        ]);
    }

    public function test_an_unknown_ticker_fails_without_calling_the_engine(): void
    {
        Http::fake();

        $this->artisan('signals:detect', ['--ticker' => 'ZZZZ'])
            ->expectsOutputToContain('No instrument found for [ZZZZ]')
            ->assertExitCode(1);

        $this->assertDatabaseCount('signals', 0);
        Http::assertNothingSent();
    }

    public function test_universe_mode_processes_only_instruments_with_stored_bars(): void
    {
        $universe = Universe::factory()->create(['slug' => 'sp500']);
        $withBars = $this->instrumentWithBars('AAA', 1);
        $withoutBars = Instrument::factory()->create(['ticker' => 'BBB']);

        $universe->instruments()->attach([$withBars->id, $withoutBars->id]);

        Http::fake([
            '*/signals/detect' => Http::response($this->signalsResponse([
                $this->signal('2026-09-01', 'rsi_oversold', ['rsi14' => 28.0]),
            ])),
        ]);

        $this->artisan('signals:detect', ['--universe' => 'sp500'])
            ->expectsOutputToContain('Processed 1 instrument(s); stored 1 signal(s).')
            ->assertExitCode(0);

        $this->assertDatabaseCount('signals', 1);
        $this->assertDatabaseHas('signals', [
            'instrument_id' => $withBars->id,
            'type' => 'rsi_oversold',
        ]);
        $this->assertDatabaseMissing('signals', ['instrument_id' => $withoutBars->id]);
        Http::assertSentCount(1);
    }

    public function test_an_unknown_universe_fails_without_calling_the_engine(): void
    {
        Http::fake();

        $this->artisan('signals:detect', ['--universe' => 'nope'])
            ->expectsOutputToContain('No universe found for [nope]')
            ->assertExitCode(1);

        $this->assertDatabaseCount('signals', 0);
        Http::assertNothingSent();
    }
}
