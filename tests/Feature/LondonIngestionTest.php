<?php

namespace Tests\Feature;

use App\Enums\IngestionRunStatus;
use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\IngestionRun;
use App\Models\Instrument;
use App\Models\Universe;
use App\Models\User;
use App\Services\Market\MarketCalendar;
use App\Services\Market\PipelineSchedule;
use App\Services\MarketContext\MarketContextRunLog;
use App\Support\ListingMarket;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LondonIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-11-09 17:15:00', 'Europe/London'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function universe(array $tickers = ['AZN.L']): Universe
    {
        $universe = Universe::factory()->create(['slug' => 'london-fixture', 'market' => 'XLON', 'currency' => 'GBX', 'position' => 5]);
        foreach ($tickers as $ticker) {
            $instrument = Instrument::factory()->create(['ticker' => $ticker, 'market' => 'XLON', 'currency' => 'GBX', 'exchange' => 'LSE']);
            $universe->instruments()->attach($instrument);
        }

        return $universe;
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/fixtures/universes/london_pipeline.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_london_definitions_suffixes_and_default_install_do_not_expose_a_new_universe(): void
    {
        $this->assertSame('GBX', ListingMarket::currency('XLON'));
        $this->assertSame('Europe/London', ListingMarket::timezone('XLON'));
        $this->assertSame('08:00', ListingMarket::open('XLON'));
        $this->assertSame('16:30', ListingMarket::close('XLON'));
        $this->assertTrue(ListingMarket::tickerMatches('XLON', 'bt-a.l'));
        foreach (['AZN', 'AZN.MC', 'AZN.DE', 'AZN.PA', 'AZN.AS'] as $ticker) {
            $this->assertFalse(ListingMarket::tickerMatches('XLON', $ticker));
        }
        foreach (['US', 'XMAD', 'XETR', 'XPAR'] as $market) {
            $this->assertFalse(ListingMarket::tickerMatches($market, 'AZN.L'));
        }
        $schedule = new Schedule;
        app(PipelineSchedule::class)->register($schedule);
        $this->assertNull(collect($schedule->events())->first(fn ($e) => $e->timezone === 'Europe/London'));
        $this->assertDatabaseCount('universes', 0);
        $this->assertNotContains('ftse100', config('universes.public'));
        $this->assertNotContains('XLON', array_column($this->getJson('/api/markets/status')->assertOk()->json('markets'), 'code'));
    }

    public function test_uk_holidays_differ_from_paris_and_year_end_half_days_stay_open(): void
    {
        $calendar = app(MarketCalendar::class);
        $holidays = config('ingestion.markets.XLON.holidays');
        $this->assertCount(16, array_unique($holidays));
        foreach ($holidays as $date) {
            $day = Carbon::parse($date);
            $this->assertFalse($day->isWeekend());
            $this->assertFalse($calendar->isTradingDay('XLON', $day));
        }
        foreach (['2026-05-04', '2026-05-25', '2026-08-31', '2026-12-28', '2027-05-03', '2027-05-31', '2027-08-30', '2027-12-27', '2027-12-28'] as $date) {
            $this->assertTrue($calendar->isTradingDay('XPAR', Carbon::parse($date)), $date);
            $this->assertFalse($calendar->isTradingDay('XLON', Carbon::parse($date)), $date);
        }
        foreach (['2026-12-24', '2026-12-31', '2027-12-24', '2027-12-31'] as $date) {
            $session = $calendar->session('XLON', Carbon::parse($date));
            $this->assertTrue($session['early_close']);
            $this->assertSame('12:30', $session['closes_at']->setTimezone('Europe/London')->format('H:i'));
        }
        $this->assertSame('17:15', $calendar->runTime('XLON'));
        $this->assertTrue($calendar->isTradingDay('XLON', Carbon::parse('2026-12-29')));
        $this->assertTrue($calendar->isTradingDay('XLON', Carbon::parse('2026-12-30')));
    }

    public function test_us_constituent_snapshots_do_not_collide_with_london_suffix(): void
    {
        foreach (['sp500', 'nasdaq100'] as $slug) {
            $handle = fopen(base_path("database/data/{$slug}.csv"), 'rb');
            $header = fgetcsv($handle, null, ',', '"', '');
            $index = array_search('ticker', $header, true);
            $this->assertNotFalse($index);
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $this->assertFalse(str_ends_with(strtoupper($row[$index]), '.L'), $row[$index]);
            }
            fclose($handle);
        }
    }

    public function test_latest_session_uses_london_wall_clock_across_dst_and_us_transition_gaps(): void
    {
        $calendar = app(MarketCalendar::class);
        foreach ([
            ['2026-03-16 17:14:00', '2026-03-13'], ['2026-03-16 17:15:00', '2026-03-16'],
            ['2026-03-27 17:14:00', '2026-03-26'], ['2026-03-27 17:15:00', '2026-03-27'],
            ['2026-03-30 16:14:00', '2026-03-27'], ['2026-03-30 16:15:00', '2026-03-30'],
            ['2026-10-23 16:14:00', '2026-10-22'], ['2026-10-23 16:15:00', '2026-10-23'],
            ['2026-10-26 17:14:00', '2026-10-23'], ['2026-10-26 17:15:00', '2026-10-26'],
            ['2026-12-28 18:00:00', '2026-12-24'],
        ] as [$instant, $expected]) {
            $this->assertSame($expected, $calendar->latestSession('XLON', Carbon::parse($instant, 'UTC'))->toDateString(), $instant);
        }
    }

    public function test_schedule_is_registered_only_for_a_seeded_london_universe_and_skips_its_holidays(): void
    {
        $this->universe();
        $schedule = new Schedule;
        app(PipelineSchedule::class)->register($schedule);
        $event = collect($schedule->events())->first(fn ($e) => $e->timezone === 'Europe/London');
        $this->assertNotNull($event);
        $this->assertSame('15 17 * * 1-5', $event->expression);
        $this->assertStringContainsString('XLON', $event->command);
        $this->assertTrue($event->filtersPass($this->app));
        Carbon::setTestNow(Carbon::parse('2026-12-28 17:15:00', 'Europe/London'));
        $this->assertFalse($event->filtersPass($this->app));
        Carbon::setTestNow(Carbon::parse('2026-12-24 17:15:00', 'Europe/London'));
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_pounds_dollars_and_euros_fail_currency_checks_without_writing_failed_items(): void
    {
        $universe = $this->universe(['POUND.L', 'DOLLAR.L', 'EURO.L', 'AZN.L']);
        $fixture = $this->fixture();
        Http::fake(['*/eod/*' => function (Request $request) use ($fixture) {
            $currency = match (basename(parse_url($request->url(), PHP_URL_PATH))) {
                'POUND.L' => 'GBP', 'DOLLAR.L' => 'USD', 'EURO.L' => 'EUR', default => 'GBX',
            };

            return Http::response(['currency' => $currency, 'bars' => array_slice($fixture['bars'], -1)]);
        }]);
        $this->artisan('ingestion:run', ['--universe' => $universe->slug])->assertExitCode(0);
        $run = IngestionRun::where('universe_id', $universe->id)->sole();
        $this->assertSame(IngestionRunStatus::Partial, $run->status);
        $this->assertSame(3, $run->items()->where('status', 'failed')->count());
        foreach ($run->items()->where('status', 'failed')->get() as $item) {
            $this->assertStringContainsString('currency_mismatch', $item->message);
            $this->assertSame(0, DailyBar::where('instrument_id', $item->instrument_id)->count());
        }
        $this->assertDatabaseCount('daily_bars', 1);
    }

    public function test_fixture_pipeline_preserves_pence_computes_snapshots_and_serves_gbx_candidates(): void
    {
        $this->universe();
        config(['macro.enabled' => true]); // London must never enter the US-only stages.
        $fixture = $this->fixture();
        Http::fake([
            '*/eod/AZN.L*' => Http::response(['currency' => 'GBX', 'bars' => $fixture['bars']]),
            '*/indicators/compute' => Http::response(['snapshots' => $fixture['snapshots']]),
            '*/signals/detect' => Http::response(['signals' => []]),
            '*/patterns/detect' => Http::response(['patterns' => []]),
        ]);
        $this->artisan('ingestion:pipeline', ['--market' => 'XLON'])->assertExitCode(0);
        $this->assertDatabaseCount('daily_bars', 210);
        $this->assertDatabaseCount('indicator_snapshots', 1);
        $last = $fixture['bars'][209];
        $this->assertEqualsWithDelta($last['close'], (float) DailyBar::orderByDesc('date')->first()->close, 0.000001);
        $this->assertEqualsWithDelta(round($fixture['snapshots'][0]['sma200'], 4), (float) IndicatorSnapshot::first()->sma200, 0.000001);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/eod/AZN.L') && $r['market'] === 'XLON');
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/indicators/compute') && (float) $r['bars'][209]['close'] === $last['close']);
        $this->assertNull(MarketContextRunLog::get('events:refresh'));
        $admin = User::factory()->admin()->create();
        $body = $this->actingAs($admin)->getJson('/api/screener?universe=london-fixture')->assertOk()->json('candidates.0');
        $this->assertSame('AZN.L', $body['ticker']);
        $this->assertSame('GBX', $body['currency']);
        $this->assertSame('XLON', $body['market']);
        $this->assertEqualsWithDelta($last['close'], $body['close'], 0.000001);
        $this->getJson('/api/screener?universe=london-fixture&sector_trend=uptrend')->assertUnprocessable()->assertJsonPath('code', 'filter_not_available_for_universe');
        $this->getJson('/api/instruments/AZN.L')->assertOk()->assertJsonPath('instrument.currency', 'GBX')
            ->assertJsonPath('instrument.availability.company_card', false)->assertJsonPath('instrument.availability.forecast', false)
            ->assertJsonPath('instrument.availability.fundamentals', false);
        $this->getJson('/api/markets/status')->assertOk()->assertJsonPath('markets.0.code', 'XLON');
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create())->getJson('/api/screener?universe=london-fixture')->assertNotFound();
    }
}
