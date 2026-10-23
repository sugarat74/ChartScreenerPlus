<?php

namespace App\Mail;

use App\Services\Alerts\AlertDigestContent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The email that confirms a new account's address
 * (docs/specs/auth-email-verification.md, D6).
 *
 * Queued, HTML + plain text, in the locale of the request that sent it. It
 * carries no user-supplied text (not even the name), no images, no tracking
 * and no List-Unsubscribe header (transactional, not list mail). The signed
 * URL is built at request time, so its 24 h run from "create" or "resend".
 */
class VerifyEmailAddress extends Mailable implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly string $url, public readonly ?int $userId = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('account_email.verify.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.verify-email',
            text: 'mail.verify-email-text',
            with: [
                'url' => $this->url,
                'privacyUrl' => AlertDigestContent::siteUrl('/privacidad'),
            ],
        );
    }

    public function failed(?Throwable $exception): void
    {
        // Never the address or the link: transport messages often quote the
        // recipient, so only the user id and the exception class are kept.
        Log::warning('Verification email failed after retries.', [
            'user_id' => $this->userId,
            'error' => $exception === null ? null : $exception::class,
        ]);
    }
}
