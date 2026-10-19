<?php

namespace Tests\Feature;

use App\Models\AiCompanyCardGeneration;
use App\Models\AiScreenerQuery;
use App\Models\Alert;
use App\Models\Instrument;
use App\Models\LoginEvent;
use App\Models\SavedScreener;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * account-self-service: name and password changes, data export and account
 * deletion for the signed-in user (docs/specs/account-self-service.md).
 */
class AccountSelfServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'http://localhost:5173';

    private const PASSWORD = 'correct-horse-battery';

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost', 'localhost:5173']]);
    }

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function endpointProvider(): array
    {
        return [
            'update name' => ['PATCH', '/api/account', ['name' => 'Ana']],
            'change password' => ['PUT', '/api/account/password', [
                'current_password' => self::PASSWORD,
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ]],
            'export' => ['GET', '/api/account/export', []],
            'delete' => ['DELETE', '/api/account', ['password' => self::PASSWORD, 'confirmation' => 'DELETE']],
        ];
    }

    #[DataProvider('endpointProvider')]
    public function test_a_visitor_gets_401(string $method, string $uri, array $body): void
    {
        $this->withHeader('Origin', self::ORIGIN)->json($method, $uri, $body)->assertStatus(401);
    }

    #[DataProvider('endpointProvider')]
    public function test_a_request_without_a_session_gets_400_and_changes_nothing(string $method, string $uri, array $body): void
    {
        $user = User::factory()->create(['name' => 'Original', 'password' => self::PASSWORD]);
        $hash = $user->password;

        // No Origin header: Sanctum does not start a session (bearer-like caller).
        $this->actingAs($user)->json($method, $uri, $body)->assertStatus(400);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Original', 'password' => $hash]);
    }

    public function test_the_user_can_change_their_name(): void
    {
        $user = User::factory()->create(['name' => 'Original']);

        $this->actingAs($user)->withHeader('Origin', self::ORIGIN)
            ->patchJson('/api/account', ['name' => 'Ana María'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Ana María')
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Ana María']);
    }

    public function test_an_empty_or_too_long_name_is_rejected(): void
    {
        $user = User::factory()->create(['name' => 'Original']);
        $this->actingAs($user)->withHeader('Origin', self::ORIGIN);

        $this->patchJson('/api/account', ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson('/api/account', ['name' => str_repeat('a', 256)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson('/api/account', ['name' => str_repeat('a', 255)])->assertOk();
        // Only the name can change: role and email in the body are ignored.
        $this->patchJson('/api/account', ['name' => 'Ana', 'role' => 'admin', 'email' => 'other@example.com'])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Ana', 'role' => 'user', 'email' => $user->email]);
    }

    public function test_a_password_change_ends_other_sessions_and_keeps_the_current_one(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['email' => 'ana@example.com', 'password' => self::PASSWORD]);

        $current = $this->signIn('ana@example.com', self::PASSWORD);
        $other = $this->signIn('ana@example.com', self::PASSWORD);
        $stranger = User::factory()->create();
        $strangerSession = $this->insertSession($stranger);
        $this->assertSame(2, DB::table('sessions')->where('user_id', $user->id)->count());

        $response = $this->asBrowser($current)->putJson('/api/account/password', [
            'current_password' => self::PASSWORD,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertNoContent();

        $renewed = $this->sessionCookie($response);
        $this->assertNotSame($current, $renewed);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('sessions', ['id' => $strangerSession]);

        $this->asBrowser($renewed)->getJson('/api/user')->assertOk()->assertJsonPath('user.email', 'ana@example.com');
        $this->asBrowser($other)->getJson('/api/user')->assertUnauthorized();
        $this->asBrowser($current)->getJson('/api/user')->assertUnauthorized();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
        $this->asBrowser(null)->postJson('/api/login', ['email' => 'ana@example.com', 'password' => self::PASSWORD])->assertUnprocessable();
        $this->asBrowser(null)->postJson('/api/login', ['email' => 'ana@example.com', 'password' => 'brand-new-password'])->assertOk();
    }

    public function test_a_wrong_current_password_or_invalid_new_password_changes_nothing(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $hash = $user->password;
        $this->actingAs($user)->withHeader('Origin', self::ORIGIN);

        $this->putJson('/api/account/password', [
            'current_password' => 'not-my-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->putJson('/api/account/password', [
            'current_password' => self::PASSWORD,
            'password' => 'brand-new-password',
            'password_confirmation' => 'different-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->putJson('/api/account/password', [
            'current_password' => self::PASSWORD,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_password_changes_are_throttled(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->actingAs($user)->withHeader('Origin', self::ORIGIN);
        $body = ['current_password' => 'wrong', 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'];

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->putJson('/api/account/password', $body)->assertUnprocessable();
        }

        $this->putJson('/api/account/password', $body)->assertStatus(429);
    }

    public function test_name_changes_are_throttled(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withHeader('Origin', self::ORIGIN);

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->patchJson('/api/account', ['name' => "Ana {$attempt}"])->assertOk();
        }

        $this->patchJson('/api/account', ['name' => 'Ana 31'])->assertStatus(429);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Ana 30']);
    }

    public function test_exports_are_throttled(): void
    {
        $this->actingAs(User::factory()->create())->withHeader('Origin', self::ORIGIN);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->getJson('/api/account/export')->assertOk();
        }

        $this->getJson('/api/account/export')->assertStatus(429);
    }

    public function test_deletion_attempts_are_throttled(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->actingAs($user)->withHeader('Origin', self::ORIGIN);
        $body = ['password' => 'not-my-password', 'confirmation' => 'DELETE'];

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->deleteJson('/api/account', $body)->assertUnprocessable();
        }

        $this->deleteJson('/api/account', ['password' => self::PASSWORD, 'confirmation' => 'DELETE'])->assertStatus(429);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_the_export_holds_every_section_with_only_the_users_own_data(): void
    {
        $user = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => self::PASSWORD]);
        $other = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);
        $mine = $this->seedOwnedData($user, 'MINE');
        $theirs = $this->seedOwnedData($other, 'THEIRS');

        $response = $this->actingAs($user)->withHeader('Origin', self::ORIGIN)
            ->getJson('/api/account/export')
            ->assertOk();

        $this->assertStringContainsString('attachment; filename="chartiko-account-'.now()->toDateString().'.json"', (string) $response->headers->get('Content-Disposition'));

        $export = $response->json();
        $this->assertSame([
            'format', 'generated_at', 'profile', 'watchlist', 'saved_screeners', 'alerts',
            'notifications', 'sign_in_activity', 'active_sessions', 'ai_card_requests', 'ai_screener_requests',
        ], array_keys($export));
        $this->assertSame('chartiko-account-export-v1', $export['format']);
        $this->assertSame(
            ['name', 'email', 'role', 'created_at', 'updated_at', 'email_verified_at'],
            array_keys($export['profile']),
        );
        $this->assertSame('ana@example.com', $export['profile']['email']);

        $this->assertSame([['ticker' => 'MINE', 'name' => 'MINE Corp']], array_map(
            fn (array $row) => ['ticker' => $row['ticker'], 'name' => $row['name']],
            $export['watchlist'],
        ));
        $this->assertNotNull($export['watchlist'][0]['added_at']);
        $this->assertSame(['MINE screener'], array_column($export['saved_screeners'], 'name'));
        $this->assertSame('rvol_desc', $export['saved_screeners'][0]['criteria']['sort']);
        $this->assertCount(2, $export['alerts']);
        $this->assertSame(['MINE screener'], array_values(array_filter(array_column($export['alerts'], 'saved_screener'))));
        $this->assertSame(['MINE'], array_column($export['notifications'], 'data')[0]['tickers']);
        $this->assertSame(['login'], array_column($export['sign_in_activity'], 'event'));
        $this->assertSame('203.0.113.7', $export['sign_in_activity'][0]['ip_address']);
        $this->assertSame([['ip_address', 'user_agent', 'last_activity_at']], array_map('array_keys', $export['active_sessions']));
        $this->assertSame('Agent MINE', $export['active_sessions'][0]['user_agent']);
        $this->assertSame([['ticker' => 'MINE', 'locale' => 'es']], array_map(
            fn (array $row) => ['ticker' => $row['ticker'], 'locale' => $row['locale']],
            $export['ai_card_requests'],
        ));
        $this->assertSame([[
            'locale' => 'en', 'query_length' => 17, 'outcome' => 'ok', 'unsupported_count' => 1,
        ]], array_map(fn (array $row) => array_diff_key($row, ['created_at' => true]), $export['ai_screener_requests']));
        $this->assertNotNull($export['ai_screener_requests'][0]['created_at']);

        $raw = $response->getContent();
        $this->assertStringNotContainsString($user->fresh()->password, $raw);
        $this->assertStringNotContainsString((string) $user->fresh()->remember_token, $raw);
        $this->assertStringNotContainsString($mine['session'], $raw);
        $this->assertStringNotContainsString('payload-MINE', $raw);
        $this->assertStringNotContainsString('remember_token', $raw);
        $this->assertStringNotContainsString('"password"', $raw);
        foreach (['bob@example.com', 'THEIRS', $theirs['session'], 'Agent THEIRS', '198.51.100.9'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $raw);
        }
    }

    public function test_an_admin_can_export_and_rename_but_not_delete_their_account(): void
    {
        $admin = User::factory()->admin()->create(['password' => self::PASSWORD]);
        $this->actingAs($admin)->withHeader('Origin', self::ORIGIN);

        $this->getJson('/api/account/export')->assertOk()->assertJsonPath('profile.role', 'admin');
        $this->patchJson('/api/account', ['name' => 'Root'])->assertOk();

        $this->deleteJson('/api/account', ['password' => self::PASSWORD, 'confirmation' => 'DELETE'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'account.admin_cannot_delete');

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'admin']);
        $this->assertDatabaseMissing('login_events', ['event' => LoginEvent::EVENT_ACCOUNT_DELETED]);
    }

    public function test_deleting_the_account_removes_owned_rows_and_ends_the_session(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['email' => 'ana@example.com', 'password' => self::PASSWORD]);
        $other = User::factory()->create(['email' => 'bob@example.com']);
        $this->seedOwnedData($user, 'MINE');
        $theirs = $this->seedOwnedData($other, 'THEIRS');
        $current = $this->signIn('ana@example.com', self::PASSWORD);
        $this->assertSame(2, DB::table('sessions')->where('user_id', $user->id)->count());

        $this->asBrowser($current)
            ->deleteJson('/api/account', ['password' => self::PASSWORD, 'confirmation' => 'DELETE'])
            ->assertNoContent();

        $this->asBrowser($current)->getJson('/api/user')->assertUnauthorized();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        foreach (['watchlist_items', 'saved_screeners', 'alerts'] as $table) {
            $this->assertSame(0, DB::table($table)->where('user_id', $user->id)->count(), $table);
            $this->assertSame(1 + ($table === 'alerts' ? 1 : 0), DB::table($table)->where('user_id', $other->id)->count(), $table);
        }
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $user->id)->where('notifiable_type', $user->getMorphClass())->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $other->id)->count());

        // Shared logs keep their rows but lose the account link.
        $this->assertSame(0, LoginEvent::query()->where('user_id', $user->id)->count());
        $this->assertSame(0, AiCompanyCardGeneration::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, AiCompanyCardGeneration::query()->whereNull('user_id')->count());
        $this->assertSame(1, AiCompanyCardGeneration::query()->where('user_id', $other->id)->count());
        $this->assertSame(0, AiScreenerQuery::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, AiScreenerQuery::query()->whereNull('user_id')->count());
        $this->assertSame(1, AiScreenerQuery::query()->where('user_id', $other->id)->count());
        $this->assertSame(1, LoginEvent::query()->where('user_id', $other->id)->count());
        $this->assertDatabaseHas('sessions', ['id' => $theirs['session'], 'user_id' => $other->id]);

        $deleted = LoginEvent::query()->where('event', LoginEvent::EVENT_ACCOUNT_DELETED)->sole();
        $this->assertSame('ana@example.com', $deleted->email);
        $this->assertNull($deleted->user_id);
        $this->assertNull($deleted->ip_address);
        $this->assertNull($deleted->user_agent);
        // No sign-out event is recorded for the deleted account.
        $this->assertSame(0, LoginEvent::query()->where('event', LoginEvent::EVENT_LOGOUT)->count());

        $this->asBrowser(null)
            ->postJson('/api/login', ['email' => 'ana@example.com', 'password' => self::PASSWORD])
            ->assertUnprocessable();
    }

    public function test_the_spanish_confirmation_word_is_accepted(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->actingAs($user)->withHeaders(['Origin' => self::ORIGIN, 'Accept-Language' => 'es'])
            ->deleteJson('/api/account', ['password' => self::PASSWORD, 'confirmation' => 'ELIMINAR'])
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidDeletionProvider(): array
    {
        return [
            'wrong password' => [['password' => 'not-my-password', 'confirmation' => 'DELETE'], 'password'],
            'missing password' => [['confirmation' => 'DELETE'], 'password'],
            'missing confirmation' => [['password' => self::PASSWORD], 'confirmation'],
            'wrong word' => [['password' => self::PASSWORD, 'confirmation' => 'BORRAR'], 'confirmation'],
            'lowercase word' => [['password' => self::PASSWORD, 'confirmation' => 'delete'], 'confirmation'],
        ];
    }

    #[DataProvider('invalidDeletionProvider')]
    public function test_an_unconfirmed_deletion_is_rejected_and_deletes_nothing(array $body, string $field): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->seedOwnedData($user, 'MINE');

        $this->actingAs($user)->withHeader('Origin', self::ORIGIN)
            ->deleteJson('/api/account', $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertSame(1, DB::table('saved_screeners')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $user->id)->count());
        $this->assertDatabaseMissing('login_events', ['event' => LoginEvent::EVENT_ACCOUNT_DELETED]);
    }

    public function test_a_failure_during_deletion_rolls_everything_back(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $this->seedOwnedData($user, 'MINE');
        User::deleting(function (): void {
            throw new \RuntimeException('boom');
        });

        $this->actingAs($user)->withHeader('Origin', self::ORIGIN)
            ->deleteJson('/api/account', ['password' => self::PASSWORD, 'confirmation' => 'DELETE'])
            ->assertStatus(500);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $user->id)->count());
        $this->assertDatabaseMissing('login_events', ['event' => LoginEvent::EVENT_ACCOUNT_DELETED]);
    }

    /**
     * Every column that points at a user must be handled by the export and
     * the deletion. A new one fails here until it is added to both services
     * and to this allowlist.
     */
    public function test_every_user_owned_column_is_covered_by_export_and_deletion(): void
    {
        $covered = [
            'sessions.user_id' => 'deleted explicitly; exported as active_sessions',
            'watchlist_items.user_id' => 'FK cascade; exported as watchlist',
            'saved_screeners.user_id' => 'FK cascade; exported as saved_screeners',
            'alerts.user_id' => 'FK cascade; exported as alerts',
            'notifications.notifiable_id' => 'User::deleting hook; exported as notifications',
            'login_events.user_id' => 'nulled by FK; exported as sign_in_activity',
            'login_events.actor_id' => 'nulled by FK; Admin-only action, Admins cannot self-delete',
            'ai_company_card_generations.user_id' => 'nulled by FK; exported as ai_card_requests',
            'ai_screener_queries.user_id' => 'nulled by FK; exported as ai_screener_requests',
            'forecast_backtest_runs.requested_by' => 'Admin-only; Admins cannot self-delete',
            'personal_access_tokens.tokenable_id' => 'deleted explicitly; no tokens are issued',
        ];

        $found = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                if (in_array($column, ['user_id', 'requested_by', 'actor_id'], true) || str_ends_with($column, 'able_id')) {
                    $found[] = "{$table}.{$column}";
                }
            }
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if ($foreignKey['foreign_table'] === 'users') {
                    foreach ($foreignKey['columns'] as $column) {
                        $found[] = "{$table}.{$column}";
                    }
                }
            }
        }

        $found = array_values(array_unique($found));
        sort($found);
        $expected = array_keys($covered);
        sort($expected);

        $this->assertSame($expected, $found, 'A user-owned column must be handled by AccountExporter and AccountDeleter.');
    }

    /**
     * One row in every user-owned table, tagged so other accounts' data can be
     * recognised in the export.
     *
     * @return array{session: string}
     */
    private function seedOwnedData(User $user, string $tag): array
    {
        $instrument = Instrument::factory()->create(['ticker' => $tag, 'company' => "{$tag} Corp"]);
        $user->watchlist()->attach($instrument->id);
        $screener = SavedScreener::factory()->for($user)->create(['name' => "{$tag} screener"]);
        Alert::factory()->for($user)->create();
        Alert::factory()->for($user)->create(['kind' => Alert::KIND_SCREENER, 'saved_screener_id' => $screener->id, 'signal_types' => null]);
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\AlertTriggered',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => json_encode(['kind' => 'watchlist_signal', 'tickers' => [$tag]]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        LoginEvent::query()->create([
            'event' => LoginEvent::EVENT_LOGIN,
            'user_id' => $user->id,
            'email' => $user->email,
            'ip_address' => $tag === 'MINE' ? '203.0.113.7' : '198.51.100.9',
            'user_agent' => "Agent {$tag}",
        ]);
        AiCompanyCardGeneration::query()->create([
            'instrument_id' => $instrument->id,
            'user_id' => $user->id,
            'locale' => 'es',
            'model' => 'test-model',
            'schema_version' => 'v2',
            'prompt_version' => 'v3',
            'response_format' => 'json_schema',
            'status' => AiCompanyCardGeneration::STATUS_SUCCEEDED,
        ]);
        AiScreenerQuery::query()->create([
            'user_id' => $user->id,
            'locale' => $tag === 'MINE' ? 'en' : 'es',
            'query_length' => $tag === 'MINE' ? 17 : 99,
            'outcome' => AiScreenerQuery::OUTCOME_OK,
            'unsupported_count' => 1,
            'model' => 'test-model',
            'schema_version' => 'screener-query-v1',
            'prompt_version' => 'screener-query-prompt-v1',
        ]);

        return ['session' => $this->insertSession($user, "Agent {$tag}", "payload-{$tag}")];
    }

    private function insertSession(User $user, string $agent = 'Agent', string $payload = 'payload'): string
    {
        $id = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => $agent,
            'payload' => base64_encode($payload),
            'last_activity' => now()->getTimestamp(),
        ]);

        return $id;
    }

    /** Sign in through the API like the SPA and return the session cookie value. */
    private function signIn(string $email, string $password): string
    {
        $response = $this->asBrowser(null)->postJson('/api/login', ['email' => $email, 'password' => $password])->assertOk();

        return $this->sessionCookie($response);
    }

    private function sessionCookie(TestResponse $response): string
    {
        $cookie = $response->getCookie(config('session.cookie'), false);
        $this->assertNotNull($cookie);

        return $cookie->getValue();
    }

    /**
     * Next request as a browser holding `$cookie` (or no cookie). The auth
     * guards are reset so no user leaks from the previous request.
     */
    private function asBrowser(?string $cookie): static
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        // A fresh session store too: the cached one would merge the previous
        // request's attributes into the next session it loads.
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->withHeader('Origin', self::ORIGIN)->withCredentials();

        return $cookie === null ? $this : $this->withUnencryptedCookie(config('session.cookie'), $cookie);
    }
}
