<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsMarketContextFixture;
use Tests\TestCase;

/**
 * `GET /api/market-context` (docs/specs/market-context-ui.md, C1, C5, C6;
 * Scenarios 1, 4, 5, 6, 7) on the production-like fixture. Registered Users
 * only since market-context-patterns (C1-C2): guests get 401.
 */
class MarketContextApiTest extends TestCase
{
    use BuildsMarketContextFixture;
    use RefreshDatabase;

    private ?User $user = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->setUpMarketContextFixture();
        config(['market_context.public' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_public_payload_has_the_contract_keys_and_values(): void
    {
        $this->productionLikeFixture();

        $response = $this->asUser()->getJson('/api/market-context')->assertOk();
        $body = $response->json();

        $this->assertSame(['as_of', 'public', 'regime', 'sectors', 'breadth', 'macro'], array_keys($body));
        $this->assertSame('2026-10-23', $body['as_of']);
        $this->assertTrue($body['public']);

        $this->assertSame(
            ['date', 'stale', 'regime', 'score', 'available', 'rules_version', 'rules', 'components'],
            array_keys($body['regime']),
        );
        $this->assertSame('2026-10-22', $body['regime']['date']);
        $this->assertFalse($body['regime']['stale']);
        $this->assertSame('neutral', $body['regime']['regime']);
        $this->assertSame(1, $body['regime']['score']);
        $this->assertSame(5, $body['regime']['available']);
        $this->assertSame(1, $body['regime']['rules_version']);
        $this->assertSame(['min_available' => 4, 'favourable_min_score' => 3, 'adverse_max_score' => -3], $body['regime']['rules']);
        // Passthrough, stored order, disabled components included.
        $this->assertSame(self::REGIME_COMPONENTS, $body['regime']['components']);

        $this->assertSame(['date' => '2026-10-22', 'stale' => false], array_intersect_key($body['sectors'], ['date' => 1, 'stale' => 1]));
        $rows = $body['sectors']['rows'];
        $this->assertCount(11, $rows);
        $this->assertSame(range(1, 11), array_column($rows, 'rs_rank'));
        $this->assertSame(
            ['ticker' => 'XLK', 'sector_key' => 'information_technology', 'ret_5' => 1.23, 'ret_20' => 4.5, 'ret_60' => 9.87,
                'rs_60' => 3.21, 'rs_rank' => 1, 'trend' => 'uptrend', 'breadth_200' => 71.43],
            $rows[0],
        );
        $keys = array_column($rows, 'sector_key');
        sort($keys);
        $this->assertSame([
            'communication_services', 'consumer_discretionary', 'consumer_staples', 'energy', 'financials', 'health_care',
            'industrials', 'information_technology', 'materials', 'real_estate', 'utilities',
        ], $keys);

        $this->assertSame([
            'date' => '2026-10-22', 'stale' => false, 'members' => 503, 'pct_above_sma50' => 58.1, 'pct_above_sma200' => 55.2,
            'new_highs' => 21, 'new_lows' => 4, 'advancers' => 301, 'decliners' => 195, 'spy_trend' => 'uptrend',
        ], $body['breadth']);

        // Decimals are JSON numbers, never strings.
        $raw = $response->getContent();
        $this->assertStringContainsString('"ret_5":1.23', $raw);
        $this->assertStringContainsString('"pct_above_sma200":55.2', $raw);

        $macro = $body['macro'];
        $this->assertSame(['rates', 'curve', 'liquidity', 'leading', 'central_banks', 'sources'], array_keys($macro));
        $this->assertSame(['value' => 4.11, 'as_of' => '2026-10-21', 'stale' => false], $macro['rates']['dgs10']);
        $this->assertSame(['value' => 3.8, 'as_of' => '2026-10-21', 'stale' => false], $macro['rates']['dgs3mo']);
        $this->assertEqualsWithDelta(0.10, $macro['rates']['dgs10_change_20']['value'], 0.00001);
        $this->assertEqualsWithDelta(0.31, $macro['curve']['spread_10y3m']['value'], 0.00001);
        $this->assertEqualsWithDelta(0.51, $macro['curve']['spread_10y2y']['value'], 0.00001);
        $this->assertFalse($macro['curve']['inverted']);
        $this->assertEquals(['value' => 6130, 'as_of' => '2026-10-14', 'stale' => false], $macro['liquidity']['net_liquidity']);
        $this->assertSame(1.0717, $macro['liquidity']['change_4w_pct']['value']);
        $this->assertSame(2.1667, $macro['liquidity']['change_13w_pct']['value']);
        $this->assertSame(
            ['icsa', 'icsa_4w', 'icsa_4w_vs_52w_min_pct', 'permit', 'permit_change_3m_pct', 'nfci', 'oecd_cli', 'oecd_cli_change_1m'],
            array_keys($macro['leading']),
        );
        $this->assertSame(227500.0, (float) $macro['leading']['icsa_4w']['value']);
        $this->assertNull($macro['leading']['nfci']);
        // Monthly readings keep the date of the observation (the 1st).
        $this->assertSame('2026-09-01', $macro['leading']['permit']['as_of']);
        $this->assertSame('2026-09-01', $macro['leading']['permit_change_3m_pct']['as_of']);
        $this->assertSame(3.5714, $macro['leading']['permit_change_3m_pct']['value']);
        $this->assertSame('2026-09-01', $macro['leading']['oecd_cli']['as_of']);
        $this->assertEqualsWithDelta(0.2, $macro['leading']['oecd_cli_change_1m']['value'], 0.00001);

        $fed = $macro['central_banks']['fed'];
        $this->assertSame(['lower', 'upper', 'last_change', 'last_meeting', 'next_meeting', 'calendar'], array_keys($fed));
        $this->assertEquals(
            ['direction' => 'cut', 'from' => ['lower' => 4.0, 'upper' => 4.25], 'to' => ['lower' => 3.75, 'upper' => 4.0], 'effective_date' => '2026-09-17'],
            $fed['last_change'],
        );
        $this->assertSame(['start' => '2026-09-15', 'decision' => '2026-09-16'], $fed['last_meeting']);
        $this->assertSame(['start' => '2026-10-27', 'decision' => '2026-10-28'], $fed['next_meeting']);
        $this->assertSame(
            ['source_url' => 'https://www.federalreserve.gov/monetarypolicy/fomccalendars.htm', 'checked_at' => '2026-10-22'],
            $fed['calendar'],
        );
        $ecb = $macro['central_banks']['ecb'];
        $this->assertSame(['deposit_rate', 'last_change', 'last_meeting', 'next_meeting', 'calendar'], array_keys($ecb));
        $this->assertEquals(['direction' => 'cut', 'from' => 2.75, 'to' => 2.5, 'effective_date' => '2026-06-11'], $ecb['last_change']);
        $this->assertSame('https://www.ecb.europa.eu/press/calendars/mgcgc/html/index.en.html', $ecb['calendar']['source_url']);

        $this->assertSame(['rates', 'liquidity', 'central_banks', 'leading'], array_keys($macro['sources']));
        $this->assertSame(['Board of Governors of the Federal Reserve System (US), H.15 Selected Interest Rates, via FRED®'], $macro['sources']['rates']);
        $this->assertSame([
            'Board of Governors of the Federal Reserve System (US), FOMC Press Release, via FRED®',
            'Source: ECB statistics, via FRED®',
        ], $macro['sources']['central_banks']);
        $this->assertSame([
            'U.S. Employment and Training Administration, via FRED®',
            'U.S. Census Bureau and U.S. Department of Housing and Urban Development, via FRED®',
            'OECD, Composite Leading Indicators (CC BY 4.0), via FRED®',
        ], $macro['sources']['leading']);
        // NFCI off: no Chicago Fed source anywhere.
        $this->assertStringNotContainsString('Chicago', $raw);
    }

    public function test_sector_rows_order_nulls_last_then_ticker(): void
    {
        $rows = self::SECTOR_ROWS;
        $rows['XLE'][0] = null;
        $rows['XLB'][0] = null;
        $this->sectorRows(self::LATEST, $rows);

        $tickers = array_column($this->asUser()->getJson('/api/market-context')->assertOk()->json('sectors.rows'), 'ticker');

        $this->assertSame(['XLB', 'XLE'], array_slice($tickers, -2));
        $this->assertSame('XLK', $tickers[0]);
    }

    public function test_stale_flags_follow_seven_days(): void
    {
        // 2026-10-16 is 7 days before 10-23 (fresh); 10-15 is 8 days (stale).
        $this->regimeRow('2026-10-16');
        $this->breadthRow('2026-10-15');
        $this->sectorRows('2026-10-15');

        $body = $this->asUser()->getJson('/api/market-context')->assertOk()->json();

        $this->assertFalse($body['regime']['stale']);
        $this->assertTrue($body['breadth']['stale']);
        $this->assertTrue($body['sectors']['stale']);
    }

    public function test_insufficient_regime_and_stale_snapshots(): void
    {
        $components = self::REGIME_COMPONENTS;
        $components['breadth'] = ['status' => 'stale', 'score' => null, 'value' => 55.2, 'as_of' => '2026-10-14', 'stale' => true];
        $components['sector_participation'] = ['status' => 'stale', 'score' => null, 'value' => 6, 'as_of' => '2026-10-14', 'stale' => true];
        $this->regimeRow(self::LATEST, 'insufficient', null, 3, $components);
        $this->breadthRow('2026-10-14');
        $this->sectorRows('2026-10-14');

        $body = $this->asUser()->getJson('/api/market-context')->assertOk()->json();

        $this->assertSame('insufficient', $body['regime']['regime']);
        $this->assertNull($body['regime']['score']);
        $this->assertSame(3, $body['regime']['available']);
        $this->assertSame('stale', $body['regime']['components']['breadth']['status']);
        $this->assertTrue($body['breadth']['stale']);
        $this->assertTrue($body['sectors']['stale']);
    }

    public function test_macro_is_null_when_disabled(): void
    {
        config(['macro.enabled' => false]);
        $this->productionLikeFixture();

        $body = $this->asUser()->getJson('/api/market-context')->assertOk()->json();

        $this->assertNull($body['macro']);
        $this->assertSame('neutral', $body['regime']['regime']);
    }

    public function test_empty_database_gives_nulls_and_empty_readings(): void
    {
        $body = $this->asUser()->getJson('/api/market-context')->assertOk()->json();

        $this->assertNull($body['regime']);
        $this->assertNull($body['sectors']);
        $this->assertNull($body['breadth']);
        $this->assertSame(['value' => null, 'as_of' => null, 'stale' => false], $body['macro']['rates']['dgs10']);
        $this->assertSame(['value' => null, 'as_of' => null, 'stale' => false], $body['macro']['liquidity']['net_liquidity']);
        $this->assertNull($body['macro']['leading']['nfci']);
    }

    public function test_gate_off_is_401_for_guests_404_for_users_and_open_for_admins(): void
    {
        // Production rendering (no debug trace): the ability's denyAsNotFound
        // message is the whole JSON body.
        config(['market_context.public' => false, 'app.debug' => false]);
        $this->productionLikeFixture();

        $this->getJson('/api/market-context')->assertUnauthorized();

        $this->asUser()->getJson('/api/market-context')
            ->assertNotFound()
            ->assertExactJson(['message' => 'This page is not available yet.']);
        $this->asUser()->withHeader('Accept-Language', 'es')->getJson('/api/market-context')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Esta página aún no está disponible.']);

        // A fresh request in production; the test app keeps resolved guards.
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/market-context')
            ->assertOk()
            ->assertJsonPath('public', false)
            ->assertJsonPath('regime.regime', 'neutral');
    }

    public function test_admin_preview_works_through_the_stateful_spa_session(): void
    {
        config(['market_context.public' => false]);
        $admin = User::factory()->admin()->create();

        // A first-party SPA request (stateful domain): the session user passes `auth:sanctum`.
        $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost/'])
            ->actingAs($admin, 'web')
            ->getJson('/api/market-context')
            ->assertOk();
    }

    public function test_gate_on_serves_registered_users_and_401s_guests(): void
    {
        $this->productionLikeFixture();

        $this->getJson('/api/market-context')->assertUnauthorized();
        $this->asUser()->getJson('/api/market-context')->assertOk()->assertJsonPath('public', true);
    }

    public function test_payload_is_cached_until_a_recording_command_finishes(): void
    {
        $this->productionLikeFixture();
        $this->asUser()->getJson('/api/market-context')->assertJsonPath('regime.regime', 'neutral');

        DB::table('market_regime_snapshots')->update(['regime' => 'favourable', 'score' => 3]);

        // Still the cached payload.
        $this->asUser()->getJson('/api/market-context')->assertJsonPath('regime.regime', 'neutral');

        // A recording command (here with nothing to compute) renews the version.
        config(['macro.enabled' => false]);
        Artisan::call('macro:refresh');
        $this->asUser()->getJson('/api/market-context')->assertJsonPath('regime.regime', 'neutral');

        Artisan::call('market-regime:compute', ['--date' => '1999-01-01']);
        $this->asUser()->getJson('/api/market-context')->assertJsonPath('regime.regime', 'favourable');
    }

    public function test_a_new_day_rebuilds_the_payload_and_its_stale_flags(): void
    {
        $this->productionLikeFixture();
        $this->asUser()->getJson('/api/market-context')->assertJsonPath('breadth.stale', false);

        DB::table('market_regime_snapshots')->update(['regime' => 'adverse', 'score' => -3]);
        Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00', 'America/New_York'));

        $this->asUser()->getJson('/api/market-context')
            ->assertJsonPath('as_of', '2026-10-30')
            ->assertJsonPath('regime.regime', 'adverse')
            ->assertJsonPath('breadth.stale', true);
    }

    public function test_public_flag_follows_the_live_config_not_the_cache(): void
    {
        $this->productionLikeFixture();
        $admin = User::factory()->admin()->create();
        $this->asUser()->getJson('/api/market-context')->assertJsonPath('public', true);

        config(['market_context.public' => false]);
        $this->app['auth']->forgetGuards();

        $this->actingAs($admin)->getJson('/api/market-context')->assertJsonPath('public', false);
    }

    /** A Registered User; every payload test reads the API signed in (market-context-patterns). */
    private function asUser(): static
    {
        $this->user ??= User::factory()->create();

        return $this->actingAs($this->user);
    }
}
