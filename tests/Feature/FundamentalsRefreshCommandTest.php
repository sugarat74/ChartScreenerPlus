<?php

namespace Tests\Feature;

use App\Models\CompanyFundamental;
use App\Models\Instrument;
use App\Models\Universe;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesSecEdgar;
use Tests\TestCase;

/**
 * `fundamentals:refresh` and its schedule (docs/specs/company-fundamentals-sec.md
 * scenarios 1, 5, 6 and 7). Offline: fixtures + `Http::preventStrayRequests()`,
 * fake clock for the request spacing.
 */
class FundamentalsRefreshCommandTest extends TestCase
{
    use FakesSecEdgar;
    use RefreshDatabase;

    private const FACTS = 'data.sec.gov/api/xbrl/companyfacts/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableSec();
        $this->fakeSleeper();
    }

    /** @param list<string> $tickers */
    private function universe(array $tickers): void
    {
        $universe = Universe::factory()->create(['slug' => 'sp500', 'name' => 'S&P 500']);
        foreach ($tickers as $ticker) {
            $universe->instruments()->attach(Instrument::factory()->create(['ticker' => $ticker, 'company' => $ticker.' Inc.']));
        }
    }

    private function row(string $ticker): ?CompanyFundamental
    {
        return CompanyFundamental::query()->whereHas('instrument', fn ($q) => $q->where('ticker', $ticker))->first();
    }

    private function sent(string $fragment): int
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), $fragment))->count();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function notConfigured(): array
    {
        return [
            'disabled' => [['fundamentals.enabled' => false]],
            'empty user agent' => [['fundamentals.user_agent' => '']],
            'user agent without email' => [['fundamentals.user_agent' => 'Chartiko']],
            'plain http SEC url' => [['fundamentals.data_base_url' => 'http://sec.example.test']],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('notConfigured')]
    public function test_refuses_to_run_without_configuration_and_sends_nothing(array $override): void
    {
        config()->set($override);
        $this->universe(['AAPL']);
        $this->fakeSec();

        $this->artisan('fundamentals:refresh')->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertDatabaseCount('company_fundamentals', 0);
    }

    public function test_refreshes_one_ticker_with_three_requests(): void
    {
        $this->universe(['AAPL', 'MSFT']);
        $this->fakeSec();

        $this->artisan('fundamentals:refresh', ['--ticker' => ['aapl']])
            ->expectsOutputToContain('1 updated, 0 unchanged, 0 without data, 0 without CIK, 0 failed')
            ->assertExitCode(0);

        Http::assertSentCount(3);
        $row = $this->row('AAPL');
        $this->assertSame('ok', $row->status);
        $this->assertSame(['0000320193', '3571', false], [$row->cik, $row->sic, $row->is_financial]);
        $this->assertSame('2026-06-27', $row->period_end->format('Y-m-d'));
        $this->assertSame('0000320193-26-000020', $row->latest_accession);
        $this->assertNull($row->pending_accession);
        $this->assertSame('8.7200', $row->eps_diluted_ttm);
        $this->assertSame('466823000000.0000', $row->revenue_ttm);
        $this->assertSame('0000320193-26-000020', $row->inputs['_meta']['submissions_accession']);
        $this->assertNull($this->row('MSFT'));
    }

    public function test_universe_run_groups_shared_ciks_maps_class_tickers_and_records_the_lag(): void
    {
        $this->universe(['AAPL', 'BRK.B', 'GOOG', 'GOOGL', 'JPM', 'MSFT', 'XOM', 'ZZZZ']);
        // Berkshire is in the ticker map but has no fixture: the SEC answers 404.
        $this->fakeSec(['data.sec.gov/submissions/CIK0001067983.json' => Http::response('', 404)]);

        $this->artisan('fundamentals:refresh')
            ->expectsOutputToContain('5 updated, 0 unchanged, 1 without data, 1 without CIK, 0 failed')
            ->assertExitCode(0);

        // One tickers map, one submissions per CIK (6), one companyfacts per CIK with data (5).
        Http::assertSentCount(1 + 6 + 5);
        $this->assertSame(1, $this->sent(self::FACTS.'CIK0001652044.json'), 'GOOG and GOOGL share one fetch');
        $this->assertSame(1, $this->sent('submissions/CIK0001067983.json'), 'BRK.B is looked up as BRK-B');

        $goog = $this->row('GOOG');
        $googl = $this->row('GOOGL');
        $this->assertEquals($googl->only(['cik', 'period_end', 'revenue_ttm', 'shares_outstanding']), $goog->only(['cik', 'period_end', 'revenue_ttm', 'shares_outstanding']));
        $this->assertSame('12230000000.0000', $goog->shares_outstanding);

        $jpm = $this->row('JPM');
        $this->assertTrue($jpm->is_financial);
        $this->assertSame(['6021', 'National Commercial Banks'], [$jpm->sic, $jpm->sic_description]);

        // XOM: submissions list a 10-Q (2026-08-03) that companyfacts does not have yet.
        $xom = $this->row('XOM');
        $this->assertSame('2026-03-31', $xom->period_end->format('Y-m-d'));
        $this->assertSame(['0000034088-26-000093', '10-Q', '2026-08-03'], [$xom->pending_accession, $xom->pending_form, $xom->pending_filed_at->format('Y-m-d')]);
        $this->assertNull($this->row('AAPL')->pending_accession);

        $this->assertSame('no_data', $this->row('BRK.B')->status);
        $this->assertSame('no_cik', $this->row('ZZZZ')->status);
        $this->assertDatabaseCount('company_fundamentals', 8);
    }

    public function test_an_unchanged_filing_skips_companyfacts_unless_pending_or_forced(): void
    {
        $this->universe(['AAPL', 'GOOG', 'GOOGL', 'JPM', 'MSFT', 'XOM']);
        $this->fakeSec();
        $this->artisan('fundamentals:refresh')->assertExitCode(0);
        $before = Http::recorded()->count();

        $this->artisan('fundamentals:refresh')
            ->expectsOutputToContain('1 updated, 4 unchanged')
            ->assertExitCode(0);

        $second = Http::recorded()->slice($before)->map(fn (array $pair): string => $pair[0]->url())->values()->all();
        $this->assertSame([
            'https://data.sec.gov/submissions/CIK0000320193.json',
            'https://data.sec.gov/submissions/CIK0001652044.json',
            'https://data.sec.gov/submissions/CIK0000019617.json',
            'https://data.sec.gov/submissions/CIK0000789019.json',
            'https://data.sec.gov/submissions/CIK0000034088.json',
            // The open XOM lag re-fetches companyfacts every run.
            'https://data.sec.gov/api/xbrl/companyfacts/CIK0000034088.json',
        ], $second, 'the ticker map is cached; unchanged companies cost one submissions request each');

        $before = Http::recorded()->count();
        $this->artisan('fundamentals:refresh', ['--force' => true])->assertExitCode(0);
        $this->assertSame(1, Http::recorded()->slice($before)->filter(fn (array $pair): bool => str_contains($pair[0]->url(), self::FACTS.'CIK0000320193'))->count());
    }

    public function test_a_new_filing_in_submissions_triggers_a_refetch(): void
    {
        $this->universe(['AAPL']);
        $this->fakeSec();
        $this->artisan('fundamentals:refresh')->assertExitCode(0);

        $submissions = self::secFixture('submissions_aapl');
        array_unshift($submissions['filings']['recent']['accessionNumber'], '0000320193-26-000099');
        array_unshift($submissions['filings']['recent']['filingDate'], '2026-10-30');
        array_unshift($submissions['filings']['recent']['reportDate'], '2026-09-26');
        array_unshift($submissions['filings']['recent']['form'], '10-K');
        $this->fakeSec(['data.sec.gov/submissions/*' => Http::response($submissions)]);

        $this->artisan('fundamentals:refresh')->assertExitCode(0);

        $this->assertSame(1, $this->sent(self::FACTS));
        // The fixture has no facts of that 10-K yet: it is now the pending filing.
        $this->assertSame(['0000320193-26-000099', '10-K'], [$this->row('AAPL')->pending_accession, $this->row('AAPL')->pending_form]);
    }

    public function test_one_company_failure_is_isolated_and_keeps_its_last_figures(): void
    {
        $this->universe(['AAPL', 'JPM', 'MSFT']);
        $this->fakeSec();
        $this->artisan('fundamentals:refresh')->assertExitCode(0);

        $this->fakeSec([self::FACTS.'CIK0000789019.json' => Http::response('down', 503)]);
        $this->artisan('fundamentals:refresh', ['--force' => true])
            ->expectsOutputToContain('Failed: MSFT')
            ->expectsOutputToContain('2 updated, 0 unchanged, 0 without data, 0 without CIK, 1 failed')
            ->assertExitCode(0);

        $msft = $this->row('MSFT');
        $this->assertSame('ok', $msft->status, 'last good figures kept');
        $this->assertSame('2026-06-30', $msft->period_end->format('Y-m-d'));
        $this->assertSame('sec_unavailable (503)', $msft->last_error);
        $this->assertNull($this->row('AAPL')->last_error);
    }

    public function test_a_first_failure_creates_an_error_row_and_all_failed_exits_one(): void
    {
        $this->universe(['AAPL']);
        $this->fakeSec([self::FACTS.'*' => Http::response('down', 500)]);

        $this->artisan('fundamentals:refresh')->assertExitCode(1);

        $row = $this->row('AAPL');
        $this->assertSame('error', $row->status);
        $this->assertNull($row->period_end);
        $this->assertSame(1 + 1 + 3, Http::recorded()->count(), 'companyfacts tried once + two retries');
    }

    public function test_a_403_stops_the_whole_run(): void
    {
        $this->universe(['AAPL', 'MSFT', 'XOM']);
        $this->fakeSec(['data.sec.gov/submissions/CIK0000320193.json' => Http::response('Undeclared Automated Tool', 403)]);

        $this->artisan('fundamentals:refresh')
            ->expectsOutputToContain('Run stopped')
            ->assertExitCode(1);

        Http::assertSentCount(2);
        $this->assertDatabaseCount('company_fundamentals', 0);
    }

    public function test_a_held_lock_is_a_skip_without_requests(): void
    {
        $this->universe(['AAPL']);
        $this->fakeSec();
        $lock = Cache::lock('fundamentals:refresh', 60);
        $this->assertTrue($lock->get());

        $this->artisan('fundamentals:refresh')->expectsOutputToContain('already running')->assertExitCode(0);
        $lock->release();

        Http::assertNothingSent();
    }

    public function test_writes_nothing_outside_company_fundamentals_and_leaves_the_pipeline_lock(): void
    {
        $this->universe(['AAPL', 'JPM']);
        $this->fakeSec();
        $tables = ['daily_bars', 'indicator_snapshots', 'signals', 'chart_patterns', 'screener_rows', 'ingestion_runs', 'ingestion_run_items', 'ai_company_cards'];
        $before = array_map(fn (string $table): int => DB::table($table)->count(), $tables);

        $this->artisan('fundamentals:refresh')->assertExitCode(0);

        $this->assertSame($before, array_map(fn (string $table): int => DB::table($table)->count(), $tables));
        $pipeline = Cache::lock('ingestion:pipeline', 10);
        $this->assertTrue($pipeline->get(), 'the EOD pipeline lock is untouched');
        $pipeline->release();
        $this->assertDatabaseCount('company_fundamentals', 2);
    }

    private function scheduledEvent(): Event
    {
        $this->app->make(Kernel::class)->bootstrap();
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn (Event $event): bool => $event->description === 'fundamentals-refresh')->values();
        $this->assertCount(1, $events);

        return $events->first();
    }

    public function test_schedule_runs_weekdays_at_seven_new_york_only_when_enabled(): void
    {
        $event = $this->scheduledEvent();

        $this->assertSame('0 7 * * 1-5', $event->expression);
        $this->assertSame('America/New_York', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(90, $event->expiresAt);
        $this->assertTrue($event->runInBackground);
        $this->assertStringContainsString('fundamentals:refresh', (string) $event->command);
        $this->assertStringNotContainsString('ingestion', (string) $event->command);

        // 07:00 EDT = 11:00 UTC on Wednesday 2026-07-15.
        Carbon::setTestNow(Carbon::parse('2026-07-15 11:00:00', 'UTC'));
        $this->assertTrue($event->isDue($this->app));
        $this->assertTrue($event->filtersPass($this->app));
        config()->set('fundamentals.enabled', false);
        $this->assertFalse($event->filtersPass($this->app), 'disabled: the event does not run');
        Carbon::setTestNow();
    }
}
