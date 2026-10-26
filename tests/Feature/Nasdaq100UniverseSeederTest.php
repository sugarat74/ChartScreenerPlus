<?php

namespace Tests\Feature;

use App\Models\DailyBar;
use App\Models\IngestionRun;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\ScreenerRow;
use App\Models\Universe;
use App\Models\User;
use App\Services\Screener\ScreenerFilterParser;
use Database\Seeders\Nasdaq100UniverseSeeder;
use Database\Seeders\Sp500UniverseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * universe-nasdaq100 (docs/specs/multi-market-universes.md, Feature 4): the
 * committed, dated Nasdaq-100 snapshot and its seeder. Offline: the committed
 * CSV only, and a faked engine.
 */
class Nasdaq100UniverseSeederTest extends TestCase
{
    use RefreshDatabase;

    /** Securities in the snapshot: 100 companies, Alphabet with two share classes. */
    private const EXPECTED_ROWS = 101;

    /** Members that are not S&P 500 constituents at the snapshot date. */
    private const NEW_MEMBERS = [
        'ALAB', 'ALNY', 'ARM', 'ASML', 'CCEP', 'CRWV', 'FER', 'MELI',
        'MSTR', 'NBIS', 'PDD', 'RKLB', 'SHOP', 'SPCX', 'TRI',
    ];

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
        $handle = fopen(base_path('database/data/nasdaq100.csv'), 'rb');
        $header = fgetcsv($handle, null, ',', '"', '');
        $rows = [];

        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($header, $cells);
        }

        fclose($handle);

        return $rows;
    }

    private function seedBoth(): Universe
    {
        $this->seed(Sp500UniverseSeeder::class);
        $this->seed(Nasdaq100UniverseSeeder::class);

        return Universe::query()->where('slug', 'nasdaq100')->firstOrFail();
    }

    public function test_the_committed_snapshot_has_the_expected_shape(): void
    {
        $rows = $this->dataset();
        $tickers = array_column($rows, 'ticker');

        $this->assertCount(self::EXPECTED_ROWS, $rows);
        $this->assertCount(self::EXPECTED_ROWS, array_unique($tickers), 'Duplicate tickers in the snapshot.');
        $this->assertContains('GOOGL', $tickers);
        $this->assertContains('GOOG', $tickers);

        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression('/^[A-Z]{1,5}$/', $row['ticker']);
            $this->assertContains($row['sector'], ScreenerFilterParser::SECTORS, "Sector of {$row['ticker']}");
            $this->assertMatchesRegularExpression('/^([A-Z]{2})?$/', $row['country'], "Country of {$row['ticker']}");
            $this->assertSame('NASDAQ', $row['exchange'], "Exchange of {$row['ticker']}");
        }

        // The notes file documents source, date, hash and the rebalance procedure.
        $notes = (string) file_get_contents(base_path('database/data/nasdaq100.md'));
        $this->assertStringContainsString('2026-10-26', $notes);
        $this->assertStringContainsString('Rebalance procedure', $notes);
        $this->assertStringContainsString('SHA-256', $notes);
    }

    public function test_seeding_creates_the_us_usd_universe_after_the_sp500(): void
    {
        $universe = $this->seedBoth();

        $expected = array_column($this->dataset(), 'ticker');
        sort($expected);

        $this->assertSame('Nasdaq-100', $universe->name);
        $this->assertSame('US', $universe->market);
        $this->assertSame('USD', $universe->currency);
        $this->assertTrue($universe->is_screener);
        $this->assertSame(1, $universe->position);
        $this->assertSame(self::EXPECTED_ROWS, $universe->instruments()->count());
        $this->assertSame($expected, $universe->instruments()->orderBy('ticker')->pluck('ticker')->all());
    }

    public function test_overlapping_members_are_the_existing_sp500_instruments_with_unchanged_fields(): void
    {
        $this->seed(Sp500UniverseSeeder::class);
        $before = Instrument::query()->orderBy('id')->get()->map(fn ($i) => $i->getAttributes())->all();
        $sp500Count = count($before);

        $nasdaq = $this->seedBoth();

        // Only the non-S&P members were created; nobody was duplicated or changed.
        $this->assertSame($sp500Count + count(self::NEW_MEMBERS), Instrument::query()->count());
        $this->assertSame(
            $before,
            Instrument::query()->orderBy('id')->limit($sp500Count)->get()->map(fn ($i) => $i->getAttributes())->all(),
        );
        $this->assertSame(0, Instrument::query()->select('ticker')->groupBy('ticker')->havingRaw('count(*) > 1')->get()->count());

        $overlap = array_diff(array_column($this->dataset(), 'ticker'), self::NEW_MEMBERS);
        $this->assertCount(86, $overlap);

        foreach ($overlap as $ticker) {
            $instrument = Instrument::query()->where('ticker', $ticker)->sole();
            $this->assertLessThanOrEqual(
                $before[$sp500Count - 1]['id'],
                $instrument->id,
                "{$ticker} must be the S&P 500 Instrument.",
            );
            $this->assertSame(
                ['nasdaq100', 'sp500'],
                $instrument->universes()->orderBy('slug')->pluck('slug')->all(),
                $ticker,
            );
        }

        // The two Alphabet share classes are two Instruments in both universes.
        $this->assertSame(2, $nasdaq->instruments()->whereIn('ticker', ['GOOG', 'GOOGL'])->count());
    }

    public function test_non_sp500_members_are_classified_as_us_usd_instruments(): void
    {
        $this->seedBoth();

        $new = Instrument::query()->whereIn('ticker', self::NEW_MEMBERS)->get();
        $this->assertCount(count(self::NEW_MEMBERS), $new);

        foreach ($new as $instrument) {
            $this->assertSame('US', $instrument->market);
            $this->assertSame('USD', $instrument->currency);
            $this->assertSame('NASDAQ', $instrument->exchange);
            $this->assertContains($instrument->sector, ScreenerFilterParser::SECTORS);
            $this->assertTrue($instrument->active);
            $this->assertSame(['nasdaq100'], $instrument->universes()->pluck('slug')->all());
        }

        // Headquarters country, not listing country.
        $this->assertSame('NL', Instrument::query()->where('ticker', 'ASML')->sole()->country);
        $this->assertSame('GB', Instrument::query()->where('ticker', 'ARM')->sole()->country);
    }

    public function test_re_seeding_is_idempotent_and_each_instrument_has_one_screener_row(): void
    {
        $this->seedBoth();
        $instruments = Instrument::query()->count();
        $memberships = DB::table('instrument_universe')->count();

        $aapl = Instrument::query()->where('ticker', 'AAPL')->sole();
        DailyBar::factory()->create(['instrument_id' => $aapl->id, 'date' => '2026-10-01']);
        IndicatorSnapshot::factory()->create(['instrument_id' => $aapl->id, 'date' => '2026-10-01']);

        $this->seed(Nasdaq100UniverseSeeder::class);

        $this->assertSame($instruments, Instrument::query()->count());
        $this->assertSame($memberships, DB::table('instrument_universe')->count());
        $this->assertSame(1, Universe::query()->where('slug', 'nasdaq100')->count());

        // One Instrument, one Screener row, listed by both universes.
        $this->assertSame(1, ScreenerRow::query()->where('instrument_id', $aapl->id)->count());
        $admin = User::factory()->admin()->create();

        foreach (['sp500', 'nasdaq100'] as $slug) {
            $tickers = array_column(
                $this->actingAs($admin)->getJson("/api/screener?universe={$slug}")->assertOk()->json('candidates'),
                'ticker',
            );
            $this->assertSame(['AAPL'], $tickers, $slug);
        }
    }

    public function test_the_universe_is_not_public_by_default_but_admins_can_preview_it(): void
    {
        $this->seedBoth();

        $this->assertNotContains('nasdaq100', config('universes.public'));

        $visitor = $this->getJson('/api/universes')->assertOk();
        $this->assertSame(['sp500'], array_column($visitor->json('universes'), 'slug'));
        $this->getJson('/api/screener?universe=nasdaq100')->assertNotFound();

        $admin = User::factory()->admin()->create();
        $response = $this->actingAs($admin)->getJson('/api/universes')->assertOk();
        $this->assertSame(['sp500', 'nasdaq100'], array_column($response->json('universes'), 'slug'));
        $this->actingAs($admin)->getJson('/api/screener?universe=nasdaq100')->assertOk();
    }

    public function test_the_us_pipeline_fetches_only_the_non_sp500_members_of_the_nasdaq100(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 16:30:00', 'America/New_York'));
        $this->seedBoth();

        $bars = [];
        foreach ([0, 1, 2] as $index) {
            $bars[] = [
                'date' => Carbon::parse('2026-07-13')->addDays($index)->toDateString(),
                'open' => 10.0, 'high' => 11.0, 'low' => 9.0, 'close' => 10.5, 'volume' => 1000,
            ];
        }

        Http::fake([
            '*/eod/*' => Http::response(['symbol' => 'X', 'bars' => $bars, 'currency' => 'USD']),
            '*/indicators/compute' => Http::response(['snapshots' => []]),
            '*/signals/detect' => Http::response(['signals' => []]),
            '*/patterns/detect' => Http::response(['patterns' => []]),
            '*' => Http::response([], 200),
        ]);

        $this->artisan('ingestion:pipeline', ['--force' => true]);

        $requested = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/eod/'))
            ->map(fn (array $pair): string => basename(parse_url($pair[0]->url(), PHP_URL_PATH)));

        // One fetch per Instrument: 503 S&P 500 members plus the 15 new Nasdaq-100 ones.
        $this->assertSame(Instrument::query()->count(), $requested->count());
        $this->assertSame($requested->count(), $requested->unique()->count(), 'An Instrument was fetched twice.');

        $run = IngestionRun::query()->whereHas('universe', fn ($q) => $q->where('slug', 'nasdaq100'))->sole();
        $this->assertSame(count(self::NEW_MEMBERS), $run->total);

        // One set of bars per Instrument: an overlapping member has 3 bars, not 6.
        $aapl = Instrument::query()->where('ticker', 'AAPL')->sole();
        $this->assertSame(3, DailyBar::query()->where('instrument_id', $aapl->id)->count());
    }
}
