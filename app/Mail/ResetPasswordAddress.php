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

/** Queued account recovery email, rendered in the request locale. */
class ResetPasswordAddress extends Mailable implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly string $url, public readonly int $userId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('account_email.reset.subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.reset-password', text: 'mail.reset-password-text', with: [
            'url' => $this->url,
            'privacyUrl' => AlertDigestContent::siteUrl('/privacidad'),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Password reset email failed after retries.', [
            'user_id' => $this->userId,
            'error' => $exception === null ? null : $exception::class,
        ]);
    }
}
