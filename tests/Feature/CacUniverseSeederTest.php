<?php

namespace Tests\Feature;

use App\Models\DailyBar;
use App\Models\IngestionRun;
use App\Models\Instrument;
use App\Models\Universe;
use App\Models\User;
use App\Services\Market\MarketCalendar;
use App\Services\Market\PipelineSchedule;
use App\Services\Screener\ScreenerFilterParser;
use App\Support\ListingMarket;
use Database\Seeders\CacUniverseSeeder;
use Database\Seeders\DaxUniverseSeeder;
use Database\Seeders\Sp500UniverseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * universe-cac40 (docs/specs/universes-cac40-ftse100.md, Feature 1): the
 * committed, dated CAC 40 snapshot, its seeder and the Euronext Paris
 * calendar. Offline: the committed CSV only, and a faked engine.
 */
class CacUniverseSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_ROWS = 40;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingestion.universe' => 'sp500', 'universes.public' => ['sp500']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return list<array<string, string>>
     */
    private function dataset(): array
    {
        $handle = fopen(base_path('database/data/cac40.csv'), 'rb');
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];

        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($header, $cells);
        }

        fclose($handle);

        return $rows;
    }

    private function seedCac(): Universe
    {
        $this->seed(CacUniverseSeeder::class);

        return Universe::query()->where('slug', 'cac40')->firstOrFail();
    }

    public function test_the_committed_snapshot_has_the_expected_shape(): void
    {
        $rows = $this->dataset();
        $tickers = array_column($rows, 'ticker');

        $this->assertCount(self::EXPECTED_ROWS, $rows);
        $this->assertCount(self::EXPECTED_ROWS, array_unique($tickers), 'Duplicate tickers in the snapshot.');
        $sorted = $tickers;
        sort($sorted);
        $this->assertSame($sorted, $tickers, 'The snapshot is sorted by ticker.');

        $as = [];

        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{2,6}\.(PA|AS)$/', $row['ticker']);
            $this->assertContains($row['sector'], ScreenerFilterParser::SECTORS, "Sector of {$row['ticker']}");
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $row['country'], "Country of {$row['ticker']}");
            $this->assertSame($row['ticker'] === 'MT.AS' ? 'AMS' : 'PAR', $row['exchange'], "Exchange of {$row['ticker']}");
            $this->assertNotSame('', $row['company']);
            $this->assertNotSame('', $row['industry']);

            if (str_ends_with($row['ticker'], '.AS')) {
                $as[] = $row['ticker'];
            }
        }

        // The only non-.PA line is ArcelorMittal (decision C3): 39 .PA + MT.AS.
        $this->assertSame(['MT.AS'], $as);
        $this->assertNotContains('MT.PA', $tickers);

        // Headquarters country, not listing country.
        $countries = array_column($rows, 'country', 'ticker');
        $this->assertSame('FR', $countries['SAN.PA']);
        $this->assertSame('NL', $countries['AIR.PA']);
        $this->assertSame('LU', $countries['MT.AS']);
        $this->assertSame('CH', $countries['STMPA.PA']);

        $sectors = array_column($rows, 'sector', 'ticker');
        $this->assertSame('Materials', $sectors['MT.AS']);
        $this->assertSame('Utilities', $sectors['VIE.PA']);
        $this->assertSame('Real Estate', $sectors['URW.PA']);

        // The notes file documents source, date, hash and the rebalance procedure.
        $notes = (string) file_get_contents(base_path('database/data/cac40.md'));
        $this->assertStringContainsString('2026-11-08', $notes);
        $this->assertStringContainsString('Rebalance procedure', $notes);
        $this->assertStringContainsString('b422996cdaa72131e1334f61f401a05a1b0548d95a65b2571e4211f1a8ff698f', $notes);
        $this->assertStringContainsString('MT.AS', $notes);
        $this->assertStringContainsString('SHA-256', $notes);
        $this->assertStringContainsString(hash_file('sha256', base_path('database/data/cac40.csv')), $notes);
    }

    public function test_seeding_creates_the_xetr_eur_universe_after_the_ibex35_position(): void
    {
        $universe = $this->seedCac();

        $expected = array_column($this->dataset(), 'ticker');
        sort($expected);

        $this->assertSame('CAC 40', $universe->name);
        $this->assertSame('XPAR', $universe->market);
        $this->assertSame('EUR', $universe->currency);
        $this->assertTrue($universe->is_screener);
        $this->assertSame(4, $universe->position);
        $actual = $universe->instruments()->pluck('ticker')->all();
        sort($actual);
        $this->assertSame($expected, $actual);

        foreach (Instrument::query()->get() as $instrument) {
            $this->assertSame('XPAR', $instrument->market);
            $this->assertSame('EUR', $instrument->currency);
            $this->assertContains($instrument->exchange, ['PAR', 'AMS']);
            $this->assertTrue($instrument->active);
        }
    }

    public function test_re_seeding_is_idempotent(): void
    {
        $this->seedCac();
        $instruments = Instrument::query()->count();
        $memberships = DB::table('instrument_universe')->count();

        $this->seed(CacUniverseSeeder::class);

        $this->assertSame(self::EXPECTED_ROWS, Instrument::query()->count());
        $this->assertSame($instruments, Instrument::query()->count());
        $this->assertSame($memberships, DB::table('instrument_universe')->count());
        $this->assertSame(1, Universe::query()->where('slug', 'cac40')->count());
    }

    public function test_it_does_not_touch_the_sp500_instruments(): void
    {
        $this->seed(Sp500UniverseSeeder::class);
        $sp500 = Instrument::query()->count();

        $this->seedCac();

        $this->assertSame($sp500 + self::EXPECTED_ROWS, Instrument::query()->count());
        $this->assertSame(0, Instrument::query()->whereIn('market', ['US'])->where(fn ($q) => $q->where('ticker', 'like', '%.PA')->orWhere('ticker', 'MT.AS'))->count());
    }

    public function test_the_universe_is_not_public_by_default_but_admins_can_preview_it(): void
    {
        $this->seed(Sp500UniverseSeeder::class);
        $this->seedCac();

        $this->assertNotContains('cac40', config('universes.public'));

        $visitor = $this->getJson('/api/universes')->assertOk();
        $this->assertSame(['sp500'], array_column($visitor->json('universes'), 'slug'));
        $this->getJson('/api/screener?universe=cac40')->assertNotFound();

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->getJson('/api/universes')->assertOk();
        $this->assertSame(['sp500', 'cac40'], array_column($response->json('universes'), 'slug'));
        $this->assertSame('EUR', collect($response->json('universes'))->firstWhere('slug', 'cac40')['currency']);
        $this->actingAs($admin)->getJson('/api/screener?universe=cac40')->assertOk();
    }

    public function test_the_committed_euronext_holidays_are_weekdays_and_half_days_are_trading_days(): void
    {
        $holidays = config('ingestion.markets.XPAR.holidays');

        $this->assertSame(array_values(array_unique($holidays)), $holidays, 'Duplicate holiday.');
        $this->assertSame(
            ['2026-01-01', '2026-04-03', '2026-04-06', '2026-05-01', '2026-12-25', '2027-01-01', '2027-03-26', '2027-03-29'],
            $holidays,
        );

        foreach ($holidays as $holiday) {
            $this->assertTrue(Carbon::parse($holiday)->isWeekday(), "{$holiday} is not a weekday.");
        }

        $this->assertSame(
            ['2026-12-24' => '14:05', '2026-12-31' => '14:05', '2027-12-24' => '14:05', '2027-12-31' => '14:05'],
            config('ingestion.markets.XPAR.early_closes'),
        );

        $calendar = new MarketCalendar;

        foreach (['2026-04-03', '2026-04-06', '2026-05-01', '2026-12-25'] as $day) {
            $this->assertFalse($calendar->isTradingDay('XPAR', Carbon::parse($day)), $day);
        }

        // 24 and 31 December are half days (trading days), unlike Xetra.
        $this->assertTrue($calendar->isTradingDay('XPAR', Carbon::parse('2026-12-24')));
        $this->assertTrue($calendar->isTradingDay('XPAR', Carbon::parse('2026-12-31')));
        $this->assertFalse($calendar->isTradingDay('XETR', Carbon::parse('2026-12-24')));

        // UK-only bank holidays are Paris trading days.
        foreach (['2026-05-04', '2026-05-25', '2026-08-31'] as $day) {
            $this->assertTrue($calendar->isTradingDay('XPAR', Carbon::parse($day)), $day);
        }

        // A Paris closure that is a US trading day, and the reverse.
        $this->assertTrue($calendar->isTradingDay('US', Carbon::parse('2026-04-06')));
        $this->assertFalse($calendar->isTradingDay('US', Carbon::parse('2026-09-07')));
        $this->assertTrue($calendar->isTradingDay('XPAR', Carbon::parse('2026-09-07')));
    }

    public function test_the_christmas_week_has_paris_sessions_on_the_24th_and_28th_but_not_the_25th(): void
    {
        $calendar = new MarketCalendar;

        $expected = [
            '2026-12-24' => true, // half day
            '2026-12-25' => false, // Christmas
            '2026-12-26' => false, // Saturday
            '2026-12-27' => false, // Sunday
            '2026-12-28' => true,
        ];

        foreach ($expected as $day => $trading) {
            $this->assertSame($trading, $calendar->isTradingDay('XPAR', Carbon::parse($day)), $day);
        }

        $this->assertSame('2026-12-24', $calendar->latestSession('XPAR', Carbon::parse('2026-12-24 18:15:00', 'Europe/Paris'))->toDateString());
        $this->assertSame('2026-12-24', $calendar->latestSession('XPAR', Carbon::parse('2026-12-28 09:00:00', 'Europe/Paris'))->toDateString());
        $this->assertSame('2026-12-28', $calendar->latestSession('XPAR', Carbon::parse('2026-12-28 18:15:00', 'Europe/Paris'))->toDateString());
    }

    public function test_latest_session_flips_at_18_15_paris_across_the_cet_cest_change(): void
    {
        $calendar = new MarketCalendar;

        // Winter (CET, UTC+1): Friday 2026-03-27 18:15 = 17:15Z.
        $this->assertSame('2026-03-27', $calendar->latestSession('XPAR', Carbon::parse('2026-03-27 17:15:00', 'UTC'))->toDateString());
        $this->assertSame('2026-03-26', $calendar->latestSession('XPAR', Carbon::parse('2026-03-27 17:14:00', 'UTC'))->toDateString());
        // Summer (CEST, UTC+2): Monday 2026-03-30 18:15 = 16:15Z.
        $this->assertSame('2026-03-30', $calendar->latestSession('XPAR', Carbon::parse('2026-03-30 16:15:00', 'UTC'))->toDateString());
        $this->assertSame('2026-03-27', $calendar->latestSession('XPAR', Carbon::parse('2026-03-30 16:14:00', 'UTC'))->toDateString());
        $this->assertSame('18:15', $calendar->runTime('XPAR'));
        $this->assertSame('Europe/Paris', $calendar->timezone('XPAR'));
    }

    public function test_the_production_schedule_has_the_paris_event_at_18_15_only_when_seeded(): void
    {
        $schedule = new Schedule;
        app(PipelineSchedule::class)->register($schedule);
        $this->assertNull(collect($schedule->events())->first(fn ($event) => $event->timezone === 'Europe/Paris'));

        $this->seedCac();

        $schedule = new Schedule;
        app(PipelineSchedule::class)->register($schedule);
        $paris = collect($schedule->events())->first(fn ($event) => $event->timezone === 'Europe/Paris' && str_contains((string) $event->command, 'ingestion:pipeline'));

        $this->assertNotNull($paris);
        $this->assertSame('15 18 * * 1-5', $paris->expression);
        $this->assertMatchesRegularExpression('/--market=["\']?XPAR["\']?/', (string) $paris->command);
    }

    public function test_the_suffix_rules_accept_pa_and_the_single_as_exception_only_for_xpar(): void
    {
        $this->assertTrue(ListingMarket::tickerMatches('XPAR', 'SAN.PA'));
        $this->assertTrue(ListingMarket::tickerMatches('XPAR', 'MT.AS'));
        $this->assertTrue(ListingMarket::tickerMatches('XPAR', 'stlap.pa'));
        $this->assertFalse(ListingMarket::tickerMatches('XPAR', 'SAN'));
        $this->assertFalse(ListingMarket::tickerMatches('XPAR', 'SAN.MC'));
        $this->assertFalse(ListingMarket::tickerMatches('XPAR', 'SAP.DE'));
        $this->assertFalse(ListingMarket::tickerMatches('XMAD', 'SAN.PA'));
        $this->assertFalse(ListingMarket::tickerMatches('XETR', 'SAN.PA'));
        $this->assertFalse(ListingMarket::tickerMatches('US', 'SAN.PA'));
        $this->assertFalse(ListingMarket::tickerMatches('XMAD', 'MT.AS'));
        $this->assertFalse(ListingMarket::tickerMatches('XETR', 'MT.AS'));
        $this->assertFalse(ListingMarket::tickerMatches('US', 'MT.AS'));
        $this->assertSame('.PA', ListingMarket::suffix('XPAR'));
        $this->assertSame(['.PA', '.AS'], ListingMarket::suffixes('XPAR'));
        $this->assertSame([], ListingMarket::suffixes('US'));
        $this->assertSame('Europe/Paris', ListingMarket::timezone('XPAR'));
        $this->assertSame('09:00', ListingMarket::open('XPAR'));
        $this->assertSame('17:30', ListingMarket::close('XPAR'));
        $this->assertSame('EUR', ListingMarket::currency('XPAR'));
    }

    public function test_no_us_dataset_ticker_collides_with_the_new_suffix_rules(): void
    {
        foreach (['sp500', 'nasdaq100'] as $slug) {
            $handle = fopen(base_path("database/data/{$slug}.csv"), 'rb');
            fgetcsv($handle, null, ',', '"', '');

            while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $this->assertDoesNotMatchRegularExpression('/\.(L|PA|AS)$/i', $cells[0], "{$slug}: {$cells[0]}");
            }

            fclose($handle);
        }
    }

    public function test_airbus_in_paris_and_in_the_dax_are_separate_instruments(): void
    {
        $this->seed(DaxUniverseSeeder::class);
        $this->seedCac();

        $paris = Instrument::query()->where('ticker', 'AIR.PA')->sole();
        $xetra = Instrument::query()->where('ticker', 'AIR.DE')->sole();

        $this->assertNotSame($paris->id, $xetra->id);
        $this->assertSame('XPAR', $paris->listingMarket());
        $this->assertSame('XETR', $xetra->listingMarket());
        $this->assertSame(['cac40'], $paris->universes()->pluck('slug')->all());
        $this->assertSame(['dax'], $xetra->universes()->pluck('slug')->all());
        // ArcelorMittal's Amsterdam line is not the IBEX 35 line.
        $this->assertSame(1, Instrument::query()->where('ticker', 'MT.AS')->count());
        $this->assertSame(0, Instrument::query()->where('ticker', 'MTS.MC')->count());
        $this->assertSame(self::EXPECTED_ROWS + 40, Instrument::query()->count());
    }

    public function test_the_public_label_is_cac_40(): void
    {
        $this->seedCac();
        config(['universes.public' => ['sp500', 'cac40']]);

        $payload = $this->getJson('/api/universes')->assertOk()->json('universes');
        $cac = collect($payload)->firstWhere('slug', 'cac40');

        $this->assertSame('CAC 40', $cac['name']);
        $this->assertSame('XPAR', $cac['market']);
        $this->assertSame('EUR', $cac['currency']);
    }

    public function test_the_lock_wait_default_covers_several_european_pipelines(): void
    {
        $this->assertStringContainsString("'INGESTION_LOCK_WAIT_SECONDS', 3600", (string) file_get_contents(base_path('config/ingestion.php')));
    }

    public function test_the_xpar_pipeline_waits_for_another_european_pipeline_and_ingests_with_eur(): void
    {
        // No frozen clock: the lock wait polls real time.
        config(['ingestion.lock_wait_seconds' => 10]);
        $this->seedCac();

        // Keep the run light: eight members still prove one fetch per Instrument.
        $keep = ['SAN.PA', 'MT.AS', 'AIR.PA', 'STLAP.PA', 'STMPA.PA', 'MC.PA', 'OR.PA', 'ENX.PA'];
        Instrument::query()->whereNotIn('ticker', $keep)->delete();

        $bars = [];
        foreach ([0, 1] as $index) {
            $bars[] = [
                'date' => Carbon::parse('2026-07-14')->addDays($index)->toDateString(),
                'open' => 10.0, 'high' => 11.0, 'low' => 9.0, 'close' => 10.5, 'volume' => 1000,
            ];
        }

        Http::fake([
            '*/eod/*' => Http::response(['symbol' => 'X', 'bars' => $bars, 'currency' => 'EUR']),
            '*/indicators/compute' => Http::response(['snapshots' => []]),
            '*/signals/detect' => Http::response(['signals' => []]),
            '*/patterns/detect' => Http::response(['patterns' => []]),
            '*' => Http::response([], 200),
        ]);

        // Another European pipeline holds the global lock for one second.
        $this->assertTrue(Cache::lock('ingestion:pipeline', 1)->get());

        $this->artisan('ingestion:pipeline', ['--market' => 'XPAR', '--force' => true])
            ->expectsOutputToContain('waiting up to 10 s')
            ->doesntExpectOutputToContain('Alerts as of')
            ->assertExitCode(0);

        $requested = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/eod/'))
            ->map(fn (array $pair): string => basename(parse_url($pair[0]->url(), PHP_URL_PATH)));

        $this->assertSame(count($keep), $requested->count());
        $this->assertSame(count($keep), $requested->unique()->count());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/eod/MT.AS') && str_contains($request->url(), 'market=XPAR'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/eod/SAN.PA') && str_contains($request->url(), 'market=XPAR'));

        $run = IngestionRun::query()->whereHas('universe', fn ($q) => $q->where('slug', 'cac40'))->sole();
        $this->assertSame(count($keep), $run->total);
        $this->assertSame(count($keep) * 2, DailyBar::query()->count());
    }
}
