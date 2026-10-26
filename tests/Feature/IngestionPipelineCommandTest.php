<?php

namespace Tests\Feature;

use App\Enums\IngestionRunStatus;
use App\Models\IngestionRun;
use App\Models\Instrument;
use App\Models\MacroSeries;
use App\Models\MarketBreadthSnapshot;
use App\Models\MarketRegimeSnapshot;
use App\Models\MarketSectorSnapshot;
use App\Models\Universe;
use App\Services\Macro\MacroSeriesRefresher;
use App\Services\MarketContext\MarketContextCache;
use App\Services\MarketContext\MarketContextComputer;
use App\Services\MarketContext\MarketContextRunLog;
use App\Services\MarketRegime\MarketRegimeComputer;
use App\Services\Screener\ScreenerRowBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesFred;
use Tests\TestCase;

class IngestionPipelineCommandTest extends TestCase
{
    use FakesFred;
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
     * A fake engine pattern response.
     *
     * @return array<string, mixed>
     */
    private function patternsResponse(): array
    {
        return [
            'patterns' => [[
                'type' => 'double_bottom',
                'status' => 'forming',
                'as_of_date' => '2026-09-03',
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-03',
                'breakout_level' => 101.5,
                'points' => [['date' => '2026-09-01', 'price' => 99.0, 'role' => 'left_low']],
                'metadata' => ['rise_pct' => 10.5],
            ]],
        ];
    }

    /**
     * A complete set of offline engine fakes for the whole pipeline.
     */
    private function fakeEngine(int $barCount = 3, ?int $patternsStatus = null): void
    {
        Http::fake([
            '*/eod/AAA' => Http::response($this->barsResponse('AAA', $barCount)),
            '*/indicators/compute' => Http::response($this->snapshotsResponse($barCount)),
            '*/signals/detect' => Http::response($this->signalsResponse()),
            '*/patterns/detect' => $patternsStatus === null
                ? Http::response($this->patternsResponse())
                : Http::response(['detail' => 'Engine failed.'], $patternsStatus),
        ]);
    }

    public function test_the_pipeline_runs_ingestion_indicators_and_signals(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        $this->fakeEngine();

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('Alerts as of 2026-09-03')
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
        $this->assertDatabaseCount('chart_patterns', 1);
        // The Screener rows were rebuilt from the new data before the alerts.
        $this->assertDatabaseHas('screener_rows', ['ticker' => 'AAA', 'date' => '2026-09-03', 'signal_count' => 1]);

        // One request per stage.
        Http::assertSentCount(4);
    }

    public function test_a_failed_screener_refresh_skips_alerts_and_fails_the_pipeline(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        $this->fakeEngine();
        $this->app->instance(ScreenerRowBuilder::class, new class extends ScreenerRowBuilder
        {
            public function refresh(): int
            {
                throw new \RuntimeException('refresh failed');
            }
        });

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('Screener rows were not refreshed: refresh failed')
            ->expectsOutputToContain('Alerts were not evaluated because the screener rows could not be refreshed.')
            ->doesntExpectOutputToContain('Alerts as of')
            ->expectsOutputToContain('EOD pipeline finished with errors.')
            ->assertExitCode(1);
    }

    public function test_alerts_are_not_evaluated_when_signal_detection_fails(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        Http::fake([
            '*/eod/AAA' => Http::response($this->barsResponse('AAA', 3)),
            '*/indicators/compute' => Http::response($this->snapshotsResponse(3)),
            '*/signals/detect' => Http::response(['detail' => 'Engine failed.'], 500),
            '*/patterns/detect' => Http::response($this->patternsResponse()),
        ]);

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('Alerts were not evaluated because signal detection failed.')
            ->doesntExpectOutputToContain('Alerts as of')
            ->expectsOutputToContain('EOD pipeline finished with errors.')
            ->assertExitCode(1);
    }

    public function test_a_pattern_stage_failure_fails_the_pipeline_but_keeps_earlier_stages(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        $this->fakeEngine(patternsStatus: 500);

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('EOD pipeline finished with errors.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('daily_bars', 3);
        $this->assertDatabaseCount('indicator_snapshots', 3);
        $this->assertDatabaseCount('signals', 1);
        $this->assertDatabaseCount('chart_patterns', 0);
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
            || str_contains($request->url(), 'signals')
            || str_contains($request->url(), 'patterns'));
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
        $this->assertDatabaseCount('chart_patterns', 1);
    }

