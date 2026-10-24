<?php

namespace Tests\Feature;

use App\Mail\ResetPasswordAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'http://localhost:5173';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'sanctum.stateful' => ['localhost', 'localhost:5173'],
            'mail.provider' => 'Brevo',
            'mail.provider_location' => 'EU',
            'app.url' => 'https://www.chartiko.com',
        ]);
    }

    public function test_known_and_unknown_addresses_get_the_same_answer_but_only_known_mail_is_queued(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'known@example.com']);
        $known = $this->withHeaders(['Origin' => self::ORIGIN, 'Accept-Language' => 'en'])
            ->postJson('/api/password/forgot', ['email' => 'KNOWN@example.com'])->assertOk()->json();
        $unknown = $this->withHeader('Origin', self::ORIGIN)
            ->postJson('/api/password/forgot', ['email' => 'missing@example.com'])->assertOk()->json();

        $this->assertSame($known, $unknown);
        Mail::assertQueued(ResetPasswordAddress::class, 1);
        $mail = Mail::queued(ResetPasswordAddress::class)->sole();
        $this->assertSame($user->id, $mail->userId);
        $this->assertSame('en', $mail->locale);
        $this->assertStringStartsWith('https://www.chartiko.com/restablecer-contrasena?', $mail->url);
        $this->assertStringContainsString('token=', $mail->url);
    }

    public function test_unready_mail_never_creates_a_token_or_queues_a_message(): void
    {
        Mail::fake();
        config(['mail.provider' => null]);
        User::factory()->create(['email' => 'known@example.com']);
        $this->withHeader('Origin', self::ORIGIN)->postJson('/api/password/forgot', ['email' => 'known@example.com'])->assertOk();
        Mail::assertNothingQueued();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_valid_token_changes_password_ends_all_sessions_and_cannot_be_reused(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'known@example.com']);
        $token = Password::broker()->createToken($user);
        DB::table('sessions')->insert([
            'id' => 'another-session', 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'Test', 'payload' => 'payload', 'last_activity' => now()->timestamp,
        ]);
        $payload = [
            'email' => $user->email, 'token' => $token,
            'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ];
        $this->withHeader('Origin', self::ORIGIN)->postJson('/api/password/reset', $payload)->assertOk();
        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword123!', $user->password));
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'another-session']);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertGuest();
        $this->withHeader('Origin', self::ORIGIN)->postJson('/api/password/reset', $payload)
            ->assertStatus(422)->assertJsonPath('code', 'password_reset.invalid_link');
    }

    public function test_expired_and_tampered_tokens_leave_the_password_unchanged(): void
    {
        $user = User::factory()->create(['email' => 'known@example.com']);
        $token = Password::broker()->createToken($user);
        $payload = [
            'email' => $user->email, 'token' => $token.'wrong',
            'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ];
        $this->withHeader('Origin', self::ORIGIN)->postJson('/api/password/reset', $payload)->assertStatus(422);
        $this->travel(61)->minutes();
        $payload['token'] = $token;
        $this->withHeader('Origin', self::ORIGIN)->postJson('/api/password/reset', $payload)->assertStatus(422);
        $this->assertFalse(Hash::check('NewPassword123!', $user->fresh()->password));
    }

    public function test_repeat_requests_do_not_queue_more_mail_within_the_broker_cooldown(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'known@example.com']);
        $this->withHeader('Origin', self::ORIGIN)->postJson('/api/password/forgot', ['email' => 'known@example.com'])->assertOk();
        $this->withHeader('Origin', self::ORIGIN)->postJson('/api/password/forgot', ['email' => 'known@example.com'])->assertOk();
        Mail::assertQueued(ResetPasswordAddress::class, 1);
    }

    public function test_a_signed_in_requester_is_signed_out_after_a_valid_reset(): void
    {
        $user = User::factory()->create(['email' => 'known@example.com']);
        $token = Password::broker()->createToken($user);
        $this->actingAs($user)->withHeader('Origin', self::ORIGIN)->postJson('/api/password/reset', [
            'email' => $user->email, 'token' => $token,
            'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ])->assertOk();
        $this->assertGuest();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_anonymous_request_rate_limit_is_separate_from_login(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->withHeader('Origin', self::ORIGIN)
                ->postJson('/api/password/forgot', ['email' => 'missing@example.com'])->assertOk();
        }
        $this->withHeader('Origin', self::ORIGIN)
            ->postJson('/api/password/forgot', ['email' => 'missing@example.com'])->assertStatus(429);
    }

    public function test_reset_mail_uses_account_layout_without_account_creation_claim(): void
    {
        app()->setLocale('es');
        $mail = new ResetPasswordAddress('https://www.chartiko.com/restablecer-contrasena?token=example', 7);
        $html = $mail->render();
        $this->assertStringContainsString('Restablece tu contraseña', $html);
        $this->assertStringContainsString('Política de privacidad', $html);
        $this->assertStringContainsString('Si no has solicitado el cambio', $html);
        $this->assertStringNotContainsString('se ha creado una cuenta', $html);
    }

    public function test_requested_email_link_resets_the_account_end_to_end(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'known@example.com', 'password' => 'OldPassword123!']);
        $this->withHeader('Origin', self::ORIGIN)
            ->postJson('/api/password/forgot', ['email' => $user->email])->assertOk();
        $mail = Mail::queued(ResetPasswordAddress::class)->sole();
        parse_str((string) parse_url($mail->url, PHP_URL_QUERY), $query);
        $this->withHeader('Origin', self::ORIGIN)->postJson('/api/password/reset', [
            'email' => $query['email'], 'token' => $query['token'],
            'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ])->assertOk();
        $this->withHeader('Origin', self::ORIGIN)->postJson('/api/login', [
            'email' => $user->email, 'password' => 'OldPassword123!',
        ])->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Origin', self::ORIGIN)->postJson('/api/login', [
            'email' => $user->email, 'password' => 'NewPassword123!',
        ])->assertOk();
    }
}
