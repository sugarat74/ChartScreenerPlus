<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsMarketContextFixture;
use Tests\TestCase;

/**
 * The single Market Context entitlement `view-market-context`
 * (docs/specs/market-context-patterns.md, C1-C2; Scenarios 2 and 7): the
 * ability matrix, the `capabilities.market_context` flag of the auth payloads
 * and the status contract of the three Market Context endpoints.
 */
class MarketContextAccessTest extends TestCase
{
    use BuildsMarketContextFixture;
    use RefreshDatabase;

    private const ORIGIN = 'http://localhost:5173';

    private const ENDPOINTS = [
        '/api/market-context',
        '/api/market-context/history?metric=regime_score&range=1y',
        '/api/instruments/AAPL/market-context',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->setUpMarketContextFixture();
        config(['sanctum.stateful' => ['localhost', 'localhost:5173'], 'market_context.public' => false]);

        $aapl = Instrument::factory()->create(['ticker' => 'AAPL', 'sector' => 'Information Technology']);
        $this->mainUniverse->instruments()->attach($aapl->id);
        $this->sectorRows();
        $this->regimeRow();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_ability_matrix(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->admin()->create();

        // Guests never pass (the ability's user parameter is not nullable).
        $this->assertFalse(Gate::allows('view-market-context'));
        $this->assertFalse(Gate::forUser($user)->allows('view-market-context'));
        $this->assertTrue(Gate::forUser($admin)->allows('view-market-context'));

        $denied = Gate::forUser($user)->inspect('view-market-context');
        $this->assertSame(404, $denied->status());
        $this->assertSame('This page is not available yet.', $denied->message());

        config(['market_context.public' => true]);

        $this->assertFalse(Gate::allows('view-market-context'));
        $this->assertTrue(Gate::forUser($user)->allows('view-market-context'));
        $this->assertTrue(Gate::forUser($admin)->allows('view-market-context'));
    }

    public function test_an_account_pending_email_verification_is_denied_even_with_the_flag_on(): void
    {
        // auth-email-verification (D4): the ability also answers outside the
        // `verified` route group (public Screener filters, alerts, capability).
        config([
            'market_context.public' => true,
            'auth.verification.enabled' => true,
            'mail.provider' => 'Acme Mail',
            'mail.provider_location' => 'UE (Francia)',
        ]);
        $pending = User::factory()->unverified()->create();
        $pendingAdmin = User::factory()->admin()->unverified()->create();

        $this->assertSame(404, Gate::forUser($pending)->inspect('view-market-context')->status());
        $this->assertTrue(Gate::forUser($pendingAdmin)->allows('view-market-context'));
        $this->assertTrue(Gate::forUser(User::factory()->create())->allows('view-market-context'));

        $this->actingAs($pending)->getJson('/api/market-context')
            ->assertForbidden()
            ->assertJsonPath('code', 'email_verification.required');

        config(['auth.verification.enabled' => false]);
        $this->assertTrue(Gate::forUser($pending)->allows('view-market-context'));
    }

    public function test_the_capability_follows_the_ability_in_every_auth_payload(): void
    {
        $this->withHeader('Origin', self::ORIGIN);

        $this->postJson('/api/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated()->assertJsonPath('user.capabilities', ['market_context' => false]);

        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.capabilities.market_context', false);

        config(['market_context.public' => true]);

        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.capabilities.market_context', true);
        $this->patchJson('/api/account', ['name' => 'Ada L.'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Ada L.')
            ->assertJsonPath('user.capabilities.market_context', true)
            ->assertJsonMissingPath('user.password');

        config(['market_context.public' => false]);
        $this->postJson('/api/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        User::factory()->admin()->create(['email' => 'root@example.com', 'password' => 'Password123!']);
        $this->postJson('/api/login', ['email' => 'root@example.com', 'password' => 'Password123!'])
            ->assertOk()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('user.capabilities.market_context', true);
    }

    public function test_the_account_export_has_no_capabilities(): void
    {
        $this->actingAs(User::factory()->create())->withHeader('Origin', self::ORIGIN);

        $this->assertStringNotContainsString('capabilities', $this->getJson('/api/account/export')->assertOk()->getContent());
    }

    public function test_guests_get_401_in_both_modes(): void
    {
        foreach ([false, true] as $public) {
            config(['market_context.public' => $public]);

            foreach (self::ENDPOINTS as $endpoint) {
                $this->getJson($endpoint)->assertUnauthorized();
            }
        }
    }

    public function test_users_get_the_not_available_404_while_the_flag_is_off(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();

        foreach (self::ENDPOINTS as $endpoint) {
            $this->actingAs($user)->getJson($endpoint)
                ->assertNotFound()
                ->assertExactJson(['message' => 'This page is not available yet.']);
            $this->actingAs($user)->withHeader('Accept-Language', 'es')->getJson($endpoint)
                ->assertNotFound()
                ->assertExactJson(['message' => 'Esta página aún no está disponible.']);
            $this->withHeader('Accept-Language', 'en');
        }
    }

    public function test_users_get_200_with_the_flag_on(): void
    {
        config(['market_context.public' => true]);
        $user = User::factory()->create();

        foreach (self::ENDPOINTS as $endpoint) {
            $this->actingAs($user)->getJson($endpoint)->assertOk();
        }
    }

    public function test_admins_get_200_with_the_flag_off(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (self::ENDPOINTS as $endpoint) {
            $this->actingAs($admin)->getJson($endpoint)->assertOk();
        }
    }

    public function test_admin_preview_through_the_stateful_spa_session(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (self::ENDPOINTS as $endpoint) {
            $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost/'])
                ->actingAs($admin, 'web')
                ->getJson($endpoint)
                ->assertOk();
        }
    }
}
