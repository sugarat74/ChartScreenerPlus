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
use Database\Seeders\Ftse100UniverseSeeder;
use Database\Seeders\Ibex35UniverseSeeder;
use Database\Seeders\Sp500UniverseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * universe-ftse100 (docs/specs/universes-cac40-ftse100.md, Feature 3): the
 * committed, dated FTSE 100 snapshot (the 97 constituents quoted in pence),
 * its seeder and the London universe wiring. Offline: the committed CSV only,
 * and a faked engine.
 */
class Ftse100UniverseSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_ROWS = 97;

    /** Constituents quoted in USD/EUR on the LSE, left out (decision P2). */
    private const EXCLUDED = ['CPG.L', 'IHG.L', 'MTLN.L'];

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
    private function dataset(string $slug = 'ftse100'): array
    {
        $handle = fopen(base_path("database/data/{$slug}.csv"), 'rb');
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];

        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($header, $cells);
        }

        fclose($handle);

        return $rows;
    }

    private function seedFtse(): Universe
    {
        $this->seed(Ftse100UniverseSeeder::class);

        return Universe::query()->where('slug', 'ftse100')->firstOrFail();
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

        foreach ($rows as $row) {
            // Yahoo form: letters/digits, one optional hyphenated share class, `.L`.
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{2,5}(-[A-Z])?\.L$/', $row['ticker']);
            $this->assertContains($row['sector'], ScreenerFilterParser::SECTORS, "Sector of {$row['ticker']}");
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $row['country'], "Country of {$row['ticker']}");
            $this->assertSame('LSE', $row['exchange'], "Exchange of {$row['ticker']}");
            $this->assertNotSame('', $row['company']);
            $this->assertNotSame('', $row['industry']);
        }

        // BT Group's class-A share in Yahoo's hyphen form; the only class ticker.
        $this->assertContains('BT-A.L', $tickers);
        $this->assertNotContains('BT.A.L', $tickers);
        $this->assertSame(['BT-A.L'], array_values(array_filter($tickers, fn (string $t): bool => str_contains($t, '-'))));

        // Headquarters country, not listing or registration country.
        $countries = array_column($rows, 'country', 'ticker');
        $this->assertSame('GB', $countries['AZN.L']);
        $this->assertSame('CH', $countries['CCH.L']);
        $this->assertSame('IE', $countries['DCC.L']);
        $this->assertSame('BM', $countries['HSX.L']);
        $this->assertSame('GG', $countries['PSH.L']);
        $this->assertSame('ES', $countries['IAG.L']);

        // ICB text verbatim; GICS sector by the documented rule and its one override.
        $sectors = array_column($rows, 'sector', 'ticker');
        $industries = array_column($rows, 'industry', 'ticker');
        $this->assertSame('Pharmaceuticals, Biotechnology and Marijuana Producers', $industries['AZN.L']);
        $this->assertSame('Health Care', $sectors['AZN.L']);
        $this->assertSame('Financials', $sectors['SMT.L']);
        $this->assertSame('Closed End Investments', $industries['SMT.L']);
        $this->assertSame('Real Estate', $sectors['LAND.L']);
        $this->assertSame('Energy', $sectors['SHEL.L']);
        $this->assertSame('Utilities', $sectors['SSE.L']);
        $this->assertSame('Communication Services', $sectors['BT-A.L']);
        $this->assertSame('Industrials', $sectors['IAG.L']);
        $this->assertSame('Industrials', $sectors['EZJ.L']);
        $this->assertSame('Travel and Leisure', $industries['EZJ.L']);
        $this->assertSame('Consumer Discretionary', $sectors['WTB.L']);

        // The September 2026 review and the Beazley deletion are applied.
        foreach (['EZJ.L', 'ITH.L'] as $added) {
            $this->assertContains($added, $tickers);
        }
        foreach (['ENT.L', 'PSN.L', 'BEZ.L'] as $removed) {
            $this->assertNotContains($removed, $tickers);
        }
    }

    public function test_the_non_pence_constituents_are_left_out_and_documented(): void
    {
        $tickers = array_column($this->dataset(), 'ticker');
        $notes = (string) file_get_contents(base_path('database/data/ftse100.md'));
        $excluded = substr($notes, (int) strpos($notes, '## Excluded constituents'));

        foreach (self::EXCLUDED as $ticker) {
            $this->assertNotContains($ticker, $tickers);
            $this->assertStringContainsString("| {$ticker} |", $excluded, "{$ticker} is listed under Excluded constituents.");
        }

        $this->assertStringContainsString('| CPG.L | Compass Group | USD |', $excluded);
        $this->assertStringContainsString('| IHG.L | IHG Hotels & Resorts | USD |', $excluded);
        $this->assertStringContainsString('| MTLN.L | Metlen Energy & Metals | EUR |', $excluded);
        $this->assertSame(self::EXPECTED_ROWS + count(self::EXCLUDED), 100);
    }

    public function test_the_notes_document_source_date_hashes_and_the_rebalance_procedure(): void
    {
        $notes = (string) file_get_contents(base_path('database/data/ftse100.md'));

        $this->assertStringContainsString('as_of 2026-11-13', $notes);
        $this->assertStringContainsString('Rebalance procedure', $notes);
        // Wikipedia raw wikitext of the revision the spec hashed on 2026-11-08.
        $this->assertStringContainsString('9acd8ddd9746050749cb76111955aebb7d463fc6c912fc662c4d67476004d7d9', $notes);
        $this->assertStringContainsString('1384229503', $notes);
        $this->assertStringContainsString('BT-A.L', $notes);
        $this->assertStringContainsString(hash_file('sha256', base_path('database/data/ftse100.csv')), $notes);

        // Every member has a row in the per-ticker table.
        foreach (array_column($this->dataset(), 'ticker') as $ticker) {
            $this->assertStringContainsString("| {$ticker} |", $notes, "{$ticker} is in the per-ticker table.");
        }
    }

    public function test_seeding_creates_the_xlon_gbx_universe_after_the_cac_40_position(): void
    {
        $universe = $this->seedFtse();

        $expected = array_column($this->dataset(), 'ticker');
        sort($expected);

        $this->assertSame('FTSE 100', $universe->name);
        $this->assertSame('XLON', $universe->market);
        $this->assertSame('GBX', $universe->currency);
        $this->assertTrue($universe->is_screener);
        $this->assertSame(5, $universe->position);
        $actual = $universe->instruments()->pluck('ticker')->all();
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertCount(self::EXPECTED_ROWS, $actual);

        foreach (Instrument::query()->get() as $instrument) {
            $this->assertSame('XLON', $instrument->market);
            $this->assertSame('GBX', $instrument->currency);
            $this->assertSame('LSE', $instrument->exchange);
            $this->assertTrue($instrument->active);
            $this->assertTrue(ListingMarket::tickerMatches('XLON', $instrument->ticker));
        }

        $this->assertSame(0, Instrument::query()->whereIn('ticker', self::EXCLUDED)->count());
    }

    public function test_re_seeding_is_idempotent(): void
    {
        $this->seedFtse();
        $instruments = Instrument::query()->count();
        $memberships = DB::table('instrument_universe')->count();

        $this->seed(Ftse100UniverseSeeder::class);

        $this->assertSame(self::EXPECTED_ROWS, Instrument::query()->count());
        $this->assertSame($instruments, Instrument::query()->count());
        $this->assertSame($memberships, DB::table('instrument_universe')->count());
        $this->assertSame(1, Universe::query()->where('slug', 'ftse100')->count());
    }

    public function test_it_does_not_touch_the_other_universes(): void
    {
        $this->seed(Sp500UniverseSeeder::class);
        $this->seed(Ibex35UniverseSeeder::class);
        $this->seed(DaxUniverseSeeder::class);
        $this->seed(CacUniverseSeeder::class);
        $before = Instrument::query()->orderBy('id')->get(['id', 'ticker', 'market', 'currency', 'active'])->toArray();
        $memberships = DB::table('instrument_universe')->count();

        $this->seedFtse();

        $after = Instrument::query()->whereNotIn('market', ['XLON'])->orderBy('id')->get(['id', 'ticker', 'market', 'currency', 'active'])->toArray();
        $this->assertSame($before, $after);
        $this->assertSame(count($before) + self::EXPECTED_ROWS, Instrument::query()->count());
        $this->assertSame($memberships + self::EXPECTED_ROWS, DB::table('instrument_universe')->count());
        $this->assertSame(0, Instrument::query()->where('market', '!=', 'XLON')->where('ticker', 'like', '%.L')->count());
    }

    public function test_iag_in_london_and_in_the_ibex_35_are_separate_instruments(): void
    {
        $this->seed(Ibex35UniverseSeeder::class);
        $this->seedFtse();

        $london = Instrument::query()->where('ticker', 'IAG.L')->sole();
        $madrid = Instrument::query()->where('ticker', 'IAG.MC')->sole();

        $this->assertNotSame($london->id, $madrid->id);
        $this->assertSame('XLON', $london->listingMarket());
        $this->assertSame('GBX', $london->currency);
        $this->assertSame('XMAD', $madrid->listingMarket());
        $this->assertSame('EUR', $madrid->currency);
        $this->assertSame(['ftse100'], $london->universes()->pluck('slug')->all());
        $this->assertSame(['ibex35'], $madrid->universes()->pluck('slug')->all());
        $this->assertSame($madrid->sector, $london->sector);
        $this->assertSame($madrid->country, $london->country);
    }

    public function test_the_suffix_rules_accept_every_member_only_for_xlon(): void
    {
        foreach (array_column($this->dataset(), 'ticker') as $ticker) {
            $this->assertTrue(ListingMarket::tickerMatches('XLON', $ticker), $ticker);

            foreach (['US', 'XMAD', 'XETR', 'XPAR'] as $market) {
                $this->assertFalse(ListingMarket::tickerMatches($market, $ticker), "{$ticker} on {$market}");
            }
        }

        $this->assertSame(['.L'], ListingMarket::suffixes('XLON'));
    }

    public function test_no_other_committed_dataset_ticker_ends_in_the_london_suffix(): void
    {
        foreach (['sp500', 'nasdaq100', 'ibex35', 'dax', 'cac40'] as $slug) {
            foreach (array_column($this->dataset($slug), 'ticker') as $ticker) {
                $this->assertDoesNotMatchRegularExpression('/\.L$/i', $ticker, "{$slug}: {$ticker}");
            }
        }
    }

    public function test_the_universe_is_not_public_by_default_but_admins_can_preview_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'UTC'));
        $this->seed(Sp500UniverseSeeder::class);
        $this->seedFtse();

        $this->assertNotContains('ftse100', config('universes.public'));
        $this->assertStringNotContainsString('ftse100', (string) file_get_contents(base_path('config/universes.php')));

        $visitor = $this->getJson('/api/universes')->assertOk();
        $this->assertSame(['sp500'], array_column($visitor->json('universes'), 'slug'));
        $this->getJson('/api/screener?universe=ftse100')->assertNotFound();
        $this->assertNotContains('XLON', array_column($this->getJson('/api/markets/status')->assertOk()->json('markets'), 'code'));

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->getJson('/api/universes')->assertOk();
        $this->assertSame(['sp500', 'ftse100'], array_column($response->json('universes'), 'slug'));
        $ftse = collect($response->json('universes'))->firstWhere('slug', 'ftse100');
        $this->assertSame('GBX', $ftse['currency']);
        $this->assertSame(self::EXPECTED_ROWS, $ftse['members']);
        $this->actingAs($admin)->getJson('/api/screener?universe=ftse100')->assertOk();

        // The market-hours strip shows London to the Admin (open at 11:00 BST).
        $markets = collect($this->actingAs($admin)->getJson('/api/markets/status')->assertOk()->json('markets'));
        $this->assertSame(['US', 'XLON'], $markets->pluck('code')->all());
        $this->assertTrue($markets->firstWhere('code', 'XLON')['is_open']);
    }

    public function test_the_public_label_is_ftse_100(): void
    {
        $this->seedFtse();
        config(['universes.public' => ['sp500', 'ftse100']]);

        $payload = $this->getJson('/api/universes')->assertOk()->json('universes');
        $ftse = collect($payload)->firstWhere('slug', 'ftse100');

        $this->assertSame('FTSE 100', $ftse['name']);
        $this->assertSame('XLON', $ftse['market']);
        $this->assertSame('GBX', $ftse['currency']);
    }

    public function test_the_production_schedule_has_the_london_event_at_17_15_only_when_seeded(): void
    {
        $schedule = new Schedule;
        app(PipelineSchedule::class)->register($schedule);
        $this->assertNull(collect($schedule->events())->first(fn ($event) => $event->timezone === 'Europe/London'));

        $this->seedFtse();

        $schedule = new Schedule;
        app(PipelineSchedule::class)->register($schedule);
        $london = collect($schedule->events())->first(fn ($event) => $event->timezone === 'Europe/London' && str_contains((string) $event->command, 'ingestion:pipeline'));

        $this->assertNotNull($london);
        $this->assertSame('15 17 * * 1-5', $london->expression);
        $this->assertMatchesRegularExpression('/--market=["\']?XLON["\']?/', (string) $london->command);
    }

    public function test_the_christmas_and_new_year_weeks_follow_the_london_calendar(): void
    {
        $calendar = new MarketCalendar;

        $expected = [
            '2026-12-24' => true, // half day, 12:30 close
            '2026-12-25' => false, // Christmas
            '2026-12-26' => false, // Saturday
            '2026-12-27' => false, // Sunday
            '2026-12-28' => false, // substitute Boxing Day
            '2026-12-29' => true,
            '2026-12-30' => true,
            '2026-12-31' => true, // half day, 12:30 close
            '2027-01-01' => false,
        ];

        foreach ($expected as $day => $trading) {
            $this->assertSame($trading, $calendar->isTradingDay('XLON', Carbon::parse($day)), $day);
        }

        foreach (['2026-12-24', '2026-12-31'] as $day) {
            $session = $calendar->session('XLON', Carbon::parse($day));
            $this->assertTrue($session['early_close'], $day);
            $this->assertSame('12:30', $session['closes_at']->setTimezone('Europe/London')->format('H:i'), $day);
        }

        // Frozen clock: the pipeline's latest session through the holiday week.
        $this->assertSame('2026-12-24', $calendar->latestSession('XLON', Carbon::parse('2026-12-24 17:15:00', 'Europe/London'))->toDateString());
        $this->assertSame('2026-12-24', $calendar->latestSession('XLON', Carbon::parse('2026-12-28 17:15:00', 'Europe/London'))->toDateString());
        $this->assertSame('2026-12-29', $calendar->latestSession('XLON', Carbon::parse('2026-12-29 17:15:00', 'Europe/London'))->toDateString());
        $this->assertSame('2026-12-31', $calendar->latestSession('XLON', Carbon::parse('2027-01-01 12:00:00', 'Europe/London'))->toDateString());

        // Paris trades on the UK-only substitute holiday.
        $this->assertTrue($calendar->isTradingDay('XPAR', Carbon::parse('2026-12-28')));
    }

    public function test_the_xlon_pipeline_fetches_each_member_once_with_the_london_market_and_stores_pence(): void
    {
        Http::preventStrayRequests();
        $this->seedFtse();

        // Keep the run light: eight members, BT-A.L and IAG.L included.
        $keep = ['AZN.L', 'BT-A.L', 'IAG.L', 'LLOY.L', 'SHEL.L', 'SMT.L', 'PSH.L', 'WTB.L'];
        Instrument::query()->whereNotIn('ticker', $keep)->delete();

        $bars = [];
        foreach ([0, 1] as $index) {
            $bars[] = [
                'date' => Carbon::parse('2026-07-14')->addDays($index)->toDateString(),
                'open' => 1250.0, 'high' => 1270.5, 'low' => 1240.0, 'close' => 1264.0, 'volume' => 1000,
            ];
        }

        Http::fake([
            '*/eod/*' => Http::response(['symbol' => 'X', 'bars' => $bars, 'currency' => 'GBX']),
            '*/indicators/compute' => Http::response(['snapshots' => []]),
            '*/signals/detect' => Http::response(['signals' => []]),
            '*/patterns/detect' => Http::response(['patterns' => []]),
        ]);

        $this->artisan('ingestion:pipeline', ['--market' => 'XLON', '--force' => true])
            ->doesntExpectOutputToContain('Alerts as of')
            ->assertExitCode(0);

        $requested = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/eod/'))
            ->map(fn (array $pair): string => basename(parse_url($pair[0]->url(), PHP_URL_PATH)));

        $this->assertSame(count($keep), $requested->count());
        $this->assertSame(count($keep), $requested->unique()->count());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/eod/BT-A.L') && str_contains($request->url(), 'market=XLON'));

        $run = IngestionRun::query()->whereHas('universe', fn ($q) => $q->where('slug', 'ftse100'))->sole();
        $this->assertSame(count($keep), $run->total);
        $this->assertSame(count($keep), $run->succeeded);
        $this->assertSame(count($keep) * 2, DailyBar::query()->count());
        // Pence stored as quoted, never divided by 100.
        $this->assertEqualsWithDelta(1264.0, (float) DailyBar::query()->max('close'), 0.0001);
    }
}
