<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sign-in activity retention
    |--------------------------------------------------------------------------
    |
    | Days that sign-in activity (email, IP address, user agent) is kept for
    | the Admin overview before `model:prune` deletes it. Decided 2026-10-07:
    | 90 days. Keep the privacy notice in sync when changing it.
    |
    */

    'activity_retention_days' => (int) env('ADMIN_ACTIVITY_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Admin list page sizes
    |--------------------------------------------------------------------------
    */

    'per_page_default' => 25,
    'per_page_max' => 100,

];
