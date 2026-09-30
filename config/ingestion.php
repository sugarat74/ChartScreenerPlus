<?php

return [

    /*
    |--------------------------------------------------------------------------
    | EOD ingestion schedule
    |--------------------------------------------------------------------------
    |
    | Timezone and wall-clock time for the daily EOD pipeline. The wall-clock
    | time is interpreted in `timezone` (the US market timezone), so the tz
    | database shifts the UTC fire instant across DST. config/app.php stays UTC.
    |
    */

    'timezone' => env('INGESTION_TIMEZONE', 'America/New_York'),

    'market_close' => env('INGESTION_MARKET_CLOSE', '16:00'),

    'schedule_buffer_minutes' => (int) env('INGESTION_SCHEDULE_BUFFER_MINUTES', 30),

    'universe' => env('INGESTION_UNIVERSE', 'sp500'),

    // How long the command-level overlap lock is held before it expires on its
    // own, so a crashed run cannot hold the pipeline forever.
    'lock_ttl_seconds' => (int) env('INGESTION_LOCK_TTL_SECONDS', 7200),

    /*
    |--------------------------------------------------------------------------
    | NYSE market holidays
    |--------------------------------------------------------------------------
    |
    | Dates (Y-m-d) on which the NYSE is closed. Weekend dates are not listed;
    | weekends are skipped by the schedule's weekday filter and by
    | App\Services\Market\MarketCalendar. Half-days (early closes) are trading
    | days and are intentionally NOT listed.
    |
    | Source: official NYSE calendar (nyse.com) for the current + next year,
    | with the standard Saturday -> preceding Friday / Sunday -> following
    | Monday observed rule. NYSE does NOT observe New Year's Day on the
    | preceding Friday when January 1 falls on a Saturday.
    |
    | REFRESH ANNUALLY: this is a committed snapshot, not a computed calendar.
    | Add the next year's dates (and drop the oldest) when rolling into a new
    | year. A missing holiday only causes a harmless idempotent run attempt.
    |
    */

    'holidays' => [
        // 2026
        '2026-01-01',
        '2026-01-19',
        '2026-02-16',
        '2026-04-03',
        '2026-05-25',
        '2026-06-19',
        '2026-07-03',
        '2026-09-07',
        '2026-11-26',
        '2026-12-25',
        // 2027
        '2027-01-01',
        '2027-01-18',
        '2027-02-15',
        '2027-03-26',
        '2027-05-31',
        '2027-06-18',
        '2027-07-05',
        '2027-09-06',
        '2027-11-25',
        '2027-12-24',
    ],

];
