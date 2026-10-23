<?php

namespace App\Console\Commands;

use App\Mail\OutboundTestEmail;
use App\Support\OutboundMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Operator test email (docs/specs/alerts-email-golive.md, D2).
 *
 * Sends one email synchronously (never queued), so a transport error surfaces
 * at once in the console. It refuses unless outbound email is ready (provider
 * and location named, and a delivering mailer in production). It never writes
 * to the log: no address, link or body can reach `laravel.log`. Artisan only;
 * there is no HTTP route.
 */
class SendTestEmail extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mail:test
        {to : Address that receives the test email}
        {--locale=es : Language of the email (es or en)}';

    /**
     * @var string
     */
    protected $description = 'Send one test email through the configured mailer (synchronous, never logged)';

    private const LOCALES = ['es', 'en'];

    public function handle(): int
    {
        if (! OutboundMail::ready()) {
            $this->error('Outbound email is not ready (MAIL_PROVIDER, MAIL_PROVIDER_LOCATION and a delivering mailer are required).');

            return self::FAILURE;
        }

        $to = trim((string) $this->argument('to'));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Invalid address.');

            return self::FAILURE;
        }

        $locale = (string) $this->option('locale');
        if (! in_array($locale, self::LOCALES, true)) {
            $this->error('Invalid locale (es or en).');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');

        try {
            $sent = Mail::to($to)->locale($locale)->send(new OutboundTestEmail);
        } catch (Throwable $exception) {
            // Console only: transport messages may quote the recipient, so
            // nothing is ever passed to the logger.
            $this->error('Sending failed: '.$exception::class.': '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Test email sent (mailer %s, host %s, port %s).',
            $mailer,
            (string) (config("mail.mailers.{$mailer}.host") ?? '-'),
            (string) (config("mail.mailers.{$mailer}.port") ?? '-'),
        ));
        $this->line('Message-ID: '.($sent?->getMessageId() ?? 'unavailable'));

        return self::SUCCESS;
    }
}
