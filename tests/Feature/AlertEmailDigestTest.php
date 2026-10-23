<?php

namespace Tests\Feature;

use App\Jobs\SendAlertDigest;
use App\Mail\AlertDigest;
use App\Models\AlertEmailDigest;
use App\Models\User;
use App\Notifications\AlertTriggered;
use App\Services\Alerts\AlertDigestContent;
use App\Services\Alerts\AlertDigestSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Traits\Localizable;
use RuntimeException;
use Tests\TestCase;

/**
 * Daily alert email digest (docs/specs/alerts-email-digest.md): opt-in,
 * one email per user per as-of, content, unavailability and failure safety.
 * Offline: Mail::fake / the array mailer, sync queue.
 */
class AlertEmailDigestTest extends TestCase
{
    use Localizable, RefreshDatabase;

    private int $alertId = 0;

    private const ORIGIN = 'http://localhost:5173';

    private const AS_OF = '2026-10-02';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enable();
    }

    private function enable(bool $enabled = true, ?string $provider = 'Acme Mail'): void
    {
        config([
            'alerts.email_digest.enabled' => $enabled,
            'alerts.email_digest.provider' => $provider,
            'alerts.email_digest.provider_location' => 'EU (Ireland)',
            'app.url' => 'https://www.chartiko.com',
        ]);
    }

    private function optedIn(string $locale = 'es'): User
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);
        $user->forceFill(['alert_email_opt_in_at' => now(), 'preferred_locale' => $locale])->save();

        return $user;
    }

    private function notify(User $user, string $kind = 'watchlist_signal', array $items = [], int $more = 0, string $asOf = self::AS_OF, ?string $screener = null): void
    {
        $user->notify(new AlertTriggered(
            alertId: ++$this->alertId,
            kind: $kind,
            asOf: $asOf,
            savedScreenerId: $screener === null ? null : 1,
            savedScreenerName: $screener,
            items: $items,
            more: $more,
        ));
    }

    private function twoNotifications(User $user): void
    {
        $this->notify($user, 'watchlist_signal', [
            ['ticker' => 'NVDA', 'name' => 'NVIDIA Corp', 'reason' => 'golden_cross'],
        ]);
        $this->notify($user, 'watchlist_pattern', [
            ['ticker' => 'EIX', 'name' => 'Edison International', 'reason' => 'pattern:bear_pennant:confirmed', 'status' => 'confirmed', 'breakout_level' => 53.79],
        ]);
    }

    public function test_scenario_1_one_email_per_user_per_as_of_listing_every_notification(): void
    {
        Mail::fake();
        $user = $this->optedIn();
        $this->twoNotifications($user);
        $this->notify($user, 'watchlist_signal', [['ticker' => 'OLD', 'name' => null, 'reason' => 'rsi_oversold']], asOf: '2026-10-01');

        $this->assertSame(1, app(AlertDigestSender::class)->queue(self::AS_OF));

        Mail::assertSent(AlertDigest::class, function (AlertDigest $mail) use ($user): bool {
            $tickers = collect($mail->digest->sections)->flatMap(fn (array $section) => array_column($section['items'], 'ticker'))->all();

            return $mail->hasTo('ana@example.com')
                && $mail->user->is($user)
                && $tickers === ['NVDA', 'EIX']
                && count($mail->digest->sections) === 2;
        });
        Mail::assertSentCount(1);
        $ledger = AlertEmailDigest::query()->sole();
        $this->assertSame(self::AS_OF, $ledger->as_of->toDateString());
        $this->assertSame(2, $ledger->items);
        $this->assertNotNull($ledger->sent_at);

        // Re-running for the same as-of queues and sends nothing more.
        $this->assertSame(0, app(AlertDigestSender::class)->queue(self::AS_OF));
        Mail::assertSentCount(1);
    }

    public function test_alerts_evaluate_queues_the_digests_after_the_evaluation(): void
    {
        Queue::fake();
        $user = $this->optedIn();
        \App\Models\DailyBar::factory()->create(['date' => self::AS_OF]);
        $this->twoNotifications($user);

        $this->artisan('alerts:evaluate')
            ->expectsOutputToContain('Queued 1 alert email digest(s).')
            ->assertExitCode(0);

        Queue::assertPushed(SendAlertDigest::class, 1);
    }

    public function test_scenario_2_nobody_who_did_not_opt_in_gets_an_email(): void
    {
        Mail::fake();
        $other = User::factory()->create();
        $this->twoNotifications($other);
        $silent = $this->optedIn(); // opted in, but nothing for this as-of
        $this->notify($silent, asOf: '2026-09-30', items: [['ticker' => 'X', 'name' => null, 'reason' => 'golden_cross']]);

        $this->assertSame(0, app(AlertDigestSender::class)->queue(self::AS_OF));
        Mail::assertNothingSent();
        $this->assertSame(0, AlertEmailDigest::query()->count());
    }

    public function test_an_account_pending_email_verification_gets_no_digest(): void
    {
        // auth-email-verification: never mail an unconfirmed address.
        Mail::fake();
        config(['auth.verification.enabled' => true, 'mail.provider' => 'Acme Mail', 'mail.provider_location' => 'EU (Ireland)']);
        $user = $this->optedIn();
        $user->forceFill(['email_verified_at' => null])->save();
        $this->twoNotifications($user);

        $this->assertSame(0, app(AlertDigestSender::class)->queue(self::AS_OF));
        $this->assertSame(0, AlertEmailDigest::query()->count());

        // A job queued before the account became pending sends nothing either.
        $user->forceFill(['email_verified_at' => now()])->save();
        Queue::fake();
        app(AlertDigestSender::class)->queue(self::AS_OF);
        $user->forceFill(['email_verified_at' => null])->save();
        (new SendAlertDigest(AlertEmailDigest::query()->sole()->id))->handle();
        Mail::assertNothingSent();
    }

    public function test_nothing_is_sent_while_the_feature_is_unavailable(): void
    {
        Mail::fake();
        $user = $this->optedIn();
        $this->twoNotifications($user);

        $this->enable(false);
        $this->assertSame(0, app(AlertDigestSender::class)->queue(self::AS_OF));
        // Enabled but no provider or no location: the privacy policy could not name them.
        $this->enable(true, '  ');
        $this->assertSame(0, app(AlertDigestSender::class)->queue(self::AS_OF));
        $this->enable();
        config(['alerts.email_digest.provider_location' => null]);
        $this->assertSame(0, app(AlertDigestSender::class)->queue(self::AS_OF));
        Mail::assertNothingSent();

        // A job queued before the switch was turned off sends nothing either.
        $this->enable();
        Queue::fake();
        app(AlertDigestSender::class)->queue(self::AS_OF);
        $this->enable(false);
        (new SendAlertDigest(AlertEmailDigest::query()->sole()->id))->handle();
        Mail::assertNothingSent();
    }

    public function test_preference_api_defaults_off_and_records_an_explicit_opt_in(): void
    {
        $user = User::factory()->create();

        $this->getJson('/api/alerts/email')->assertUnauthorized();

        $this->actingAs($user)->getJson('/api/alerts/email')->assertOk()->assertExactJson([
            'available' => true, 'opted_in' => false, 'opted_in_at' => null, 'locale' => null,
        ]);

        $this->actingAs($user)->withHeaders(['Origin' => self::ORIGIN, 'Accept-Language' => 'en'])
            ->putJson('/api/alerts/email', ['opt_in' => true])
            ->assertOk()
            ->assertJsonPath('opted_in', true)
            ->assertJsonPath('locale', 'en');
        $this->assertNotNull($user->fresh()->alert_email_opt_in_at);

        $this->actingAs($user)->putJson('/api/alerts/email', ['opt_in' => 'maybe'])->assertUnprocessable();

        $this->actingAs($user)->putJson('/api/alerts/email', ['opt_in' => false])
            ->assertOk()->assertJsonPath('opted_in', false);
        $this->assertNull($user->fresh()->alert_email_opt_in_at);
    }

    public function test_opt_in_is_refused_while_unavailable_but_opt_out_always_works(): void
    {
        $user = $this->optedIn();
        $this->enable(false);

        $this->actingAs($user)->withHeader('Accept-Language', 'es')->putJson('/api/alerts/email', ['opt_in' => true])
            ->assertStatus(409)
            ->assertJsonPath('message', 'El resumen diario por email todavía no está disponible.');
        $this->actingAs($user)->getJson('/api/alerts/email')->assertJsonPath('available', false);

        $this->actingAs($user)->putJson('/api/alerts/email', ['opt_in' => false])->assertOk();
        $this->assertNull($user->fresh()->alert_email_opt_in_at);
    }

    public function test_scenario_3_signed_unsubscribe_link_and_one_click_without_a_session(): void
    {
        $user = $this->optedIn('en');
        $url = AlertDigest::unsubscribeUrl($user);
        $this->assertStringStartsWith('https://www.chartiko.com/api/email/alerts/unsubscribe/'.$user->id.'?signature=', $url);
        $path = substr($url, strlen('https://www.chartiko.com'));

        // Tampered signature or another user's id: 403, nothing changes.
        $this->get(str_replace('signature=', 'signature=0', $path))->assertForbidden();
        $this->get(str_replace('/unsubscribe/'.$user->id, '/unsubscribe/'.($user->id + 1), $path))->assertForbidden();
        $this->assertNotNull($user->fresh()->alert_email_opt_in_at);

        // GET (and HEAD, as link scanners do) is side-effect free: a page with
        // a confirm button posting to the same signed URL.
        $this->call('HEAD', $path)->assertOk();
        $this->get($path)
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertSee('Stop receiving the daily summary?')
            ->assertSee('<form method="post" action="'.e($path).'">', false)
            ->assertSee('https://www.chartiko.com/', false);
        $this->assertNotNull($user->fresh()->alert_email_opt_in_at);

        // The button's form POST unsubscribes and shows the confirmation page.
        $this->post($path)
            ->assertOk()
            ->assertSee('You will no longer receive the daily summary');
        $this->assertNull($user->fresh()->alert_email_opt_in_at);
        $this->post($path)->assertOk(); // idempotent

        // RFC 8058 one-click POST from a mail client: no session, no CSRF token.
        $user->forceFill(['alert_email_opt_in_at' => now()])->save();
        $this->post($path, ['List-Unsubscribe' => 'One-Click'])->assertNoContent();
        $this->assertNull($user->fresh()->alert_email_opt_in_at);
        $this->post(str_replace('signature=', 'signature=0', $path))->assertForbidden();
    }

    public function test_the_unsubscribe_pages_follow_the_users_language(): void
    {
        $user = $this->optedIn('es');
        $path = substr(AlertDigest::unsubscribeUrl($user), strlen('https://www.chartiko.com'));

        $this->withHeader('Accept-Language', 'en')->get($path)
            ->assertOk()
            ->assertSee('¿Dejar de recibir el resumen diario?')
            ->assertSee('Darme de baja');
        $this->withHeader('Accept-Language', 'en')->post($path)
            ->assertOk()
            ->assertSee('Ya no recibirás el resumen diario');
    }

    public function test_the_unsubscribe_post_is_exempt_from_csrf_for_same_site_forms(): void
    {
        // PHPUnit skips CSRF unless the middleware runs for real; the path is
        // listed in the exemption, so a stateful (same-origin) POST passes.
        $middleware = app(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $request = \Illuminate\Http\Request::create('/api/email/alerts/unsubscribe/1?signature=x', 'POST');
        $method = new \ReflectionMethod($middleware, 'inExceptArray');
        $this->assertTrue($method->invoke($middleware, $request));
        $this->assertFalse($method->invoke($middleware, \Illuminate\Http\Request::create('/api/alerts/email', 'PUT')));
    }

    public function test_a_queued_digest_is_not_sent_after_the_user_opted_out(): void
    {
        Queue::fake();
        $user = $this->optedIn();
        $this->twoNotifications($user);
        app(AlertDigestSender::class)->queue(self::AS_OF);
        $user->forceFill(['alert_email_opt_in_at' => null])->save();

        Mail::fake();
        (new SendAlertDigest(AlertEmailDigest::query()->sole()->id))->handle();
        Mail::assertNothingSent();
        $this->assertNull(AlertEmailDigest::query()->sole()->sent_at);
    }

    public function test_the_final_failure_log_holds_no_address(): void
    {
        \Illuminate\Support\Facades\Log::spy();
        (new SendAlertDigest(7))->failed(new RuntimeException('<ana@example.com>: Recipient address rejected'));

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $context === ['alert_email_digest_id' => 7, 'error' => RuntimeException::class],
        );
    }

    public function test_scenario_4_a_transport_failure_never_fails_the_pipeline_and_never_duplicates(): void
    {
        $user = $this->optedIn();
        \App\Models\DailyBar::factory()->create(['date' => self::AS_OF]);
        $this->twoNotifications($user);

        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP down'));

        // Sync queue: the job runs inside alerts:evaluate and throws there.
        $this->artisan('alerts:evaluate')->assertExitCode(0);
        $ledger = AlertEmailDigest::query()->sole();
        $this->assertNull($ledger->sent_at);

        // A later retry succeeds once; a further retry sends nothing more.
        Mail::swap(new \Illuminate\Mail\MailManager(app()));
        Mail::fake();
        (new SendAlertDigest($ledger->id))->handle();
        (new SendAlertDigest($ledger->id))->handle();
        Mail::assertSentCount(1);
        $this->assertNotNull($ledger->fresh()->sent_at);
    }

    public function test_scenario_5_html_and_text_in_both_languages_with_disclaimer_privacy_and_no_tracking(): void
    {
        $user = $this->optedIn();
        $this->twoNotifications($user);
        $this->notify($user, 'screener_new_candidates', [['ticker' => 'AAPL', 'name' => 'Apple Inc.', 'reason' => 'new_candidate']], more: 3, screener: 'Momentum <b>');

        foreach (['es' => ['Tus alertas del', 'Cruce dorado', 'Banderín bajista · confirmado · Ruptura 53,79', 'Nuevos candidatos en «Momentum &lt;b&gt;»', 'y 3 más', 'No es asesoramiento de inversión', 'Dejar de recibir este email'],
            'en' => ['Your alerts for', 'Golden cross', 'Bearish pennant · confirmed · Breakout 53.79', 'New candidates in “Momentum &lt;b&gt;”', 'and 3 more', 'not investment advice', 'Stop receiving this email']] as $locale => $expected) {
            $digest = $this->withLocale($locale, fn () => AlertDigestContent::for($user, self::AS_OF));
            $mail = (new AlertDigest($user, $digest))->locale($locale);
            $html = $mail->render();

            foreach ($expected as $text) {
                $this->assertStringContainsString($text, $html, "{$locale}: {$text}");
            }
            $this->assertStringContainsString('https://www.chartiko.com/instruments/NVDA', $html);
            $this->assertStringContainsString('https://www.chartiko.com/privacidad', $html);
            $this->assertStringContainsString('https://www.chartiko.com/api/email/alerts/unsubscribe/'.$user->id.'?signature=', $html);
            $this->assertStringNotContainsString('<img', $html);
            $this->assertStringNotContainsString('<b>', $html); // user text is escaped

            $text = $this->withLocale($locale, fn () => view('mail.alert-digest-text', [
                'digest' => $digest,
                'inboxUrl' => 'https://www.chartiko.com/portal/notificaciones',
                'portalUrl' => 'https://www.chartiko.com/portal',
                'privacyUrl' => 'https://www.chartiko.com/privacidad',
                'unsubscribeUrl' => AlertDigest::unsubscribeUrl($user),
            ])->render());
            $this->assertStringContainsString('NVDA NVIDIA Corp', $text);
            $this->assertStringContainsString('https://www.chartiko.com/instruments/EIX', $text);
        }

        $mail = new AlertDigest($user, AlertDigestContent::for($user, self::AS_OF));
        $headers = $mail->headers()->text;
        $this->assertStringStartsWith('<https://www.chartiko.com/api/email/alerts/unsubscribe/', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
        $this->assertSame('Chartiko: tus alertas del '.$mail->digest->date, $this->withLocale('es', fn () => $mail->envelope()->subject));
    }

    public function test_the_sent_message_carries_both_parts_and_the_one_click_headers(): void
    {
        config(['mail.default' => 'array']);
        $user = $this->optedIn('en');
        $this->twoNotifications($user);

        app(AlertDigestSender::class)->queue(self::AS_OF);

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $raw = $messages[0]->toString();
        $this->assertMatchesRegularExpression('/^List-Unsubscribe: <https:\/\/www\.chartiko\.com\/api\/email\/alerts\/unsubscribe\/'.$user->id.'\?signature=[0-9a-f]+>/m', $raw);
        $this->assertStringContainsString('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $raw);
        $this->assertStringContainsString('Subject: Chartiko: your alerts for October 2, 2026', $raw);
        $this->assertStringContainsString('multipart/alternative', $raw);
        $this->assertStringContainsString('text/plain', $raw);
        $this->assertStringContainsString('text/html', $raw);
        $this->assertStringContainsString('To: ana@example.com', $raw);
    }

    public function test_an_email_lists_at_most_fifty_instruments_and_counts_the_rest(): void
    {
        $user = $this->optedIn();
        $items = array_map(fn (int $i): array => ['ticker' => "T{$i}", 'name' => null, 'reason' => 'new_candidate'], range(1, 40));
        $this->notify($user, 'screener_new_candidates', $items, more: 5, screener: 'A');
        $this->notify($user, 'screener_new_candidates', array_slice($items, 0, 20), screener: 'B');

        $digest = AlertDigestContent::for($user, self::AS_OF);

        $this->assertSame(65, $digest->total);
        $this->assertCount(40, $digest->sections[0]['items']);
        $this->assertSame(5, $digest->sections[0]['more']);
        $this->assertCount(10, $digest->sections[1]['items']);
        $this->assertSame(10, $digest->sections[1]['more']);
    }

    public function test_the_ledger_is_pruned_after_its_retention_and_cascades_with_the_account(): void
    {
        $user = $this->optedIn();
        $old = AlertEmailDigest::query()->create(['user_id' => $user->id, 'as_of' => '2026-06-01', 'items' => 1]);
        $old->forceFill(['created_at' => now()->subDays(91)])->save();
        AlertEmailDigest::query()->create(['user_id' => $user->id, 'as_of' => self::AS_OF, 'items' => 1]);

        $this->artisan('model:prune', ['--model' => [AlertEmailDigest::class]])->assertSuccessful();
        $this->assertSame([self::AS_OF], AlertEmailDigest::query()->get()->map(fn ($row) => $row->as_of->toDateString())->all());

        $user->delete();
        $this->assertSame(0, AlertEmailDigest::query()->count());
    }

    public function test_the_legal_api_names_the_provider_only_while_the_digest_is_active(): void
    {
        $this->getJson('/api/legal')
            ->assertOk()
            ->assertJsonPath('email_digest.provider', 'Acme Mail')
            ->assertJsonPath('email_digest.provider_location', 'EU (Ireland)')
            ->assertJsonPath('retention.email_digest_days', 90);

        $this->enable(false);
        $this->getJson('/api/legal')->assertOk()->assertJsonPath('email_digest', null);
    }
}
