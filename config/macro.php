<?php

/*
|--------------------------------------------------------------------------
| Macro Series from FRED (docs/specs/macro-series-fred.md)
|--------------------------------------------------------------------------
|
| A closed list of public FRED series fetched by `macro:refresh` (daily event
| and an isolated stage of `ingestion:pipeline`) through
| App\Services\Macro\FredClient only. Opt-in: nothing is requested until
| MACRO_ENABLED=true AND FRED_API_KEY is a 32-character lower-case
| alphanumeric key. The key lives only in the server .env; it never appears
| in logs, exceptions, command output, tables or the repository.
|
| FRED API terms (https://fred.stlouisfed.org/docs/api/terms_of_use.html,
| read 2026-10-22): the first public page (market-context-ui) must show
| "This product uses the FRED® API but is not endorsed or certified by the
| Federal Reserve Bank of St. Louis.", link to those terms and name each
| series' source (`attribution` below). FRED content must never be sent to
| an AI / machine-learning system, so no macro data reaches the AI provider.
|
| Each series' licence was checked on its FRED page on 2026-10-22:
| `public_domain` (Public Domain: Citation Requested), `open_attribution`
| (third-party copyright with a free reuse policy: ECB statistics, OECD
| CC BY 4.0) and `restricted` (non-commercial or uncleared terms: off by
| default behind their own flag). Values are stored as published.
|
*/

