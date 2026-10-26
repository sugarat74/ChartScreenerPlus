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
 * universe-ibex35 (docs/specs/multi-market-universes.md, Feature 5): the
 * committed, dated IBEX 35 snapshot, its seeder and the Bolsa de Madrid
 * calendar. Offline: the committed CSV only, and a faked engine.
 */
class Ibex35UniverseSeederTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_ROWS = 35;

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
        $handle = fopen(base_path('database/data/ibex35.csv'), 'rb');
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];

        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($header, $cells);
        }

        fclose($handle);

        return $rows;
    }

    private function seedIbex(): Universe
    {
        $this->seed(Ibex35UniverseSeeder::class);

        return Universe::query()->where('slug', 'ibex35')->firstOrFail();
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
            $this->assertMatchesRegularExpression('/^[A-Z]{2,5}\.MC$/', $row['ticker']);
            $this->assertContains($row['sector'], ScreenerFilterParser::SECTORS, "Sector of {$row['ticker']}");
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $row['country'], "Country of {$row['ticker']}");
            $this->assertSame('BME', $row['exchange'], "Exchange of {$row['ticker']}");
            $this->assertNotSame('', $row['company']);
        }

        // Headquarters country, not listing country.
        $countries = array_column($rows, 'country', 'ticker');
        $this->assertSame('LU', $countries['MTS.MC']);
        $this->assertSame('NL', $countries['FER.MC']);
        $this->assertSame('ES', $countries['SAN.MC']);

        // The notes file documents source, date, hash and the rebalance procedure.
        $notes = (string) file_get_contents(base_path('database/data/ibex35.md'));
        $this->assertStringContainsString('2026-10-26', $notes);
        $this->assertStringContainsString('Rebalance procedure', $notes);
        $this->assertStringContainsString('SHA-256', $notes);
        $this->assertStringContainsString(hash_file('sha256', base_path('database/data/ibex35.csv')), $notes);
    }

    public function test_seeding_creates_the_xmad_eur_universe_after_the_nasdaq100_position(): void
    {
        $universe = $this->seedIbex();

        $expected = array_column($this->dataset(), 'ticker');
        sort($expected);

        $this->assertSame('IBEX 35', $universe->name);
        $this->assertSame('XMAD', $universe->market);
        $this->assertSame('EUR', $universe->currency);
        $this->assertTrue($universe->is_screener);
        $this->assertSame(2, $universe->position);
        $this->assertSame($expected, $universe->instruments()->orderBy('ticker')->pluck('ticker')->all());

        foreach (Instrument::query()->get() as $instrument) {
            $this->assertSame('XMAD', $instrument->market);
            $this->assertSame('EUR', $instrument->currency);
            $this->assertSame('BME', $instrument->exchange);
            $this->assertTrue($instrument->active);
        }
    }

    public function test_re_seeding_is_idempotent(): void
    {
        $this->seedIbex();
        $instruments = Instrument::query()->count();
        $memberships = DB::table('instrument_universe')->count();

        $this->seed(Ibex35UniverseSeeder::class);

        $this->assertSame(self::EXPECTED_ROWS, Instrument::query()->count());
        $this->assertSame($instruments, Instrument::query()->count());
        $this->assertSame($memberships, DB::table('instrument_universe')->count());
        $this->assertSame(1, Universe::query()->where('slug', 'ibex35')->count());
    }

    public function test_it_does_not_touch_the_sp500_instruments(): void
    {
        $this->seed(Sp500UniverseSeeder::class);
        $sp500 = Instrument::query()->count();

        $this->seedIbex();

        $this->assertSame($sp500 + self::EXPECTED_ROWS, Instrument::query()->count());
        $this->assertSame(0, Instrument::query()->whereIn('market', ['US'])->where('ticker', 'like', '%.MC')->count());
    }

    public function test_the_universe_is_not_public_by_default_but_admins_can_preview_it(): void
    {
        $this->seed(Sp500UniverseSeeder::class);
        $this->seedIbex();

        $this->assertNotContains('ibex35', config('universes.public'));

        $visitor = $this->getJson('/api/universes')->assertOk();
        $this->assertSame(['sp500'], array_column($visitor->json('universes'), 'slug'));
        $this->getJson('/api/screener?universe=ibex35')->assertNotFound();

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->getJson('/api/universes')->assertOk();
        $this->assertSame(['sp500', 'ibex35'], array_column($response->json('universes'), 'slug'));
        $this->assertSame('EUR', collect($response->json('universes'))->firstWhere('slug', 'ibex35')['currency']);
        $this->actingAs($admin)->getJson('/api/screener?universe=ibex35')->assertOk();
    }

    public function test_the_committed_madrid_holidays_are_weekdays_and_known_closures_are_skipped(): void
    {
        $holidays = config('ingestion.markets.XMAD.holidays');

        $this->assertNotEmpty($holidays);
        $this->assertSame(array_values(array_unique($holidays)), $holidays, 'Duplicate holiday.');

        $years = [];
        foreach ($holidays as $holiday) {
            $date = Carbon::parse($holiday);
            $this->assertTrue($date->isWeekday(), "{$holiday} is not a weekday.");
            $years[$date->format('Y')] = true;
        }
        $this->assertSame([2026, 2027], array_keys($years));

        $calendar = new MarketCalendar;

        // BME 2026: Good Friday and Easter Monday are closed; the 24 and 31 December
        // half days are trading days.
        $this->assertFalse($calendar->isTradingDay('XMAD', Carbon::parse('2026-04-03')));
        $this->assertFalse($calendar->isTradingDay('XMAD', Carbon::parse('2026-04-06')));
        $this->assertFalse($calendar->isTradingDay('XMAD', Carbon::parse('2026-12-25')));
        $this->assertTrue($calendar->isTradingDay('XMAD', Carbon::parse('2026-12-24')));
        $this->assertTrue($calendar->isTradingDay('XMAD', Carbon::parse('2026-12-31')));
        // 12 October (Spain's national day) is not a BME closure.
        $this->assertTrue($calendar->isTradingDay('XMAD', Carbon::parse('2026-10-12')));
        // Madrid-only: Easter Monday 2026 is a US trading day.
        $this->assertTrue($calendar->isTradingDay('US', Carbon::parse('2026-04-06')));
    }

    public function test_the_production_schedule_has_the_madrid_event_at_18_15(): void
    {
        $this->seedIbex();

        $schedule = new Schedule;
        app(PipelineSchedule::class)->register($schedule);
        $madrid = collect($schedule->events())->first(fn ($event) => $event->timezone === 'Europe/Madrid' && str_contains((string) $event->command, 'ingestion:pipeline'));

        $this->assertNotNull($madrid);
        $this->assertSame('15 18 * * 1-5', $madrid->expression);
        $this->assertMatchesRegularExpression('/--market=[\'"]?XMAD[\'"]?/', (string) $madrid->command);
    }

    public function test_the_xmad_pipeline_ingests_the_35_members_with_eur_and_skips_us_stages(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 18:15:00', 'Europe/Madrid'));
        $this->seedIbex();

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

        $this->artisan('ingestion:pipeline', ['--market' => 'XMAD', '--force' => true])
            ->doesntExpectOutputToContain('Alerts as of')
            ->assertExitCode(0);

        $requested = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/eod/'))
            ->map(fn (array $pair): string => basename(parse_url($pair[0]->url(), PHP_URL_PATH)));

        $this->assertSame(self::EXPECTED_ROWS, $requested->count());
        $this->assertSame(self::EXPECTED_ROWS, $requested->unique()->count());
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/eod/SAN.MC') && str_contains($request->url(), 'market=XMAD'));

        $run = IngestionRun::query()->whereHas('universe', fn ($q) => $q->where('slug', 'ibex35'))->sole();
        $this->assertSame(self::EXPECTED_ROWS, $run->total);
        $this->assertSame(self::EXPECTED_ROWS * 2, DailyBar::query()->count());
    }
}
