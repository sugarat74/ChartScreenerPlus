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
    | legal text covers it, and the mailer must really deliver (not `log` or
    | `array` in production). The transport itself is Laravel's mail
    | configuration (MAIL_MAILER, MAIL_HOST…, MAIL_FROM_ADDRESS).
    |
    */

    'email_digest' => [
        'enabled' => (bool) env('ALERT_EMAIL_DIGEST_ENABLED', false),
        // One provider for account and alert emails: the shared MAIL_PROVIDER*
        // pair, so the privacy policy can never name two different senders
        // (docs/specs/alerts-email-golive.md, D3.2; ALERT_EMAIL_PROVIDER* is
        // retired). The keys stay so tests can still set them.
        'provider' => env('MAIL_PROVIDER'),
        'provider_location' => env('MAIL_PROVIDER_LOCATION'),
        'max_items' => 50,
        'ledger_retention_days' => (int) env('ALERT_EMAIL_LEDGER_RETENTION_DAYS', 90),
    ],

];
