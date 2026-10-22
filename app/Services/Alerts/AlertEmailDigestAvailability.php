<?php

namespace App\Services\Alerts;

/**
 * Whether the daily alert email can be offered and sent (alerts-email-digest).
 *
 * The feature flag, the provider name and its data location are all
 * required: the privacy policy names the provider and the location from the
 * same configuration, so the digest can never be active while the legal text
 * does not describe it.
 */
final class AlertEmailDigestAvailability
{
    public static function available(): bool
    {
        return (bool) config('alerts.email_digest.enabled')
            && self::provider() !== null
            && self::providerLocation() !== null;
    }

    public static function provider(): ?string
    {
        $provider = trim((string) config('alerts.email_digest.provider'));

        return $provider === '' ? null : $provider;
    }

    public static function providerLocation(): ?string
    {
        $location = trim((string) config('alerts.email_digest.provider_location'));

        return $location === '' ? null : $location;
    }
}
