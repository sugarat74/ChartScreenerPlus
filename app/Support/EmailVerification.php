<?php

namespace App\Support;

use App\Models\User;
use App\Services\Alerts\AlertDigestContent;
use Illuminate\Support\Facades\URL;

/**
 * The single email verification predicate
 * (docs/specs/auth-email-verification.md, D1-D5).
 *
 * Every access decision (the `verified` middleware, the `view-market-context`
 * ability, the alert digest, the auth payload) reads `pending()`; nothing else
 * reads the flag or `email_verified_at` to decide access.
 */
final class EmailVerification
{
    /** The SPA page that POSTs the signed link back to the API. */
    public const SPA_PATH = '/verificar-correo/';

    private const API_PATH = '/api/email/verify/';

    /**
     * Verification is enforced only while the flag is on and outbound email
     * really works; otherwise no account is ever pending.
     */
    public static function required(): bool
    {
        return (bool) config('auth.verification.enabled') && OutboundMail::ready();
    }

    /**
     * A Registered User who still has to confirm their address. Admins are
     * never pending, so they can never be locked out.
     */
    public static function pending(?User $user): bool
    {
        return $user !== null
            && self::required()
            && ! $user->isAdmin()
            && ! $user->hasVerifiedEmail();
    }

    /**
     * The link of the confirmation email: the SPA page with the relative
     * signed API path (user id, sha1 of the address, `expires`, `signature`),
     * rooted at APP_URL like the digest's unsubscribe link. The SPA POSTs it,
     * so a GET (a mail link scanner) never verifies anything.
     */
    public static function link(User $user): string
    {
        $signed = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 1440)),
            ['user' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
            absolute: false,
        );

        return AlertDigestContent::siteUrl(self::SPA_PATH.substr($signed, strlen(self::API_PATH)));
    }
}
