<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsMarketContextFixture;
use Tests\TestCase;

/**
 * The Screener sector context filters `sector_trend` and `sector_rs_rank_max`
 * (docs/specs/market-context-patterns.md, C5; Scenario 4) on the "context"
 * fixture: AAPL (XLK uptrend, rank 1), XOM (XLE downtrend, rank 10), NEE (XLU
 * mixed, no rank), ZZZ (no sector) and ODD (unmapped sector).
 */
class ScreenerSectorFiltersTest extends TestCase
{
    use BuildsMarketContextFixture;
    use RefreshDatabase;

    private const CANDIDATE_KEYS = [
        'ticker', 'company', 'sector', 'exchange', 'active', 'date', 'close', 'change_percent', 'rvol', 'rsi14', 'signals', 'patterns',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->setUpMarketContextFixture();
        config(['market_context.public' => true]);
        $this->contextFixture();
        Artisan::call('screener:refresh');
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return list<string> */
    private function tickers(string $query): array
    {
        $response = $this->actingAs($this->user)->getJson('/api/screener?'.$query)->assertOk();
        $tickers = array_column($response->json('candidates'), 'ticker');
        $this->assertSame(count($tickers), $response->json('meta.total'));
        sort($tickers);

        return $tickers;
    }

    public function test_scenario_4_results(): void
    {
        $this->assertSame(['AAPL'], $this->tickers('sector_trend=uptrend'));
        $this->assertSame(['XOM'], $this->tickers('sector_trend=downtrend'));
        $this->assertSame(['NEE'], $this->tickers('sector_trend=mixed'));
        $this->assertSame(['AAPL'], $this->tickers('sector_rs_rank_max=1'));
        $this->assertSame(['AAPL'], $this->tickers('sector_rs_rank_max=9'));
        // NEE has a null rank; ZZZ and ODD have no sector context at all.
        $this->assertSame(['AAPL', 'XOM'], $this->tickers('sector_rs_rank_max=11'));
        $this->assertSame([], $this->tickers('sector_trend=mixed&sector_rs_rank_max=11'));
        $this->assertSame(['AAPL', 'NEE', 'ODD', 'XOM', 'ZZZ'], $this->tickers(''));
    }

    public function test_the_sector_filters_combine_with_the_others_by_and(): void
    {
        $this->assertSame(['AAPL', 'XOM'], $this->tickers('sector_rs_rank_max=11&rsi_max=60'));
        $this->assertSame([], $this->tickers('sector_rs_rank_max=11&rsi_min=60'));
        $this->assertSame(['AAPL'], $this->tickers('sector_trend=uptrend&price_above_sma200=1&ma_cross=bullish'));
        $this->assertSame([], $this->tickers('sector_trend=uptrend&ma_cross=bearish'));
    }

    public function test_the_candidate_json_keys_are_unchanged(): void
    {
        $candidate = $this->actingAs($this->user)->getJson('/api/screener?sector_trend=uptrend')->assertOk()->json('candidates.0');

        $this->assertSame(self::CANDIDATE_KEYS, array_keys($candidate));
        $this->assertSame(self::CANDIDATE_KEYS, array_keys($this->getJson('/api/screener')->assertOk()->json('candidates.0')));
    }

    public function test_invalid_values_are_422_on_their_key_for_any_caller(): void
    {
        $cases = [
            'sector_trend=sideways' => 'sector_trend',
            'sector_trend[]=uptrend' => 'sector_trend',
            'sector_rs_rank_max=0' => 'sector_rs_rank_max',
            'sector_rs_rank_max=12' => 'sector_rs_rank_max',
            'sector_rs_rank_max=3.5' => 'sector_rs_rank_max',
            'sector_rs_rank_max=abc' => 'sector_rs_rank_max',
            'sector_rs_rank_max[]=3' => 'sector_rs_rank_max',
        ];

        foreach ($cases as $query => $key) {
            // A guest (validation runs before the access check)...
            $this->getJson('/api/screener?'.$query)->assertUnprocessable()->assertJsonValidationErrors([$key]);
            // ...and a signed-in user alike.
            $this->actingAs($this->user)->getJson('/api/screener?'.$query)->assertUnprocessable()->assertJsonValidationErrors([$key]);
            $this->app['auth']->forgetGuards();
        }

        $this->withHeader('Accept-Language', 'es')->getJson('/api/screener?sector_rs_rank_max=12')
            ->assertJsonPath('errors.sector_rs_rank_max.0', 'El parámetro sector_rs_rank_max debe ser un número entero del 1 al 11.');
    }

    public function test_access_guest_401_denied_user_403_admin_and_allowed_user_200(): void
    {
        $this->getJson('/api/screener?sector_trend=uptrend')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Sign in to use the sector context filters.']);
        $this->withHeader('Accept-Language', 'es')->getJson('/api/screener?sector_rs_rank_max=3')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Inicia sesión para usar los filtros de contexto sectorial.']);
        $this->withHeader('Accept-Language', 'en');

        $this->actingAs($this->user)->getJson('/api/screener?sector_trend=uptrend')->assertOk();

        config(['market_context.public' => false]);

        $this->actingAs($this->user)->getJson('/api/screener?sector_trend=uptrend')
            ->assertForbidden()
            ->assertExactJson(['message' => 'The sector context filters are not available for your account.']);
        // Without the sector keys the route stays anonymous and unchanged.
        $this->actingAs($this->user)->getJson('/api/screener?sector_trend=&sector_rs_rank_max=')->assertOk()->assertJsonPath('meta.total', 5);

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/screener?sector_trend=uptrend')
            ->assertOk()
            ->assertJsonPath('candidates.0.ticker', 'AAPL');
    }

    public function test_the_stateful_spa_session_counts_on_the_public_route(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost/'])
            ->actingAs($this->user, 'web')
            ->getJson('/api/screener?sector_rs_rank_max=1')
            ->assertOk()
            ->assertJsonPath('candidates.0.ticker', 'AAPL');
    }
}
