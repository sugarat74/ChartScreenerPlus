<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A browser SPA request is only treated as stateful (session + CSRF) by
     * Sanctum when it carries a matching Referer/Origin header. Simulate the
     * dev SPA origin so the register/login/logout session flow is exercised.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost', 'localhost:5173']]);

        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_a_visitor_can_register_and_is_authenticated(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.name', 'Ada Lovelace')
            ->assertJsonPath('user.email', 'ada@example.com');

        $this->assertAuthenticated();

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('Password123!', $user->password));
        $this->assertNotSame('Password123!', $user->password);

        $this->assertArrayNotHasKey('password', $response->json('user'));
        $this->assertArrayNotHasKey('remember_token', $response->json('user'));
    }

    public function test_registration_keeps_the_session_for_follow_up_requests(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertCreated();

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.email', 'grace@example.com');
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/register', [
            'name' => 'Someone Else',
            'email' => 'taken@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Weak Password',
            'email' => 'weak@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_rejects_a_mismatched_password_confirmation(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Mismatch',
            'email' => 'mismatch@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Different123!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_registered_user_can_log_in_and_log_out(): void
    {
        $user = User::factory()->create([
            'email' => 'login@example.com',
            'password' => 'Password123!',
        ]);

        $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'Password123!',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', 'login@example.com');

        $this->assertAuthenticated();

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.email', 'login@example.com');

        // A real HTTP request gets a fresh container; drop cached guard
        // instances so the follow-up requests re-read the session.
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/logout')->assertNoContent();

        $this->app['auth']->forgetGuards();

        $this->getJson('/api/user')->assertUnauthorized();

        $this->assertGuest();
    }

    public function test_login_fails_with_wrong_credentials(): void
    {
        User::factory()->create([
            'email' => 'wrong@example.com',
            'password' => 'Password123!',
        ]);

        $this->postJson('/api/login', [
            'email' => 'wrong@example.com',
            'password' => 'NotThePassword!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
    }

    public function test_registration_without_a_stateful_session_is_rejected_before_writing(): void
    {
        // A non-matching Origin means Sanctum does not attach the session
        // middleware; the endpoint must fail fast instead of creating a user
        // and then crashing with "Session store not set on request".
        $this->withHeaders(['Origin' => 'https://not-the-spa.example.com'])
            ->postJson('/api/register', [
                'name' => 'Stateless',
                'email' => 'stateless@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertStatus(400);

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_registration_without_any_origin_or_referer_is_rejected_before_writing(): void
    {
        $this->flushHeaders()
            ->postJson('/api/register', [
                'name' => 'No Origin',
                'email' => 'no-origin@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ])
            ->assertStatus(400);

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_login_without_a_stateful_session_is_rejected(): void
    {
        // No account exists: the stateful-session guard must fail fast before
        // validation/credentials are ever evaluated.
        $this->withHeaders(['Origin' => 'https://not-the-spa.example.com'])
            ->postJson('/api/login', [
                'email' => 'existing@example.com',
                'password' => 'Password123!',
            ])
            ->assertStatus(400);

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_login_without_any_origin_or_referer_is_rejected(): void
    {
        $this->flushHeaders()
            ->postJson('/api/login', [
                'email' => 'existing@example.com',
                'password' => 'Password123!',
            ])
            ->assertStatus(400);

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_the_current_user_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_the_current_user_endpoint_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token');
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }
}
