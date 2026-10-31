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
use Database\Seeders\DaxUniverseSeeder;
use Database\Seeders\Sp500UniverseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * universe-dax (docs/specs/multi-market-universes.md, Feature 6): the
 * committed, dated DAX snapshot, its seeder and the Xetra
 * calendar. Offline: the committed CSV only, and a faked engine.
 */
class DaxUniverseSeederTest extends TestCase
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
        $handle = fopen(base_path('database/data/dax.csv'), 'rb');
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];

        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($header, $cells);
        }

        fclose($handle);

        return $rows;
    }

    private function seedDax(): Universe
    {
        $this->seed(DaxUniverseSeeder::class);

        return Universe::query()->where('slug', 'dax')->firstOrFail();
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
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{2,5}\.DE$/', $row['ticker']);
            $this->assertContains($row['sector'], ScreenerFilterParser::SECTORS, "Sector of {$row['ticker']}");
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $row['country'], "Country of {$row['ticker']}");
            $this->assertSame('XETRA', $row['exchange'], "Exchange of {$row['ticker']}");
            $this->assertNotSame('', $row['company']);
        }

        // Headquarters country, not listing country.
        $countries = array_column($rows, 'country', 'ticker');
        $this->assertSame('NL', $countries['AIR.DE']);
        $this->assertSame('DE', $countries['SAP.DE']);

        // The base list moved: Hochtief replaced Porsche SE on 2026-06-22.
        $this->assertContains('HOT.DE', $tickers);
        $this->assertNotContains('PAH3.DE', $tickers);
        // The Wikipedia Paris line was replaced by the Xetra one.
        $this->assertNotContains('AIR.PA', $tickers);

        // The notes file documents source, date, hash and the rebalance procedure.
        $notes = (string) file_get_contents(base_path('database/data/dax.md'));
        $this->assertStringContainsString('2026-10-31', $notes);
        $this->assertStringContainsString('Rebalance procedure', $notes);
        $this->assertStringContainsString('SHA-256', $notes);
        $this->assertStringContainsString(hash_file('sha256', base_path('database/data/dax.csv')), $notes);
    }

    public function test_seeding_creates_the_xetr_eur_universe_after_the_ibex35_position(): void
    {
        $universe = $this->seedDax();

        $expected = array_column($this->dataset(), 'ticker');
        sort($expected);

        $this->assertSame('DAX', $universe->name);
        $this->assertStringNotContainsString('40', $universe->name, 'The label is "DAX", never "DAX 40".');
        $this->assertSame('XETR', $universe->market);
        $this->assertSame('EUR', $universe->currency);
        $this->assertTrue($universe->is_screener);
        $this->assertSame(3, $universe->position);
        $this->assertSame($expected, $universe->instruments()->orderBy('ticker')->pluck('ticker')->all());

        foreach (Instrument::query()->get() as $instrument) {
            $this->assertSame('XETR', $instrument->market);
            $this->assertSame('EUR', $instrument->currency);
            $this->assertSame('XETRA', $instrument->exchange);
            $this->assertTrue($instrument->active);
        }
    }

    public function test_re_seeding_is_idempotent(): void
    {
        $this->seedDax();
        $instruments = Instrument::query()->count();
        $memberships = DB::table('instrument_universe')->count();

        $this->seed(DaxUniverseSeeder::class);

        $this->assertSame(self::EXPECTED_ROWS, Instrument::query()->count());
        $this->assertSame($instruments, Instrument::query()->count());
        $this->assertSame($memberships, DB::table('instrument_universe')->count());
        $this->assertSame(1, Universe::query()->where('slug', 'dax')->count());
    }

    public function test_it_does_not_touch_the_sp500_instruments(): void
    {
        $this->seed(Sp500UniverseSeeder::class);
        $sp500 = Instrument::query()->count();

        $this->seedDax();

        $this->assertSame($sp500 + self::EXPECTED_ROWS, Instrument::query()->count());
        $this->assertSame(0, Instrument::query()->whereIn('market', ['US'])->where('ticker', 'like', '%.DE')->count());
    }

    public function test_the_universe_is_not_public_by_default_but_admins_can_preview_it(): void
    {
        $this->seed(Sp500UniverseSeeder::class);
        $this->seedDax();

        $this->assertNotContains('dax', config('universes.public'));

        $visitor = $this->getJson('/api/universes')->assertOk();
        $this->assertSame(['sp500'], array_column($visitor->json('universes'), 'slug'));
        $this->getJson('/api/screener?universe=dax')->assertNotFound();

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->getJson('/api/universes')->assertOk();
        $this->assertSame(['sp500', 'dax'], array_column($response->json('universes'), 'slug'));
        $this->assertSame('EUR', collect($response->json('universes'))->firstWhere('slug', 'dax')['currency']);
        $this->actingAs($admin)->getJson('/api/screener?universe=dax')->assertOk();
    }

    public function test_the_committed_xetra_holidays_are_weekdays_and_known_closures_are_skipped(): void
    {
        $holidays = config('ingestion.markets.XETR.holidays');

        $this->assertNotEmpty($holidays);
        $this->assertSame(array_values(array_unique($holidays)), $holidays, 'Duplicate holiday.');

        $years = [];
        foreach ($holidays as $holiday) {
            $date = Carbon::parse($holiday);
            $this->assertTrue($date->isWeekday(), "{$holiday} is not a weekday.");
            $years[$date->format('Y')] = true;
        }
        $this->assertSame([2026, 2027], array_keys($years));
        // Deutsche Boerse 2026 list: 1 Jan, 3 Apr, 6 Apr, 1 May, 24, 25 and 31 Dec.
        $this->assertSame(
            ['2026-01-01', '2026-04-03', '2026-04-06', '2026-05-01', '2026-12-24', '2026-12-25', '2026-12-31'],
            array_values(array_filter($holidays, fn (string $day): bool => str_starts_with($day, '2026'))),
        );

        $calendar = new MarketCalendar;

        // Deutsche Boerse 2026: Good Friday, Easter Monday, 1 May and, unlike BME, the
        // 24 and 31 December are full non-trading days.
        $this->assertFalse($calendar->isTradingDay('XETR', Carbon::parse('2026-04-03')));
        $this->assertFalse($calendar->isTradingDay('XETR', Carbon::parse('2026-04-06')));
        $this->assertFalse($calendar->isTradingDay('XETR', Carbon::parse('2026-12-25')));
        $this->assertFalse($calendar->isTradingDay('XETR', Carbon::parse('2026-12-24')));
        $this->assertFalse($calendar->isTradingDay('XETR', Carbon::parse('2026-12-31')));
        $this->assertFalse($calendar->isTradingDay('XETR', Carbon::parse('2026-05-01')));
        // German public holidays that Xetra trades through, and 3 October (a Saturday in 2026).
        $this->assertTrue($calendar->isTradingDay('XETR', Carbon::parse('2026-05-14')));
        $this->assertTrue($calendar->isTradingDay('XETR', Carbon::parse('2026-05-25')));
        $this->assertTrue($calendar->isTradingDay('XETR', Carbon::parse('2026-06-04')));
        // Xetra-only: Easter Monday 2026 is a US trading day.
        $this->assertTrue($calendar->isTradingDay('US', Carbon::parse('2026-04-06')));
    }

    public function test_the_production_schedule_has_the_berlin_event_at_18_15(): void
    {
        $this->seedDax();

        $schedule = new Schedule;
        app(PipelineSchedule::class)->register($schedule);
        $berlin = collect($schedule->events())->first(fn ($event) => $event->timezone === 'Europe/Berlin' && str_contains((string) $event->command, 'ingestion:pipeline'));

        $this->assertNotNull($berlin);
        $this->assertSame('15 18 * * 1-5', $berlin->expression);
        $this->assertMatchesRegularExpression('/--market=[\'"]?XETR[\'"]?/', (string) $berlin->command);
    }

    public function test_mrk_de_and_the_us_mrk_are_separate_instruments_with_separate_bars(): void
    {
        $this->seed(Sp500UniverseSeeder::class);
        $this->seedDax();

        $us = Instrument::query()->where('ticker', 'MRK')->sole();
        $de = Instrument::query()->where('ticker', 'MRK.DE')->sole();

        $this->assertNotSame($us->id, $de->id);
        $this->assertSame('US', $us->listingMarket());
        $this->assertSame('USD', $us->currency);
        $this->assertSame('XETR', $de->listingMarket());
        $this->assertSame('EUR', $de->currency);
        $this->assertSame('Merck KGaA', $de->company);

        DailyBar::factory()->create(['instrument_id' => $us->id, 'date' => '2026-07-14']);
        DailyBar::factory()->create(['instrument_id' => $de->id, 'date' => '2026-07-14']);

        $this->assertSame(1, DailyBar::query()->where('instrument_id', $us->id)->count());
        $this->assertSame(1, DailyBar::query()->where('instrument_id', $de->id)->count());
        $this->assertSame(['dax'], $de->universes()->pluck('slug')->all());
        $this->assertSame(['sp500'], $us->universes()->pluck('slug')->all());
    }

    public function test_the_public_label_is_dax_never_dax_40(): void
    {
        $this->seedDax();
        config(['universes.public' => ['sp500', 'dax']]);

        $payload = $this->getJson('/api/universes')->assertOk()->json('universes');
        $dax = collect($payload)->firstWhere('slug', 'dax');

        $this->assertSame('DAX', $dax['name']);
        $this->assertSame('XETR', $dax['market']);
        $this->assertSame('EUR', $dax['currency']);

        foreach (['frontend/src/i18n/guide/en.ts', 'frontend/src/i18n/guide/es.ts'] as $file) {
            $this->assertStringNotContainsString('DAX 40', (string) file_get_contents(base_path($file)), $file);
        }
    }

    public function test_the_xetr_pipeline_ingests_the_members_with_eur_and_skips_us_stages(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 18:15:00', 'Europe/Berlin'));
        $this->seedDax();

        // Keep the run light (a heavy full-universe pipeline test hung test-pgsql once, see
        // Nasdaq100UniverseSeederTest): eight members still prove one fetch per Instrument.
        $keep = ['SAP.DE', 'MRK.DE', 'SIE.DE', 'ALV.DE', 'BMW.DE', 'HOT.DE', 'G1A.DE', 'HNR1.DE'];
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

        $this->artisan('ingestion:pipeline', ['--market' => 'XETR', '--force' => true])
            ->doesntExpectOutputToContain('Alerts as of')
            ->assertExitCode(0);

        $requested = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/eod/'))
            ->map(fn (array $pair): string => basename(parse_url($pair[0]->url(), PHP_URL_PATH)));

        $this->assertSame(count($keep), $requested->count());
        $this->assertSame(count($keep), $requested->unique()->count());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/eod/SAP.DE') && str_contains($request->url(), 'market=XETR'));

        $run = IngestionRun::query()->whereHas('universe', fn ($q) => $q->where('slug', 'dax'))->sole();
        $this->assertSame(count($keep), $run->total);
        $this->assertSame(count($keep) * 2, DailyBar::query()->count());
    }
}
