<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\MarketRegimeSnapshot;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `market-regime:compute` (docs/specs/market-regime.md, Scenario 8): options,
 * self-seed, the first-snapshot floor, idempotency and exits.
 *
 * Fixture: one `sp500` stock with bars on 7 consecutive days (2026-10-05 ..
 * 2026-10-11; 1 of 1 member = 100 % coverage, so all 7 are sessions) and
 * Breadth (65.0, uptrend) plus 11 sector rows on the last 6 sessions
 * (10-06 .. 10-11). Macro is disabled (phpunit.xml), so every row has at most
 * the 3 market components and is `insufficient`.
 */
class MarketRegimeComputeCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const SESSIONS = ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'];

    private Universe $main;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['ingestion.universe' => 'sp500']);
        $this->main = Universe::factory()->create(['slug' => 'sp500', 'name' => 'S&P 500']);
        $stock = Instrument::factory()->create(['ticker' => 'AAA']);
        $this->main->instruments()->attach($stock->id);

        foreach (self::SESSIONS as $i => $date) {
            DB::table('daily_bars')->insert([
                'instrument_id' => $stock->id,
                'date' => Carbon::parse($date)->startOfDay(),
                'open' => 100 + $i,
                'high' => 101 + $i,
                'low' => 99 + $i,
                'close' => 100 + $i,
                'volume' => 1000,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function snapshots(): void
    {
        $etfs = [];
        for ($i = 1; $i <= 11; $i++) {
            $etfs[] = Instrument::factory()->etf()->create(['ticker' => sprintf('XS%02d', $i), 'sector' => "Sector {$i}"]);
        }

        foreach (array_slice(self::SESSIONS, 1) as $date) {
            DB::table('market_breadth_snapshots')->insert([
                'universe_id' => $this->main->id,
                'date' => Carbon::parse($date)->startOfDay(),
                'members' => 1,
                'pct_above_sma200' => '65.0000',
                'advancers' => 1,
                'decliners' => 0,
                'spy_trend' => 'uptrend',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($etfs as $etf) {
                DB::table('market_sector_snapshots')->insert([
                    'date' => Carbon::parse($date)->startOfDay(),
                    'instrument_id' => $etf->id,
                    'sector' => $etf->sector,
                    'trend' => 'uptrend',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Every row without its timestamps, by date.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return DB::table('market_regime_snapshots')
            ->orderBy('date')
            ->get(['id', 'date', 'regime', 'score', 'available', 'components', 'rules_version'])
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    public function test_an_empty_table_self_seeds_from_the_first_snapshot_and_a_rerun_changes_nothing(): void
    {
        $this->snapshots();

        $this->artisan('market-regime:compute')
            ->expectsOutputToContain('Market regime computed for 6 session(s) (2026-10-06 to 2026-10-11); latest 2026-10-11: insufficient (score n/a, 3 available).')
            ->assertExitCode(0);

        $this->assertSame(6, MarketRegimeSnapshot::query()->count());
        $this->assertSame('2026-10-06', MarketRegimeSnapshot::query()->orderBy('date')->first()->date->toDateString());
        $before = $this->rows();

        // Not empty any more: the default run recomputes the latest 5 sessions.
        $this->artisan('market-regime:compute')
            ->expectsOutputToContain('Market regime computed for 5 session(s) (2026-10-07 to 2026-10-11)')
            ->assertExitCode(0);

        $this->assertSame($before, $this->rows());

        $latest = MarketRegimeSnapshot::query()->whereDate('date', '2026-10-11')->sole();
        // 65 >= 60 +1, uptrend +1, 11 of 11 sectors up +1; macro disabled.
        $this->assertSame(['ok', 1, 65.0], [$latest->components['breadth']['status'], $latest->components['breadth']['score'], $latest->components['breadth']['value']]);
        $this->assertSame(11, $latest->components['sector_participation']['value']);
        $this->assertSame('disabled', $latest->components['liquidity']['status']);
    }

    public function test_from_before_the_first_snapshot_starts_at_it(): void
    {
        $this->snapshots();

        $this->artisan('market-regime:compute', ['--from' => '2026-10-05'])
            ->expectsOutputToContain('Starting at the first market snapshot (2026-10-06).')
            ->expectsOutputToContain('Market regime computed for 6 session(s) (2026-10-06 to 2026-10-11)')
            ->assertExitCode(0);

        $this->assertSame(6, MarketRegimeSnapshot::query()->count());
    }

    public function test_from_and_date_narrow_the_sessions(): void
    {
        $this->snapshots();

        $this->artisan('market-regime:compute', ['--from' => '2026-10-09'])
            ->expectsOutputToContain('Market regime computed for 3 session(s) (2026-10-09 to 2026-10-11)')
            ->assertExitCode(0);

        $this->artisan('market-regime:compute', ['--date' => '2026-10-07'])
            ->expectsOutputToContain('Market regime computed for 1 session(s) (2026-10-07 to 2026-10-07)')
            ->assertExitCode(0);

        $this->assertSame(4, MarketRegimeSnapshot::query()->count());
    }

    public function test_a_date_before_the_first_snapshot_or_not_a_session_fails(): void
    {
        $this->snapshots();

        $this->artisan('market-regime:compute', ['--date' => '2026-10-05'])
            ->expectsOutputToContain('2026-10-05 is before the first market snapshot (2026-10-06).')
            ->assertExitCode(1);

        $this->artisan('market-regime:compute', ['--date' => '2026-10-12'])
            ->expectsOutputToContain('2026-10-12 is not a market session of the main universe.')
            ->assertExitCode(1);

        $this->assertSame(0, MarketRegimeSnapshot::query()->count());
    }

    public function test_malformed_or_combined_options_fail(): void
    {
        $this->snapshots();

        $this->artisan('market-regime:compute', ['--date' => '2026-13-01'])
            ->expectsOutputToContain('--date and --from must be dates in Y-m-d format.')
            ->assertExitCode(1);

        $this->artisan('market-regime:compute', ['--from' => '10/06/2026'])
            ->expectsOutputToContain('--date and --from must be dates in Y-m-d format.')
            ->assertExitCode(1);

        $this->artisan('market-regime:compute', ['--date' => '2026-10-07', '--from' => '2026-10-06'])
            ->expectsOutputToContain('Use either --date or --from, not both.')
            ->assertExitCode(1);

        $this->assertSame(0, MarketRegimeSnapshot::query()->count());
    }

    public function test_no_market_snapshot_yet_is_a_successful_no_op(): void
    {
        $this->artisan('market-regime:compute')
            ->expectsOutputToContain('No market snapshots yet; nothing to compute.')
            ->assertExitCode(0);

        $this->assertSame(0, MarketRegimeSnapshot::query()->count());
    }

    public function test_a_missing_main_universe_fails(): void
    {
        config(['ingestion.universe' => 'unknown']);

        $this->artisan('market-regime:compute')
            ->expectsOutputToContain('No universe found for [unknown].')
            ->assertExitCode(1);
    }
}
