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

];
