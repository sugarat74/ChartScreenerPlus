<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\LoginEvent;
use App\Models\User;
use App\Support\UserAgentLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * admin-users-sessions: Admin overview of users, active sessions and sign-in
 * activity, plus session revocation. Sessions are inserted directly because
 * the test environment uses the array session driver.
 */
class AdminUsersSessionsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';

    private const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    /** @return array<string, array{0: string, 1: string}> */
    public static function adminEndpointProvider(): array
    {
        return [
            'summary' => ['GET', '/api/admin/users/summary'],
            'users' => ['GET', '/api/admin/users'],
            'user detail' => ['GET', '/api/admin/users/1'],
            'revoke user sessions' => ['DELETE', '/api/admin/users/1/sessions'],
            'sessions' => ['GET', '/api/admin/sessions'],
            'revoke session' => ['DELETE', '/api/admin/sessions/'.str_repeat('a', 40)],
            'activity' => ['GET', '/api/admin/activity'],
        ];
    }

    #[DataProvider('adminEndpointProvider')]
    public function test_guests_get_401_and_registered_users_get_403(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertStatus(401);

        $this->actingAs(User::factory()->create());
        $this->json($method, $uri)->assertStatus(403);
    }

    public function test_sign_in_activity_is_recorded_without_passwords(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
        $this->withHeaders(['Origin' => 'http://localhost:5173', 'User-Agent' => self::CHROME_WINDOWS]);

        $this->postJson('/api/login', ['email' => 'ana@example.com', 'password' => 'wrong-password'])->assertStatus(422);
        $this->postJson('/api/login', ['email' => 'Ghost@Example.com', 'password' => 'whatever-123'])->assertStatus(422);
        $this->postJson('/api/login', ['email' => 'ana@example.com', 'password' => 'correct-horse-battery'])->assertOk();
        $this->postJson('/api/logout')->assertNoContent();

        $events = LoginEvent::query()->orderBy('id')->get();
        $this->assertSame(['failed', 'failed', 'login', 'logout'], $events->pluck('event')->all());
        $this->assertSame([$user->id, null, $user->id, $user->id], $events->pluck('user_id')->all());
        $this->assertSame('ana@example.com', $events[0]->email);
        $this->assertSame('ghost@example.com', $events[1]->email);
        $this->assertSame('127.0.0.1', $events[2]->ip_address);
        $this->assertSame(self::CHROME_WINDOWS, $events[2]->user_agent);

        $stored = json_encode(LoginEvent::query()->get()->toArray());
        $this->assertStringNotContainsString('wrong-password', $stored);
        $this->assertStringNotContainsString('correct-horse-battery', $stored);
    }

    public function test_a_failing_activity_write_never_breaks_sign_in(): void
    {
        User::factory()->create(['email' => 'ana@example.com', 'password' => 'correct-horse-battery']);
        Schema::drop('login_events');
        $this->withHeader('Origin', 'http://localhost:5173');

        $this->postJson('/api/login', ['email' => 'ana@example.com', 'password' => 'wrong-password'])->assertStatus(422);
        $this->postJson('/api/login', ['email' => 'ana@example.com', 'password' => 'correct-horse-battery'])->assertOk();
    }

    public function test_last_activity_falls_back_to_the_latest_sign_in_without_sessions(): void
    {
        $admin = User::factory()->admin()->create();
        $ana = User::factory()->create(['email' => 'ana@example.com']);
        $signedIn = now()->subHours(5)->startOfSecond();
        LoginEvent::query()->create(['event' => 'login', 'user_id' => $ana->id, 'created_at' => $signedIn]);

        $row = $this->actingAs($admin)->getJson('/api/admin/users?search=ana@example.com')->json('data.0');

        $this->assertSame($signedIn->toIso8601String(), $row['last_login_at']);
        $this->assertSame($row['last_login_at'], $row['last_activity_at']);
        $this->assertSame(0, $row['active_sessions_count']);
    }

    public function test_user_list_is_searchable_paginated_and_never_exposes_secrets(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Root Admin', 'email' => 'root@example.com', 'created_at' => now()->subDays(40)]);
        $ana = User::factory()->create(['name' => 'Ana Pérez', 'email' => 'ana@example.com', 'created_at' => now()->subDays(2)]);
        // Fixed identities: Faker names/emails could contain "ana" (Diana, Hannah).
        foreach (['Bob Stone', 'Carl Moss', 'Dirk Ruiz'] as $index => $name) {
            User::factory()->create(['name' => $name, 'email' => "user{$index}@test.invalid", 'created_at' => now()->subDays(10)]);
        }

        $ana->savedScreeners()->create(['name' => 'Golden', 'filters' => ['signal' => ['golden_cross']]]);
        $instrument = Instrument::query()->create(['ticker' => 'NVDA', 'company' => 'NVIDIA', 'sector' => 'Tech', 'exchange' => 'NASDAQ', 'active' => true]);
        $ana->watchlist()->attach($instrument->id);
        LoginEvent::query()->create(['event' => 'login', 'user_id' => $ana->id, 'created_at' => now()->subHours(3)]);
        $this->insertSession($ana, now()->subMinutes(5)->getTimestamp(), self::SAFARI_IPHONE);
        $this->insertSession($ana, now()->subDays(2)->getTimestamp());

        $this->actingAs($admin);

        $list = $this->getJson('/api/admin/users?search=ANA')->assertOk();
        $list->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'ana@example.com')
            ->assertJsonPath('data.0.role', 'user')
            ->assertJsonPath('data.0.saved_screeners_count', 1)
            ->assertJsonPath('data.0.watchlist_count', 1)
            ->assertJsonPath('data.0.active_sessions_count', 1);
        $this->assertNotNull($list->json('data.0.last_login_at'));
        $this->assertNotNull($list->json('data.0.last_activity_at'));
        $this->assertSame(
            ['id', 'name', 'email', 'role', 'created_at', 'last_login_at', 'last_activity_at', 'active_sessions_count', 'saved_screeners_count', 'watchlist_count'],
            array_keys($list->json('data.0')),
        );

        $this->getJson('/api/admin/users?search=pérez')->assertJsonPath('meta.total', 1);

        $page = $this->getJson('/api/admin/users?per_page=2&page=1')->assertOk();
        $page->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3);
        $this->assertSame('ana@example.com', $page->json('data.0.email'));
        $this->getJson('/api/admin/users?per_page=9999')->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/admin/users?per_page=abc')->assertJsonPath('meta.per_page', 25);

        // Wildcards are literal, not "match everything".
        $this->getJson('/api/admin/users?search=%25')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/admin/users?search=_')->assertJsonPath('meta.total', 0);

        $all = json_encode($this->getJson('/api/admin/users')->json());
        $this->assertStringNotContainsString('password', $all);
        $this->assertStringNotContainsString('remember_token', $all);
    }

    public function test_summary_counts_users_sessions_and_failed_sign_ins(): void
    {
        $admin = User::factory()->admin()->create(['created_at' => now()->subDays(60)]);
        $recent = User::factory()->create(['created_at' => now()->subDays(3)]);
        User::factory()->create(['created_at' => now()->subDays(20)]);
        $this->insertSession($recent, now()->subMinutes(1)->getTimestamp());
        $this->insertSession(null, now()->subMinutes(1)->getTimestamp());
        LoginEvent::query()->create(['event' => 'failed', 'email' => 'x@example.com', 'created_at' => now()->subHours(2)]);
        LoginEvent::query()->create(['event' => 'failed', 'email' => 'x@example.com', 'created_at' => now()->subDays(3)]);

        $this->actingAs($admin)->getJson('/api/admin/users/summary')
            ->assertOk()
            ->assertExactJson(['summary' => [
                'users_total' => 3,
                'admins_total' => 1,
                'users_new_7d' => 1,
                'users_new_30d' => 2,
                'users_active_24h' => 1,
                'sessions_active' => 1,
                'failed_logins_24h' => 1,
                'activity_retention_days' => 90,
            ]]);
    }

    public function test_sessions_list_shows_active_authenticated_sessions_without_ids(): void
    {
        $admin = User::factory()->admin()->create();
        $ana = User::factory()->create(['name' => 'Ana']);
        $active = $this->insertSession($ana, now()->subMinutes(2)->getTimestamp(), self::CHROME_WINDOWS, '203.0.113.7');
        $expired = $this->insertSession($ana, now()->subHours(5)->getTimestamp());
        $this->insertSession(null, now()->subMinute()->getTimestamp());

        $response = $this->actingAs($admin)->getJson('/api/admin/sessions')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.name', 'Ana')
            ->assertJsonPath('data.0.ip_address', '203.0.113.7')
            ->assertJsonPath('data.0.device', ['browser' => 'Chrome', 'os' => 'Windows'])
            ->assertJsonPath('data.0.is_current', false);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $response->json('data.0.ref'));
        $body = $response->getContent();
        $this->assertStringNotContainsString($active, $body);
        $this->assertStringNotContainsString($expired, $body);
        $this->assertStringNotContainsString('payload', $body);
    }

    public function test_admin_can_end_one_session_and_the_revocation_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $ana = User::factory()->create();
        $keep = $this->insertSession($ana, now()->getTimestamp());
        $end = $this->insertSession($ana, now()->getTimestamp());
        $this->actingAs($admin);

        $ref = collect($this->getJson('/api/admin/sessions')->json('data'))
            ->first(fn (array $row) => $row['ref'] === substr(hash_hmac('sha256', $end, (string) config('app.key')), 0, 40))['ref'];

        $this->deleteJson("/api/admin/sessions/{$ref}")->assertOk()->assertExactJson(['revoked' => 1]);

        $this->assertDatabaseMissing('sessions', ['id' => $end]);
        $this->assertDatabaseHas('sessions', ['id' => $keep]);
        // The Admin's own IP/device are never stored under the target user.
        $this->assertDatabaseHas('login_events', [
            'event' => 'session_revoked',
            'user_id' => $ana->id,
            'actor_id' => $admin->id,
            'sessions_revoked' => 1,
            'ip_address' => null,
            'user_agent' => null,
        ]);

        $this->deleteJson("/api/admin/sessions/{$ref}")->assertStatus(404);
    }

    public function test_admin_cannot_end_the_session_they_are_using(): void
    {
        // A real browser-like flow on the database session driver: sign in,
        // then reuse the session cookie the API returned.
        config(['session.driver' => 'database']);
        $admin = User::factory()->admin()->create(['email' => 'root@example.com', 'password' => 'correct-horse-battery']);
        $other = $this->insertSession($admin, now()->getTimestamp());
        $this->withHeader('Origin', 'http://localhost:5173');

        $login = $this->postJson('/api/login', ['email' => 'root@example.com', 'password' => 'correct-horse-battery'])->assertOk();
        $cookie = $login->getCookie(config('session.cookie'), false);
        $this->assertNotNull($cookie);
        // JSON test requests only send cookies with credentials, like the SPA's fetch.
        $this->withCredentials()->withUnencryptedCookie(config('session.cookie'), $cookie->getValue());

        $sessions = collect($this->getJson('/api/admin/sessions')->assertOk()->json('data'));
        $this->assertCount(2, $sessions);
        $current = $sessions->firstWhere('is_current', true);
        $this->assertNotNull($current);

        $this->deleteJson("/api/admin/sessions/{$current['ref']}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('session');
        $this->assertSame(2, DB::table('sessions')->where('user_id', $admin->id)->count());

        // "End all" on yourself keeps the current session and ends the others.
        $this->deleteJson("/api/admin/users/{$admin->id}/sessions")->assertOk()->assertExactJson(['revoked' => 1]);
        $this->assertDatabaseMissing('sessions', ['id' => $other]);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $admin->id)->count());
        $this->getJson('/api/admin/sessions')->assertOk()->assertJsonPath('data.0.is_current', true);
    }

    public function test_admin_can_end_all_sessions_of_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $ana = User::factory()->create();
        $bob = User::factory()->create();
        $this->insertSession($ana, now()->getTimestamp());
        $this->insertSession($ana, now()->subHours(6)->getTimestamp());
        $bobSession = $this->insertSession($bob, now()->getTimestamp());

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$ana->id}/sessions")
            ->assertOk()
            ->assertExactJson(['revoked' => 2]);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $ana->id)->count());
        $this->assertDatabaseHas('sessions', ['id' => $bobSession]);
        $this->deleteJson('/api/admin/users/999999/sessions')->assertStatus(404);
    }

    public function test_user_detail_includes_sessions_and_recent_activity(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Root']);
        $ana = User::factory()->create(['email' => 'ana@example.com']);
        $this->insertSession($ana, now()->getTimestamp(), self::SAFARI_IPHONE);
        LoginEvent::query()->create(['event' => 'login', 'user_id' => $ana->id, 'ip_address' => '198.51.100.4', 'created_at' => now()->subMinute()]);
        LoginEvent::query()->create(['event' => 'session_revoked', 'user_id' => $ana->id, 'actor_id' => $admin->id, 'sessions_revoked' => 2, 'created_at' => now()]);

        $this->actingAs($admin)->getJson("/api/admin/users/{$ana->id}")
            ->assertOk()
            ->assertJsonPath('user.email', 'ana@example.com')
            ->assertJsonCount(1, 'sessions')
            ->assertJsonPath('sessions.0.device', ['browser' => 'Safari', 'os' => 'iOS'])
            ->assertJsonCount(2, 'activity')
            ->assertJsonPath('activity.0.event', 'session_revoked')
            ->assertJsonPath('activity.0.actor.name', 'Root')
            ->assertJsonPath('activity.1.ip_address', '198.51.100.4');

        $this->getJson('/api/admin/users/999999')->assertStatus(404);
    }

    public function test_activity_log_filters_and_validates(): void
    {
        $admin = User::factory()->admin()->create();
        $ana = User::factory()->create();
        LoginEvent::query()->create(['event' => 'login', 'user_id' => $ana->id, 'created_at' => now()->subMinutes(3)]);
        LoginEvent::query()->create(['event' => 'failed', 'email' => 'nobody@example.com', 'created_at' => now()->subMinutes(2)]);
        LoginEvent::query()->create(['event' => 'logout', 'user_id' => $ana->id, 'created_at' => now()->subMinute()]);
        $this->actingAs($admin);

        $this->getJson('/api/admin/activity')->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.event', 'logout');
        $this->getJson('/api/admin/activity?event=failed')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.email', 'nobody@example.com')
            ->assertJsonPath('data.0.user', null);
        $this->getJson("/api/admin/activity?user_id={$ana->id}")->assertJsonPath('meta.total', 2);
        $this->getJson('/api/admin/activity?event=hacked')->assertStatus(422)->assertJsonValidationErrors('event');
    }

    public function test_activity_older_than_the_retention_period_is_pruned(): void
    {
        $old = LoginEvent::query()->create(['event' => 'login', 'ip_address' => '192.0.2.1', 'created_at' => now()->subDays(91)]);
        $kept = LoginEvent::query()->create(['event' => 'login', 'ip_address' => '192.0.2.2', 'created_at' => now()->subDays(89)]);

        $this->artisan('model:prune', ['--model' => [LoginEvent::class]])->assertSuccessful();

        $this->assertDatabaseMissing('login_events', ['id' => $old->id]);
        $this->assertDatabaseHas('login_events', ['id' => $kept->id]);
    }

    public function test_the_prune_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('model:prune')->assertSuccessful();
    }

    public function test_user_agent_labels(): void
    {
        $this->assertSame(['browser' => 'Chrome', 'os' => 'Windows'], UserAgentLabel::describe(self::CHROME_WINDOWS));
        $this->assertSame(['browser' => 'Safari', 'os' => 'iOS'], UserAgentLabel::describe(self::SAFARI_IPHONE));
        $this->assertSame(
            ['browser' => 'Edge', 'os' => 'Windows'],
            UserAgentLabel::describe('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/141.0 Safari/537.36 Edg/141.0'),
        );
        $this->assertSame(
            ['browser' => 'Firefox', 'os' => 'Linux'],
            UserAgentLabel::describe('Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0'),
        );
        $this->assertSame(['browser' => null, 'os' => null], UserAgentLabel::describe(null));
    }

    private function insertSession(
        ?User $user,
        int $lastActivity,
        ?string $agent = null,
        string $ip = '127.0.0.1',
        ?string $id = null,
    ): string {
        $id ??= Str::random(40);
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user?->id,
            'ip_address' => $ip,
            'user_agent' => $agent,
            'payload' => base64_encode('payload'),
            'last_activity' => $lastActivity,
        ]);

        return $id;
    }
}
