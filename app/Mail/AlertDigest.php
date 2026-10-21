<?php

namespace App\Mail;

use App\Models\User;
use App\Services\Alerts\AlertDigestContent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

/**
 * The daily alert email (alerts-email-digest): HTML and plain-text parts,
 * no images, no tracking pixels and no click tracking (links point straight
 * at chartiko.com). Carries RFC 2369 / RFC 8058 one-click unsubscribe headers
 * with a signed URL, so unsubscribing never needs a session.
 */
class AlertDigest extends Mailable
{
    public function __construct(
        public readonly User $user,
        public readonly AlertDigestContent $digest,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('alert_email.subject', ['date' => $this->digest->date]));
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.self::unsubscribeUrl($this->user).'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.alert-digest',
            text: 'mail.alert-digest-text',
            with: [
                'digest' => $this->digest,
                'inboxUrl' => AlertDigestContent::siteUrl('/portal/notificaciones'),
                'portalUrl' => AlertDigestContent::siteUrl('/portal'),
                'privacyUrl' => AlertDigestContent::siteUrl('/privacidad'),
                'unsubscribeUrl' => self::unsubscribeUrl($this->user),
            ],
        );
    }

    /**
     * Signed, non-expiring link that works for both the GET page and the
     * one-click POST. The signature covers the user id only, so a forged id
     * fails with 403.
     */
    public static function unsubscribeUrl(User $user): string
    {
        // Signed as a relative URL (validated with `signed:relative`), then
        // rooted at APP_URL, so the link is right from the queue worker and
        // behind the reverse proxy alike.
        return AlertDigestContent::siteUrl(URL::signedRoute('alert-email.unsubscribe', ['user' => $user->id], absolute: false));
    }
}
