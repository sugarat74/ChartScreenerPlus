<?php

namespace Tests\Feature;

use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\SavedScreener;
use App\Models\Signal;
use App\Models\Universe;
use App\Models\User;
use App\Services\Screener\Ai\ScreenerQuerySchema;
use App\Services\Screener\CandidateQuery;
use App\Services\Screener\ScreenerFilterParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Table column filters and sorts of the public Screener
 * (docs/specs/screener-table-column-controls.md): every displayed column
 * filters and sorts server-side, before counting and paging, with nulls last,
 * a ticker tie-break, strict validation and Saved Screener round trips.
 */
class ScreenerColumnControlsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Five Candidates with ties, nulls and LIKE wildcard characters.
     */
    private function seedCandidates(): void
    {
        $universe = Universe::factory()->create(['slug' => 'sp500', 'name' => 'S&P 500']);

        $rows = [
            // ticker, company, sector, industry, country, previous close, close, rvol, rsi, signals
            ['AAPL', 'Apple Inc.', 'Information Technology', 'Technology Hardware, Storage & Peripherals', 'US', 190, 200, 2.0, 60, ['golden_cross']],
            ['MSFT', 'Microsoft Corp', 'Information Technology', 'Systems Software', 'US', 400, 400, 1.5, 55, []],
            ['ACN', 'Accenture plc', 'Information Technology', 'IT Consulting & Other Services', 'IE', 330, 300, null, 40, []],
            ['JNJ', 'Johnson & Johnson', 'Health Care', 'Pharmaceuticals', 'US', 150, 150, 0.8, null, []],
            ['XYZ', '100%_Pure Co', null, null, null, null, 10, 3.0, 70, ['rsi_overbought', 'pivot_breakout_rvol']],
        ];

        foreach ($rows as [$ticker, $company, $sector, $industry, $country, $previous, $close, $rvol, $rsi, $signals]) {
            $instrument = Instrument::factory()->create([
                'ticker' => $ticker,
                'company' => $company,
                'sector' => $sector,
                'industry' => $industry,
                'country' => $country,
                'exchange' => 'NYSE',
                'active' => true,
            ]);
            $universe->instruments()->attach($instrument->id);

            if ($previous !== null) {
                $this->bar($instrument, '2026-10-22', $previous);
            }
            $this->bar($instrument, '2026-10-23', $close);

            IndicatorSnapshot::factory()->create([
                'instrument_id' => $instrument->id,
                'date' => '2026-10-23',
                'rvol' => $rvol,
                'rsi14' => $rsi,
                'sma50' => 100,
                'sma200' => 90,
            ]);

            foreach ($signals as $type) {
                Signal::factory()->create(['instrument_id' => $instrument->id, 'date' => '2026-10-23', 'type' => $type, 'metadata' => []]);
            }
        }

        $this->refreshScreenerRows();
    }

    private function bar(Instrument $instrument, string $date, float $close): void
    {
        DailyBar::factory()->create([
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
     * @return list<string>
     */
    private function tickers(string $query): array
    {
        return array_column($this->getJson('/api/screener?'.$query)->assertOk()->json('candidates'), 'ticker');
    }

    public function test_search_matches_ticker_or_company_case_insensitively_and_literally(): void
    {
        $this->seedCandidates();

        $this->assertSame(['MSFT'], $this->tickers('search=micro'));
        $this->assertSame(['AAPL'], $this->tickers('search=aapl'));
        $this->assertSame(['ACN'], $this->tickers('search='.rawurlencode('  PLC ')));
        // LIKE wildcards typed by the user match literally.
        $this->assertSame(['XYZ'], $this->tickers('search=%25'));
        $this->assertSame(['XYZ'], $this->tickers('search=_'));
        // An SQL fragment is only a bound value: no match, nothing broken.
        $this->assertSame([], $this->tickers('search='.rawurlencode("' or 1=1 --")));
        $this->assertSame(5, $this->getJson('/api/screener')->assertOk()->json('meta.total'));
    }

    public function test_filters_apply_before_paging_so_matches_outside_the_page_count(): void
    {
        $this->seedCandidates();

        // "o" matches three companies; the default rank (RVOL desc) pages them.
        $first = $this->getJson('/api/screener?search=o&limit=1')->assertOk();
        $first->assertJsonPath('meta.total', 3)->assertJsonPath('meta.returned', 1);
        $this->assertSame(['XYZ'], array_column($first->json('candidates'), 'ticker'));
        $this->assertSame(['JNJ'], $this->tickers('search=o&limit=1&offset=2'));
    }

    public function test_classification_filters_match_only_non_null_canonical_values(): void
    {
        $this->seedCandidates();

        $this->assertSame(['JNJ'], $this->tickers('sector='.rawurlencode('Health Care')));
        $this->assertSame(['AAPL', 'ACN', 'MSFT'], $this->tickers('sector='.rawurlencode('Information Technology').'&sort=sector_asc'));
        $this->assertSame(['ACN'], $this->tickers('country=ie'));
        $this->assertSame(['AAPL', 'MSFT', 'JNJ'], $this->tickers('country=US'));
        $this->assertSame(['MSFT'], $this->tickers('industry='.rawurlencode(' SOFTWARE ')));
        // A null industry or country never matches.
        $this->assertNotContains('XYZ', $this->tickers('industry=a'));
        $this->assertSame([], $this->tickers('country=FR'));

        foreach (['sector=Tech', 'sector[]=Energy', 'country=USA', 'country=1A', 'country[]=US'] as $query) {
            $this->getJson('/api/screener?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/screener?industry='.str_repeat('a', 121))->assertUnprocessable()->assertJsonValidationErrors(['industry']);
        $this->getJson('/api/screener?search='.str_repeat('a', 101))->assertUnprocessable()->assertJsonValidationErrors(['search']);
        $this->getJson('/api/screener?search=a%00b')->assertUnprocessable()->assertJsonValidationErrors(['search']);
        $this->getJson('/api/screener?search[]=a')->assertUnprocessable()->assertJsonValidationErrors(['search']);
        // Blank text is off, not an error.
        $this->assertCount(5, $this->tickers('search=%20%20&industry='));
    }

    public function test_numeric_ranges_are_inclusive_and_combine_with_and(): void
    {
        $this->seedCandidates();

        $this->assertSame(['JNJ', 'AAPL', 'ACN'], $this->tickers('close_min=150&close_max=300&sort=close_asc'));
        // A null change (one bar only) never matches an active bound.
        $this->assertSame(['AAPL', 'JNJ', 'MSFT'], $this->tickers('change_min=0&sort=change_desc'));
        $this->assertSame(['ACN'], $this->tickers('change_max=-9'));
        // A null RVOL never matches either RVOL bound.
        $this->assertSame(['MSFT', 'JNJ'], $this->tickers('rvol_max=1.5'));
        $this->assertSame(['AAPL', 'MSFT'], $this->tickers('min_rvol=1.5&rvol_max=2'));
        $this->assertSame(['MSFT'], $this->tickers('rsi_min=55&rsi_max=55'));
        // AND across columns.
        $this->assertSame(['AAPL', 'MSFT'], $this->tickers('close_min=100&change_min=0&sector='.rawurlencode('Information Technology')));
        // A valid combination with no match is an empty 200.
        $this->getJson('/api/screener?close_min=1000&close_max=1000')->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('candidates', []);
    }

    public function test_invalid_and_inverted_ranges_are_rejected(): void
    {
        $this->seedCandidates();

        $cases = [
            'close_min=abc' => 'close_min',
            'close_min=-1' => 'close_min',
            'close_max=1000001' => 'close_max',
            'change_min=-101' => 'change_min',
            'change_max=1001' => 'change_max',
            'rvol_max=-0.5' => 'rvol_max',
            'min_rvol=-1' => 'min_rvol',
            'rsi_max=101' => 'rsi_max',
            'close_max=1e400' => 'close_max',
            'close_min=300&close_max=200' => 'close_min',
            'change_min=5&change_max=1' => 'change_min',
            'min_rvol=3&rvol_max=1' => 'min_rvol',
            'rsi_min=70&rsi_max=30' => 'rsi_min',
        ];

        foreach ($cases as $query => $key) {
            $this->getJson('/api/screener?'.$query)->assertUnprocessable()->assertJsonValidationErrors([$key]);
        }

        $this->withHeader('Accept-Language', 'es')
            ->getJson('/api/screener?close_min=300&close_max=200')
            ->assertUnprocessable()
            ->assertJsonPath('errors.close_min.0', 'El parámetro close_min no puede ser mayor que close_max.');
    }

    public function test_every_column_sorts_both_ways_with_nulls_last_and_a_ticker_tie_break(): void
    {
        $this->seedCandidates();

        $expected = [
            'company_asc' => ['XYZ', 'ACN', 'AAPL', 'JNJ', 'MSFT'],
            'company_desc' => ['MSFT', 'JNJ', 'AAPL', 'ACN', 'XYZ'],
            'sector_asc' => ['JNJ', 'AAPL', 'ACN', 'MSFT', 'XYZ'],
            'sector_desc' => ['AAPL', 'ACN', 'MSFT', 'JNJ', 'XYZ'],
            'industry_asc' => ['ACN', 'JNJ', 'MSFT', 'AAPL', 'XYZ'],
            'industry_desc' => ['AAPL', 'MSFT', 'JNJ', 'ACN', 'XYZ'],
            'country_asc' => ['ACN', 'AAPL', 'JNJ', 'MSFT', 'XYZ'],
            'country_desc' => ['AAPL', 'JNJ', 'MSFT', 'ACN', 'XYZ'],
            'close_asc' => ['XYZ', 'JNJ', 'AAPL', 'ACN', 'MSFT'],
            'close_desc' => ['MSFT', 'ACN', 'AAPL', 'JNJ', 'XYZ'],
            'change_asc' => ['ACN', 'JNJ', 'MSFT', 'AAPL', 'XYZ'],
            'change_desc' => ['AAPL', 'JNJ', 'MSFT', 'ACN', 'XYZ'],
            'rvol_asc' => ['JNJ', 'MSFT', 'AAPL', 'XYZ', 'ACN'],
            'rvol_desc' => ['XYZ', 'AAPL', 'MSFT', 'JNJ', 'ACN'],
            'rsi_asc' => ['ACN', 'MSFT', 'AAPL', 'XYZ', 'JNJ'],
            'rsi_desc' => ['XYZ', 'AAPL', 'MSFT', 'ACN', 'JNJ'],
            'signal_count_asc' => ['ACN', 'JNJ', 'MSFT', 'AAPL', 'XYZ'],
            'signal_count_desc' => ['XYZ', 'AAPL', 'ACN', 'JNJ', 'MSFT'],
        ];

        $this->assertSame(ScreenerFilterParser::ALL_SORTS, array_values(array_intersect(ScreenerFilterParser::ALL_SORTS, array_keys($expected))));
        $this->assertCount(count(ScreenerFilterParser::ALL_SORTS), $expected);

        foreach ($expected as $sort => $order) {
            $response = $this->getJson('/api/screener?sort='.$sort)->assertOk()->assertJsonPath('sort', $sort);
            $this->assertSame($order, array_column($response->json('candidates'), 'ticker'), $sort);
        }
    }

    public function test_pagination_follows_the_column_order(): void
    {
        $this->seedCandidates();

        $page = $this->getJson('/api/screener?sort=close_asc&limit=2&offset=2')->assertOk();
        $page->assertJsonPath('meta.total', 5)->assertJsonPath('meta.returned', 2);
        $this->assertSame(['AAPL', 'ACN'], array_column($page->json('candidates'), 'ticker'));

        $filtered = $this->getJson('/api/screener?sector='.rawurlencode('Information Technology').'&sort=company_desc&limit=2&offset=2')->assertOk();
        $filtered->assertJsonPath('meta.total', 3);
        $this->assertSame(['ACN'], array_column($filtered->json('candidates'), 'ticker'));
    }

    public function test_a_malformed_sort_never_reaches_sql(): void
    {
        $this->seedCandidates();

        foreach (['ticker', 'close; drop table screener_rows', 'company_ASC', 'sort[]=close_asc'] as $sort) {
            $query = str_starts_with($sort, 'sort[]') ? $sort : 'sort='.rawurlencode($sort);
            $this->getJson('/api/screener?'.$query)->assertUnprocessable()->assertJsonValidationErrors(['sort']);
        }

        $this->assertSame(5, $this->getJson('/api/screener')->assertOk()->json('meta.total'));
    }

    public function test_column_controls_are_public_while_sector_context_stays_protected(): void
    {
        $this->seedCandidates();

        // A Visitor may use every basic column criterion.
        $this->getJson('/api/screener?search=a&sector='.rawurlencode('Information Technology').'&country=US&industry=s&close_min=1&sort=industry_desc')
            ->assertOk();

        // Market Context-only filters keep their authorization.
        $this->getJson('/api/screener?sector=Energy&sector_trend=uptrend')->assertUnauthorized();
    }

    public function test_old_queries_keep_their_results(): void
    {
        $this->seedCandidates();

        $this->assertSame(['XYZ', 'AAPL', 'MSFT', 'JNJ', 'ACN'], $this->tickers(''));
        $this->assertSame(['XYZ', 'AAPL'], $this->tickers('min_rvol=2'));
        $this->assertSame(['AAPL'], $this->tickers('signal=golden_cross&sort=rsi_desc'));
    }

    public function test_column_criteria_round_trip_through_a_saved_screener(): void
    {
        $this->seedCandidates();
        $user = User::factory()->create();

        $filters = [
            'signal' => [], 'rsi_min' => null, 'rsi_max' => null, 'min_rvol' => null, 'price_above_sma200' => false,
            'ma_cross' => null, 'pattern' => [], 'pattern_status' => 'any', 'sort' => 'company_desc',
            'sector_trend' => null, 'sector_rs_rank_max' => null,
            'search' => '  c  ', 'sector' => 'Information Technology', 'industry' => null, 'country' => 'US',
            'rvol_max' => 3, 'close_min' => 100, 'close_max' => 450.5, 'change_min' => -1, 'change_max' => null,
        ];

        $created = $this->actingAs($user)->postJson('/api/screeners', ['name' => 'Columns', 'filters' => $filters])
            ->assertCreated()
            ->assertJsonPath('screener.filters.search', 'c')
            ->assertJsonPath('screener.filters.sort', 'company_desc')
            ->assertJsonPath('screener.filters.close_max', 450.5);

        $stored = SavedScreener::query()->sole()->filters;
        $this->assertCount(20, $stored);
        $this->assertSame($created->json('screener.filters'), $this->actingAs($user)->getJson('/api/screeners')->json('screeners.0.filters'));

        // The saved definition resolves to exactly what the URL requests.
        $query = 'search=c&sector='.rawurlencode('Information Technology').'&country=US&rvol_max=3&close_min=100&close_max=450.5&change_min=-1&sort=company_desc';
        parse_str($query, $input);
        $this->assertEquals(ScreenerFilterParser::fromArray($input), CandidateQuery::fromSaved($stored));
        $this->assertSame(['MSFT', 'AAPL'], $this->tickers($query));
    }

    public function test_invalid_saved_column_criteria_are_rejected(): void
    {
        $user = User::factory()->create();
        $base = [
            'signal' => [], 'rsi_min' => null, 'rsi_max' => null, 'min_rvol' => null, 'price_above_sma200' => false,
            'ma_cross' => null, 'sort' => 'rvol_desc',
        ];

        $cases = [
            ['close_min' => 300, 'close_max' => 200, 'key' => 'close_min'],
            ['rsi_min' => 70, 'rsi_max' => 30, 'key' => 'rsi_min'],
            ['search' => "a\u{0007}b", 'key' => 'search'],
            ['search' => str_repeat('a', 101), 'key' => 'search'],
            ['sector' => 'Tech', 'key' => 'sector'],
            ['country' => 'usa', 'key' => 'country'],
            ['change_min' => -200, 'key' => 'change_min'],
            ['sort' => 'ticker', 'key' => 'sort'],
            ['unknown' => 1, 'key' => null],
        ];

        foreach ($cases as $case) {
            $key = $case['key'];
            unset($case['key']);
            $response = $this->actingAs($user)->postJson('/api/screeners', ['name' => 'Bad', 'filters' => array_merge($base, $case)])
                ->assertUnprocessable();
            $response->assertJsonValidationErrors([$key === null ? 'filters' : 'filters.'.$key]);
        }

        $this->assertDatabaseCount('saved_screeners', 0);
    }

    public function test_a_legacy_saved_row_without_column_keys_loads_as_off(): void
    {
        $filters = CandidateQuery::fromSaved(['signal' => [], 'sort' => 'rvol_desc']);

        foreach (['search', 'sector', 'industry', 'country', 'rvol_max', 'close_min', 'close_max', 'change_min', 'change_max'] as $key) {
            $this->assertNull($filters[$key], $key);
        }

        // A malformed stored value falls back to off instead of throwing.
        $odd = CandidateQuery::fromSaved(['sector' => 'Tech', 'country' => 'usa', 'search' => ['x'], 'close_min' => 'abc']);
        $this->assertSame([null, null, null, null], [$odd['sector'], $odd['country'], $odd['search'], $odd['close_min']]);
    }

    public function test_the_natural_language_schema_is_unchanged(): void
    {
        $schema = ScreenerQuerySchema::schema();

        $this->assertSame(ScreenerFilterParser::SORTS, $schema['properties']['sort']['enum']);
        foreach (['search', 'sector', 'industry', 'country', 'close_min', 'rvol_max', 'change_min'] as $key) {
            $this->assertArrayNotHasKey($key, $schema['properties']);
        }

        $answer = [
            'signal' => [], 'rsi_min' => null, 'rsi_max' => null, 'min_rvol' => null, 'price_above_sma200' => false,
            'ma_cross' => null, 'pattern' => [], 'pattern_status' => 'any', 'sort' => 'rvol_desc',
            'sector_trend' => null, 'sector_rs_rank_max' => null, 'unsupported' => [], 'explanation' => 'Default ranking.',
        ];
        $this->assertCount(11, ScreenerQuerySchema::validate($answer)['filters']);
    }
}
