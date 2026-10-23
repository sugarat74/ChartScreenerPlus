<?php

namespace App\Mail;

use App\Services\Alerts\AlertDigestContent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The operator test email sent by `php artisan mail:test`
 * (docs/specs/alerts-email-golive.md, D2).
 *
 * Deliberately NOT queued: the command sends it synchronously so transport
 * errors surface at once. HTML + plain text on the account layout, no images,
 * no user-supplied text, one plain link to the site (a rewritten tracking
 * link would be easy to spot) and the privacy link. From and Reply-To come
 * from the global mail configuration.
 */
class OutboundTestEmail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('account_email.test.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.test-email',
            text: 'mail.test-email-text',
            with: [
                'siteUrl' => AlertDigestContent::siteUrl('/'),
                'privacyUrl' => AlertDigestContent::siteUrl('/privacidad'),
            ],
        );
    }
}
