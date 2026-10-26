<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\CompanyFundamental;
use App\Models\DailyBar;
use App\Models\IndicatorSnapshot;
use App\Models\Instrument;
use App\Models\SavedScreener;
use App\Models\ScreenerRow;
use App\Models\Universe;
use App\Models\User;
use App\Services\Alerts\AlertEvaluator;
use App\Support\ListingMarket;
use Database\Seeders\IndexUniverseSeeder;
use Database\Seeders\Sp500UniverseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * universe-registry (docs/specs/multi-market-universes.md, Feature 1): Listing
 * Market and currency, the Screener universe registry with its public switch,
 * the generic index seeder, the universe-aware Screener, Saved Screeners and
 * alerts, and the US-only gates. Offline: fixture datasets only.
 */
class UniverseRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingestion.universe' => 'sp500', 'universes.public' => ['sp500']]);
        // Product default language is Spanish; assert English messages.
        $this->withHeader('Accept-Language', 'en');
    }

    private function indexSeeder(string $dataset, string $market = ListingMarket::XMAD, string $slug = 'fixture-eu', string $name = 'Fixture EU', int $position = 5): IndexUniverseSeeder
    {
        return new FixtureIndexSeeder($slug, $name, $market, $position, 'tests/fixtures/universes/'.$dataset);
    }

    private function seedEu(): Universe
    {
        $this->indexSeeder('fixture-eu.csv')->run();

        return Universe::query()->where('slug', 'fixture-eu')->firstOrFail();
    }

    private function sp500(): Universe
    {
        return Universe::factory()->create(['slug' => 'sp500', 'name' => 'S&P 500', 'position' => 0]);
    }

    private function usStock(Universe $universe, string $ticker, float $rsi = 50): Instrument
    {
        $instrument = Instrument::factory()->create(['ticker' => $ticker, 'company' => "{$ticker} Corp"]);
        $universe->instruments()->attach($instrument->id);
        $this->data($instrument, $rsi);

        return $instrument;
    }

    private function data(Instrument $instrument, float $rsi = 50, string $date = '2026-10-01'): void
    {
        DailyBar::factory()->create(['instrument_id' => $instrument->id, 'date' => $date]);
        IndicatorSnapshot::factory()->create(['instrument_id' => $instrument->id, 'date' => $date, 'rsi14' => $rsi]);
    }

    /** @return list<string> */
    private function tickers(string $query = ''): array
    {
        return array_column($this->getJson('/api/screener'.$query)->assertOk()->json('candidates'), 'ticker');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    // --- Migrations and defaults ---------------------------------------

    public function test_existing_style_rows_are_us_usd_and_sp500_is_the_only_screener_universe_after_seeding(): void
    {
        $instrument = Instrument::factory()->create();
        $etf = Instrument::factory()->etf()->create();
        $this->assertSame('US', $instrument->fresh()->market);
        $this->assertSame('USD', $instrument->fresh()->currency);
        $this->assertSame('US', $etf->fresh()->market);
        $this->assertSame('USD', $etf->fresh()->currency);

        $this->seed(Sp500UniverseSeeder::class);

        $sp500 = Universe::query()->where('slug', 'sp500')->firstOrFail();
        $this->assertTrue($sp500->is_screener);
        $this->assertSame(0, $sp500->position);
        $this->assertSame('US', $sp500->market);
        $this->assertSame('USD', $sp500->currency);
        $this->assertSame(['sp500'], Universe::query()->screener()->pluck('slug')->all());
    }

    public function test_a_universe_created_without_flags_is_not_a_screener_universe(): void
    {
        $universe = Universe::query()->create(['name' => 'Plain', 'slug' => 'plain']);

        $this->assertFalse($universe->fresh()->is_screener);
        $this->assertSame(0, $universe->fresh()->position);
        $this->assertSame('US', $universe->fresh()->market);
    }

    public function test_the_default_screener_response_only_gains_market_and_currency(): void
    {
        $universe = $this->sp500();
        $this->usStock($universe, 'AAA');
        $this->refreshScreenerRows();

        $response = $this->getJson('/api/screener')->assertOk();

        $this->assertSame(['universe', 'sort', 'candidates', 'meta'], array_keys($response->json()));
        $this->assertSame('US', $response->json('candidates.0.market'));
        $this->assertSame('USD', $response->json('candidates.0.currency'));
    }

    // --- Registry endpoint and visibility -----------------------------

    public function test_the_universes_endpoint_lists_visible_screener_universes_in_order(): void
    {
        $sp500 = $this->sp500();
        $this->usStock($sp500, 'AAA');
        $this->usStock($sp500, 'BBB');
        $inactive = $this->usStock($sp500, 'OFF');
        $inactive->update(['active' => false]);
        Universe::factory()->notScreener()->create(['slug' => 'market-context', 'name' => 'Market Context']);
        $this->seedEu();
        $this->refreshScreenerRows();

        // Visitor: only the public one; Market Context never.
        $this->getJson('/api/universes')->assertOk()->assertExactJson(['universes' => [[
            'slug' => 'sp500', 'name' => 'S&P 500', 'market' => 'US', 'currency' => 'USD',
            'members' => 2, 'latest_date' => '2026-10-01', 'is_default' => true,
        ]]]);

        // Admin: also the not-yet-public universe, ordered by position.
        $this->actingAs($this->admin())->getJson('/api/universes')->assertOk()
            ->assertJsonPath('universes.0.slug', 'sp500')
            ->assertJsonPath('universes.1.slug', 'fixture-eu')
            ->assertJsonPath('universes.1.name', 'Fixture EU')
            ->assertJsonPath('universes.1.market', 'XMAD')
            ->assertJsonPath('universes.1.currency', 'EUR')
            ->assertJsonPath('universes.1.members', 2)
            ->assertJsonPath('universes.1.latest_date', null)
            ->assertJsonPath('universes.1.is_default', false)
            ->assertJsonCount(2, 'universes');
    }

    public function test_a_signed_in_non_admin_sees_what_a_visitor_sees(): void
    {
        $this->sp500();
        $this->seedEu();

        $this->actingAs(User::factory()->create())->getJson('/api/universes')->assertOk()
            ->assertJsonCount(1, 'universes')
            ->assertJsonPath('universes.0.slug', 'sp500');
    }

    public function test_a_universe_in_the_public_list_is_visible_to_everyone_and_can_be_hidden_again(): void
    {
        $this->sp500();
        $this->seedEu();

        config(['universes.public' => ['sp500', 'fixture-eu']]);
        $this->getJson('/api/universes')->assertOk()->assertJsonCount(2, 'universes');

        // The kill-switch: removing the slug hides it at once.
        config(['universes.public' => ['sp500']]);
        $this->getJson('/api/universes')->assertOk()->assertJsonCount(1, 'universes');
        $this->getJson('/api/screener?universe=fixture-eu')->assertNotFound();
    }

    public function test_the_screener_serves_a_visible_universe_with_its_currency(): void
    {
        $sp500 = $this->sp500();
        $this->usStock($sp500, 'AAA');
        $eu = $this->seedEu();
        foreach (Instrument::query()->whereIn('ticker', ['SAN.MC', 'ITX.MC'])->get() as $instrument) {
            $this->data($instrument);
        }
        $this->refreshScreenerRows();
        config(['universes.public' => ['sp500', 'fixture-eu']]);

        $response = $this->getJson('/api/screener?universe=fixture-eu')->assertOk();

        $this->assertSame('fixture-eu', $response->json('universe.slug'));
        $this->assertSame(['ITX.MC', 'SAN.MC'], collect($response->json('candidates'))->pluck('ticker')->sort()->values()->all());
        $this->assertSame(['EUR'], collect($response->json('candidates'))->pluck('currency')->unique()->values()->all());
        $this->assertSame(['XMAD'], collect($response->json('candidates'))->pluck('market')->unique()->values()->all());
        $this->assertSame(['AAA'], $this->tickers());
        $this->assertNotNull($eu);
    }

    public function test_market_context_unknown_and_hidden_universes_are_404_json(): void
    {
        $this->sp500();
        Universe::factory()->notScreener()->create(['slug' => 'market-context', 'name' => 'Market Context']);
        $this->seedEu();

        foreach (['market-context', 'does-not-exist', 'fixture-eu'] as $slug) {
            $this->getJson('/api/screener?universe='.$slug)
                ->assertNotFound()
                ->assertExactJson(['message' => 'Universe not found.']);
        }

        // An Admin may preview the hidden Screener universe, never Market Context.
        $admin = $this->admin();
        $this->actingAs($admin)->getJson('/api/screener?universe=fixture-eu')->assertOk();
        $this->actingAs($admin)->getJson('/api/screener?universe=market-context')->assertNotFound();
    }

    // --- Seeder rules ---------------------------------------------------

    public function test_a_mc_ticker_in_an_xetr_dataset_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SAN.MC');

        $this->indexSeeder('bad-xetr-mc.csv', ListingMarket::XETR, 'fixture-de', 'Fixture DE')->run();
    }

    public function test_a_de_ticker_in_a_us_dataset_throws_and_stores_nothing(): void
    {
        try {
            $this->indexSeeder('bad-us-de.csv', ListingMarket::US, 'fixture-us', 'Fixture US')->run();
            $this->fail('Expected the seeder to throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('BMW.DE', $exception->getMessage());
        }

        $this->assertSame(0, Instrument::query()->count());
        $this->assertSame(0, Universe::query()->count());
    }

    public function test_a_de_ticker_in_an_xmad_dataset_throws(): void
    {
        $this->expectException(RuntimeException::class);

        $this->indexSeeder('bad-suffix-xmad.csv')->run();
    }

    public function test_an_existing_instrument_with_another_market_throws(): void
    {
        Instrument::factory()->create(['ticker' => 'SAN.MC', 'market' => 'XETR', 'currency' => 'EUR']);

        try {
            $this->indexSeeder('fixture-eu.csv')->run();
            $this->fail('Expected the seeder to throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('SAN.MC', $exception->getMessage());
        }

        $this->assertSame(0, Universe::query()->count());
    }

    public function test_an_existing_instrument_with_another_currency_throws(): void
    {
        Instrument::factory()->create(['ticker' => 'SAN.MC', 'market' => 'XMAD', 'currency' => 'USD']);

        $this->expectException(RuntimeException::class);

        $this->indexSeeder('fixture-eu.csv')->run();
    }

    public function test_rerunning_the_seeder_is_a_no_op(): void
    {
        $this->seedEu();
        $before = [
            Instrument::query()->orderBy('id')->get()->toArray(),
            Universe::query()->orderBy('id')->get()->toArray(),
            DB::table('instrument_universe')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
        ];

        $this->seedEu();

        $this->assertSame($before, [
            Instrument::query()->orderBy('id')->get()->toArray(),
            Universe::query()->orderBy('id')->get()->toArray(),
            DB::table('instrument_universe')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
        ]);
        $eu = Universe::query()->where('slug', 'fixture-eu')->firstOrFail();
        $this->assertSame('XMAD', $eu->market);
        $this->assertSame('EUR', $eu->currency);
        $this->assertTrue($eu->is_screener);
        $this->assertSame(5, $eu->position);
        $this->assertSame(['EUR'], Instrument::query()->pluck('currency')->unique()->values()->all());
    }

    public function test_removing_a_member_detaches_it_keeps_its_bars_and_deactivates_only_orphans(): void
    {
        $eu = $this->seedEu();
        $itx = Instrument::query()->where('ticker', 'ITX.MC')->firstOrFail();
        $this->data($itx);

        // ITX.MC also belongs to a second Screener universe; SAN.MC does not.
        $this->data(Instrument::query()->where('ticker', 'SAN.MC')->firstOrFail());
        $other = Universe::factory()->create(['slug' => 'other-eu', 'name' => 'Other']);
        $other->instruments()->attach($itx->id);

        // A snapshot with only SAN.MC: ITX.MC is detached from fixture-eu...
        $this->indexSeeder('fixture-eu-one.csv')->run();

        $this->assertSame(['SAN.MC'], $eu->instruments()->pluck('ticker')->all());
        $this->assertTrue($itx->fresh()->active, 'still in another Screener universe');
        $this->assertSame(1, $itx->dailyBars()->count());

        // ...and when it is in no Screener universe it becomes inactive, with its history.
        $other->instruments()->detach($itx->id);
        $this->indexSeeder('fixture-eu.csv')->run();
        $this->indexSeeder('fixture-eu-one.csv')->run();

        $this->assertFalse($itx->fresh()->active);
        $this->assertSame(1, $itx->dailyBars()->count());
        $this->assertSame(1, Instrument::query()->where('ticker', 'ITX.MC')->count());

        // Re-adding it makes it active again.
        $this->indexSeeder('fixture-eu.csv')->run();
        $this->assertTrue($itx->fresh()->active);
        $this->assertSame(['ITX.MC', 'SAN.MC'], $eu->instruments()->orderBy('ticker')->pluck('ticker')->all());
    }

    public function test_an_overlapping_sp500_instrument_keeps_its_sp500_fields_and_both_universes_list_it(): void
    {
        $sp500 = $this->sp500();
        $alpha = Instrument::factory()->create([
            'ticker' => 'AAA', 'company' => 'Alpha Inc', 'sector' => 'Information Technology',
            'industry' => 'Software', 'country' => 'US', 'exchange' => 'NASDAQ',
        ]);
        $sp500->instruments()->attach($alpha->id);
        $this->data($alpha);

        (new FixtureIndexSeeder('fixture-nq', 'Fixture NQ', ListingMarket::US, 1, 'tests/fixtures/universes/fixture-us.csv'))->run();

        $alpha->refresh();
        $this->assertSame('Alpha Inc', $alpha->company);
        $this->assertSame('Information Technology', $alpha->sector);
        $this->assertSame('US', $alpha->country);
        $this->assertSame('NASDAQ', $alpha->exchange);
        $this->assertSame(1, Instrument::query()->where('ticker', 'AAA')->count());
        $this->assertSame(['fixture-nq', 'sp500'], $alpha->universes()->orderBy('slug')->pluck('slug')->all());

        // One screener row, listed in both universes' Candidate lists.
        $new = Instrument::query()->where('ticker', 'NEW')->firstOrFail();
        $this->data($new);
        $this->refreshScreenerRows();
        config(['universes.public' => ['sp500', 'fixture-nq']]);

        $this->assertSame(1, ScreenerRow::query()->where('ticker', 'AAA')->count());
        $this->assertSame(['AAA'], $this->tickers());
        $this->assertSame(['AAA', 'NEW'], collect($this->tickers('?universe=fixture-nq&sort=rsi_asc'))->sort()->values()->all());
        // The new US member is classified from the dataset.
        $this->assertSame('New Co', $new->fresh()->company);
        $this->assertSame('US', $new->fresh()->market);
    }

    // --- Saved Screeners ------------------------------------------------

    private const FILTERS = [
        'signal' => [], 'rsi_min' => null, 'rsi_max' => null, 'min_rvol' => null, 'price_above_sma200' => false,
        'ma_cross' => null, 'pattern' => [], 'pattern_status' => 'any', 'sort' => 'rvol_desc',
    ];

    public function test_a_saved_screener_round_trips_its_universe_and_legacy_rows_read_as_sp500(): void
    {
        $this->sp500();
        $this->seedEu();
        config(['universes.public' => ['sp500', 'fixture-eu']]);
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/screeners', ['name' => 'EU', 'filters' => self::FILTERS + ['universe' => 'fixture-eu']])
            ->assertCreated()
            ->assertJsonPath('screener.universe', ['slug' => 'fixture-eu', 'name' => 'Fixture EU'])
            ->assertJsonPath('screener.filters.universe', 'fixture-eu');

        // Explicitly the default universe is stored as the default.
        $this->actingAs($user)->postJson('/api/screeners', ['name' => 'US', 'filters' => self::FILTERS + ['universe' => 'sp500']])
            ->assertCreated()
            ->assertJsonPath('screener.universe', ['slug' => 'sp500', 'name' => 'S&P 500']);
        $user->savedScreeners()->create(['name' => 'Legacy', 'filters' => self::FILTERS]);

        $listed = $this->actingAs($user)->getJson('/api/screeners')->assertOk()->json('screeners');
        $byName = collect($listed)->keyBy('name');

        $this->assertSame('fixture-eu', $byName['EU']['filters']['universe']);
        $this->assertSame(['slug' => 'fixture-eu', 'name' => 'Fixture EU'], $byName['EU']['universe']);
        $this->assertSame(['slug' => 'sp500', 'name' => 'S&P 500'], $byName['Legacy']['universe']);
        $this->assertArrayNotHasKey('universe', $byName['Legacy']['filters']);
        $this->assertCount(20, $byName['Legacy']['filters']);
        $this->assertSame('sp500', $byName['US']['universe']['slug']);
    }

    public function test_a_saved_screener_rejects_hidden_unknown_and_non_screener_universes(): void
    {
        $this->sp500();
        $this->seedEu();
        Universe::factory()->notScreener()->create(['slug' => 'market-context']);
        $user = User::factory()->create();

        foreach (['fixture-eu', 'nope', 'market-context'] as $slug) {
            $this->actingAs($user)->postJson('/api/screeners', ['name' => 'X '.$slug, 'filters' => self::FILTERS + ['universe' => $slug]])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('filters.universe');
        }
        $this->assertSame(0, SavedScreener::query()->count());

        // An Admin may save against the preview universe.
        $this->actingAs($this->admin())->postJson('/api/screeners', ['name' => 'Preview', 'filters' => self::FILTERS + ['universe' => 'fixture-eu']])
            ->assertCreated();
    }

    public function test_sector_context_filters_are_rejected_for_a_non_us_universe(): void
    {
        $this->sp500();
        $this->seedEu();
        config(['universes.public' => ['sp500', 'fixture-eu'], 'market_context.public' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/screener?universe=fixture-eu&sector_trend=uptrend')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'filter_not_available_for_universe')
            ->assertJsonValidationErrors('sector_trend');
        $this->actingAs($user)->getJson('/api/screener?universe=fixture-eu&sector_rs_rank_max=3')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sector_rs_rank_max');
        // The US universe is unchanged.
        $this->actingAs($user)->getJson('/api/screener?sector_trend=uptrend')->assertOk();

        $this->actingAs($user)->postJson('/api/screeners', [
            'name' => 'EU ctx',
            'filters' => self::FILTERS + ['universe' => 'fixture-eu', 'sector_trend' => 'uptrend', 'sector_rs_rank_max' => null],
        ])->assertUnprocessable()->assertJsonValidationErrors('filters.sector_trend');
        $this->assertSame(0, SavedScreener::query()->count());
    }

    // --- Alerts -----------------------------------------------------------

    private function screenerAlert(User $user, array $filters): Alert
    {
        $screener = $user->savedScreeners()->create(['name' => 'Alert '.uniqid(), 'filters' => $filters + self::FILTERS]);

        return $user->alerts()->create(['kind' => Alert::KIND_SCREENER, 'saved_screener_id' => $screener->id]);
    }

    public function test_a_screener_alert_evaluates_in_its_own_universe_only(): void
    {
        $sp500 = $this->sp500();
        $this->usStock($sp500, 'AAA');
        $eu = $this->seedEu();
        foreach (Instrument::query()->whereIn('ticker', ['SAN.MC', 'ITX.MC'])->get() as $instrument) {
            $this->data($instrument);
        }
        $this->refreshScreenerRows();
        config(['universes.public' => ['sp500', 'fixture-eu']]);
        $user = User::factory()->create();
        $euAlert = $this->screenerAlert($user, ['universe' => 'fixture-eu']);
        $usAlert = $this->screenerAlert($user, []);

        $evaluator = app(AlertEvaluator::class);
        $this->assertSame(AlertEvaluator::BASELINE, $evaluator->evaluate($euAlert, '2026-10-01'));
        $this->assertSame(AlertEvaluator::BASELINE, $evaluator->evaluate($usAlert, '2026-10-01'));

        $this->assertSame(['tickers' => ['ITX.MC', 'SAN.MC']], $euAlert->fresh()->last_state);
        $this->assertSame(['tickers' => ['AAA']], $usAlert->fresh()->last_state);
        $this->assertNotNull($eu);
    }

    public function test_a_screener_alert_on_a_universe_the_owner_can_no_longer_see_is_skipped(): void
    {
        $this->sp500();
        $this->seedEu();
        config(['universes.public' => ['sp500', 'fixture-eu']]);
        $user = User::factory()->create();
        $alert = $this->screenerAlert($user, ['universe' => 'fixture-eu']);

        // The kill-switch hides the universe: no baseline, no notification, state untouched.
        config(['universes.public' => ['sp500']]);

        $this->assertSame(AlertEvaluator::SKIPPED, app(AlertEvaluator::class)->evaluate($alert, '2026-10-01'));
        $this->assertNull($alert->fresh()->last_state);
        $this->assertNull($alert->fresh()->last_evaluated_as_of);
        $this->assertSame(0, $user->notifications()->count());

        // An Admin owner keeps evaluating it (preview).
        $adminAlert = $this->screenerAlert($this->admin(), ['universe' => 'fixture-eu']);
        $this->assertSame(AlertEvaluator::BASELINE, app(AlertEvaluator::class)->evaluate($adminAlert, '2026-10-01'));
    }

    // --- US-only gates ------------------------------------------------------

    public function test_us_only_endpoints_are_404_for_a_non_us_instrument_and_availability_says_so(): void
    {
        $this->seedEu();
        $this->usStock($this->sp500(), 'NVDA');
        $user = User::factory()->create();

        $payload = $this->getJson('/api/instruments/SAN.MC')->assertOk()->json('instrument');
        $this->assertSame('XMAD', $payload['market']);
        $this->assertSame('EUR', $payload['currency']);
        $this->assertSame(['company_card' => false, 'fundamentals' => false, 'forecast' => false], $payload['availability']);

        $us = $this->getJson('/api/instruments/NVDA')->assertOk()->json('instrument');
        $this->assertSame('US', $us['market']);
        $this->assertSame('USD', $us['currency']);
        $this->assertSame(['company_card' => true, 'fundamentals' => true, 'forecast' => true], $us['availability']);

        $expected = ['message' => 'This feature is not available for instruments listed on this market.'];
        $this->actingAs($user)->getJson('/api/instruments/SAN.MC/company-card')->assertNotFound()->assertExactJson($expected);
        $this->actingAs($user)->postJson('/api/instruments/SAN.MC/company-card')->assertNotFound()->assertExactJson($expected);
        $this->actingAs($user)->postJson('/api/instruments/SAN.MC/forecast', ['horizon' => (int) config('forecast.horizons')[0], 'minimum_probability' => 60])
            ->assertNotFound()->assertExactJson($expected);
        // A US stock still reaches the card (a stored-card read).
        $this->actingAs($user)->getJson('/api/instruments/NVDA/company-card')->assertOk();
    }

    public function test_the_not_available_message_is_localized(): void
    {
        $this->seedEu();
        $user = User::factory()->create();

        $this->withHeader('Accept-Language', 'es')->actingAs($user)->getJson('/api/instruments/SAN.MC/company-card')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Esta función no está disponible para instrumentos cotizados en este mercado.']);
    }

    public function test_fundamentals_refresh_skips_non_us_instruments(): void
    {
        $sp500 = $this->sp500();
        $this->usStock($sp500, 'AAA');
        $eu = $this->seedEu();
        $sp500->instruments()->attach(Instrument::query()->where('ticker', 'SAN.MC')->value('id'));

        $refresher = app(\App\Services\Fundamentals\FundamentalsRefresher::class);

        $this->assertSame(['AAA'], $refresher->instruments([])->pluck('ticker')->all());
        $this->assertSame(['AAA'], $refresher->instruments(['AAA', 'SAN.MC'])->pluck('ticker')->all());
        $this->assertSame(0, CompanyFundamental::query()->count());
        $this->assertNotNull($eu);
    }

    public function test_the_watchlist_payload_carries_market_and_currency(): void
    {
        $this->seedEu();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/watchlist', ['ticker' => 'SAN.MC'])
            ->assertCreated()
            ->assertJsonPath('item.market', 'XMAD')
            ->assertJsonPath('item.currency', 'EUR');
        $this->actingAs($user)->getJson('/api/watchlist')->assertOk()
            ->assertJsonPath('items.0.currency', 'EUR');
    }

    public function test_listing_market_helper_rules(): void
    {
        $this->assertTrue(ListingMarket::tickerMatches('US', 'BRK.B'));
        $this->assertFalse(ListingMarket::tickerMatches('US', 'SAP.DE'));
        $this->assertFalse(ListingMarket::tickerMatches('XMAD', 'SAP.DE'));
        $this->assertFalse(ListingMarket::tickerMatches('XMAD', 'SAN'));
        $this->assertTrue(ListingMarket::tickerMatches('XETR', 'MRK.DE'));
        $this->assertFalse(ListingMarket::tickerMatches('NOPE', 'AAA'));
        $this->assertSame('EUR', ListingMarket::currency('XETR'));
        $this->assertSame('Europe/Madrid', ListingMarket::timezone('XMAD'));
        $this->assertSame('17:30', ListingMarket::close('XMAD'));
        $this->assertSame(['US', 'XMAD', 'XETR'], ListingMarket::codes());
    }
}

/**
 * A fixture index seeder (the base class is abstract and concrete indexes
 * arrive with later features).
 */
class FixtureIndexSeeder extends IndexUniverseSeeder
{
    public function __construct(
        private readonly string $universeSlug,
        private readonly string $display,
        private readonly string $listing,
        private readonly int $order,
        private readonly string $path,
    ) {}

    protected function slug(): string
    {
        return $this->universeSlug;
    }

    protected function universeName(): string
    {
        return $this->display;
    }

    protected function market(): string
    {
        return $this->listing;
    }

    protected function position(): int
    {
        return $this->order;
    }

    protected function datasetPath(): string
    {
        return $this->path;
    }
}
