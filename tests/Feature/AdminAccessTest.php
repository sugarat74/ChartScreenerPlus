<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Match the dev SPA origin so Sanctum treats the registration request as
     * stateful (session + CSRF); role escalation over HTTP is tested there.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost', 'localhost:5173']]);

        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_a_guest_is_rejected_from_the_admin_endpoint(): void
    {
        $this->getJson('/api/admin/ping')->assertUnauthorized();
    }

    public function test_a_registered_non_admin_is_forbidden_from_the_admin_endpoint(): void
    {
        $user = User::factory()->create();

        $this->assertSame(User::ROLE_USER, $user->role);
        $this->assertFalse($user->isAdmin());

        $this->actingAs($user)->getJson('/api/admin/ping')->assertForbidden();
    }

    public function test_an_admin_can_reach_the_admin_endpoint(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->getJson('/api/admin/ping')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_registration_ignores_a_supplied_role(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Would Be Admin',
            'email' => 'bootleg-admin@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => User::ROLE_ADMIN,
        ])
            ->assertCreated()
            // The registration response must not echo an elevated role.
            ->assertJsonPath('user.role', User::ROLE_USER);

        $this->assertDatabaseHas('users', [
            'email' => 'bootleg-admin@example.com',
            'role' => User::ROLE_USER,
        ]);

        $user = User::query()->where('email', 'bootleg-admin@example.com')->firstOrFail();

        $this->assertSame(User::ROLE_USER, $user->role);
        $this->assertFalse($user->isAdmin());
    }

    public function test_role_is_not_mass_assignable(): void
    {
        $user = new User([
            'name' => 'Mass Assignment',
            'email' => 'mass@example.com',
            'password' => 'Password123!',
            'role' => User::ROLE_ADMIN,
        ]);

        // The ignored `role` falls back to the model default, never `admin`.
        $this->assertSame(User::ROLE_USER, $user->role);
        $this->assertFalse($user->isAdmin());
    }

    public function test_the_make_admin_command_promotes_an_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'promote@example.com']);

        $this->assertFalse($user->isAdmin());

        $this->artisan('app:make-admin', ['email' => 'promote@example.com'])
            ->assertExitCode(0);

        $this->assertTrue($user->fresh()->isAdmin());
        $this->assertDatabaseHas('users', [
            'email' => 'promote@example.com',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    public function test_the_make_admin_command_fails_for_an_unknown_account(): void
    {
        $this->artisan('app:make-admin', ['email' => 'missing@example.com'])
            ->assertExitCode(1);

        $this->assertDatabaseCount('users', 0);
    }
}
