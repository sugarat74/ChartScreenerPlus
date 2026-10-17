<?php

/*
|--------------------------------------------------------------------------
| Company Fundamentals from SEC EDGAR (docs/specs/company-fundamentals-sec.md)
|--------------------------------------------------------------------------
|
| Verified fundamentals computed by Chartiko from the companies' own 10-K /
| 10-Q filings (EDGAR XBRL "companyfacts", public domain) for the Company
| Card. Opt-in: `fundamentals:refresh` refuses to run and the API returns no
| fundamentals until `enabled` is true AND `user_agent` is a descriptive
| contact (a name and an email, as the SEC fair-access policy requires).
| See docs/ai-company-card-operations.md before enabling.
|
*/

return [
    'enabled' => (bool) env('SEC_FUNDAMENTALS_ENABLED', false),

    // e.g. "Chartiko ops@chartiko.com". Empty or without a name and an email
    // counts as not configured: the SEC answers 403 to undeclared clients.
    'user_agent' => (string) env('SEC_USER_AGENT', ''),

    // Request spacing for every SEC request; clamped to 1..9 (SEC limit: 10/s).
    'requests_per_second' => (int) env('SEC_REQUESTS_PER_SECOND', 5),
    'timeout_seconds' => (int) env('SEC_TIMEOUT_SECONDS', 30),

    // Bounded retries for 429 / 5xx / timeouts in SecEdgarClient only (the EOD
    // pipeline scheduling rule in CONSTRAINTS.md is unchanged). One backoff
    // delay (seconds) per retry.
    'retries' => 2,
    'backoff_seconds' => [2, 8],

    // Overridable only for local QA fakes: must be https://, or http:// on
    // localhost / 127.0.0.1; anything else counts as not configured.
    'data_base_url' => env('SEC_DATA_BASE_URL', 'https://data.sec.gov'),
    'tickers_url' => env('SEC_TICKERS_URL', 'https://www.sec.gov/files/company_tickers.json'),
    'tickers_cache_days' => 7,

    // Overlap guard for the command (seconds).
    'lock_ttl_seconds' => 3600,

    'schema_version' => 'fundamentals-v1',

    // SEC SIC codes treated as financial companies (banks, credit, brokers and
    // exchanges, insurance): EBITDA-based metrics and ROIC do not apply.
    // REITs (6798) are outside this range on purpose.
    'financial_sic' => [6000, 6411],
];
