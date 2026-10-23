<?php

namespace App\Support;

/**
 * Whether the application can really send account email
 * (docs/specs/auth-email-verification.md, D1). Shared by email verification
 * and, next, password reset.
 *
 * Ready means the sending provider and its data location are both named (the
 * privacy policy names them from the same values) and, in production, the
 * mailer is a real transport: the `log` mailer would write addresses and live
 * links into `laravel.log`, and `array` sends nothing.
 */
final class OutboundMail
{
    private const NON_DELIVERING_MAILERS = ['log', 'array'];

    public static function ready(): bool
    {
        if (self::provider() === null || self::providerLocation() === null) {
            return false;
        }

        return ! (app()->isProduction()
            && in_array((string) config('mail.default'), self::NON_DELIVERING_MAILERS, true));
    }

    public static function provider(): ?string
    {
        return self::trimmed(config('mail.provider'));
    }

    public static function providerLocation(): ?string
    {
        return self::trimmed(config('mail.provider_location'));
    }

    private static function trimmed(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
