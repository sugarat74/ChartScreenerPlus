<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Alerts (docs/specs/alerts-engine.md)
    |--------------------------------------------------------------------------
    |
    | `max_per_user` caps the Alerts one Registered User can keep; `max_items`
    | caps the instruments listed in one notification (the rest is summarized
    | as a count); notifications older than `notification_retention_days` are
    | deleted daily. Keep the privacy policy in sync when changing retention.
    |
    */

    'max_per_user' => (int) env('ALERTS_MAX_PER_USER', 10),

    'max_items' => 50,

    'notification_retention_days' => (int) env('ALERTS_NOTIFICATION_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Daily email digest (docs/specs/alerts-email-digest.md)
    |--------------------------------------------------------------------------
    |
    | Off by default. The digest is available only when `enabled` is true AND
    | the sending provider and its data location are both set: the privacy
    | policy names them from these values, so no email can go out before the
    | legal text covers it. The transport itself is Laravel's mail
    | configuration (MAIL_MAILER, MAIL_HOST…, MAIL_FROM_ADDRESS).
    |
    */

    'email_digest' => [
        'enabled' => (bool) env('ALERT_EMAIL_DIGEST_ENABLED', false),
        // A blank value falls back to the shared MAIL_PROVIDER* pair, so one
        // pair names the provider for account and alert emails alike
        // (docs/specs/auth-email-verification.md).
        'provider' => env('ALERT_EMAIL_PROVIDER') ?: env('MAIL_PROVIDER'),
        'provider_location' => env('ALERT_EMAIL_PROVIDER_LOCATION') ?: env('MAIL_PROVIDER_LOCATION'),
        'max_items' => 50,
        'ledger_retention_days' => (int) env('ALERT_EMAIL_LEDGER_RETENTION_DAYS', 90),
    ],

];