    /**
     * Seed the `market-context` universe with SPY and XLK (the
     * MarketContextUniverseSeeder shape, reduced).
     */
    private function marketContextUniverse(): Universe
    {
        $universe = Universe::factory()->notScreener()->create(['slug' => 'market-context', 'name' => 'Market Context']);
        $universe->instruments()->attach([
            Instrument::factory()->etf()->create(['ticker' => 'SPY', 'sector' => null])->id,
            Instrument::factory()->etf()->create(['ticker' => 'XLK', 'sector' => 'Information Technology'])->id,
        ]);

        return $universe;
    }

    /**
     * Engine fakes for the main universe plus the ETFs; `$etfStatus` makes
     * every ETF bar request fail.
     */
    private function fakeEngineWithEtfs(?int $etfStatus = null): void
    {
        $etf = fn (string $symbol) => $etfStatus === null
            ? Http::response($this->barsResponse($symbol, 3))
            : Http::response(['detail' => 'Upstream failed.'], $etfStatus);

        Http::fake([
            '*/eod/AAA' => Http::response($this->barsResponse('AAA', 3)),
            '*/eod/SPY' => $etf('SPY'),
            '*/eod/XLK' => $etf('XLK'),
            '*/indicators/compute' => Http::response($this->snapshotsResponse(3)),
            '*/signals/detect' => Http::response($this->signalsResponse()),
            '*/patterns/detect' => Http::response($this->patternsResponse()),
        ]);
    }

    public function test_an_unseeded_market_context_universe_skips_the_block_without_requests(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        $this->fakeEngine();

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('Market context universe not seeded; skipped.')
            ->expectsOutputToContain('EOD pipeline completed.')
            ->assertExitCode(0);

        Http::assertSentCount(4);
        $this->assertDatabaseCount('market_breadth_snapshots', 0);
    }

    public function test_the_market_context_block_ingests_the_etfs_and_stores_the_snapshots(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        $market = $this->marketContextUniverse();
        $this->fakeEngineWithEtfs();

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('Market context computed for 3 session(s)')
            ->expectsOutputToContain('Market regime computed for 3 session(s) (2026-09-01 to 2026-09-03); latest 2026-09-03: insufficient')
            ->expectsOutputToContain('Alerts as of 2026-09-03')
            ->expectsOutputToContain('EOD pipeline completed.')
            ->assertExitCode(0);

        // Its own ingestion run, bars and snapshots; no signals or patterns.
        $run = IngestionRun::query()->where('universe_id', $market->id)->sole();
        $this->assertSame(IngestionRunStatus::Completed, $run->status);
        $this->assertSame(2, $run->total);
        $this->assertDatabaseCount('daily_bars', 9);
        $this->assertDatabaseCount('indicator_snapshots', 9);
        $this->assertDatabaseCount('signals', 1);
        $this->assertDatabaseCount('chart_patterns', 1);

        $this->assertSame(3, MarketBreadthSnapshot::query()->count());
        $this->assertSame(3, MarketSectorSnapshot::query()->count());
        // The regime stage self-seeded one row per computed session; with
        // macro disabled at most 3 components exist, so each is insufficient.
        $this->assertSame(3, MarketRegimeSnapshot::query()->count());
        $this->assertSame(3, MarketRegimeSnapshot::query()->where('regime', MarketRegimeSnapshot::REGIME_INSUFFICIENT)->whereNull('score')->count());
        // The Screener rows stay stock-only.
        $this->assertDatabaseHas('screener_rows', ['ticker' => 'AAA']);
        $this->assertDatabaseMissing('screener_rows', ['ticker' => 'XLK']);
        $this->assertDatabaseMissing('screener_rows', ['ticker' => 'SPY']);

        // AAA: 4 stage requests; ETFs: 2 bar + 2 indicator requests, so no
        // signal or pattern request was made for them.
        Http::assertSentCount(8);
    }

