<?php

namespace Tests\Feature;

use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\SavedScreener;
use App\Models\ScreenerRow;
use App\Models\Universe;
use App\Models\User;
use App\Services\Screener\Ai\ScreenerQuerySchema;
use App\Services\Screener\CandidateQuery;
use App\Services\Screener\ScreenerFilterParser;
use App\Services\Screener\ScreenerRowBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Technical indicator filters, sorts and payload fields of the Screener
 * (screener-technical-filters-api, docs/specs/technical-indicators-expansion.md
 * D4/D7): ADX, Bollinger %B and bandwidth, ATR as percent of price and
 * Stochastic %K, precomputed on `screener_rows` by `screener:refresh`.
 */
class ScreenerTechnicalFiltersTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = ['adx', 'bb_pctb', 'bb_width', 'atr_pct', 'stoch_k'];

    private Universe $universe;

    /**
     * Five Candidates (close, upper, middle, lower, adx, atr14, stoch_k):
     * - AAA: %B 0.5, width 20, ATR 2 %, ADX 30, %K 80
     * - BBB: %B 0.75, width 40, ATR 10 %, ADX 20, %K 50
     * - CCC: zero-width band (%B null, width 0), ATR 1 %, ADX 30 (ties AAA), %K 20
     * - DDD: no indicator values at all
     * - EEE: zero close (ATR % null), %B 0, width 200
     */
    private function seedCandidates(): void
    {
        $this->universe = Universe::factory()->create(['slug' => 'sp500', 'name' => 'S&P 500']);

        $rows = [
            ['AAA', 100, 110, 100, 90, 30, 2, 80],
            ['BBB', 55, 60, 50, 40, 20, 5.5, 50],
            ['CCC', 100, 100, 100, 100, 30, 1, 20],
            ['DDD', 80, null, null, null, null, null, null],
            ['EEE', 0, 10, 5, 0, 10, 1, 95],
        ];

        foreach ($rows as [$ticker, $close, $upper, $middle, $lower, $adx, $atr, $stoch]) {
            $this->member($this->universe, $ticker, $close, [
                'bb_upper' => $upper,
                'bb_middle' => $middle,
                'bb_lower' => $lower,
                'adx' => $adx,
                'atr14' => $atr,
                'stoch_k' => $stoch,
            ]);
        }

        $this->refreshScreenerRows();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function member(Universe $universe, string $ticker, float|int $close, array $snapshot): Instrument
    {
        $instrument = Instrument::factory()->create(['ticker' => $ticker, 'active' => true]);
        $universe->instruments()->attach($instrument->id);

        DailyBar::factory()->create([
            'instrument_id' => $instrument->id,
            'date' => '2026-10-23',
            'open' => $close,
            'high' => $close,
            'low' => $close,
            'close' => $close,
            'volume' => 1_000_000,
        ]);

        IndicatorSnapshot::factory()->create($snapshot + [
            'instrument_id' => $instrument->id,
            'date' => '2026-10-23',
            'rvol' => 1.0,
        ]);

        return $instrument;
    }

    /**
     * @return list<string>
     */
    private function tickers(string $query): array
    {
        return array_column($this->getJson('/api/screener?'.$query)->assertOk()->json('candidates'), 'ticker');
    }

    public function test_refresh_fills_the_columns_with_the_spec_formulas_and_propagates_nulls(): void
    {
        $this->seedCandidates();

        $row = fn (string $ticker): ScreenerRow => ScreenerRow::query()->where('ticker', $ticker)->firstOrFail();

        $aaa = $row('AAA');
        $this->assertEqualsWithDelta(30.0, (float) $aaa->adx, 1e-9);
        $this->assertEqualsWithDelta(0.5, $aaa->bb_pctb, 1e-9);
        $this->assertEqualsWithDelta(20.0, $aaa->bb_width, 1e-9);
        $this->assertEqualsWithDelta(2.0, $aaa->atr_pct, 1e-9);
        $this->assertEqualsWithDelta(80.0, (float) $aaa->stoch_k, 1e-9);

        $bbb = $row('BBB');
        $this->assertEqualsWithDelta(0.75, $bbb->bb_pctb, 1e-9);
        $this->assertEqualsWithDelta(40.0, $bbb->bb_width, 1e-9);
        $this->assertEqualsWithDelta(10.0, $bbb->atr_pct, 1e-9);

        // A zero-width band has no %B but a bandwidth of 0.
        $ccc = $row('CCC');
        $this->assertNull($ccc->bb_pctb);
        $this->assertEqualsWithDelta(0.0, $ccc->bb_width, 1e-9);

        // Missing snapshot values stay null.
        $ddd = $row('DDD');
        foreach (self::KEYS as $column) {
            $this->assertNull($ddd->{$column}, $column);
        }

        // A zero close has no ATR %.
        $eee = $row('EEE');
        $this->assertNull($eee->atr_pct);
        $this->assertEqualsWithDelta(0.0, $eee->bb_pctb, 1e-9);
        $this->assertEqualsWithDelta(200.0, $eee->bb_width, 1e-9);
    }

    public function test_the_columns_exist_with_single_column_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('screener_rows', self::KEYS));

        $indexed = collect(Schema::getIndexes('screener_rows'))
            ->filter(fn (array $index): bool => count($index['columns']) === 1)
            ->pluck('columns.0')
            ->all();

        foreach (self::KEYS as $column) {
            $this->assertContains($column, $indexed, $column);
        }
    }

    public function test_refresh_keeps_the_row_count(): void
    {
        $this->seedCandidates();

        $this->assertSame(5, ScreenerRow::query()->count());
        $this->assertSame(5, app(ScreenerRowBuilder::class)->refresh());
        $this->assertSame(5, ScreenerRow::query()->count());
    }

    public function test_the_candidate_payload_gains_the_five_fields_with_null_when_missing(): void
    {
        $this->seedCandidates();

        $candidates = collect($this->getJson('/api/screener?sort=adx_desc')->assertOk()->json('candidates'))->keyBy('ticker');

        $this->assertEqualsWithDelta(30.0, $candidates['AAA']['adx'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $candidates['AAA']['bb_pctb'], 1e-9);
        $this->assertEqualsWithDelta(20.0, $candidates['AAA']['bb_width'], 1e-9);
        $this->assertEqualsWithDelta(2.0, $candidates['AAA']['atr_pct'], 1e-9);
        $this->assertEqualsWithDelta(80.0, $candidates['AAA']['stoch_k'], 1e-9);
        foreach (self::KEYS as $key) {
            $this->assertArrayHasKey($key, $candidates['DDD']);
            $this->assertNull($candidates['DDD'][$key], $key);
        }

        // The original keys keep their order and types.
        $this->assertSame(
            ['ticker', 'company', 'sector', 'industry', 'country', 'exchange', 'market', 'currency', 'active', 'date', 'close', 'change_percent', 'rvol', 'rsi14'],
            array_slice(array_keys($candidates['AAA']), 0, 14),
        );
        $this->assertSame(['signals', 'patterns'], array_slice(array_keys($candidates['AAA']), -2));
    }

    public function test_each_range_is_inclusive_and_never_matches_null(): void
    {
        $this->seedCandidates();

        $this->assertSame(['AAA', 'CCC'], $this->tickers('adx_min=30&sort=adx_asc'));
        $this->assertSame(['BBB', 'EEE'], $this->tickers('adx_max=20&sort=adx_desc'));
        $this->assertSame(['AAA', 'BBB'], $this->tickers('bb_pctb_min=0.5&sort=bb_pctb_asc'));
        $this->assertSame(['EEE', 'AAA'], $this->tickers('bb_pctb_max=0.5&sort=bb_pctb_asc'));
        $this->assertSame(['BBB', 'EEE'], $this->tickers('bb_width_min=40&sort=bb_width_asc'));
        $this->assertSame(['CCC', 'AAA'], $this->tickers('bb_width_max=20&sort=bb_width_asc'));
        $this->assertSame(['AAA', 'BBB'], $this->tickers('atr_pct_min=2&sort=atr_pct_asc'));
        $this->assertSame(['CCC', 'AAA'], $this->tickers('atr_pct_max=2&sort=atr_pct_asc'));
        $this->assertSame(['AAA', 'EEE'], $this->tickers('stoch_k_min=80&sort=stoch_k_asc'));
        $this->assertSame(['CCC', 'BBB'], $this->tickers('stoch_k_max=50&sort=stoch_k_asc'));

        // Both bounds at once, and AND with an existing filter.
        $this->assertSame(['BBB', 'AAA'], $this->tickers('stoch_k_min=50&stoch_k_max=80&sort=stoch_k_asc'));
        $this->assertSame(['AAA'], $this->tickers('adx_min=30&bb_width_min=20&min_rvol=1'));
        $this->assertSame([], $this->tickers('adx_min=30&bb_width_min=30'));
    }

    public function test_filters_respect_the_universe_and_pagination(): void
    {
        $this->seedCandidates();
        config(['universes.public' => ['sp500', 'nasdaq100']]);
        $other = Universe::factory()->create(['slug' => 'nasdaq100', 'name' => 'Nasdaq-100']);
        $this->member($other, 'ZZZ', 100, ['bb_upper' => 110, 'bb_middle' => 100, 'bb_lower' => 90, 'adx' => 31, 'atr14' => 1, 'stoch_k' => 10]);
        $this->refreshScreenerRows();

        $this->assertSame(['AAA', 'CCC'], $this->tickers('adx_min=30&sort=adx_asc'));
        $this->assertSame(['ZZZ'], $this->tickers('universe=nasdaq100&adx_min=30'));

        $page = $this->getJson('/api/screener?adx_min=20&sort=adx_desc&limit=1&offset=1')->assertOk();
        $this->assertSame(['CCC'], array_column($page->json('candidates'), 'ticker'));
        $this->assertSame(3, $page->json('meta.total'));
    }

    public function test_every_indicator_sort_orders_both_ways_with_nulls_last_and_a_ticker_tie_break(): void
    {
        $this->seedCandidates();

        $expected = [
            'adx_asc' => ['EEE', 'BBB', 'AAA', 'CCC', 'DDD'],
            'adx_desc' => ['AAA', 'CCC', 'BBB', 'EEE', 'DDD'],
            'bb_pctb_asc' => ['EEE', 'AAA', 'BBB', 'CCC', 'DDD'],
            'bb_pctb_desc' => ['BBB', 'AAA', 'EEE', 'CCC', 'DDD'],
            'bb_width_asc' => ['CCC', 'AAA', 'BBB', 'EEE', 'DDD'],
            'bb_width_desc' => ['EEE', 'BBB', 'AAA', 'CCC', 'DDD'],
            'atr_pct_asc' => ['CCC', 'AAA', 'BBB', 'DDD', 'EEE'],
            'atr_pct_desc' => ['BBB', 'AAA', 'CCC', 'DDD', 'EEE'],
            'stoch_k_asc' => ['CCC', 'BBB', 'AAA', 'EEE', 'DDD'],
            'stoch_k_desc' => ['EEE', 'AAA', 'BBB', 'CCC', 'DDD'],
        ];

        foreach ($expected as $sort => $order) {
            $response = $this->getJson('/api/screener?sort='.$sort)->assertOk()->assertJsonPath('sort', $sort);
            $this->assertSame($order, array_column($response->json('candidates'), 'ticker'), $sort);
            $this->assertContains($sort, ScreenerFilterParser::COLUMN_SORTS);
        }
    }

    public function test_invalid_values_are_422_on_their_key(): void
    {
        $this->seedCandidates();

        $cases = [
            'adx_min=101' => 'adx_min',
            'adx_max=-1' => 'adx_max',
            'bb_pctb_min=-1.5' => 'bb_pctb_min',
            'bb_pctb_max=2.5' => 'bb_pctb_max',
            'bb_width_max=1001' => 'bb_width_max',
            'atr_pct_min=-0.1' => 'atr_pct_min',
            'stoch_k_max=100.5' => 'stoch_k_max',
            'adx_min=abc' => 'adx_min',
            'stoch_k_min[]=1' => 'stoch_k_min',
            'adx_min=50&adx_max=40' => 'adx_min',
            'bb_pctb_min=1&bb_pctb_max=0' => 'bb_pctb_min',
            'bb_width_min=10&bb_width_max=5' => 'bb_width_min',
            'atr_pct_min=5&atr_pct_max=1' => 'atr_pct_min',
            'stoch_k_min=60&stoch_k_max=40' => 'stoch_k_min',
            'sort=adx' => 'sort',
        ];

        foreach ($cases as $query => $key) {
            $this->getJson('/api/screener?'.$query)->assertUnprocessable()->assertJsonValidationErrors([$key]);
        }

        // The documented edges are accepted.
        $this->getJson('/api/screener?bb_pctb_min=-1&bb_pctb_max=2&adx_min=0&adx_max=100&bb_width_max=1000')->assertOk();
    }

    public function test_the_original_sorts_and_the_default_ranking_are_unchanged(): void
    {
        $this->seedCandidates();

        $this->assertSame(['rvol_desc', 'rsi_desc', 'rsi_asc', 'change_desc', 'change_asc', 'signal_count_desc'], ScreenerFilterParser::SORTS);
        $this->assertSame('rvol_desc', ScreenerFilterParser::DEFAULT_SORT);
        $this->getJson('/api/screener')->assertOk()->assertJsonPath('sort', 'rvol_desc');
        // Equal RVOL everywhere: the ticker tie-break decides.
        $this->assertSame(['AAA', 'BBB', 'CCC', 'DDD', 'EEE'], $this->tickers(''));
    }

    public function test_saved_screeners_round_trip_the_ten_keys_and_old_definitions_load(): void
    {
        $this->seedCandidates();
        $user = User::factory()->create();

        $filters = [
            'signal' => [], 'rsi_min' => null, 'rsi_max' => null, 'min_rvol' => null, 'price_above_sma200' => false,
            'ma_cross' => null, 'sort' => 'adx_desc',
            'adx_min' => 25, 'bb_pctb_max' => 0.8, 'bb_width_min' => 10.5, 'atr_pct_max' => 5, 'stoch_k_min' => 10,
        ];

        $created = $this->actingAs($user)->postJson('/api/screeners', ['name' => 'Trend', 'filters' => $filters])
            ->assertCreated()
            ->assertJsonPath('screener.filters.sort', 'adx_desc')
            ->assertJsonPath('screener.filters.adx_min', 25)
            ->assertJsonPath('screener.filters.bb_width_min', 10.5)
            ->assertJsonPath('screener.filters.adx_max', null)
            ->assertJsonPath('screener.filters.stoch_k_max', null);

        $stored = SavedScreener::query()->sole()->filters;
        $this->assertSame($created->json('screener.filters'), $this->actingAs($user)->getJson('/api/screeners')->json('screeners.0.filters'));

        // The stored definition resolves to what the URL requests.
        $query = 'adx_min=25&bb_pctb_max=0.8&bb_width_min=10.5&atr_pct_max=5&stoch_k_min=10&sort=adx_desc';
        parse_str($query, $input);
        $this->assertEquals(ScreenerFilterParser::fromArray($input), CandidateQuery::fromSaved($stored));
        $this->assertSame(['AAA'], $this->tickers($query));

        // Old stored definitions (without the keys) load as "off"; odd values never throw.
        $old = CandidateQuery::fromSaved(['signal' => [], 'sort' => 'rvol_desc']);
        foreach (ScreenerFilterParser::RANGE_PAIRS as [$min, $max]) {
            $this->assertNull($old[$min]);
            $this->assertNull($old[$max]);
        }
        $odd = CandidateQuery::fromSaved(['adx_min' => 'abc', 'stoch_k_max' => ['x']]);
        $this->assertNull($odd['adx_min']);
        $this->assertNull($odd['stoch_k_max']);

        $user->savedScreeners()->create(['name' => 'Legacy', 'filters' => ['signal' => [], 'sort' => 'rvol_desc']]);
        $legacy = collect($this->actingAs($user)->getJson('/api/screeners')->assertOk()->json('screeners'))->firstWhere('name', 'Legacy');
        $this->assertNull($legacy['filters']['adx_min']);
    }

    public function test_invalid_saved_indicator_criteria_are_rejected(): void
    {
        $user = User::factory()->create();
        $base = [
            'signal' => [], 'rsi_min' => null, 'rsi_max' => null, 'min_rvol' => null, 'price_above_sma200' => false,
            'ma_cross' => null, 'sort' => 'rvol_desc',
        ];

        $cases = [
            ['adx_min' => 101, 'key' => 'adx_min'],
            ['bb_pctb_min' => -2, 'key' => 'bb_pctb_min'],
            ['atr_pct_max' => 'x', 'key' => 'atr_pct_max'],
            ['adx_min' => 60, 'adx_max' => 40, 'key' => 'adx_min'],
            ['stoch_k_min' => 70, 'stoch_k_max' => 30, 'key' => 'stoch_k_min'],
            ['sort' => 'adx', 'key' => 'sort'],
        ];

        foreach ($cases as $case) {
            $key = $case['key'];
            unset($case['key']);

            $this->actingAs($user)->postJson('/api/screeners', ['name' => 'Bad '.$key, 'filters' => $case + $base])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['filters.'.$key]);
        }

        $this->assertSame(0, SavedScreener::query()->count());
    }

    public function test_the_natural_language_schema_is_unchanged(): void
    {
        $schema = ScreenerQuerySchema::schema();

        $this->assertSame(ScreenerFilterParser::SORTS, $schema['properties']['sort']['enum']);
        foreach (array_keys(ScreenerFilterParser::NUMERIC_BOUNDS) as $key) {
            if (preg_match('/^(adx|bb_pctb|bb_width|atr_pct|stoch_k)_/', $key) === 1) {
                $this->assertArrayNotHasKey($key, $schema['properties'], $key);
            }
        }

        // An AI answer can never carry the indicator keys into its filters.
        $answer = [
            'signal' => [], 'rsi_min' => null, 'rsi_max' => null, 'min_rvol' => null, 'price_above_sma200' => false,
            'ma_cross' => null, 'pattern' => [], 'pattern_status' => 'any', 'sort' => 'rvol_desc',
            'sector_trend' => null, 'sector_rs_rank_max' => null, 'unsupported' => [], 'explanation' => 'Default ranking.',
        ];
        $this->assertCount(11, ScreenerQuerySchema::validate($answer)['filters']);
    }
}
