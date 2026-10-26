<?php

namespace Tests\Feature;

use App\Enums\IngestionRunStatus;
use App\Models\DailyBar;
use App\Models\IngestionRun;
use App\Models\Instrument;
use App\Models\Universe;
use App\Models\User;
use App\Services\Engine\EngineClient;
use App\Services\Market\PipelineSchedule;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Per-market ingestion (docs/specs/multi-market-universes.md, Feature 2):
 * engine market parameter, currency guard, per-market pipeline, schedule and
 * the Admin freshness endpoint. The engine is always faked.
 */
class MultiMarketIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<int, string>  $tickers
     */
    private function universe(string $slug, array $tickers, string $market = 'US', string $currency = 'USD', int $position = 0): Universe
    {
        $universe = Universe::factory()->create([
            'slug' => $slug,
            'name' => strtoupper($slug),
            'market' => $market,
            'currency' => $currency,
            'position' => $position,
        ]);

        foreach ($tickers as $ticker) {
            $instrument = Instrument::query()->where('ticker', $ticker)->first()
                ?? Instrument::factory()->create(['ticker' => $ticker, 'market' => $market, 'currency' => $currency]);
            $universe->instruments()->attach($instrument->id);
        }

        return $universe;
    }

    /**
     * @return array<string, mixed>
     */
    private function eod(string $symbol, ?string $currency = null): array
    {
        $bars = [];
        foreach ([0, 1, 2] as $index) {
            $bars[] = [
                'date' => Carbon::parse('2026-07-13')->addDays($index)->toDateString(),
                'open' => 10.0, 'high' => 11.0, 'low' => 9.0, 'close' => 10.5, 'volume' => 1000,
            ];
        }

        return ['symbol' => $symbol, 'bars' => $bars, 'currency' => $currency];
    }

    /**
     * @return array<int, mixed>
     */
    private function stageFakes(): array
    {
        return [
            '*/indicators/compute' => Http::response(['snapshots' => []]),
            '*/signals/detect' => Http::response(['signals' => []]),
            '*/patterns/detect' => Http::response(['patterns' => []]),
        ];
    }

    private function eodRequests(): int
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/eod/'))->count();
    }

    public function test_the_engine_client_sends_the_market_only_for_non_us_tickers(): void
    {
        Http::fake(['*' => Http::response($this->eod('X', 'EUR'))]);
        $client = app(EngineClient::class);

        $client->eodBars('AAA');
        $client->eodBars('SAN.MC', 'XMAD');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/eod/AAA'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/eod/SAN.MC') && str_contains($request->url(), 'market=XMAD'));
        $this->assertSame('EUR', $client->eodSeries('SAN.MC', 'XMAD')['currency']);
    }

    public function test_a_currency_mismatch_fails_the_item_and_stores_no_bars(): void
    {
        $universe = $this->universe('ibex-fx', ['SAN.MC', 'ITX.MC'], 'XMAD', 'EUR');
        Http::fake([
            '*/eod/SAN.MC*' => Http::response($this->eod('SAN.MC', 'USD')),
            '*/eod/ITX.MC*' => Http::response($this->eod('ITX.MC', 'EUR')),
        ]);

        $this->artisan('ingestion:run', ['--universe' => 'ibex-fx'])->assertExitCode(0);

        $run = IngestionRun::query()->where('universe_id', $universe->id)->sole();
        $this->assertSame(IngestionRunStatus::Partial, $run->status);
        $failed = $run->items()->where('status', 'failed')->sole();
        $this->assertStringContainsString('currency_mismatch', $failed->message);
        $this->assertSame(0, DailyBar::query()->where('instrument_id', $failed->instrument_id)->count());
        $this->assertSame(3, DailyBar::query()->count());
    }

    public function test_a_source_that_reports_no_currency_is_accepted(): void
    {
        $this->universe('sp500', ['AAA']);
        Http::fake(['*/eod/AAA' => Http::response($this->eod('AAA', null))]);

        $this->artisan('ingestion:run', ['--universe' => 'sp500'])->assertExitCode(0);

        $this->assertSame(3, DailyBar::query()->count());
    }

    public function test_overlapping_members_are_fetched_once_across_us_universes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:30:00', 'America/New_York'));
        $this->universe('sp500', ['AAA', 'BBB'], position: 0);
        $this->universe('second', ['BBB', 'CCC'], position: 1);
        Http::fake([
            '*/eod/AAA' => Http::response($this->eod('AAA')),
            '*/eod/BBB' => Http::response($this->eod('BBB')),
            '*/eod/CCC' => Http::response($this->eod('CCC')),
        ] + $this->stageFakes());

        $this->artisan('ingestion:pipeline')->expectsOutputToContain('EOD pipeline completed.')->assertExitCode(0);

        $this->assertSame(3, $this->eodRequests());
        $this->assertSame(2, IngestionRun::query()->count());
        $this->assertSame(1, IngestionRun::query()->whereHas('universe', fn ($q) => $q->where('slug', 'second'))->sole()->total);
    }

    public function test_a_failing_second_universe_keeps_the_screener_refresh_and_alerts_but_fails_the_exit_code(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:30:00', 'America/New_York'));
        $this->universe('sp500', ['AAA'], position: 0);
        $this->universe('second', ['CCC'], position: 1);
        Http::fake([
            '*/eod/AAA' => Http::response($this->eod('AAA')),
            '*/eod/CCC' => Http::response(['detail' => 'boom'], 502),
        ] + $this->stageFakes());

        $this->artisan('ingestion:pipeline')
            ->expectsOutputToContain('Universe second stage ingestion:run failed')
            ->expectsOutputToContain('Alerts as of')
            ->expectsOutputToContain('EOD pipeline finished with errors.')
            ->assertExitCode(1);

        $this->assertSame(3, DailyBar::query()->count());
    }

    public function test_a_european_market_run_skips_every_us_only_stage(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 18:15:00', 'Europe/Madrid'));
        $this->universe('sp500', ['AAA']);
        $this->universe('market-context-x', []);
        $this->universe('ibex-fx', ['SAN.MC'], 'XMAD', 'EUR', 1);
        Http::fake(['*/eod/SAN.MC*' => Http::response($this->eod('SAN.MC', 'EUR'))] + $this->stageFakes());

        $this->artisan('ingestion:pipeline', ['--market' => 'XMAD'])
            ->expectsOutputToContain('EOD pipeline completed.')
            ->doesntExpectOutputToContain('Alerts as of')
            ->doesntExpectOutputToContain('Market context')
            ->doesntExpectOutputToContain('Macro')
            ->assertExitCode(0);

        // Only the XMAD universe ran: S&P 500's AAA was never requested, and no
        // macro, forecast or other US source was called.
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/eod/AAA'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'stlouisfed'));
        $this->assertSame(1, IngestionRun::query()->count());
        $this->assertSame('ibex-fx', IngestionRun::query()->with('universe')->sole()->universe->slug);
        $this->assertSame(3, DailyBar::query()->count());
    }

    public function test_a_european_market_without_a_seeded_universe_is_skipped_and_an_unknown_market_fails(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 18:15:00', 'Europe/Berlin'));
        Http::fake();

        $this->artisan('ingestion:pipeline', ['--market' => 'XETR'])
            ->expectsOutputToContain('No Screener universe for market XETR')
            ->assertExitCode(0);
        $this->artisan('ingestion:pipeline', ['--market' => 'NOPE'])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_a_held_lock_is_waited_on_and_then_taken(): void
    {
        $this->universe('sp500', ['AAA']);
        Http::fake(['*/eod/AAA' => Http::response($this->eod('AAA'))] + $this->stageFakes());
        config(['ingestion.lock_wait_seconds' => 10]);

        // Another pipeline holds the lock for one second only.
        $this->assertTrue(Cache::lock('ingestion:pipeline', 1)->get());

        $this->artisan('ingestion:pipeline', ['--force' => true])
            ->expectsOutputToContain('waiting up to 10 s')
            ->expectsOutputToContain('EOD pipeline completed.')
            ->assertExitCode(0);

        $this->assertSame(1, IngestionRun::query()->count());
    }

    public function test_a_lock_still_held_after_the_wait_skips_the_pipeline(): void
    {
        $this->universe('sp500', ['AAA']);
        Http::fake();
        config(['ingestion.lock_wait_seconds' => 1]);

        $held = Cache::lock('ingestion:pipeline', 60);
        $this->assertTrue($held->get());

        $this->artisan('ingestion:pipeline', ['--force' => true])
            ->expectsOutputToContain('already running')
            ->assertExitCode(0);

        $this->assertSame(0, IngestionRun::query()->count());
        Http::assertNothingSent();
        $held->release();
    }

    /**
     * @return array<string, Event>
     */
    private function scheduledPipelines(): array
    {
        $schedule = new Schedule;
        app(PipelineSchedule::class)->register($schedule);

        $events = [];
        foreach ($schedule->events() as $event) {
            $events[(string) $event->description] = $event;
        }

        return $events;
    }

    public function test_one_event_per_seeded_market_with_its_own_timezone_and_time(): void
    {
        $this->universe('sp500', ['AAA']);
        $this->universe('ibex-fx', ['SAN.MC'], 'XMAD', 'EUR', 1);
        $this->universe('dax-fx', ['SAP.DE'], 'XETR', 'EUR', 2);

        $events = $this->scheduledPipelines();

        $this->assertSame(['ingestion-pipeline', 'ingestion-pipeline-xmad', 'ingestion-pipeline-xetr'], array_keys($events));
        $this->assertSame(['30 16 * * 1-5', 'America/New_York'], [$events['ingestion-pipeline']->expression, $events['ingestion-pipeline']->timezone]);
        $this->assertSame(['15 18 * * 1-5', 'Europe/Madrid'], [$events['ingestion-pipeline-xmad']->expression, $events['ingestion-pipeline-xmad']->timezone]);
        $this->assertSame(['15 18 * * 1-5', 'Europe/Berlin'], [$events['ingestion-pipeline-xetr']->expression, $events['ingestion-pipeline-xetr']->timezone]);
        $this->assertMatchesRegularExpression('/--market=[\'"]?XMAD[\'"]?/', (string) $events['ingestion-pipeline-xmad']->command);
    }

    public function test_a_market_without_a_seeded_screener_universe_has_no_event(): void
    {
        $this->universe('sp500', ['AAA']);
        $this->universe('ibex-fx', ['SAN.MC'], 'XMAD', 'EUR', 1);

        $this->assertSame(['ingestion-pipeline', 'ingestion-pipeline-xmad'], array_keys($this->scheduledPipelines()));
    }

    public function test_each_event_skips_only_its_own_market_holidays(): void
    {
        config(['ingestion.markets.XETR.holidays' => ['2026-07-15']]);
        $this->universe('dax-fx', ['SAP.DE'], 'XETR', 'EUR', 2);
        $events = $this->scheduledPipelines();

        Carbon::setTestNow(Carbon::parse('2026-07-15 18:15:00', 'Europe/Berlin'));
        $this->assertFalse($events['ingestion-pipeline-xetr']->filtersPass($this->app));

        Carbon::setTestNow(Carbon::parse('2026-07-15 16:30:00', 'America/New_York'));
        $this->assertTrue($events['ingestion-pipeline']->filtersPass($this->app));
    }

    public function test_the_freshness_endpoint_is_admin_only(): void
    {
        $this->getJson('/api/admin/universes/freshness')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/admin/universes/freshness')->assertForbidden();
    }

    private function bar(string $ticker, string $date): void
    {
        DailyBar::factory()->create([
            'instrument_id' => Instrument::query()->where('ticker', $ticker)->value('id'),
            'date' => $date,
        ]);
    }

    public function test_the_freshness_endpoint_reports_fresh_partial_and_stale(): void
    {
        // 17:00 New York is 23:00 in Madrid and Berlin: every market's 2026-07-15 session is ready.
        Carbon::setTestNow(Carbon::parse('2026-07-15 17:00:00', 'America/New_York'));
        $this->universe('sp500', ['AAA', 'BBB', 'CCC']);
        $this->universe('ibex-fx', ['SAN.MC', 'ITX.MC'], 'XMAD', 'EUR', 1);
        $this->universe('dax-fx', ['SAP.DE'], 'XETR', 'EUR', 2);

        $this->bar('AAA', '2026-07-15');
        $this->bar('BBB', '2026-07-15');
        $this->bar('CCC', '2026-07-14');
        $this->bar('SAN.MC', '2026-07-14');
        $this->bar('ITX.MC', '2026-07-14');
        $this->bar('SAP.DE', '2026-07-15');

        $rows = collect($this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/admin/universes/freshness')
            ->assertOk()
            ->json('universes'))->keyBy('slug');

        $this->assertSame(['partial', '2026-07-15', '2026-07-15', 3, 2, 1], $this->summary($rows['sp500']));
        $this->assertSame('USD', $rows['sp500']['currency']);
        $this->assertSame(['stale', '2026-07-14', '2026-07-15', 2, 0, 2], $this->summary($rows['ibex-fx']));
        $this->assertSame('EUR', $rows['ibex-fx']['currency']);
        $this->assertSame(['fresh', '2026-07-15', '2026-07-15', 1, 1, 0], $this->summary($rows['dax-fx']));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, mixed>
     */
    private function summary(array $row): array
    {
        return [$row['status'], $row['latest_date'], $row['expected_date'], $row['members'], $row['with_bar'], $row['without_bar']];
    }
}
