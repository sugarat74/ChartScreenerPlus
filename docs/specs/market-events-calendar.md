# Market events calendar - free scope

Feature `market-events-calendar`; dependencies `market-context-ui` and `macro-series-fred` (accepted). Owner authorized the free scope on 2026-11-10. Status: passing, awaiting independent validation.

## Goal and scope

On `/mercado`, show Fed/ECB policy meetings from CentralBankCalendar and US release dates for CPI (FRED release 10), Employment Situation (50), GDP (53), Personal Income and Outlays / PCE (54), and Retail Sales (9). IDs/titles checked on official FRED release pages on 2026-11-10. No new numeric series are fetched; none of these releases has a matching series in the current Macro Observation allowlist. A date does not assert that a release or policy decision actually occurred.

Earnings dates, the chart earnings chip and additional European macro feeds are deferred until a provider with public-display rights is selected. No paid provider or account. Existing MACRO_ENABLED and FRED_API_KEY enable macro dates; Fed/ECB meetings work with macro off. Existing Market Context legal/access gate stays in force.

## Contract and scenarios

1. Meetings reuse `CentralBankCalendar.meetings`, decision date and config source URL/name/checked_at. Future Fed dates are tentative; ECB dates scheduled; elapsed dates are past, never published. Source checks older than one year are stale. Given macro is off, meetings still appear and FRED rows are neither fetched nor shown. Source days stay date-only without timezone conversion.
2. Extend FredClient with a closed releaseDates method using its spacing/retries/secret handling. Fetch `/release/dates` with release_id, realtime_start/end, include_release_dates_with_no_data=true, sort_order=asc, limit=10000. Validate list shape, release ID, exact ISO date, count and zero offset; reject truncation. Given malformed, empty, unknown-release or partial data, store no dates for that source, retaining last good rows. Future FRED dates tentative, elapsed dates past. Store no third-party text, forecasts, consensus, estimates or AI summaries.
3. market_events uniquely stores (code,date); market_event_sources stores last attempt, last success and sanitized error code. events:refresh replaces a fully validated source window transactionally, removing withdrawn/rescheduled dates; window today in ingestion timezone minus30 to plus90 days. Given a source failure, retain its data, continue the remaining sources and record failure. Command exit1 on any failure. Disabled or malformed-key paths request nothing; a lock prevents overlap. Schedule daily06:45 in ingestion timezone incl weekends; isolated US pipeline stage after macro. Admin rerun reuses queued/runlog workflow and shows failed status. Sources stale after3 days or a failed attempt.
4. GET /api/market-context/events behind auth + view-market-context: guest401, disallowed user404, Admin preview while public flag off. Optional days=14|30|90 (default14), strict ISO from/to (defaults today-7 to today+days), kind=central_bank|macro_release, region=US|EU. Reject reversed or >100-day ranges. Read-only, no provider calls. Response as_of/from/to/macro_enabled/macro_configured/sources/events. Each event has id,code,kind,region,date,status,source,source_url,checked_at,stale; sorted by date then id. Public source state exposes checked_at and stale only.
5. Full-width calendar card on /mercado; upcoming includes today; recent last7 days descending. Horizon14/30/90, kind/region native selects, localized names, explicit tentative/stale, sources and review dates. Distinct loading/error/retry/empty/disabled/unconfigured/not-refreshed states. Error isolated from other Market cards. Existing FRED notice and binding terms appear when calendar is the only FRED content shown. No hardcoded product copy or public EOD wording.

## Tasks

- [x] Config, schema, closed parser and atomic refresh/source metadata (1-3).
- [x] Opt-in command, daily schedule, isolated pipeline and Admin rerun (3).
- [x] Authorized bounded API (4).
- [x] Calendar, filters, es/en messages and legal attribution (5).
- [x] Offline regression tests, init.ps1, docs and evidence (1-5).

## Sources, reuse and fixtures

FRED endpoint: https://fred.stlouisfed.org/docs/api/fred/release_dates.html (future dates require include_release_dates_with_no_data=true). Release URLs: https://fred.stlouisfed.org/release?rid=10 and rid=50/53/54/9. Source organizations BLS, BEA and Census; factual US federal release dates only, no report prose or copyrighted third-party series. BLS public-domain policy https://www.bls.gov/bls/linksite.htm. FRED API terms https://fred.stlouisfed.org/docs/api/terms_of_use.html; existing notice, binding clause and owner legal gate apply. Calendar data never enters AI payloads. Official Fed/ECB URLs/review dates remain in config/central_banks.php.

Fixtures use the documented FRED JSON envelope and synthetic dates, explicitly not a claimed live API capture. Tests stay offline using Http::preventStrayRequests and a fake Sleeper. Parser tests include wrong release/date, empty list, count mismatch, truncated response and ignored extra fields. Refresh tests cover repeat/withdrawal, failure preservation, isolation and sanitized errors. Cover API access matrix, ranges/filters, off/no-key/lock paths, schedule/pipeline, Admin rerun, UI filters/horizon/async states/es/en. Run init.ps1. Leave passing for independent validation only after evidence.


## Implementation evidence (2026-11-10)

Full init.ps1 exited 0 (Laravel 1023 passed / 6 skipped; SPA 61 files / 486 passed; lint/typecheck/build; engine 160 passed). Final focused reruns after adding scalar-response protection and two page integration cases: backend MarketEventsTest 8 passed / 77 assertions, SPA 28 passed. Earlier broader backend selection 37 passed / 303 assertions. Existing RequireAuth lint, Vite bundle-size and Starlette/httpx warnings persist.

Browser smoke was attempted with a temporary isolated preview; CUA reported browser access host unavailable. Preview/server removed. No real FRED request, PostgreSQL execution or production rollout; fixtures are synthetic contract examples. Independent validator must assess those limits before acceptance. Operations are in deploy/README.md.
