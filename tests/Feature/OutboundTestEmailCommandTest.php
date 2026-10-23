<?php

namespace Tests\Feature;

use App\Mail\OutboundTestEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * Operator test email `php artisan mail:test` (docs/specs/alerts-email-golive.md,
 * D2): refusals, synchronous send, content and no logging. Offline: Mail::fake,
 * the array mailer or an in-process failing transport.
 */
class OutboundTestEmailCommandTest extends TestCase
{
    private const TO = 'owner@example.com';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.provider' => 'Brevo',
            'mail.provider_location' => 'UE',
            'mail.from.address' => 'no-reply@chartiko.com',
            'mail.from.name' => 'Chartiko',
            'mail.reply_to.address' => 'contacto@chartiko.com',
            'mail.reply_to.name' => 'Chartiko',
            'app.url' => 'https://www.chartiko.com',
        ]);
    }

    public function test_it_refuses_while_outbound_email_is_not_ready(): void
    {
        Mail::fake();
        config(['mail.provider' => null]);

        $this->artisan('mail:test', ['to' => self::TO])
            ->expectsOutputToContain('Outbound email is not ready')
            ->assertExitCode(1);

        config(['mail.provider' => 'Brevo', 'mail.provider_location' => '  ']);
        $this->artisan('mail:test', ['to' => self::TO])->assertExitCode(1);

        Mail::assertNothingOutgoing();
    }

    public function test_it_refuses_the_log_and_array_mailers_in_production(): void
    {
        Mail::fake();
        $this->app->detectEnvironment(fn (): string => 'production');

        foreach (['log', 'array'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->artisan('mail:test', ['to' => self::TO])
                ->expectsOutputToContain('Outbound email is not ready')
                ->assertExitCode(1);
        }

        Mail::assertNothingOutgoing();
    }

    public function test_it_refuses_an_invalid_address_or_locale(): void
    {
        Mail::fake();

        $this->artisan('mail:test', ['to' => 'not-an-address'])
            ->expectsOutput('Invalid address.')
            ->assertExitCode(1);
        $this->artisan('mail:test', ['to' => self::TO, '--locale' => 'fr'])->assertExitCode(1);

        Mail::assertNothingOutgoing();
    }

    public function test_it_sends_exactly_one_email_synchronously_in_each_language(): void
    {
        foreach (['es', 'en'] as $locale) {
            Mail::fake();

            $this->artisan('mail:test', ['to' => self::TO, '--locale' => $locale])
                ->expectsOutputToContain('Message-ID:')
                ->doesntExpectOutputToContain(self::TO)
                ->assertExitCode(0);

            Mail::assertSent(OutboundTestEmail::class, 1);
            Mail::assertSent(OutboundTestEmail::class, fn (OutboundTestEmail $mail): bool => $mail->hasTo(self::TO) && $mail->locale === $locale);
            Mail::assertNothingQueued();
        }
    }

    public function test_html_and_text_carry_the_plain_site_link_and_the_privacy_link_and_no_image(): void
    {
        $cases = [
            'es' => ['Email de prueba de Chartiko', 'ha enviado una prueba a esta dirección'],
            'en' => ['Chartiko test email', 'sent a test to this address'],
        ];

        foreach ($cases as $locale => [$heading, $why]) {
            $mail = (new OutboundTestEmail)->locale($locale);

            $mail->assertHasSubject($heading);
            $mail->assertSeeInHtml($heading);
            $mail->assertSeeInHtml($why);
            $mail->assertSeeInHtml('href="https://www.chartiko.com/"', false);
            $mail->assertSeeInHtml('href="https://www.chartiko.com/privacidad"', false);
            $mail->assertDontSeeInHtml('<img', false);
            // The account-creation footer lines are replaced for a test.
            $mail->assertDontSeeInHtml($locale === 'es' ? 'se ha creado una cuenta en chartiko.com' : 'an account was created on chartiko.com');

            $mail->assertSeeInText($heading);
            $mail->assertSeeInText($why);
            $mail->assertSeeInText('https://www.chartiko.com/');
            $mail->assertSeeInText('https://www.chartiko.com/privacidad');
        }
    }

    public function test_the_real_array_transport_shows_from_and_reply_to(): void
    {
        config(['mail.default' => 'array']);

        $this->artisan('mail:test', ['to' => self::TO, '--locale' => 'en'])
            ->expectsOutputToContain('Test email sent (mailer array')
            ->expectsOutputToContain('Message-ID:')
            ->assertExitCode(0);

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $raw = $messages[0]->toString();
        $this->assertStringContainsString('From: Chartiko <no-reply@chartiko.com>', $raw);
        $this->assertStringContainsString('Reply-To: Chartiko <contacto@chartiko.com>', $raw);
        $this->assertStringContainsString('To: '.self::TO, $raw);
        $this->assertStringContainsString('Subject: Chartiko test email', $raw);
        $this->assertStringContainsString('multipart/alternative', $raw);
    }

    public function test_a_failing_transport_exits_1_and_never_logs(): void
    {
        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Expected response code "250" but got "452" for owner@example.com.');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
        $log = Log::spy();

        $this->artisan('mail:test', ['to' => self::TO])
            ->expectsOutputToContain('Sending failed: '.TransportException::class)
            ->assertExitCode(1);

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log', 'write', 'channel', 'stack', 'build'] as $method) {
            $log->shouldNotHaveReceived($method);
        }
    }
}