return [
    'enabled' => (bool) env('MACRO_ENABLED', false),

    // Valid only if it matches ^[a-z0-9]{32}$ (FRED's key format).
    'fred_api_key' => (string) env('FRED_API_KEY', ''),

    'base_url' => 'https://api.stlouisfed.org/fred',

    // Spacing between FRED requests: at most 100 requests/minute (the
    // unofficial FRED limit is 120/minute per key).
    'request_interval_seconds' => 0.6,
    'timeout_seconds' => 20,

    // Bounded retries for 429 / 5xx / connection errors in FredClient only
    // (same exception as the SEC client). One backoff delay per retry.
    'retries' => 2,
    'backoff_seconds' => [5, 20],

    // Time budget for one `macro:refresh` run: once spent, the remaining
    // series are skipped (picked up by the next run), so an outage can never
    // hold the EOD pipeline for long. It stays well under lock_ttl_seconds
    // (one series costs at most two requests with their retries).
    'run_budget_seconds' => 300,

    // First run (or --backfill): today minus this many years.
    'backfill_years' => 5,

    // Incremental runs re-read this many days before the last stored
    // observation, so revisions (claims, permits, NFCI) are picked up.
    'refetch_days' => ['daily' => 14, 'weekly' => 56, 'monthly' => 186],

    // A reading older than this (calendar days, by its anchor series'
    // frequency) is stale. Monthly data is dated the 1st and published
    // ~45-50 days later, hence 100.
    'stale_after_days' => ['daily' => 7, 'weekly' => 16, 'monthly' => 100],

    // Overlap guard for the command (seconds).
    'lock_ttl_seconds' => 900,

    // Daily event (market timezone, every day so weekly releases are picked
    // up without a market session).
    'schedule_time' => '10:00',

    /*
    | The closed list. `release_lag_days` is the usual delay between an
    | observation's date and its publication, used for the no-look-ahead
    | (point-in-time) readings of the Market Regime backfill.
    */
    'series' => [
        'DGS3MO' => [
            'category' => 'rates', 'frequency' => 'daily', 'license' => 'public_domain', 'release_lag_days' => 1,
            'attribution' => 'Board of Governors of the Federal Reserve System (US), H.15 Selected Interest Rates, via FRED®',
            'enabled' => true,
        ],
        'DGS2' => [
            'category' => 'rates', 'frequency' => 'daily', 'license' => 'public_domain', 'release_lag_days' => 1,
            'attribution' => 'Board of Governors of the Federal Reserve System (US), H.15 Selected Interest Rates, via FRED®',
            'enabled' => true,
        ],
        'DGS10' => [
            'category' => 'rates', 'frequency' => 'daily', 'license' => 'public_domain', 'release_lag_days' => 1,
            'attribution' => 'Board of Governors of the Federal Reserve System (US), H.15 Selected Interest Rates, via FRED®',
            'enabled' => true,
        ],
        'DFEDTARL' => [
            'category' => 'policy', 'frequency' => 'daily', 'license' => 'public_domain', 'release_lag_days' => 0,
            'attribution' => 'Board of Governors of the Federal Reserve System (US), FOMC Press Release, via FRED®',
            'enabled' => true,
        ],
        'DFEDTARU' => [
            'category' => 'policy', 'frequency' => 'daily', 'license' => 'public_domain', 'release_lag_days' => 0,
            'attribution' => 'Board of Governors of the Federal Reserve System (US), FOMC Press Release, via FRED®',
            'enabled' => true,
        ],
        'ECBDFR' => [
            'category' => 'policy', 'frequency' => 'daily', 'license' => 'open_attribution', 'release_lag_days' => 0,
            'attribution' => 'Source: ECB statistics, via FRED®',
            'enabled' => true,
        ],
        'WALCL' => [
            'category' => 'liquidity', 'frequency' => 'weekly', 'license' => 'public_domain', 'release_lag_days' => 1,
            'attribution' => 'Board of Governors of the Federal Reserve System (US), H.4.1 Factors Affecting Reserve Balances, via FRED®',
            'enabled' => true,
        ],
        'WDTGAL' => [
            'category' => 'liquidity', 'frequency' => 'weekly', 'license' => 'public_domain', 'release_lag_days' => 1,
            'attribution' => 'Board of Governors of the Federal Reserve System (US), H.4.1 Factors Affecting Reserve Balances, via FRED®',
            'enabled' => true,
        ],
        'WLRRAOL' => [
            'category' => 'liquidity', 'frequency' => 'weekly', 'license' => 'public_domain', 'release_lag_days' => 1,
            'attribution' => 'Board of Governors of the Federal Reserve System (US), H.4.1 Factors Affecting Reserve Balances, via FRED®',
            'enabled' => true,
        ],
        'ICSA' => [
            'category' => 'leading', 'frequency' => 'weekly', 'license' => 'public_domain', 'release_lag_days' => 5,
            'attribution' => 'U.S. Employment and Training Administration, via FRED®',
            'enabled' => true,
        ],
        'PERMIT' => [
            'category' => 'leading', 'frequency' => 'monthly', 'license' => 'public_domain', 'release_lag_days' => 50,
            'attribution' => 'U.S. Census Bureau and U.S. Department of Housing and Urban Development, via FRED®',
            'enabled' => true,
        ],
        'USALOLITOAASTSAM' => [
            'category' => 'leading', 'frequency' => 'monthly', 'license' => 'open_attribution', 'release_lag_days' => 50,
            'attribution' => 'OECD, Composite Leading Indicators (CC BY 4.0), via FRED®',
            'enabled' => true,
        ],
        // Chicago Fed: reproduction only "not for the purpose of private
        // and/or commercial gain" without written permission.
        'NFCI' => [
            'category' => 'leading', 'frequency' => 'weekly', 'license' => 'restricted', 'release_lag_days' => 5,
            'attribution' => 'Federal Reserve Bank of Chicago, via FRED®',
            'enabled' => (bool) env('MACRO_NFCI_ENABLED', false),
        ],
        // Cboe copyright: off until Cboe's terms are cleared.
        'VIXCLS' => [
            'category' => 'volatility', 'frequency' => 'daily', 'license' => 'restricted', 'release_lag_days' => 1,
            'attribution' => 'Chicago Board Options Exchange, via FRED®',
            'enabled' => (bool) env('MACRO_VIXCLS_ENABLED', false),
        ],
    ],
];
