<?php

namespace Tests\Feature;

use App\Enums\IngestionRunStatus;
use App\Models\IngestionRun;
use App\Models\Instrument;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IngestionPipelineCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a `sp500` universe containing the given tickers.
     *
     * @param  array<int, string>  $tickers
     */
    private function universeWith(array $tickers): Universe
    {
        $universe = Universe::factory()->create(['slug' => 'sp500']);
        $instruments = [];

        foreach ($tickers as $ticker) {
            $instruments[] = Instrument::factory()->create(['ticker' => $ticker]);
        }

        $universe->instruments()->attach(
            array_map(fn (Instrument $instrument): int => $instrument->id, $instruments),
        );

        return $universe;
    }

    /**
     * Pin "now" to a normal US trading day (2026-07-15, a Wednesday).
     */
    private function atTradingDay(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:30:00', 'America/New_York'));
    }

    /**
     * Pin "now" to a committed NYSE holiday (2026-07-03, observed Independence Day).
     */
    private function atHoliday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-03 16:30:00', 'America/New_York'));
    }

    /**
     * Pin "now" to a weekend day (2026-07-11, a Saturday).
     */
    private function atWeekend(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 16:30:00', 'America/New_York'));
    }

    /**
     * A fake engine EOD response with the given number of valid bars.
     *
     * @return array<string, mixed>
     */
    private function barsResponse(string $symbol, int $count): array
    {
        $bars = [];

        for ($index = 0; $index < $count; $index++) {
            $bars[] = [
                'date' => Carbon::parse('2026-09-01')->addDays($index)->toDateString(),
                'open' => 100.0 + $index,
                'high' => 110.0 + $index,
                'low' => 90.0 + $index,
                'close' => 105.0 + $index,
                'volume' => 1_000_000 + $index,
            ];
        }

        return ['symbol' => $symbol, 'bars' => $bars];
    }

    /**
     * A fake engine indicator response with one snapshot per bar.
     *
     * @return array<string, mixed>
     */
    private function snapshotsResponse(int $count): array
    {
        $snapshots = [];

        for ($index = 0; $index < $count; $index++) {
            $snapshots[] = [
                'date' => Carbon::parse('2026-09-01')->addDays($index)->toDateString(),
                'sma20' => 100.0 + $index,
            ];
        }

        return ['snapshots' => $snapshots];
    }

    /**
     * A fake engine signal response.
     *
     * @return array<string, mixed>
     */
    private function signalsResponse(): array
    {
        return [
            'signals' => [[
                'date' => '2026-09-03',
                'type' => 'golden_cross',
                'metadata' => ['sma50' => 100.5],
            ]],
        ];
    }

    /**
     * A complete set of offline engine fakes for the whole pipeline.
     */
    private function fakeEngine(int $barCount = 3): void
    {
        Http::fake([
            '*/eod/AAA' => Http::response($this->barsResponse('AAA', $barCount)),
            '*/indicators/compute' => Http::response($this->snapshotsResponse($barCount)),
            '*/signals/detect' => Http::response($this->signalsResponse()),
        ]);
    }

    public function test_the_pipeline_runs_ingestion_indicators_and_signals(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        $this->fakeEngine();

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('EOD pipeline completed.')
            ->assertExitCode(0);

        $run = IngestionRun::query()->sole();
        $this->assertSame(IngestionRunStatus::Completed, $run->status);
        $this->assertSame(1, $run->total);
        $this->assertSame(1, $run->succeeded);
        $this->assertSame(0, $run->failed);

        // Ingestion stored the bars, indicators stored one snapshot per bar,
        // and signals stored the detected set.
        $this->assertDatabaseCount('daily_bars', 3);
        $this->assertDatabaseCount('indicator_snapshots', 3);
        $this->assertDatabaseCount('signals', 1);

        // One request per stage.
        Http::assertSentCount(3);
    }

    public function test_a_holiday_is_skipped_without_running_or_calling_the_engine(): void
    {
        $this->atHoliday();
        $this->universeWith(['AAA']);
        Http::fake();

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('Not a trading day')
            ->assertExitCode(0);

        $this->assertDatabaseCount('ingestion_runs', 0);
        $this->assertDatabaseCount('daily_bars', 0);
        Http::assertNothingSent();
    }

    public function test_a_weekend_is_skipped_without_running_or_calling_the_engine(): void
    {
        $this->atWeekend();
        $this->universeWith(['AAA']);
        Http::fake();

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('Not a trading day')
            ->assertExitCode(0);

        $this->assertDatabaseCount('ingestion_runs', 0);
        Http::assertNothingSent();
    }

    public function test_force_runs_the_pipeline_on_a_holiday(): void
    {
        $this->atHoliday();
        $this->universeWith(['AAA']);
        $this->fakeEngine();

        $this->artisan('ingestion:pipeline', ['--force' => true])
            ->expectsOutputToContain('EOD pipeline completed.')
            ->assertExitCode(0);

        $this->assertSame(
            IngestionRunStatus::Completed,
            IngestionRun::query()->sole()->status,
        );
        $this->assertDatabaseCount('daily_bars', 3);
    }

    public function test_an_already_held_lock_skips_the_pipeline(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        Http::fake();

        $held = Cache::lock('ingestion:pipeline', 7200);
        $this->assertTrue($held->get());

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('already running')
            ->assertExitCode(0);

        $this->assertDatabaseCount('ingestion_runs', 0);
        Http::assertNothingSent();

        $held->release();
    }

    public function test_an_ingestion_failure_stops_the_pipeline(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        Http::fake([
            '*/eod/*' => Http::response(['detail' => 'Upstream failed.'], 502),
        ]);

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('EOD pipeline stopped')
            ->assertExitCode(1);

        $run = IngestionRun::query()->sole();
        $this->assertSame(IngestionRunStatus::Failed, $run->status);
        $this->assertSame(1, $run->failed);

        // The later stages must never be invoked after a failed ingestion.
        $this->assertDatabaseCount('indicator_snapshots', 0);
        $this->assertDatabaseCount('signals', 0);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'indicators')
            || str_contains($request->url(), 'signals'));
    }

    public function test_re_running_the_pipeline_is_idempotent(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        $this->fakeEngine();

        $this->artisan('ingestion:pipeline')->assertExitCode(0);
        $this->artisan('ingestion:pipeline')->assertExitCode(0);

        // Two ledger runs, but no duplicated bars/snapshots/signals.
        $this->assertSame(2, IngestionRun::query()->count());
        $this->assertDatabaseCount('daily_bars', 3);
        $this->assertDatabaseCount('indicator_snapshots', 3);
        $this->assertDatabaseCount('signals', 1);
    }
}
