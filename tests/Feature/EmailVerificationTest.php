<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureEmailVerified;
use App\Mail\VerifyEmailAddress;
use App\Models\Universe;
use App\Models\User;
use App\Support\EmailVerification;
use App\Support\OutboundMail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Traits\Localizable;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * auth-email-verification (docs/specs/auth-email-verification.md): dormant
 * by default, enforced only with the flag on and outbound email ready; one
 * predicate (EmailVerification::pending) behind the `verified` route group.
 */
class EmailVerificationTest extends TestCase
{
    use Localizable, RefreshDatabase;

    private const ORIGIN = 'http://localhost:5173';

    private const SPA_PREFIX = '/verificar-correo/';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sanctum.stateful' => ['localhost', 'localhost:5173'],
            'app.url' => 'https://www.chartiko.com',
        ]);
    }

    private function requireVerification(bool $flag = true, ?string $provider = 'Acme Mail'): void
    {
        config([
            'auth.verification.enabled' => $flag,
            'mail.provider' => $provider,
            'mail.provider_location' => 'UE (Francia)',
        ]);
    }

    private function pendingUser(array $attributes = []): User
    {
        return User::factory()->unverified()->create($attributes);
    }

    /** @return array<string, string> */
    private function registration(string $email = 'ada@example.com'): array
    {
        return [
            'name' => 'Ada Lovelace',
            'email' => $email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ];
    }

    /** The API path the SPA page POSTs for an emailed link. */
    private function apiPath(string $link): string
    {
        $parts = parse_url($link);
        $this->assertSame('https', $parts['scheme']);
        $this->assertSame('www.chartiko.com', $parts['host']);
        $this->assertStringStartsWith(self::SPA_PREFIX, $parts['path']);

        return '/api/email/verify/'.substr($parts['path'], strlen(self::SPA_PREFIX)).'?'.$parts['query'];
    }

    // ---------------------------------------------------------------- dormant

    public function test_scenario_1_dormant_by_default(): void
    {
        Mail::fake();

        $this->assertFalse(EmailVerification::required());

        $this->withHeader('Origin', self::ORIGIN)->postJson('/api/register', $this->registration())
            ->assertCreated()
            ->assertJsonPath('user.email_verification_pending', false);

        $user = User::query()->where('email', 'ada@example.com')->sole();
        $this->assertNotNull($user->email_verified_at);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();

        $this->getJson('/api/watchlist')->assertOk();
        $this->getJson('/api/legal')->assertOk()->assertJsonPath('account_email', null);
    }

    public function test_the_flag_without_a_provider_is_dormant(): void
    {
        Mail::fake();
        $this->requireVerification(provider: '  ');

        $this->assertFalse(OutboundMail::ready());
        $this->assertFalse(EmailVerification::required());

        $this->withHeader('Origin', self::ORIGIN)->postJson('/api/register', $this->registration())
            ->assertCreated()
            ->assertJsonPath('user.email_verification_pending', false);
        $this->assertNotNull(User::query()->sole()->email_verified_at);
        Mail::assertNothingQueued();
    }

    public function test_scenario_9_production_with_the_log_mailer_is_dormant(): void
    {
        $this->requireVerification();
        $this->assertTrue(EmailVerification::required());

        $this->app->detectEnvironment(fn (): string => 'production');

        config(['mail.default' => 'log']);
        $this->assertFalse(OutboundMail::ready());
        $this->assertFalse(EmailVerification::required());
        $this->assertFalse(EmailVerification::pending($this->pendingUser()));

        config(['mail.default' => 'array']);
        $this->assertFalse(EmailVerification::required());

        config(['mail.default' => 'smtp']);
        $this->assertTrue(EmailVerification::required());
    }

    public function test_a_pending_user_is_not_pending_while_verification_is_off(): void
    {
        $user = $this->pendingUser();

        $this->actingAs($user)->getJson('/api/watchlist')->assertOk();
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.email_verification_pending', false);
    }

    // --------------------------------------------------------------- register

    public function test_scenario_2_register_while_required_queues_one_email_in_the_request_language(): void
    {
        Mail::fake();
        $this->requireVerification();

        $this->withHeaders(['Origin' => self::ORIGIN, 'Accept-Language' => 'en'])
            ->postJson('/api/register', $this->registration())
            ->assertCreated()
            ->assertJsonPath('user.email_verification_pending', true)
            ->assertJsonPath('user.email_verified_at', null)
            ->assertJsonPath('user.capabilities.market_context', false);

        $user = User::query()->sole();
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);

        Mail::assertQueuedCount(1);
        Mail::assertQueued(VerifyEmailAddress::class, function (VerifyEmailAddress $mail) use ($user): bool {
            return $mail->hasTo('ada@example.com') && $mail->locale === 'en'
                && str_contains($mail->url, self::SPA_PREFIX.$user->id.'/'.sha1('ada@example.com').'?expires=');
        });
    }

    public function test_the_email_is_html_and_text_in_both_languages_without_user_text_images_or_tracking(): void
    {
        $user = $this->pendingUser(['name' => 'Mallory <b>Click here</b>', 'email' => 'ana@example.com']);
        $url = EmailVerification::link($user);
        $this->assertStringContainsString(self::SPA_PREFIX.$user->id.'/'.sha1('ana@example.com').'?expires=', $url);

        $expected = [
            'es' => ['Confirma tu dirección de email', 'Confirmar mi email', 'El enlace caduca en 24 horas', 'ignora este email', 'Política de privacidad', 'Confirma tu email en Chartiko'],
            'en' => ['Confirm your email address', 'Confirm my email', 'The link expires in 24 hours', 'ignore this email', 'Privacy policy', 'Confirm your email on Chartiko'],
        ];

        foreach ($expected as $locale => $texts) {
            $mail = (new VerifyEmailAddress($url, $user->id))->locale($locale);
            [$subject, $html, $text] = $this->withLocale($locale, fn (): array => [
                $mail->envelope()->subject,
                $mail->render(),
                view('mail.verify-email-text', ['url' => $url, 'privacyUrl' => 'https://www.chartiko.com/privacidad'])->render(),
            ]);

            $this->assertSame(end($texts), $subject);
            foreach (array_slice($texts, 0, -1) as $snippet) {
                $this->assertStringContainsString($snippet, $html, "{$locale} html: {$snippet}");
                $this->assertStringContainsString($snippet, $text, "{$locale} text: {$snippet}");
            }
            $this->assertStringContainsString(e($url), $html);
            $this->assertStringContainsString($url, $text);
            $this->assertStringContainsString('https://www.chartiko.com/privacidad', $html);
            foreach ([$html, $text] as $part) {
                $this->assertStringNotContainsString('Mallory', $part);
                $this->assertStringNotContainsString('Click here', $part);
                $this->assertStringNotContainsString('<img', $part);
                $this->assertStringNotContainsString('&lt;', $part);
            }
            $this->assertStringNotContainsString('&#039;', $text);
        }
    }

    public function test_the_sent_message_has_both_parts_reply_to_and_no_list_unsubscribe(): void
    {
        config([
            'mail.default' => 'array',
            'mail.from' => ['address' => 'no-reply@chartiko.com', 'name' => 'Chartiko'],
            'mail.reply_to' => ['address' => 'contacto@chartiko.com', 'name' => 'Chartiko'],
        ]);
        $this->requireVerification();

        $this->withHeaders(['Origin' => self::ORIGIN, 'Accept-Language' => 'es'])
            ->postJson('/api/register', $this->registration())
            ->assertCreated();

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $raw = $messages[0]->toString();
        $this->assertStringContainsString('To: ada@example.com', $raw);
        $this->assertStringContainsString('From: Chartiko <no-reply@chartiko.com>', $raw);
        $this->assertStringContainsString('Reply-To: Chartiko <contacto@chartiko.com>', $raw);
        $this->assertStringContainsString('Subject: Confirma tu email en Chartiko', $raw);
        $this->assertStringContainsString('multipart/alternative', $raw);
        $this->assertStringContainsString('text/plain', $raw);
        $this->assertStringContainsString('text/html', $raw);
        $this->assertStringNotContainsString('List-Unsubscribe', $raw);
        $this->assertStringNotContainsString('Ada Lovelace', $raw);
    }

    public function test_scenario_10_a_mail_failure_never_fails_registration_and_logs_no_address(): void
    {
        $this->requireVerification();
        Log::spy();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('<ada@example.com>: Recipient address rejected'));

        $this->withHeader('Origin', self::ORIGIN)->postJson('/api/register', $this->registration())
            ->assertCreated()
            ->assertJsonPath('user.email_verification_pending', true);

        $user = User::query()->sole();
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $context === ['user_id' => $user->id, 'error' => RuntimeException::class],
        );
    }

    public function test_the_final_failure_log_of_the_queued_email_holds_no_address(): void
    {
        Log::spy();
        (new VerifyEmailAddress('https://www.chartiko.com/verificar-correo/7/x', 7))
            ->failed(new RuntimeException('<ana@example.com>: Recipient address rejected'));

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $context === ['user_id' => 7, 'error' => RuntimeException::class],
        );
    }

    // ----------------------------------------------------------------- verify

    public function test_scenario_4_the_link_verifies_once_without_a_session_and_signs_nobody_in(): void
    {
        Event::fake([Verified::class]);
        $this->requireVerification();
        $user = $this->pendingUser();
        $path = $this->apiPath(EmailVerification::link($user));

        // No Origin: not a stateful SPA request, so no session at all.
        $this->postJson($path)->assertOk()->assertExactJson(['status' => 'verified']);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest();
        Event::assertDispatchedTimes(Verified::class, 1);

        $this->postJson($path)->assertOk()->assertExactJson(['status' => 'already_verified']);
        Event::assertDispatchedTimes(Verified::class, 1);
    }

    public function test_the_session_user_can_verify_through_the_spa_and_the_gate_disappears(): void
    {
        $this->requireVerification();
        $user = $this->pendingUser();

        $this->actingAs($user)->withHeader('Origin', self::ORIGIN);
        $this->getJson('/api/watchlist')->assertForbidden();

        $this->postJson($this->apiPath(EmailVerification::link($user)))->assertOk()->assertJsonPath('status', 'verified');

        $this->app['auth']->forgetGuards();
        $this->actingAs($user->fresh());
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.email_verification_pending', false);
        $this->getJson('/api/watchlist')->assertOk();
    }

    public function test_a_get_never_verifies(): void
    {
        $this->requireVerification();
        $user = $this->pendingUser();

        $this->getJson($this->apiPath(EmailVerification::link($user)))->assertStatus(405);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_scenario_5_an_expired_link_is_rejected_unless_already_verified(): void
    {
        $this->requireVerification();
        $user = $this->pendingUser();
        $path = $this->apiPath(EmailVerification::link($user));

        $this->travel(1439)->minutes();
        $this->travel(2)->minutes();

        $this->withHeader('Accept-Language', 'es')->postJson($path)
            ->assertForbidden()
            ->assertExactJson(['code' => 'email_verification.link_expired', 'message' => 'El enlace de confirmación ha caducado. Pide otro.']);
        $this->assertNull($user->fresh()->email_verified_at);

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->postJson($path)->assertOk()->assertExactJson(['status' => 'already_verified']);
    }

    public function test_a_link_is_still_valid_just_before_24_hours(): void
    {
        $this->requireVerification();
        $user = $this->pendingUser();
        $path = $this->apiPath(EmailVerification::link($user));

        $this->travel(1439)->minutes();

        $this->postJson($path)->assertOk()->assertJsonPath('status', 'verified');
    }

    /** @return array<string, array{0: string}> */
    public static function tamperProvider(): array
    {
        return [
            'altered signature' => ['signature'],
            'another id' => ['id'],
            'extra query parameter' => ['extra'],
            'altered expiry' => ['expires'],
            'wrong hash under a valid signature' => ['hash'],
            'address changed since the link' => ['email'],
            'deleted user' => ['deleted'],
        ];
    }

    #[DataProvider('tamperProvider')]
    public function test_scenario_5_a_tampered_link_is_invalid_and_changes_nothing(string $tamper): void
    {
        Event::fake([Verified::class]);
        $this->requireVerification();
        $user = $this->pendingUser(['email' => 'ana@example.com']);
        $other = $this->pendingUser();
        $path = $this->apiPath(EmailVerification::link($user));

        $path = match ($tamper) {
            'signature' => substr($path, 0, -1).(str_ends_with($path, 'a') ? 'b' : 'a'),
            'id' => str_replace('/verify/'.$user->id.'/', '/verify/'.$other->id.'/', $path),
            'extra' => $path.'&lang=en',
            'expires' => preg_replace_callback('/expires=(\d+)/', fn (array $m): string => 'expires='.((int) $m[1] + 3600), $path),
            'hash' => URL::temporarySignedRoute('verification.verify', now()->addDay(), ['user' => $user->id, 'hash' => sha1('someone@example.com')], absolute: false),
            default => $path,
        };
        if ($tamper === 'email') {
            $user->forceFill(['email' => 'changed@example.com'])->save();
        }
        if ($tamper === 'deleted') {
            $user->delete();
        }

        $this->withHeader('Accept-Language', 'en')->postJson($path)
            ->assertForbidden()
            ->assertExactJson(['code' => 'email_verification.invalid_link', 'message' => 'The confirmation link is not valid.']);

        $this->assertSame(0, User::query()->whereNotNull('email_verified_at')->count());
        Event::assertNotDispatched(Verified::class);
    }

    public function test_a_malformed_path_is_a_json_404_never_a_redirect(): void
    {
        $this->postJson('/api/email/verify/abc/'.sha1('x'))->assertNotFound()->assertJsonStructure(['message']);
        $this->postJson('/api/email/verify/1/not-a-hash')->assertNotFound()->assertJsonStructure(['message']);
    }

    public function test_verify_is_throttled_per_ip(): void
    {
        $path = '/api/email/verify/1/'.sha1('x').'?expires=1&signature=bad';
        for ($i = 0; $i < 12; $i++) {
            $this->postJson($path)->assertForbidden();
        }
        $this->postJson($path)->assertTooManyRequests();
    }

    // ----------------------------------------------------------------- resend

    public function test_scenario_6_resend_queues_one_email_for_a_pending_user(): void
    {
        Mail::fake();
        $this->requireVerification();
        $user = $this->pendingUser(['email' => 'ana@example.com']);

        $this->actingAs($user)->withHeaders(['Origin' => self::ORIGIN, 'Accept-Language' => 'es'])
            ->postJson('/api/email/verification-notification')
            ->assertStatus(202)
            ->assertExactJson(['status' => 'sent']);

        Mail::assertQueuedCount(1);
        Mail::assertQueued(VerifyEmailAddress::class, fn (VerifyEmailAddress $mail): bool => $mail->hasTo('ana@example.com') && $mail->locale === 'es');
    }

    public function test_resend_for_a_verified_user_or_an_admin_sends_nothing(): void
    {
        Mail::fake();
        $this->requireVerification();

        $this->actingAs(User::factory()->create())->withHeader('Origin', self::ORIGIN)
            ->postJson('/api/email/verification-notification')
            ->assertOk()
            ->assertExactJson(['status' => 'already_verified']);

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->admin()->unverified()->create())
            ->postJson('/api/email/verification-notification')
            ->assertOk()
            ->assertExactJson(['status' => 'already_verified']);

        Mail::assertNothingQueued();
    }

    public function test_resend_while_not_required_is_409(): void
    {
        Mail::fake();

        $this->actingAs($this->pendingUser())->withHeaders(['Origin' => self::ORIGIN, 'Accept-Language' => 'es'])
            ->postJson('/api/email/verification-notification')
            ->assertStatus(409)
            ->assertExactJson(['code' => 'email_verification.unavailable', 'message' => 'La confirmación por email no está disponible.']);

        Mail::assertNothingQueued();
    }

    public function test_resend_needs_a_stateful_session_and_a_user(): void
    {
        $this->requireVerification();

        $this->withHeader('Origin', self::ORIGIN)->postJson('/api/email/verification-notification')->assertUnauthorized();

        Mail::fake();
        $this->flushHeaders()->actingAs($this->pendingUser())->postJson('/api/email/verification-notification')->assertStatus(400);
        Mail::assertNothingQueued();
    }

    public function test_the_seventh_resend_within_an_hour_is_a_localized_429(): void
    {
        Mail::fake();
        $this->requireVerification();
        $this->actingAs($this->pendingUser())->withHeaders(['Origin' => self::ORIGIN, 'Accept-Language' => 'es']);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/email/verification-notification')->assertStatus(202);
        }

        $response = $this->postJson('/api/email/verification-notification')
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Demasiados intentos. Espera un momento e inténtalo de nuevo.');
        $this->assertNotNull($response->headers->get('Retry-After'));
        Mail::assertQueuedCount(6);
    }

    // ------------------------------------------------------- denial and access

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function verifiedRouteProvider(): array
    {
        return [
            'forecast' => ['POST', '/api/instruments/AAPL/forecast', []],
            'company card read' => ['GET', '/api/instruments/AAPL/company-card', []],
            'company card generate' => ['POST', '/api/instruments/AAPL/company-card', []],
            'screener interpret' => ['POST', '/api/screener/interpret', ['query' => 'acciones con RSI bajo']],
            'watchlist list' => ['GET', '/api/watchlist', []],
            'watchlist add' => ['POST', '/api/watchlist', ['ticker' => 'AAPL']],
            'watchlist remove' => ['DELETE', '/api/watchlist/AAPL', []],
            'alerts list' => ['GET', '/api/alerts', []],
            'alerts create' => ['POST', '/api/alerts', ['kind' => 'watchlist_signal', 'signal_types' => ['golden_cross']]],
            'alerts update' => ['PATCH', '/api/alerts/999', ['active' => false]],
            'alerts delete' => ['DELETE', '/api/alerts/999', []],
            'notifications list' => ['GET', '/api/notifications', []],
            'notifications read' => ['POST', '/api/notifications/read', []],
            'alert email read' => ['GET', '/api/alerts/email', []],
            'alert email update' => ['PUT', '/api/alerts/email', ['opt_in' => true]],
            'saved screeners list' => ['GET', '/api/screeners', []],
            'saved screeners create' => ['POST', '/api/screeners', ['name' => 'Momentum', 'filters' => []]],
            'saved screeners delete' => ['DELETE', '/api/screeners/999', []],
            'market context' => ['GET', '/api/market-context', []],
            'market context history' => ['GET', '/api/market-context/history?metric=breadth_200&range=1y', []],
            'instrument market context' => ['GET', '/api/instruments/AAPL/market-context', []],
        ];
    }

    /** @param  array<string, mixed>  $body */
    #[DataProvider('verifiedRouteProvider')]
    public function test_scenario_3_a_pending_user_gets_the_localized_json_403_and_nothing_is_written(string $method, string $uri, array $body): void
    {
        $this->requireVerification();
        config(['market_context.public' => true]);
        $this->actingAs($this->pendingUser())->withHeader('Origin', self::ORIGIN);

        foreach (['es' => 'Confirma tu email para usar esta función.', 'en' => 'Confirm your email to use this feature.'] as $locale => $message) {
            $response = $this->withHeader('Accept-Language', $locale)->json($method, $uri, $body);
            $response->assertForbidden()->assertExactJson(['code' => 'email_verification.required', 'message' => $message]);
            $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        }

        $this->assertDatabaseCount('watchlist_items', 0);
        $this->assertDatabaseCount('saved_screeners', 0);
        $this->assertDatabaseCount('alerts', 0);
    }

    public function test_a_pending_user_keeps_the_session_the_account_and_sign_out(): void
    {
        $this->requireVerification();
        $user = $this->pendingUser(['name' => 'Ana']);
        $this->actingAs($user)->withHeader('Origin', self::ORIGIN);

        $this->getJson('/api/user')->assertOk()
            ->assertJsonPath('user.email_verification_pending', true)
            ->assertJsonPath('user.capabilities.market_context', false);
        $this->patchJson('/api/account', ['name' => 'Ana María'])->assertOk()
            ->assertJsonPath('user.name', 'Ana María')
            ->assertJsonPath('user.email_verification_pending', true);
        $this->getJson('/api/account/export')->assertOk();
        $this->postJson('/api/logout')->assertNoContent();
    }

    public function test_a_pending_user_can_delete_the_account(): void
    {
        $this->requireVerification();
        $user = $this->pendingUser(['password' => 'correct-horse-battery']);

        $this->actingAs($user)->withHeader('Origin', self::ORIGIN)
            ->deleteJson('/api/account', ['password' => 'correct-horse-battery', 'confirmation' => 'DELETE'])
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_scenario_7_an_admin_is_never_pending(): void
    {
        $this->requireVerification();
        config(['market_context.public' => false]);
        $admin = User::factory()->admin()->unverified()->create();

        $this->assertFalse(EmailVerification::pending($admin));
        $this->actingAs($admin)->withHeader('Origin', self::ORIGIN);
        $this->getJson('/api/user')->assertOk()
            ->assertJsonPath('user.email_verification_pending', false)
            ->assertJsonPath('user.capabilities.market_context', true);
        $this->getJson('/api/admin/ping')->assertOk();
        $this->getJson('/api/admin/users')->assertOk();
        $this->getJson('/api/watchlist')->assertOk();
        $this->getJson('/api/screeners')->assertOk();
        $this->getJson('/api/market-context')->assertOk();
    }

    public function test_market_context_is_denied_to_a_pending_user_outside_the_route_group(): void
    {
        $this->requireVerification();
        config(['market_context.public' => true]);
        Universe::factory()->create(['slug' => 'sp500']);
        $pending = $this->pendingUser();

        $this->actingAs($pending)->getJson('/api/screener?sector_trend=uptrend')
            ->assertForbidden()
            ->assertExactJson(['message' => 'The sector context filters are not available for your account.']);
        $this->getJson('/api/user')->assertJsonPath('user.capabilities.market_context', false);

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create())->getJson('/api/screener?sector_trend=uptrend')->assertOk();
    }

    public function test_the_verified_alias_is_the_json_middleware(): void
    {
        $this->assertSame(EnsureEmailVerified::class, app('router')->getMiddleware()['verified']);
    }

    public function test_every_account_only_route_is_in_the_verified_group(): void
    {
        $allowed = ['api/user', 'api/logout', 'api/account', 'api/account/password', 'api/account/export', 'api/email/verification-notification'];
        $checked = 0;

        /** @var RoutingRoute $route */
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            $middleware = $route->gatherMiddleware();
            if (! str_starts_with($uri, 'api/') || ! in_array('auth:sanctum', $middleware, true)) {
                continue;
            }
            if (in_array('admin', $middleware, true)) {
                $this->assertNotContains('verified', $middleware, "{$uri}: Admins are never pending");

                continue;
            }

            $checked++;
            if (in_array($uri, $allowed, true)) {
                $this->assertNotContains('verified', $middleware, "{$uri} must stay reachable while pending");
            } else {
                $this->assertContains('verified', $middleware, "{$uri} must be in the verified group");
            }
        }

        $this->assertGreaterThan(25, $checked);
    }

    public function test_the_new_answers_are_json_even_without_a_json_accept_header(): void
    {
        // The guest 401 of a non-JSON request is api-guest-json-errors; the
        // verify route and the `verified` middleware answer JSON by themselves.
        $this->call('POST', '/api/email/verify/1/'.sha1('x'))
            ->assertForbidden()
            ->assertJsonPath('code', 'email_verification.invalid_link');

        $this->requireVerification();
        $this->actingAs($this->pendingUser())->call('GET', '/api/watchlist')
            ->assertForbidden()
            ->assertJsonPath('code', 'email_verification.required');
    }

    // ------------------------------------------------------------- migration

    public function test_scenario_8_the_migration_backfills_only_null_rows(): void
    {
        $kept = '2025-01-02 03:04:05';
        DB::table('users')->insert([
            ['name' => 'A', 'email' => 'a@example.com', 'password' => 'x', 'email_verified_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'B', 'email' => 'b@example.com', 'password' => 'x', 'email_verified_at' => $kept, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'C', 'email' => 'c@example.com', 'password' => 'x', 'email_verified_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration = require database_path('migrations/2026_10_26_000001_backfill_users_email_verified_at.php');
        $migration->up();

        $this->assertSame(0, DB::table('users')->whereNull('email_verified_at')->count());
        $this->assertSame($kept, (string) DB::table('users')->where('email', 'b@example.com')->value('email_verified_at'));
    }

    // ------------------------------------------------------------------ legal

    public function test_the_legal_api_names_the_account_email_provider_only_while_required(): void
    {
        $this->getJson('/api/legal')->assertOk()->assertJsonPath('account_email', null);

        $this->requireVerification();
        $this->getJson('/api/legal')->assertOk()
            ->assertJsonPath('account_email.provider', 'Acme Mail')
            ->assertJsonPath('account_email.provider_location', 'UE (Francia)');
    }
}
