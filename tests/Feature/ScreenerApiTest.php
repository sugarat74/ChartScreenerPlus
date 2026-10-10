<?php

namespace Tests\Feature;

use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\Signal;
use App\Models\Universe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contract tests for the public, read-only screener endpoint.
 *
 * The endpoint is anonymous by design (`docs/user-and-access-model.md`) and is
 * the server-side ranking contract `screener-filters-ui` / `candidate-list-
 * ranking` consume: AND-combined filters, an OR signal list, deterministic
 * nulls-last sorts with a ticker tie-break, a clamped `limit`, and a JSON
 * `404` for an unknown universe.
 */
class ScreenerApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The universe slug the screener defaults to (`config('ingestion.universe')`).
     */
    private const DEFAULT_UNIVERSE = 'sp500';

    private function universe(string $slug = self::DEFAULT_UNIVERSE, string $name = 'S&P 500'): Universe
    {
        return Universe::factory()->create(['slug' => $slug, 'name' => $name]);
    }

    /**
     * Create one universe member with sane market-data defaults.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function member(Universe $universe, string $ticker, array $attributes = []): Instrument
    {
        $instrument = Instrument::factory()->create(array_merge([
            'ticker' => $ticker,
            'company' => $ticker.' Holdings',
            'sector' => 'Information Technology',
            'exchange' => 'NASDAQ',
            'active' => true,
        ], $attributes));

        $universe->instruments()->attach($instrument->id);

        return $instrument;
    }

    /**
     * Create one daily bar with a flat intrabar range.
     */
    private function bar(Instrument $instrument, string $date, float $close): DailyBar
    {
        return DailyBar::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => $date,
            'open' => $close,
            'high' => $close,
            'low' => $close,
            'close' => $close,
            'volume' => 1_000_000,
        ]);
    }

    /**
     * Create a latest bar and the previous bar used for `change_percent`.
     */
    private function bars(Instrument $instrument, float $previousClose, float $latestClose): void
    {
        $this->bar($instrument, '2026-09-25', $previousClose);
        $this->bar($instrument, '2026-09-28', $latestClose);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function snapshot(Instrument $instrument, array $attributes = [], string $date = '2026-09-28'): IndicatorSnapshot
    {
        return IndicatorSnapshot::factory()->create(array_merge([
            'instrument_id' => $instrument->id,
            'date' => $date,
            'rsi14' => 50,
            'rvol' => 1,
            'sma50' => 100,
            'sma200' => 90,
        ], $attributes));
    }

    private function signal(Instrument $instrument, string $type, string $date = '2026-09-28'): Signal
    {
        return Signal::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => $date,
            'type' => $type,
            'metadata' => [],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return list<string>
     */
    private function tickers(array $candidates): array
    {
        return array_column($candidates, 'ticker');
    }

    public function test_returns_ranked_candidates_for_the_default_universe(): void
    {
        $universe = $this->universe();

        $apple = $this->member($universe, 'AAPL');
        $microsoft = $this->member($universe, 'MSFT');
        $nvidia = $this->member($universe, 'NVDA');

        $this->bars($apple, 100, 101.5);
        $this->bars($microsoft, 100, 102.5);
        $this->bars($nvidia, 100, 110.25);

        $this->snapshot($apple, ['rvol' => 2.5, 'rsi14' => 55]);
        $this->snapshot($microsoft, ['rvol' => 3.4, 'rsi14' => 62.5]);
        $this->snapshot($nvidia, ['rvol' => 1.2, 'rsi14' => 48]);

        $this->signal($nvidia, 'pivot_breakout_rvol');
        $this->signal($nvidia, 'golden_cross');

        $response = $this->getJson('/api/screener')->assertOk();

        $response
            ->assertJsonPath('universe.slug', 'sp500')
            ->assertJsonPath('universe.name', 'S&P 500')
            ->assertJsonPath('sort', 'rvol_desc')
            ->assertJsonPath('meta.limit', 50)
            ->assertJsonPath('meta.returned', 3)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('candidates.0.ticker', 'MSFT')
            ->assertJsonPath('candidates.0.company', 'MSFT Holdings')
            ->assertJsonPath('candidates.0.sector', 'Information Technology')
            ->assertJsonPath('candidates.0.exchange', 'NASDAQ')
            ->assertJsonPath('candidates.0.active', true)
            ->assertJsonPath('candidates.0.date', '2026-09-28')
            ->assertJsonPath('candidates.0.close', 102.5)
            ->assertJsonPath('candidates.0.change_percent', 2.5)
            ->assertJsonPath('candidates.0.rvol', 3.4)
            ->assertJsonPath('candidates.0.rsi14', 62.5)
            ->assertJsonPath('candidates.0.signals', [])
            ->assertJsonPath('candidates.1.ticker', 'AAPL')
            ->assertJsonPath('candidates.2.ticker', 'NVDA')
            ->assertJsonPath('candidates.2.signals', ['golden_cross', 'pivot_breakout_rvol']);

        $this->assertSame(
            ['MSFT', 'AAPL', 'NVDA'],
            $this->tickers($response->json('candidates')),
        );
    }

    public function test_signal_filter_narrows_and_accepts_multiple_types(): void
    {
        $universe = $this->universe();

        $golden = $this->member($universe, 'AAA');
        $overbought = $this->member($universe, 'BBB');
        $death = $this->member($universe, 'CCC');

        foreach ([$golden, $overbought, $death] as $instrument) {
            $this->bars($instrument, 100, 100);
            $this->snapshot($instrument);
        }

        $this->signal($golden, 'golden_cross');
        $this->signal($overbought, 'rsi_overbought');
        $this->signal($death, 'death_cross');

        $this->assertSame(
            ['AAA'],
            $this->tickers($this->getJson('/api/screener?signal=golden_cross')->assertOk()->json('candidates')),
        );

        // Multiple types are OR-ed (the union), for both the string and array form.
        $this->assertSame(
            ['AAA', 'BBB'],
            $this->tickers($this->getJson('/api/screener?signal=golden_cross,rsi_overbought')->assertOk()->json('candidates')),
        );

        $this->assertSame(
            ['AAA', 'BBB'],
            $this->tickers($this->getJson('/api/screener?signal[]=golden_cross&signal[]=rsi_overbought')->assertOk()->json('candidates')),
        );
    }

    public function test_rsi_range_narrows_results(): void
    {
        $universe = $this->universe();

        $members = [];

        foreach (['AAA', 'BBB', 'CCC', 'DDD'] as $ticker) {
            $members[$ticker] = $this->member($universe, $ticker);
            $this->bars($members[$ticker], 100, 100);
        }

        $this->snapshot($members['AAA'], ['rsi14' => 62.5]);
        $this->snapshot($members['BBB'], ['rsi14' => 30]);
        $this->snapshot($members['CCC'], ['rsi14' => 80]);
        // An instrument with insufficient history can never satisfy the filter.
        $this->snapshot($members['DDD'], ['rsi14' => null]);

        $bounded = $this->getJson('/api/screener?rsi_min=30&rsi_max=62.5')->assertOk();

        $this->assertSame(['AAA', 'BBB'], $this->tickers($bounded->json('candidates')));

        $high = $this->getJson('/api/screener?rsi_min=63')->assertOk();
        $this->assertSame(['CCC'], $this->tickers($high->json('candidates')));

        $this->assertSame(
            [],
            $this->tickers($this->getJson('/api/screener?rsi_min=200')->assertOk()->json('candidates')),
        );
    }

    public function test_min_rvol_narrows_results(): void
    {
        $universe = $this->universe();

        $low = $this->member($universe, 'AAA');
        $mid = $this->member($universe, 'BBB');
        $high = $this->member($universe, 'CCC');

        foreach ([$low, $mid, $high] as $instrument) {
            $this->bars($instrument, 100, 100);
        }

        $this->snapshot($low, ['rvol' => 1]);
        $this->snapshot($mid, ['rvol' => 2.5]);
        $this->snapshot($high, ['rvol' => 4]);

        // The boundary is inclusive; the default order is RVOL descending.
        $response = $this->getJson('/api/screener?min_rvol=2.5')->assertOk();

        $this->assertSame(['CCC', 'BBB'], $this->tickers($response->json('candidates')));
    }

    public function test_price_above_sma200_narrows_results(): void
    {
        $universe = $this->universe();

        $above = $this->member($universe, 'AAA');
        $below = $this->member($universe, 'BBB');
        $unknown = $this->member($universe, 'CCC');

        foreach ([$above, $below, $unknown] as $instrument) {
            $this->bars($instrument, 100, 100);
        }

        $this->snapshot($above, ['sma200' => 90]);
        $this->snapshot($below, ['sma200' => 110]);
        // `sma200` is null: insufficient history must never satisfy the filter.
        $this->snapshot($unknown, ['sma200' => null]);

        $this->assertSame(
            ['AAA'],
            $this->tickers($this->getJson('/api/screener?price_above_sma200=1')->assertOk()->json('candidates')),
        );

        $this->assertSame(
            ['AAA'],
            $this->tickers($this->getJson('/api/screener?price_above_sma200=yes')->assertOk()->json('candidates')),
        );

        // An explicit false is simply "filter off".
        $this->assertCount(
            3,
            $this->getJson('/api/screener?price_above_sma200=0')->assertOk()->json('candidates'),
        );
    }

    public function test_ma_cross_narrows_results(): void
    {
        $universe = $this->universe();

        $bullish = $this->member($universe, 'AAA');
        $bearish = $this->member($universe, 'BBB');
        $equal = $this->member($universe, 'CCC');
        $unknown = $this->member($universe, 'DDD');

        foreach ([$bullish, $bearish, $equal, $unknown] as $instrument) {
            $this->bars($instrument, 100, 100);
        }

        $this->snapshot($bullish, ['sma50' => 110, 'sma200' => 100]);
        $this->snapshot($bearish, ['sma50' => 90, 'sma200' => 100]);
        // Equality is neither bullish nor bearish; a null MA is excluded too.
        $this->snapshot($equal, ['sma50' => 100, 'sma200' => 100]);
        $this->snapshot($unknown, ['sma50' => null, 'sma200' => 100]);

        $this->assertSame(
            ['AAA'],
            $this->tickers($this->getJson('/api/screener?ma_cross=bullish')->assertOk()->json('candidates')),
        );

        $this->assertSame(
            ['BBB'],
            $this->tickers($this->getJson('/api/screener?ma_cross=bearish')->assertOk()->json('candidates')),
        );
    }

    public function test_filters_combine_with_and(): void
    {
        $universe = $this->universe();

        $match = $this->member($universe, 'AAA');
        $wrongVolume = $this->member($universe, 'BBB');
        $wrongSignal = $this->member($universe, 'CCC');

        foreach ([$match, $wrongSignal] as $instrument) {
            $this->bars($instrument, 100, 100);
            $this->snapshot($instrument, ['rvol' => 3]);
        }

        $this->bars($wrongVolume, 100, 100);
        $this->snapshot($wrongVolume, ['rvol' => 1]);

        $this->signal($match, 'golden_cross');
        $this->signal($wrongVolume, 'golden_cross');
        $this->signal($wrongSignal, 'death_cross');

        $response = $this->getJson('/api/screener?signal=golden_cross&min_rvol=2')->assertOk();

        $this->assertSame(['AAA'], $this->tickers($response->json('candidates')));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_each_sort_order_changes_the_ranking(): void
    {
        $universe = $this->universe();

        $a = $this->member($universe, 'AAA');
        $b = $this->member($universe, 'BBB');
        $c = $this->member($universe, 'CCC');

        // Distinct on every primary key: rvol (3/2/1), rsi (30/50/70),
        // change (0/+10/+5), signal count (1/2/3).
        $this->bars($a, 100, 100);
        $this->bars($b, 100, 110);
        $this->bars($c, 100, 105);

        $this->snapshot($a, ['rvol' => 3, 'rsi14' => 30]);
        $this->snapshot($b, ['rvol' => 2, 'rsi14' => 50]);
        $this->snapshot($c, ['rvol' => 1, 'rsi14' => 70]);

        $this->signal($a, 'golden_cross');
        $this->signal($b, 'golden_cross');
        $this->signal($b, 'death_cross');
        $this->signal($c, 'golden_cross');
        $this->signal($c, 'death_cross');
        $this->signal($c, 'rsi_overbought');

        $expected = [
            'rvol_desc' => ['AAA', 'BBB', 'CCC'],
            'rsi_desc' => ['CCC', 'BBB', 'AAA'],
            'rsi_asc' => ['AAA', 'BBB', 'CCC'],
            'change_desc' => ['BBB', 'CCC', 'AAA'],
            'change_asc' => ['AAA', 'CCC', 'BBB'],
            'signal_count_desc' => ['CCC', 'BBB', 'AAA'],
        ];

        // The default order is `rvol_desc`.
        $this->assertSame(
            $expected['rvol_desc'],
            $this->tickers($this->getJson('/api/screener')->assertOk()->json('candidates')),
        );

        foreach ($expected as $sort => $order) {
            $this->assertSame(
                $order,
                $this->tickers($this->getJson('/api/screener?sort='.$sort)->assertOk()->json('candidates')),
                "Sort {$sort} returned the wrong ranking.",
            );
        }
    }

    public function test_sort_ties_break_by_ticker_ascending(): void
    {
        $universe = $this->universe();

        $zeta = $this->member($universe, 'ZZZ');
        $alpha = $this->member($universe, 'AAA');

        foreach ([$zeta, $alpha] as $instrument) {
            $this->bars($instrument, 100, 100);
            $this->snapshot($instrument, ['rvol' => 2]);
        }

        $this->assertSame(
            ['AAA', 'ZZZ'],
            $this->tickers($this->getJson('/api/screener?sort=rvol_desc')->assertOk()->json('candidates')),
        );
    }

    public function test_sorts_place_null_primary_keys_last(): void
    {
        $universe = $this->universe();

        $nulls = $this->member($universe, 'AAA');
        $value = $this->member($universe, 'ZZZ');

        $this->bars($nulls, 100, 100);
        $this->bars($value, 100, 100);

        $this->snapshot($nulls, ['rvol' => null, 'rsi14' => null]);
        $this->snapshot($value, ['rvol' => 2, 'rsi14' => 60]);

        // The null RVOL sorts after the numeric one even though its ticker (AAA)
        // would win the tie-break, so nulls last beats ticker ASC.
        $this->assertSame(
            ['ZZZ', 'AAA'],
            $this->tickers($this->getJson('/api/screener')->assertOk()->json('candidates')),
        );

        // Nulls are last in ascending orders too.
        $this->assertSame(
            ['ZZZ', 'AAA'],
            $this->tickers($this->getJson('/api/screener?sort=rsi_asc')->assertOk()->json('candidates')),
        );
    }

    public function test_empty_result_returns_empty_list_not_error(): void
    {
        $universe = $this->universe();

        $instrument = $this->member($universe, 'AAA');
        $this->bars($instrument, 100, 100);
        $this->snapshot($instrument, ['rvol' => 1]);

        $response = $this->getJson('/api/screener?min_rvol=99')
            ->assertOk()
            ->assertJsonPath('candidates', [])
            ->assertJsonPath('meta.limit', 50)
            ->assertJsonPath('meta.returned', 0)
            ->assertJsonPath('meta.total', 0);

        $this->assertSame([], $response->json('candidates'));
    }

    public function test_instruments_missing_a_snapshot_or_bars_are_excluded(): void
    {
        $universe = $this->universe();

        $complete = $this->member($universe, 'AAA');
        $barsOnly = $this->member($universe, 'BBB');
        $snapshotOnly = $this->member($universe, 'CCC');

        $this->bars($complete, 100, 100);
        $this->snapshot($complete);

        $this->bars($barsOnly, 100, 100);

        $this->snapshot($snapshotOnly);

        $this->assertSame(
            ['AAA'],
            $this->tickers($this->getJson('/api/screener')->assertOk()->json('candidates')),
        );
    }

    public function test_change_percent_is_computed_from_the_last_two_bars(): void
    {
        $universe = $this->universe();

        $twoBars = $this->member($universe, 'AAA');
        $oneBar = $this->member($universe, 'BBB');
        $zeroPrevious = $this->member($universe, 'CCC');

        $this->bars($twoBars, 100, 110.5);
        $this->snapshot($twoBars);

        $this->bar($oneBar, '2026-09-28', 100);
        $this->snapshot($oneBar);

        $this->bar($zeroPrevious, '2026-09-25', 0);
        $this->bar($zeroPrevious, '2026-09-28', 10);
        $this->snapshot($zeroPrevious);

        $byTicker = $this->getJson('/api/screener')->assertOk()->json('candidates');
        $byTicker = array_column($byTicker, null, 'ticker');

        $this->assertSame('2026-09-28', $byTicker['AAA']['date']);
        $this->assertSame(110.5, $byTicker['AAA']['close']);
        $this->assertSame(10.5, $byTicker['AAA']['change_percent']);

        // Fewer than two bars and a zero previous close both stay `null`.
        $this->assertNull($byTicker['BBB']['change_percent']);
        $this->assertNull($byTicker['CCC']['change_percent']);
    }

    public function test_limit_is_bounded_and_clamped(): void
    {
        $universe = $this->universe();

        foreach (['AAA', 'BBB', 'CCC'] as $ticker) {
            $instrument = $this->member($universe, $ticker);
            $this->bars($instrument, 100, 100);
            $this->snapshot($instrument);
        }

        $default = $this->getJson('/api/screener')->assertOk();
        $default->assertJsonPath('meta.limit', 50)->assertJsonPath('meta.returned', 3);

        $one = $this->getJson('/api/screener?limit=1')->assertOk();
        $one
            ->assertJsonPath('meta.limit', 1)
            ->assertJsonPath('meta.returned', 1)
            ->assertJsonPath('meta.total', 3);

        $clamped = $this->getJson('/api/screener?limit=99999')->assertOk();
        $clamped->assertJsonPath('meta.limit', 500);

        $zero = $this->getJson('/api/screener?limit=0')->assertOk();
        $zero->assertJsonPath('meta.limit', 1);

        // A non-numeric limit is never a 422: it falls back to the default.
        $this->getJson('/api/screener?limit=abc')->assertOk()->assertJsonPath('meta.limit', 50);
    }

    public function test_unknown_universe_returns_404_json(): void
    {
        $this->getJson('/api/screener?universe=does-not-exist')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Universe not found.']);
    }

    public function test_invalid_params_return_422(): void
    {
        $this->universe();

        $this->getJson('/api/screener?signal=bogus')->assertStatus(422);
        $this->getJson('/api/screener?ma_cross=sideways')->assertStatus(422);
        $this->getJson('/api/screener?rsi_min=abc')->assertStatus(422);
        $this->getJson('/api/screener?rsi_max=abc')->assertStatus(422);
        $this->getJson('/api/screener?min_rvol=nope')->assertStatus(422);
        $this->getJson('/api/screener?price_above_sma200=maybe')->assertStatus(422);
        $this->getJson('/api/screener?sort=bogus')->assertStatus(422);
    }

    public function test_payload_decimals_are_numbers(): void
    {
        $universe = $this->universe();

        $instrument = $this->member($universe, 'NVDA', ['company' => 'Nvidia']);

        $this->bar($instrument, '2026-09-25', 176);
        $this->bar($instrument, '2026-09-28', 178.2);

        $this->snapshot($instrument, [
            'rvol' => 3.4,
            'rsi14' => 62.5,
            'sma200' => 150.25,
        ]);

        $response = $this->getJson('/api/screener')->assertOk();

        $candidate = $response->json('candidates.0');

        $this->assertIsFloat($candidate['close']);
        $this->assertIsFloat($candidate['change_percent']);
        $this->assertIsFloat($candidate['rvol']);
        $this->assertIsFloat($candidate['rsi14']);

        $this->assertSame(178.2, $candidate['close']);
        $this->assertSame(3.4, $candidate['rvol']);
        $this->assertSame(62.5, $candidate['rsi14']);

        // The `decimal:4` cast strings are never serialized to the client.
        $this->assertStringNotContainsString('178.2000', $response->getContent());
        $this->assertStringNotContainsString('"3.4000"', $response->getContent());
    }

    public function test_json_structure(): void
    {
        $universe = $this->universe();

        $instrument = $this->member($universe, 'NVDA');
        $this->bars($instrument, 100, 100);
        $this->snapshot($instrument);
        $this->signal($instrument, 'golden_cross');

        $payload = $this->getJson('/api/screener')->assertOk()->json();

        $this->assertSame(['universe', 'sort', 'candidates', 'meta'], array_keys($payload));

        $this->assertSame(['slug', 'name'], array_keys($payload['universe']));

        $this->assertSame(
            ['ticker', 'company', 'sector', 'exchange', 'active', 'date', 'close', 'change_percent', 'rvol', 'rsi14', 'signals', 'patterns'],
            array_keys($payload['candidates'][0]),
        );

        $this->assertSame(['limit', 'returned', 'total'], array_keys($payload['meta']));
        $this->assertSame(['golden_cross'], $payload['candidates'][0]['signals']);
    }

    public function test_candidate_loading_does_not_scale_queries_with_universe_size(): void
    {
        $universe = $this->universe();

        for ($index = 0; $index < 20; $index++) {
            $instrument = $this->member($universe, sprintf('T%02d', $index));
            $this->bars($instrument, 100, 100);
            $this->snapshot($instrument);
            $this->signal($instrument, 'golden_cross');
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->getJson('/api/screener')->assertOk()->assertJsonPath('meta.returned', 20);

        // The candidate set is loaded with eager `latestBar`/`latestSnapshot`/
        // `signals` queries plus one bulk previous-close query, so the count is
        // constant. A query per instrument (20 members) would blow this bound.
        $this->assertLessThan(
            20,
            $queries,
            "The screener issued {$queries} queries for 20 members (N+1 regression?).",
        );
    }

    public function test_endpoint_is_rate_limited(): void
    {
        $this->universe();

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->getJson('/api/screener')->assertOk();
        }

        $this->getJson('/api/screener')->assertStatus(429);
    }
}
