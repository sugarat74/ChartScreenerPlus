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
    // before skipping with a warning. Per-market pipelines may share a time: the
    // European ones (XMAD, XETR, XPAR, XLON) fire at the same UTC instant on common
    // trading days and run one after another, so the budget covers several runs.
    // If they still do not fit, stagger with INGESTION_<CODE>_BUFFER_MINUTES.
    'lock_wait_seconds' => (int) env('INGESTION_LOCK_WAIT_SECONDS', 3600),

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
    | NYSE regular session open and early closes (market-hours-status)
    |--------------------------------------------------------------------------
    |
    | `market_open` is the regular continuous session open (America/New_York
    | wall clock; the close is `market_close`). `early_closes` maps a trading
    | day (Y-m-d) to its early close (HH:MM, same timezone): the NYSE closes at
    | 13:00 on the day after Thanksgiving and on 24 December when that is a
    | trading day (it is a holiday in 2027, listed above). Used only by the
    | open/closed status of the home page; the ingestion schedule ignores both.
    | REFRESH ANNUALLY together with the holidays above.
    |
    */

    'market_open' => env('INGESTION_MARKET_OPEN', '09:30'),

    'early_closes' => [
        '2026-11-27' => '13:00',
        '2026-12-24' => '13:00',
        '2027-11-26' => '13:00',
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
    | calendar when published. REFRESH ANNUALLY, like the NYSE list above.
    |
    | `open` and `early_closes` (`Y-m-d => HH:MM`, local) feed only the home page
    | open/closed status (market-hours-status): the regular continuous session.
    | Auctions and pre/post-market are not modelled.
    |
    | XETR (Xetra / Frankfurt): Deutsche Boerse's trading calendar
    | (https://www.cashmarket.deutsche-boerse.com/cash-en/trading/trading-calendar-and-trading-hours).
    | 2026 non-trading days: 1 Jan, 3 Apr, 6 Apr, 1 May, 24 Dec, 25 Dec, 31 Dec
    | (24 and 31 December have no trading but are settlement days: unlike BME they
    | are full closures and ARE listed). 2027 is in the same overview (1 Jan,
    | 26 Mar, 29 Mar, 24 Dec, 31 Dec; 1 May and 25 Dec fall on a Saturday), but
    | Deutsche Boerse states that changes for future years are not excluded:
    | CONFIRM 2027 on the page during 2027. German public holidays such as
    | Ascension, Whit Monday and Corpus Christi are trading days.
    |
    | XPAR (Euronext Paris): Euronext's trading hours and holidays page
    | (https://live.euronext.com/en/resources/trading-hours-holidays, fetched
    | 2026-11-08). Cash-market closures: 2026 = 1 Jan, 3 Apr (Good Friday), 6 Apr
    | (Easter Monday), 1 May, 25 Dec; 2027 = 1 Jan, 26 Mar, 29 Mar (1 May and
    | 25 Dec 2027 fall on weekends and are not listed). 24 and 31 December are
    | HALF DAYS, not closures: they are trading days listed in `early_closes`
    | (Euronext: Paris cash instruments close by 14:05 CET on those days). The
    | closing auction (to 17:35) is not modelled. The same calendar applies to
    | Amsterdam (ArcelorMittal's `MT.AS` line). 2027 comes from the same page but
    | is subject to change: CONFIRM it during 2027. REFRESH ANNUALLY.
    |
    */

    'markets' => [
        'XLON' => [
            'timezone' => env('INGESTION_XLON_TIMEZONE', 'Europe/London'),
            'open' => env('INGESTION_XLON_MARKET_OPEN', '08:00'),
            'close' => env('INGESTION_XLON_MARKET_CLOSE', '16:30'),
            'buffer_minutes' => (int) env('INGESTION_XLON_BUFFER_MINUTES', 45),
            // Checked 2026-11-10. Refresh annually from LSE business-days and
            // England/Wales bank holidays: https://www.gov.uk/bank-holidays.json
            // https://www.londonstockexchange.com/en/resources/markets-products/business-days
            'holidays' => [
                '2026-01-01', '2026-04-03', '2026-04-06', '2026-05-04',
                '2026-05-25', '2026-08-31', '2026-12-25', '2026-12-28',
                '2027-01-01', '2027-03-26', '2027-03-29', '2027-05-03',
                '2027-05-31', '2027-08-30', '2027-12-27', '2027-12-28',
            ],
            // LSE: closing process commences at 12:30 London time. The
            // pipeline still runs after the regular close + buffer (17:15).
            'early_closes' => [
                '2026-12-24' => '12:30', '2026-12-31' => '12:30',
                '2027-12-24' => '12:30', '2027-12-31' => '12:30',
            ],
        ],
        'XMAD' => [
            'timezone' => env('INGESTION_XMAD_TIMEZONE', 'Europe/Madrid'),
            'open' => env('INGESTION_XMAD_MARKET_OPEN', '09:00'),
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
            // Half days (market-hours-status): 24 and 31 December close at 14:00
            // CET. 2027 derived like its holidays: confirm with BME's calendar.
            'early_closes' => [
                '2026-12-24' => '14:00',
                '2026-12-31' => '14:00',
                '2027-12-24' => '14:00',
                '2027-12-31' => '14:00',
            ],
        ],
        'XETR' => [
            'timezone' => env('INGESTION_XETR_TIMEZONE', 'Europe/Berlin'),
            'open' => env('INGESTION_XETR_MARKET_OPEN', '09:00'),
            'close' => env('INGESTION_XETR_MARKET_CLOSE', '17:30'),
            'buffer_minutes' => (int) env('INGESTION_XETR_BUFFER_MINUTES', 45),
            'holidays' => [
                // 2026 (Deutsche Boerse official)
                '2026-01-01',
                '2026-04-03',
                '2026-04-06',
                '2026-05-01',
                '2026-12-24',
                '2026-12-25',
                '2026-12-31',
                // 2027 (Deutsche Boerse overview, subject to change: confirm in 2027)
                '2027-01-01',
                '2027-03-26',
                '2027-03-29',
                '2027-12-24',
                '2027-12-31',
            ],
            // Xetra has no half days: 24 and 31 December are full closures.
            'early_closes' => [],
        ],
        'XPAR' => [
            'timezone' => env('INGESTION_XPAR_TIMEZONE', 'Europe/Paris'),
            'open' => env('INGESTION_XPAR_MARKET_OPEN', '09:00'),
            'close' => env('INGESTION_XPAR_MARKET_CLOSE', '17:30'),
            'buffer_minutes' => (int) env('INGESTION_XPAR_BUFFER_MINUTES', 45),
            'holidays' => [
                // 2026 (Euronext official)
                '2026-01-01',
                '2026-04-03',
                '2026-04-06',
                '2026-05-01',
                '2026-12-25',
                // 2027 (Euronext overview, subject to change: confirm in 2027)
                '2027-01-01',
                '2027-03-26',
                '2027-03-29',
            ],
            // Half days (market-hours-status): 24 and 31 December close at 14:05 CET.
            'early_closes' => [
                '2026-12-24' => '14:05',
                '2026-12-31' => '14:05',
                '2027-12-24' => '14:05',
                '2027-12-31' => '14:05',
            ],
        ],
    ],

];
