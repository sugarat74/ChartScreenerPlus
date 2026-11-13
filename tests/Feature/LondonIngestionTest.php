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

    public function test_a_whole_series_relabelled_to_pounds_is_rejected_against_stored_pence(): void
    {
        $universe = $this->universe(['AZN.L', 'BT-A.L']);
        $azn = Instrument::where('ticker', 'AZN.L')->sole();
        $bt = Instrument::where('ticker', 'BT-A.L')->sole();
        // Stored pence history (13,930p and 196.5p on the last stored session).
        DailyBar::factory()->create(['instrument_id' => $azn->id, 'date' => '2026-11-06', 'open' => 13900, 'high' => 14000, 'low' => 13850, 'close' => 13930, 'volume' => 1000]);
        DailyBar::factory()->create(['instrument_id' => $bt->id, 'date' => '2026-11-06', 'open' => 195, 'high' => 197, 'low' => 194, 'close' => 196.5, 'volume' => 1000]);
        $bar = fn (string $date, float $close): array => ['date' => $date, 'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close, 'volume' => 1000];
        Http::fake(['*/eod/*' => function (Request $request) use ($bar) {
            // AZN.L: internally consistent but in pounds (139.3), still labelled GBX.
            // BT-A.L: normal pence with an ordinary day-over-day move.
            return basename(parse_url($request->url(), PHP_URL_PATH)) === 'AZN.L'
                ? Http::response(['currency' => 'GBX', 'bars' => [$bar('2026-11-06', 139.3), $bar('2026-11-09', 140.1)]])
                : Http::response(['currency' => 'GBX', 'bars' => [$bar('2026-11-06', 196.5), $bar('2026-11-09', 199.0)]]);
        }]);

        $this->artisan('ingestion:run', ['--universe' => $universe->slug])->assertExitCode(0);

        $run = IngestionRun::where('universe_id', $universe->id)->sole();
        $this->assertSame(IngestionRunStatus::Partial, $run->status);
        $failed = $run->items()->where('status', 'failed')->sole();
        $this->assertSame($azn->id, $failed->instrument_id);
        $this->assertStringContainsString('price_scale_mismatch', $failed->message);
        // Nothing overwritten or added for the flipped series.
        $this->assertSame(1, DailyBar::where('instrument_id', $azn->id)->count());
        $this->assertEqualsWithDelta(13930, (float) DailyBar::where('instrument_id', $azn->id)->sole()->close, 0.0001);
        $this->assertSame(2, DailyBar::where('instrument_id', $bt->id)->count());
    }

    public function test_the_stored_scale_guard_is_london_only_and_tolerates_a_gap(): void
    {
        // A Paris Instrument keeps today's behavior (split-adjusted history is rewritten).
        $paris = Instrument::factory()->create(['ticker' => 'SAN.PA', 'market' => 'XPAR', 'currency' => 'EUR', 'exchange' => 'PAR']);
        $universe = Universe::factory()->create(['slug' => 'paris-fixture', 'market' => 'XPAR', 'currency' => 'EUR', 'position' => 4]);
        $universe->instruments()->attach($paris);
        DailyBar::factory()->create(['instrument_id' => $paris->id, 'date' => '2026-11-06', 'open' => 9000, 'high' => 9000, 'low' => 9000, 'close' => 9000, 'volume' => 1]);
        // A London Instrument with an old stored bar and no overlap: compared with the first newer bar.
        $london = $this->universe(['AZN.L']);
        $azn = Instrument::where('ticker', 'AZN.L')->sole();
        DailyBar::factory()->create(['instrument_id' => $azn->id, 'date' => '2026-10-30', 'open' => 13500, 'high' => 13500, 'low' => 13500, 'close' => 13500, 'volume' => 1]);
        $bar = fn (string $date, float $close): array => ['date' => $date, 'open' => $close, 'high' => $close, 'low' => $close, 'close' => $close, 'volume' => 1];
        Http::fake([
            '*/eod/SAN.PA*' => Http::response(['currency' => 'EUR', 'bars' => [$bar('2026-11-06', 90), $bar('2026-11-09', 91)]]),
            '*/eod/AZN.L*' => Http::response(['currency' => 'GBX', 'bars' => [$bar('2026-10-01', 12000), $bar('2026-11-06', 13900), $bar('2026-11-09', 13930)]]),
        ]);

        $this->artisan('ingestion:run', ['--universe' => 'paris-fixture'])->assertExitCode(0);
        $this->artisan('ingestion:run', ['--universe' => $london->slug])->assertExitCode(0);

        $this->assertEqualsWithDelta(90, (float) DailyBar::where('instrument_id', $paris->id)->whereDate('date', '2026-11-06')->sole()->close, 0.0001);
        $this->assertSame(4, DailyBar::where('instrument_id', $azn->id)->count());
        $this->assertSame(IngestionRunStatus::Completed, IngestionRun::where('universe_id', $london->id)->sole()->status);
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
        // US-only stages (Market Context, macro, regime) never run for London;
        // a stray FRED request would also fail via preventStrayRequests().
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'stlouisfed') || str_contains($r->url(), '/market-context'));
        $this->assertNull(MarketContextRunLog::get('macro:refresh'));
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