    public function test_a_failed_etf_ingestion_never_changes_the_exit_code_or_skips_alerts(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        $market = $this->marketContextUniverse();
        $this->fakeEngineWithEtfs(etfStatus: 502);
        Log::spy();

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('Market context stage ingestion:run failed (exit 1); the pipeline continues.')
            ->expectsOutputToContain('Alerts as of 2026-09-03')
            ->expectsOutputToContain('EOD pipeline completed.')
            ->assertExitCode(0);

        $run = IngestionRun::query()->where('universe_id', $market->id)->sole();
        $this->assertSame(IngestionRunStatus::Failed, $run->status);
        $this->assertSame(2, $run->failed);
        // The main universe completed and the Screener rows were refreshed.
        $this->assertSame(IngestionRunStatus::Completed, IngestionRun::query()->where('universe_id', '!=', $market->id)->sole()->status);
        $this->assertDatabaseHas('screener_rows', ['ticker' => 'AAA', 'date' => '2026-09-03']);
        Log::shouldHaveReceived('warning')->with('Market context stage failed.', ['stage' => 'ingestion:run', 'exit' => 1])->once();
    }

    public function test_a_throwing_market_context_compute_never_changes_the_exit_code(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        $this->marketContextUniverse();
        $this->fakeEngineWithEtfs();
        $this->app->instance(MarketContextComputer::class, new class extends MarketContextComputer
        {
            public function compute(array $dates): array
            {
                throw new \LogicException('compute exploded');
            }
        });
        Log::spy();

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('Market context stage market-context:compute failed (exit 1); the pipeline continues.')
            ->expectsOutputToContain('Alerts as of 2026-09-03')
            ->expectsOutputToContain('EOD pipeline completed.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('market_breadth_snapshots', 0);
        Log::shouldHaveReceived('warning')->with('Market context stage failed.', ['stage' => 'market-context:compute', 'exit' => 1])->once();
    }

    public function test_the_pipeline_records_the_three_market_context_runs_and_bumps_the_cache(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        $this->marketContextUniverse();
        $this->enableFred();
        $this->fakeEngineWithEtfs();
        Http::fake(['api.stlouisfed.org/*' => Http::failedConnection()]);
        $this->fakeMacroSleeper();
        Exceptions::fake();
        $version = MarketContextCache::version();

        // The macro stage fails; the exit code is unchanged (market-context-ui, C4).
        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('Macro stage macro:refresh failed (exit 1); the pipeline continues.')
            ->expectsOutputToContain('EOD pipeline completed.')
            ->assertExitCode(0);

        $this->assertSame('succeeded', MarketContextRunLog::get('market-context:compute')['status']);
        $this->assertSame(['failed', 1], [MarketContextRunLog::get('macro:refresh')['status'], MarketContextRunLog::get('macro:refresh')['exit_code']]);
        $this->assertSame('succeeded', MarketContextRunLog::get('market-regime:compute')['status']);
        $this->assertNotSame($version, MarketContextCache::version());
    }

    public function test_a_disabled_macro_stage_is_skipped_without_requests(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        $this->fakeEngine();

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('Macro series disabled; skipped.')
            ->expectsOutputToContain('Alerts as of 2026-09-03')
            ->expectsOutputToContain('EOD pipeline completed.')
            ->assertExitCode(0);

        // Only the four engine stage requests: nothing reached FRED.
        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'stlouisfed'));
        $this->assertDatabaseCount('macro_series', 0);
    }

    public function test_a_failing_macro_stage_never_changes_the_exit_code_nor_leaks_the_key(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        $this->enableFred();
        $this->fakeEngine();
        // Every FRED request fails with Guzzle's connection error, whose
        // message carries the full URL and the key.
        Http::fake(['api.stlouisfed.org/*' => Http::failedConnection()]);
        $this->fakeMacroSleeper();
        Exceptions::fake();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = ['message' => $event->message, 'context' => $event->context];
        });

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('Macro stage macro:refresh failed (exit 1); the pipeline continues.')
            ->expectsOutputToContain('Alerts as of 2026-09-03')
            ->expectsOutputToContain('EOD pipeline completed.')
            ->doesntExpectOutputToContain(self::FRED_TEST_KEY)
            ->assertExitCode(0);

        // The Screener rows were refreshed after the macro stage.
        $this->assertDatabaseHas('screener_rows', ['ticker' => 'AAA', 'date' => '2026-09-03']);
        $this->assertSame('unavailable', MacroSeries::query()->where('code', 'DGS10')->value('last_error'));
        $this->assertContains(['message' => 'Macro stage failed.', 'context' => ['stage' => 'macro:refresh', 'exit' => 1]], $logged);
        $this->assertStringNotContainsString(self::FRED_TEST_KEY, json_encode($logged));
        Exceptions::assertNothingReported();
    }

    public function test_a_throwing_macro_stage_never_changes_the_exit_code(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        $this->enableFred();
        $this->fakeEngine();
        $this->app->instance(MacroSeriesRefresher::class, new class extends MacroSeriesRefresher
        {
            public function __construct() {}

            public function refresh(string $code, bool $backfill = false): array
            {
                throw new \LogicException('refresh exploded');
            }
        });
        Exceptions::fake();
        Log::spy();

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('Macro stage macro:refresh failed (exit 1); the pipeline continues.')
            ->expectsOutputToContain('Alerts as of 2026-09-03')
            ->expectsOutputToContain('EOD pipeline completed.')
            ->assertExitCode(0);

        Exceptions::assertReported(\LogicException::class);
        Log::shouldHaveReceived('warning')->with('Macro stage failed.', ['stage' => 'macro:refresh', 'exit' => 1])->once();
        // The macro lock was released despite the exception.
        $this->assertTrue(Cache::lock('macro:refresh', 10)->get());
    }

    public function test_the_market_regime_stage_runs_after_macro_and_before_the_screener_refresh(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        $this->marketContextUniverse();
        $this->fakeEngineWithEtfs();

        $exit = $this->withoutMockingConsoleOutput()->artisan('ingestion:pipeline');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $macro = strpos($output, 'Macro series disabled; skipped.');
        $regime = strpos($output, 'Market regime computed for 3 session(s)');
        $screener = strpos($output, 'Screener rows refreshed:');
        $this->assertNotFalse($macro);
        $this->assertNotFalse($regime);
        $this->assertNotFalse($screener);
        $this->assertLessThan($regime, $macro);
        $this->assertLessThan($screener, $regime);
        $this->assertStringContainsString('EOD pipeline completed.', $output);
    }

    public function test_a_throwing_market_regime_stage_never_changes_the_exit_code(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        $this->fakeEngine();
        $this->app->instance(MarketRegimeComputer::class, new class(new MarketContextComputer) extends MarketRegimeComputer
        {
            public function firstSnapshotDate(): ?string
            {
                throw new \LogicException('regime exploded');
            }
        });
        Exceptions::fake();
        Log::spy();

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('Market regime stage market-regime:compute failed (exit 1); the pipeline continues.')
            ->expectsOutputToContain('Alerts as of 2026-09-03')
            ->expectsOutputToContain('EOD pipeline completed.')
            ->assertExitCode(0);

        Exceptions::assertReported(\LogicException::class);
        Log::shouldHaveReceived('warning')->with('Market regime stage failed.', ['stage' => 'market-regime:compute', 'exit' => 1])->once();
        // The Screener rows were refreshed after the regime stage.
        $this->assertDatabaseHas('screener_rows', ['ticker' => 'AAA', 'date' => '2026-09-03']);
        $this->assertDatabaseCount('market_regime_snapshots', 0);
    }

    public function test_a_failing_market_regime_stage_keeps_the_exit_code_of_the_other_stages(): void
    {
        $this->atTradingDay();
        $this->universeWith(['AAA']);
        $this->fakeEngine(patternsStatus: 500);
        $this->app->instance(MarketRegimeComputer::class, new class(new MarketContextComputer) extends MarketRegimeComputer
        {
            public function firstSnapshotDate(): ?string
            {
                throw new \RuntimeException('No universe found for [sp500].');
            }
        });
        Log::spy();

        // A pattern failure still exits 1, exactly as without the regime; the
        // regime's own exit 1 is only printed and logged.
        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('Market regime stage market-regime:compute failed (exit 1); the pipeline continues.')
            ->expectsOutputToContain('Alerts as of 2026-09-03')
            ->expectsOutputToContain('EOD pipeline finished with errors.')
            ->assertExitCode(1);

        Log::shouldHaveReceived('warning')->with('Market regime stage failed.', ['stage' => 'market-regime:compute', 'exit' => 1])->once();
    }
}
