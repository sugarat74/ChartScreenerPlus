<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\MarketBreadthSnapshot;
use App\Models\MarketSectorSnapshot;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `market-context:compute` (docs/specs/market-context-sectors.md, scenarios
 * 1-3 and the session/benchmark/validation edge cases). Offline fixtures with
 * hand-computed figures.
 *
 * Fixture: 260 consecutive daily bars (index 0..259, day 0 = 2025-01-01); the
 * computed session `d` is index 259. Stocks of `sp500`:
 * - T1 (Information Technology): close 100 + i, high close + 1, low close - 1;
 *   on d close 359 > sma50 330 > sma200 250; high 360 >= max of the previous
 *   251 highs (359): new high; advancer.
 * - T2 (Information Technology): close 400 - i; on d close 141 < sma50 160,
 *   < sma200 250; low 140 <= min of the previous 251 lows (141): new low;
 *   decliner.
 * - E1 (Energy): flat close 50, high 51, low 49, except a high of 60 at index 7
 *   (= d - 252, just outside the 251-bar window): still a new high (51 >= 51)
 *   and a new low (49 <= 49); unchanged close counts in neither; close 50 is
 *   not above sma50 50, is above sma200 40.
 * - E2 (Energy): close 30 until index 258, 31 on d (high 32 >= 31: new high;
 *   low 30 > 29: no new low); advancer; sma50 31 (not above), sma200 null.
 *
 * Breadth on d: members 4; above SMA50 1/4 = 25.0000; above SMA200 2/3 =
 * 66.6667; new highs 3, new lows 2 (4 eligible); advancers 2, decliners 1.
 * Sector breadth_200: Information Technology 1/2 = 50.0000, Energy 1/1 =
 * 100.0000.
 *
 * ETFs of `market-context` (step closes; the value at an index holds until
 * the next breakpoint): SPY d-60 80, d-20 125, d-5 50, d 100 -> ret_60 25,
 * ret_20 -20, ret_5 100; sma50 90 / sma200 80 -> uptrend.
 * XLK d-60 50, d-20 80, d-5 200, d 100 -> ret_60 100, ret_20 25, ret_5 -50;
 * rs_20 = 25 - (-20) = 45, rs_60 = 100 - 25 = 75; sma50 110 / sma200 120 ->
 * downtrend. XLE d-60 100, d-20 90, d-5 80, d 75 -> ret_60 -25, ret_20
 * 75/90 - 1 = -16.6667, ret_5 -6.25; rs_20 = -16.6667 - (-20) = 3.3333,
 * rs_60 = -25 - 25 = -50; sma50 70 / sma200 80 -> mixed. rs_rank: XLK 1, XLE 2.
 */
class MarketContextComputeCommandTest extends TestCase
{
    use RefreshDatabase;

    private const START = '2025-01-01';

    private const BARS = 260;

    private Universe $main;

