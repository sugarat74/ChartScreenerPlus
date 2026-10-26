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

    // How long a pipeline that finds the global pipeline lock held waits for it
    // before skipping with a warning. Per-market pipelines may share a time.
    'lock_wait_seconds' => (int) env('INGESTION_LOCK_WAIT_SECONDS', 900),

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

    /*
    |--------------------------------------------------------------------------
    | Listing Market calendars and schedules (non-US)
    |--------------------------------------------------------------------------
    |
    | `US` is configured by the keys above (timezone, market_close,
    | schedule_buffer_minutes, holidays), unchanged. Every other Listing Market
    | (App\Support\ListingMarket) has its own entry here: `timezone`, `close`
    | (local wall clock), `buffer_minutes` after the close, and `holidays`
    | (weekday closures, Y-m-d; half days are trading days). The 45-minute
    | European buffer covers the closing auction and source publication.
    |
    | XMAD (Bolsa de Madrid): official BME trading calendar
    | (https://www.bolsasymercados.es/en/bme-exchange/trading/trading-calendar.html).
    | 2026 is as published by BME (non-trading days: 1 Jan, 3 Apr, 6 Apr, 1 May,
    | 25 Dec). 24 and 31 December are trading days that close at 14:00 CET: they
    | are half days and are NOT listed. 2027 is DERIVED from BME's pattern
    | (1 Jan, Good Friday, Easter Monday, 1 May, 25 and 26 Dec; weekends are not
    | listed) because BME had not published it yet: CONFIRM it against BME's 2027
    | calendar when published. The XETR (Xetra) list arrives with universe-dax
    | and is empty until then. REFRESH ANNUALLY, like the NYSE list above.
    |
    */

    'markets' => [
        'XMAD' => [
            'timezone' => env('INGESTION_XMAD_TIMEZONE', 'Europe/Madrid'),
            'close' => env('INGESTION_XMAD_MARKET_CLOSE', '17:30'),
            'buffer_minutes' => (int) env('INGESTION_XMAD_BUFFER_MINUTES', 45),
            'holidays' => [
                // 2026 (BME official)
                '2026-01-01',
                '2026-04-03',
                '2026-04-06',
                '2026-05-01',
                '2026-12-25',
                // 2027 (derived, confirm when BME publishes it)
                '2027-01-01',
                '2027-03-26',
                '2027-03-29',
            ],
        ],
        'XETR' => [
            'timezone' => env('INGESTION_XETR_TIMEZONE', 'Europe/Berlin'),
            'close' => env('INGESTION_XETR_MARKET_CLOSE', '17:30'),
            'buffer_minutes' => (int) env('INGESTION_XETR_BUFFER_MINUTES', 45),
            'holidays' => [],
        ],
    ],

];