    private Universe $market;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingestion.universe' => 'sp500', 'market_context.universe' => 'market-context', 'market_context.benchmark' => 'SPY']);
        $this->main = Universe::factory()->create(['slug' => 'sp500', 'name' => 'S&P 500']);
        $this->market = Universe::factory()->create(['slug' => 'market-context', 'name' => 'Market Context']);
    }

    private function day(int $index): string
    {
        return Carbon::parse(self::START)->addDays($index)->toDateString();
    }

    private function d(): string
    {
        return $this->day(self::BARS - 1);
    }

    private function stock(string $ticker, string $sector): Instrument
    {
        $instrument = Instrument::factory()->create(['ticker' => $ticker, 'sector' => $sector]);
        $this->main->instruments()->attach($instrument->id);

        return $instrument;
    }

    private function etf(string $ticker, ?string $sector): Instrument
    {
        $instrument = Instrument::factory()->etf()->create(['ticker' => $ticker, 'sector' => $sector]);
        $this->market->instruments()->attach($instrument->id);

        return $instrument;
    }

    /**
     * Insert bars; `$closes`, `$highs`, `$lows` are keyed by day index.
     *
     * @param  array<int, float>  $closes
     * @param  array<int, float>  $highs
     * @param  array<int, float>  $lows
     */
    private function bars(Instrument $instrument, array $closes, array $highs = [], array $lows = []): void
    {
        $rows = [];
        foreach ($closes as $index => $close) {
            $rows[] = [
                'instrument_id' => $instrument->id,
                'date' => Carbon::parse($this->day($index))->startOfDay(),
                'open' => $close,
                'high' => $highs[$index] ?? $close + 1,
                'low' => $lows[$index] ?? $close - 1,
                'close' => $close,
                'volume' => 1000,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('daily_bars')->insert($chunk);
        }
    }

    /**
     * Step closes over `$count` bars ending on d: each breakpoint (an offset
     * back from d) sets the close from that bar on.
     *
     * @param  array<int, float>  $breakpoints  sessions-back => close
     * @return array<int, float>
     */
    private function steps(array $breakpoints, int $count = self::BARS): array
    {
        krsort($breakpoints);
        $closes = [];
        $first = self::BARS - $count;
        $value = reset($breakpoints);

        for ($index = $first; $index < self::BARS; $index++) {
            $back = self::BARS - 1 - $index;
            if (array_key_exists($back, $breakpoints)) {
                $value = $breakpoints[$back];
            }
            $closes[$index] = $value;
        }

        return $closes;
    }

    private function snapshot(Instrument $instrument, ?float $sma50, ?float $sma200, ?string $date = null): void
    {
        DB::table('indicator_snapshots')->insert([
            'instrument_id' => $instrument->id,
            'date' => Carbon::parse($date ?? $this->d())->startOfDay(),
            'sma50' => $sma50,
            'sma200' => $sma200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fixture(): void
    {
        $range = range(0, self::BARS - 1);

        $t1 = $this->stock('T1', 'Information Technology');
        $this->bars($t1, array_combine($range, array_map(fn (int $i): float => 100.0 + $i, $range)));
        $this->snapshot($t1, 330, 250);

        $t2 = $this->stock('T2', 'Information Technology');
        $this->bars($t2, array_combine($range, array_map(fn (int $i): float => 400.0 - $i, $range)));
        $this->snapshot($t2, 160, 250);

        $e1 = $this->stock('E1', 'Energy');
        $this->bars($e1, array_fill(0, self::BARS, 50.0), [7 => 60.0]);
        $this->snapshot($e1, 50, 40);

        $e2 = $this->stock('E2', 'Energy');
        $closes = array_fill(0, self::BARS, 30.0);
        $closes[self::BARS - 1] = 31.0;
        $this->bars($e2, $closes);
        $this->snapshot($e2, 31, null);

        $spy = $this->etf('SPY', null);
        $this->bars($spy, $this->steps([60 => 80, 20 => 125, 5 => 50, 0 => 100]));
        $this->snapshot($spy, 90, 80);

        $xlk = $this->etf('XLK', 'Information Technology');
        $this->bars($xlk, $this->steps([60 => 50, 20 => 80, 5 => 200, 0 => 100]));
        $this->snapshot($xlk, 110, 120);

        $xle = $this->etf('XLE', 'Energy');
        $this->bars($xle, $this->steps([60 => 100, 20 => 90, 5 => 80, 0 => 75]));
        $this->snapshot($xle, 70, 80);
    }

    private function sector(string $ticker, ?string $date = null): ?MarketSectorSnapshot
    {
        return MarketSectorSnapshot::query()
            ->whereHas('instrument', fn ($query) => $query->where('ticker', $ticker))
            ->whereDate('date', $date ?? $this->d())
            ->first();
    }

    private function breadth(?string $date = null): ?MarketBreadthSnapshot
    {
        return MarketBreadthSnapshot::query()->whereDate('date', $date ?? $this->d())->first();
    }

    /** @return array<string, mixed> */
    private function sectorValues(MarketSectorSnapshot $row): array
    {
        return $row->only(['sector', 'ret_5', 'ret_20', 'ret_60', 'rs_20', 'rs_60', 'rs_rank', 'trend', 'breadth_200']);
    }

    public function test_it_stores_the_hand_computed_sector_and_breadth_figures(): void
    {
        $this->fixture();

        $this->artisan('market-context:compute', ['--date' => $this->d()])
            ->expectsOutputToContain('Market context computed for 1 session(s) ('.$this->d().' to '.$this->d().'): 2 sector row(s), 1 breadth row(s).')
            ->assertExitCode(0);

        $this->assertSame([
            'sector' => 'Information Technology',
            'ret_5' => '-50.0000',
            'ret_20' => '25.0000',
            'ret_60' => '100.0000',
            'rs_20' => '45.0000',
            'rs_60' => '75.0000',
            'rs_rank' => 1,
            'trend' => 'downtrend',
            'breadth_200' => '50.0000',
        ], $this->sectorValues($this->sector('XLK')));

        $this->assertSame([
            'sector' => 'Energy',
            'ret_5' => '-6.2500',
            'ret_20' => '-16.6667',
            'ret_60' => '-25.0000',
            'rs_20' => '3.3333',
            'rs_60' => '-50.0000',
            'rs_rank' => 2,
            'trend' => 'mixed',
            'breadth_200' => '100.0000',
        ], $this->sectorValues($this->sector('XLE')));

        // SPY is the benchmark: no sector row; its trend lives in the Breadth row.
        $this->assertNull($this->sector('SPY'));
        $this->assertDatabaseCount('market_sector_snapshots', 2);

        $breadth = $this->breadth();
        $this->assertSame($this->main->id, $breadth->universe_id);
        $this->assertSame([
            'members' => 4,
            'pct_above_sma50' => '25.0000',
            'pct_above_sma200' => '66.6667',
            'new_highs' => 3,
            'new_lows' => 2,
            'advancers' => 2,
            'decliners' => 1,
            'spy_trend' => 'uptrend',
        ], $breadth->only(['members', 'pct_above_sma50', 'pct_above_sma200', 'new_highs', 'new_lows', 'advancers', 'decliners', 'spy_trend']));
        $this->assertDatabaseCount('market_breadth_snapshots', 1);
    }

    public function test_missing_inputs_give_nulls_never_guesses(): void
    {
        $this->fixture();

        // A stock with only 100 bars (a rising series, so it would be a new
        // high if it were eligible) and a sector ETF with 30 bars and no SMA200.
        $short = $this->stock('SHORT', 'Energy');
        $this->bars($short, array_combine(range(160, 259), array_map(fn (int $i): float => 10.0 + $i, range(160, 259))));
        $this->snapshot($short, 1, null);

        $xlf = $this->etf('XLF', 'Financials');
        $this->bars($xlf, $this->steps([29 => 40, 20 => 50, 5 => 40, 0 => 44], 30));
        $this->snapshot($xlf, 42, null);

        $this->artisan('market-context:compute', ['--date' => $this->d()])->assertExitCode(0);

        $xlf = $this->sector('XLF');
        $this->assertNull($xlf->trend);
        $this->assertNull($xlf->ret_60);
        $this->assertNull($xlf->rs_60);
        $this->assertNull($xlf->rs_rank);
        // 30 bars are enough for the shorter periods: 44/40 - 1 and 44/50 - 1.
        $this->assertSame('10.0000', $xlf->ret_5);
        $this->assertSame('-12.0000', $xlf->ret_20);
        $this->assertSame('8.0000', $xlf->rs_20);
        // No Financials stock in the main universe: an empty denominator is null.
        $this->assertNull($xlf->breadth_200);

        // The ranking ignores the null rs_60.
        $this->assertSame(1, $this->sector('XLK')->rs_rank);
        $this->assertSame(2, $this->sector('XLE')->rs_rank);

        $breadth = $this->breadth();
        $this->assertSame(5, $breadth->members);
        // SHORT is not eligible for new highs/lows (fewer than 252 bars)...
        $this->assertSame(3, $breadth->new_highs);
        $this->assertSame(2, $breadth->new_lows);
        // ...but still counts as an advancer and above its SMA50.
        $this->assertSame(3, $breadth->advancers);
        $this->assertSame('40.0000', $breadth->pct_above_sma50);
    }

    public function test_an_etf_without_a_snapshot_has_a_null_trend_and_no_eligible_member_gives_null_highs(): void
    {
        $stock = $this->stock('ONLY', 'Energy');
        $this->bars($stock, $this->steps([0 => 10], 10));
        $spy = $this->etf('SPY', null);
        $this->bars($spy, $this->steps([0 => 100], 10));
        $xle = $this->etf('XLE', 'Energy');
        $this->bars($xle, $this->steps([0 => 50], 10));

        $this->artisan('market-context:compute', ['--date' => $this->d()])->assertExitCode(0);

        $this->assertNull($this->sector('XLE')->trend);
        $this->assertNull($this->sector('XLE')->breadth_200);
        $breadth = $this->breadth();
        $this->assertNull($breadth->spy_trend);
        $this->assertNull($breadth->new_highs);
        $this->assertNull($breadth->new_lows);
        $this->assertNull($breadth->pct_above_sma50);
        $this->assertNull($breadth->pct_above_sma200);
        $this->assertSame(0, $breadth->advancers);
        $this->assertSame(0, $breadth->decliners);
    }

    public function test_reruns_are_idempotent_and_from_backfills_every_session(): void
    {
        $this->fixture();

        $this->artisan('market-context:compute', ['--date' => $this->d()])->assertExitCode(0);
        $before = [$this->sectorValues($this->sector('XLK')), $this->sectorValues($this->sector('XLE')), $this->breadth()->toArray()];

        $this->artisan('market-context:compute', ['--date' => $this->d()])->assertExitCode(0);
        $this->assertDatabaseCount('market_sector_snapshots', 2);
        $this->assertDatabaseCount('market_breadth_snapshots', 1);
        $after = [$this->sectorValues($this->sector('XLK')), $this->sectorValues($this->sector('XLE')), $this->breadth()->toArray()];
        unset($before[2]['updated_at'], $after[2]['updated_at']);
        $this->assertSame($before, $after);

        // Backfill from d - 10 sessions: 11 sessions, 2 sector ETFs each.
        $this->artisan('market-context:compute', ['--from' => $this->day(self::BARS - 11)])
            ->expectsOutputToContain('Market context computed for 11 session(s)')
            ->assertExitCode(0);
        $this->assertDatabaseCount('market_breadth_snapshots', 11);
        $this->assertDatabaseCount('market_sector_snapshots', 22);
        $this->assertSame(
            array_map(fn (int $i): string => $this->day($i), range(self::BARS - 11, self::BARS - 1)),
            MarketBreadthSnapshot::query()->orderBy('date')->get()->map(fn (MarketBreadthSnapshot $row): string => $row->date->toDateString())->all(),
        );
        $this->assertSame([$before[0], $before[1]], [$this->sectorValues($this->sector('XLK')), $this->sectorValues($this->sector('XLE'))]);

        // A past session uses only its own history: on d - 1, XLK's close is
        // 200 (the d - 5 step) against 50 twenty bars back (d - 21).
        $this->assertSame('300.0000', $this->sector('XLK', $this->day(self::BARS - 2))->ret_20);

        // The rerun of the backfill changes no count.
        $this->artisan('market-context:compute', ['--from' => $this->day(self::BARS - 11)])->assertExitCode(0);
        $this->assertDatabaseCount('market_breadth_snapshots', 11);
        $this->assertDatabaseCount('market_sector_snapshots', 22);
    }

    public function test_the_default_run_recomputes_the_latest_five_sessions_and_ignores_a_stray_date(): void
    {
        $this->fixture();
        // One stock has a bar on an extra day: 1 of 4 members is below the 50 % coverage.
        $this->bars(Instrument::query()->where('ticker', 'T1')->sole(), [self::BARS => 500.0]);

        $this->artisan('market-context:compute')
            ->expectsOutputToContain('Market context computed for 5 session(s) ('.$this->day(self::BARS - 5).' to '.$this->d().')')
            ->assertExitCode(0);

        $this->assertDatabaseCount('market_breadth_snapshots', 5);
        $this->assertNull($this->breadth($this->day(self::BARS)));

        $this->artisan('market-context:compute', ['--date' => $this->day(self::BARS)])
            ->expectsOutputToContain('is not a market session')
            ->assertExitCode(1);
    }

    public function test_sector_rows_are_skipped_when_the_benchmark_has_no_bar(): void
    {
        $this->fixture();
        DB::table('daily_bars')
            ->where('instrument_id', Instrument::query()->where('ticker', 'SPY')->value('id'))
            ->whereDate('date', $this->d())
            ->delete();

        $this->artisan('market-context:compute', ['--date' => $this->d()])
            ->expectsOutputToContain('0 sector row(s), 1 breadth row(s)')
            ->expectsOutputToContain($this->d().': the benchmark has no bar; sector rows skipped.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('market_sector_snapshots', 0);
        $this->assertNull($this->breadth()->spy_trend);
        $this->assertSame(4, $this->breadth()->members);
    }

    public function test_stored_dates_match_the_daily_bar_format_and_etfs_are_not_breadth_members(): void
    {
        $this->fixture();

        $this->artisan('market-context:compute', ['--date' => $this->d()])->assertExitCode(0);

        // Dates are written like daily_bars (startOfDay), so both read back the same way.
        $this->assertSame(
            DB::table('daily_bars')->whereDate('date', $this->d())->value('date'),
            DB::table('market_breadth_snapshots')->value('date'),
        );
        $this->assertSame(
            DB::table('market_breadth_snapshots')->value('date'),
            DB::table('market_sector_snapshots')->where('instrument_id', $this->sector('XLK')->instrument_id)->value('date'),
        );
        // The 3 ETFs have bars on d but only the 4 stocks are members.
        $this->assertSame(4, $this->breadth()->members);
    }

    public function test_invalid_options_and_missing_universes_exit_one(): void
    {
        $this->fixture();

        $this->artisan('market-context:compute', ['--date' => $this->d(), '--from' => $this->d()])
            ->expectsOutputToContain('either --date or --from')
            ->assertExitCode(1);
        $this->artisan('market-context:compute', ['--date' => '2025/09/17'])->assertExitCode(1);
        $this->artisan('market-context:compute', ['--from' => '2025-02-30'])->assertExitCode(1);

        config(['ingestion.universe' => 'missing']);
        $this->artisan('market-context:compute')->expectsOutputToContain('No universe found for [missing]')->assertExitCode(1);

        config(['ingestion.universe' => 'sp500']);
        $this->market->delete();
        $this->artisan('market-context:compute')->expectsOutputToContain('No universe found for [market-context]')->assertExitCode(1);

        $this->assertDatabaseCount('market_breadth_snapshots', 0);
    }
}
