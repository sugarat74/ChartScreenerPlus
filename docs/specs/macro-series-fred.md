# Feature Implementation Spec: Daily FRED macro series and central bank calendar (opt-in)

## Source Feature

- `id`: `macro-series-fred`
- `area`: `backend`
- `depends_on`: `ingestion-scheduler` (`accepted`)
- `status`: `not_started` at planning time (2026-10-22)
- `source`: `feature_list.json`. Product decisions: `docs/specs/market-context.md` → "Domain Decisions (shared)" and "Feature 2". This spec is the implementation contract; where it is more precise than the umbrella, this spec wins (see "Resolved Ambiguities And Deviations"). The umbrella was narrowly corrected on 2026-10-22 to match it.

## Goal

When enabled (`MACRO_ENABLED=true` + a FRED API key on the server), Chartiko fetches a closed list of public FRED series through the FRED API, stores their observations with their own dates, and exposes pure derived readings (rates, curve, net liquidity, leading data, policy rates, last rate change, next central bank meeting) that `market-regime` and `market-context-ui` will read. It runs inside the daily pipeline (isolated) and as its own daily event. Disabled by default: nothing is requested and nothing changes.

## Non-Goals

- No API endpoint, page, Admin UI, regime or i18n text (Features 3–4). No FRED data in any public payload yet.
- No Cboe put/call (Feature 6), although the storage accepts `source = cboe`.
- No series outside the closed list; no ALFRED vintages; no market-implied probabilities; no forecasts.
- No engine change. No FRED data sent to the AI provider (see Domain Rule 9).

## Job Story

When the daily data update runs,
I want the macro series stored with their own dates and readings derived by fixed rules,
so the Market Regime and the Market page can show and score them without calling FRED per request.

## Users And Permissions

- Visitor / Registered User / Admin: no new surface.
- Operator: `php artisan macro:refresh {--series=*} {--backfill}`; sets `MACRO_ENABLED`, `FRED_API_KEY`, `MACRO_NFCI_ENABLED`, `MACRO_VIXCLS_ENABLED` in the server `.env`.

## Series Verification (FRED website, 2026-10-22, no key)

Every page also shows FRED's generic line "Data in this graph are copyrighted. Please review the copyright information in the series notes before sharing."; the per-series tag below is what decides.

| Code | Owner / release | FRED units · frequency | Latest obs. seen | FRED tag | Decision |
| --- | --- | --- | --- | --- | --- |
| `DGS3MO`, `DGS2`, `DGS10` | Federal Reserve Board, H.15 | Percent · Daily | 2026-10-20 (updated 10-21) | Public Domain: Citation Requested | **enabled** |
| `T10Y2Y`, `T10Y3M` | St. Louis Fed, Interest Rate Spreads | Percent · Daily | 2026-10-21 | Copyrighted: Citation Required | **not fetched**: computed as `DGS10 − DGS2` / `DGS10 − DGS3MO` |
| `DFEDTARL`, `DFEDTARU` | Federal Reserve Board, FOMC Press Release | Percent · Daily, 7-Day | 2026-10-22 (3.75 / 4.00) | Public Domain: Citation Requested | **enabled** |
| `ECBDFR` | ECB, Key ECB Interest Rates | Percent · Daily, 7-Day | 2026-10-22 (2.50) | Copyrighted: Citation Required; note "Copyright, 2021, European Central Bank (ECB). Reprinted with permission." | **enabled**: the ECB statistics reuse policy allows free reuse "irrespective of any subsequent commercial or non-commercial use", unaltered, quoting "Source: ECB statistics" |
| `WALCL` | Federal Reserve Board, H.4.1 | Millions of U.S. Dollars · Weekly, As of Wednesday | 2026-10-14 | Public Domain: Citation Requested | **enabled** |
| `WTREGEN` | H.4.1 (TGA, **week average**) | Millions · Weekly, Ending Wednesday | 2026-10-14 | Public Domain | **replaced** by `WDTGAL` |
| `WDTGAL` | H.4.1 (TGA, Wednesday level) | Millions · Weekly, As of Wednesday | 2026-10-14 | Public Domain: Citation Requested | **enabled** |
| `RRPONTSYD` | NY Fed, Temporary Open Market Operations | Billions · Daily | 2026-10-22 | Copyrighted: Citation Required | **replaced** by `WLRRAOL` |
| `WLRRAOL` | H.4.1 (reverse repos: others, Wednesday level) | Millions · Weekly, As of Wednesday | 2026-10-14 | Public Domain: Citation Requested | **enabled** |
| `ICSA` | U.S. Employment and Training Administration | Number, SA · Weekly, Ending Saturday | 2026-10-17 | Public Domain: Citation Requested | **enabled** |
| `PERMIT` | Census Bureau / HUD, New Residential Construction | Thousands of Units, SAAR · Monthly | 2026-09 | Public Domain: Citation Requested | **enabled** |
| `NFCI` | Chicago Fed | Index · Weekly, Ending Friday | 2026-10-16 | Copyrighted: Citation Required | **off by default** (`MACRO_NFCI_ENABLED=false`): the Chicago Fed legal notice allows reproduction only "not for the purpose of private and/or commercial gain" with credit; other uses need its written permission |
| `VIXCLS` | Cboe | Index · Daily, Close | 2026-10-21 | Copyrighted: Citation Required; "Copyright, 2016, Chicago Board Options Exchange, Inc. Reprinted with permission." | **off by default** (`MACRO_VIXCLS_ENABLED=false`) until Cboe terms are cleared |
| `USALOLITONOSTSAM` | OECD CLI, normalized | Index · Monthly | Jan 2024 (stale) | Copyrighted: Citation Required | **omitted** (no longer updated) |
| `USALOLITOAASTSAM` | OECD CLI, amplitude adjusted, US | Index, SA · Monthly | Sep 2026 (updated 2026-10-15) | Copyrighted: Citation Required | **enabled**: OECD content is CC BY 4.0 by default since 2024-07-01; attribution required. FRED shows no next release date, so staleness (Rule 6) covers a silent discontinuation |

**FRED terms** (`https://fred.stlouisfed.org/docs/api/terms_of_use.html`, `https://fred.stlouisfed.org/legal/`, read 2026-10-22). These terms apply to Feature 4 (the first public display); they are recorded here:
- The page must show verbatim: "This product uses the FRED® API but is not endorsed or certified by the Federal Reserve Bank of St. Louis."
- The page must link to the FRED API Terms of Use.
- Chartiko's own terms of use must state that users agree to be bound by the FRED® API Terms of Use.
- "Public Domain: Citation requested" and "Copyrighted: Citation required" series may be used with attribution to the source and to FRED.
- FRED prohibits using FRED content "in connection with the development or training of any software program or system or machine learning", including generative AI.

## Domain Rules (contract)

1. **Config.** `config/macro.php`:
   - `enabled` (`MACRO_ENABLED`, default `false`); `fred_api_key` (`FRED_API_KEY`, default `''`; valid only if `^[a-z0-9]{32}$`).
   - `base_url` `https://api.stlouisfed.org/fred` (not env); `request_interval_seconds` `0.6` (≤ 100 requests/min; the unofficial limit is 120/min per key); `timeout_seconds` `20`; `retries` `2`; `backoff_seconds` `[5, 20]`.
   - `backfill_years` `5`; `refetch_days` `{daily: 14, weekly: 56, monthly: 186}`; `stale_after_days` `{daily: 7, weekly: 16, monthly: 100}`.
   - `lock_ttl_seconds` `900`; `schedule_time` `10:00`; `run_budget_seconds` `300` (added at implementation, see deviation 11).
   - `series`: one entry per code with `category` (`rates`|`policy`|`liquidity`|`leading`|`volatility`), `frequency` (`daily`|`weekly`|`monthly`), `license` (`public_domain`|`open_attribution`|`restricted`), `release_lag_days`, `attribution` (source line for Feature 4) and `enabled`.
   - Enabled codes: `DGS3MO`, `DGS2`, `DGS10` (rates, daily, lag 1); `DFEDTARL`, `DFEDTARU`, `ECBDFR` (policy, daily, lag 0); `WALCL`, `WDTGAL`, `WLRRAOL` (liquidity, weekly, lag 1); `ICSA` (leading, weekly, lag 5); `PERMIT` (leading, monthly, lag 50); `USALOLITOAASTSAM` (leading, monthly, lag 50, `open_attribution`); `ECBDFR` is also `open_attribution`.
   - `NFCI` (leading, weekly, lag 5) and `VIXCLS` (volatility, daily, lag 1) are `restricted`, with `enabled` read from `MACRO_NFCI_ENABLED` / `MACRO_VIXCLS_ENABLED` (default `false`).
2. **Storage.** Table `macro_series`:
   - `id`, `code` string(32) unique, `source` string(16) (`fred` here, `cboe` in Feature 6), `category` string(16), `frequency` string(16), `title` string(255) null, `units` string(128) null.
   - `last_observation_date` date null, `source_updated_at` timestamp null (FRED `last_updated`), `last_attempted_at` timestamp null, `last_refreshed_at` timestamp null (last success), `last_error` string(32) null (a code, never a message), timestamps.

   Table `macro_observations`: `id`, `macro_series_id` FK `cascadeOnDelete`, `date` date, `value` decimal(20,6), timestamps; unique `(macro_series_id, date)`.

   Values are stored **as published**: no unit conversion, no rounding beyond 6 dp. Models `MacroSeries` (`$table = 'macro_series'`) and `MacroObservation`: `date` cast `date`, `value` cast `decimal:6`, `#[Fillable]`, `HasFactory`.
3. **FRED client** `App\Services\Macro\FredClient` (Laravel, modelled on `SecEdgarClient`):
   - `series(code)` calls `GET {base}/series?series_id=&api_key=&file_type=json` and reads `seriess[0]`: `title`, `units`, `frequency_short`, `last_updated` (`Y-m-d H:i:sP`, e.g. `2013-07-31 09:26:16-05`).
   - `observations(code, start)` calls `GET {base}/series/observations?series_id=&api_key=&file_type=json&observation_start=Y-m-d&sort_order=asc` (the default `limit` of 100000 is enough).
   - Requests are spaced by `request_interval_seconds` through the injected `App\Services\Fundamentals\Sleeper`, with no redirects. 429, 5xx and connection errors are retried `retries` times with `backoff_seconds`.
   - Failures throw `FredUnavailable(code, ?status)`, whose message **is the code only**. Codes:
     - `invalid_key`: 400 whose JSON `error_message` mentions `api_key`;
     - `bad_request`: any other 400;
     - `not_found` (404), `rate_limited` (429 after retries), `unavailable` (5xx or connection error after retries);
     - `invalid_response`: not JSON, missing keys, a bad date or a value that is neither numeric nor `"."`;
     - `http_error`: any other status.
   - Logs carry only `{series, endpoint, status}`. A `ConnectionException` is caught and never rethrown or reported, because Guzzle puts the full URL, and with it the key, in its message.
4. **Refresh** (`App\Services\Macro\MacroSeriesRefresher`, one series at a time):
   - Upsert the `macro_series` row from config.
   - Choose `start`:
     - no stored observation, or `--backfill`: today − `backfill_years`;
     - otherwise: `last_observation_date − refetch_days[frequency]`, so revisions (claims, permits, NFCI) are picked up.
   - Fetch the metadata, then the observations. Liquidity series need `units` starting with `Millions of` (else `units_changed`, nothing written), because Rule 5 depends on that scale.
   - In one transaction:
     - upsert every numeric observation keyed `(macro_series_id, Carbon::parse(date)->startOfDay())`, updating a changed value (revision);
     - **delete** stored rows whose date FRED now returns as `"."`; never store `"."` or 0 for it;
     - set `title`, `units`, `source_updated_at`, `last_observation_date` (max stored date), `last_refreshed_at`, `last_attempted_at`, and `last_error = null`.
   - On failure: write nothing but `last_attempted_at` and `last_error` (code); earlier data is kept. Any other `Throwable` becomes `internal_error`, logged with the exception class only.
5. **Derived readings** (`App\Services\Macro\MacroSnapshotService::snapshot(string $asOf, bool $pointInTime = false): array`, read-only, no HTTP).
   - **Inputs.** Only enabled series. Observations with `date <= asOf`; with `pointInTime`, only those with `date + release_lag_days <= asOf` (no look-ahead for the Feature 3 backfill).
   - **Reading shape.** Every reading is `{value: ?float, as_of: ?Y-m-d, stale: bool}`. Values are rounded to 4 dp. A missing input gives `value = null`, `as_of = null` and `stale = false`. A disabled series gives `null` for its whole block.
   - `rates`: `dgs3mo`, `dgs2`, `dgs10` = latest value. `dgs10_change_20` = `DGS10[last] − DGS10[last−20 obs]` in pp, `null` with fewer than 21 observations.
   - `curve`:
     - `spread_10y2y` = `DGS10 − DGS2` and `spread_10y3m` = `DGS10 − DGS3MO`, each on the latest date where both legs have an observation;
     - `inverted` = `spread_10y3m < 0` (strict), `null` when unavailable.
   - `liquidity`:
     - `net_liquidity` (billions) = `(WALCL − WDTGAL − WLRRAOL) / 1000` on the latest date `w` where **all three** have an observation on exactly that date. A Wednesday missing any leg is skipped, and the previous complete one is used.
     - `change_4w_pct` / `change_13w_pct` = `(NL(w) / NL(w − 28 d | w − 91 d) − 1) × 100`. The base must exist on that exact date and be `> 0`, else `null`.
   - `leading`:
     - `icsa` = latest value. `icsa_4w` = mean of the latest 4 observations, `null` unless they are consecutive weeks (7-day steps).
     - `icsa_4w_vs_52w_min_pct` = `(icsa_4w / min(4w averages of the latest 52 weeks, current included) − 1) × 100`. It needs 55 consecutive weekly observations, else `null`.
     - `permit` = latest value. `permit_change_3m_pct` = `(PERMIT[m] / PERMIT[m − 3 months] − 1) × 100` on the exact month start, else `null`.
     - `nfci` = latest value (positive = tighter than average).
     - `oecd_cli` = latest value. `oecd_cli_change_1m` = value − value of the previous month (exact month start), else `null`.
   - `volatility`: `vix` = latest value.
   - `central_banks`:
     - `fed`: `lower`/`upper` from `DFEDTARL`/`DFEDTARU` on their latest common date.
     - `ecb`: `deposit_rate` from `ECBDFR`.
     - Each bank has `last_change` = `{direction: raise|cut, from, to, effective_date}` for the most recent observation whose value differs from the previous observation. The Fed uses the upper limit to detect the change and reports both limits in `from`/`to`. It is `null` when there is no change in stored history.
     - Each bank also has `last_meeting` and `next_meeting` from Rule 7.
   - **Staleness.** A reading is stale when `asOf − as_of > stale_after_days[frequency of its anchor series]`. Derived readings use the date of the observation they were computed from.
6. **Staleness thresholds** follow umbrella decision 3, corrected: daily 7, weekly 16, **monthly 100** days. 75 would flag permits and the OECD CLI as stale every month: their observation is dated the 1st and published about 45–50 days later.
7. **Central bank calendar.** `config/central_banks.php` holds, per bank (`fed`, `ecb`), `name`, `source_url`, `checked_at` (`2026-10-22`) and `meetings` (`[{start, decision}]`, `Y-m-d`, ascending).
   - `App\Services\Macro\CentralBankCalendar`: `next(bank, asOf)` = first meeting with `decision >= asOf`; `last(bank, asOf)` = latest with `decision < asOf`. Either is `null` when there is none, never a guess.
   - There is no inferred "hold": a rate change takes effect after the decision (Fed +1 day, ECB about +6 days), so the reading reports the last change and the last and next meeting dates side by side.
   - FOMC (federalreserve.gov `fomccalendars.htm`; 2027 dates are tentative until confirmed at the previous meeting). Decision is day 2:
     - 2026: Jan 27–28, Mar 17–18, Apr 28–29, Jun 16–17, Jul 28–29, Sep 15–16, Oct 27–28, Dec 8–9.
     - 2027: Jan 26–27, Mar 16–17, Apr 27–28, Jun 8–9, Jul 27–28, Sep 14–15, Oct 26–27, Dec 7–8.
   - ECB Governing Council monetary policy meetings (ecb.europa.eu `press/calendars/mgcgc`). Decision is day 2:
     - 2026: Feb 4–5, Mar 18–19, Apr 29–30, Jun 10–11, Jul 22–23, Sep 9–10, Oct 28–29, Dec 16–17.
     - 2027: Feb 3–4, Mar 17–18, Apr 28–29, Jun 9–10, Jul 21–22, Sep 8–9, Oct 27–28, Dec 15–16.
     - Oct 2026 onwards was read on the official page; Jan–Sep 2026 had already dropped off it and comes from the same page as indexed by search.
8. **Isolation.** `macro:refresh` in the pipeline never changes the exit code or skips later stages (umbrella decision 5, same as the Market Context block).
9. **Secrecy and use.**
   - The key appears in no log, exception message, command output, `last_error`, payload, test fixture or committed file. Tests use the fake key `abcdefghijklmnopqrstuvwxyz012345`.
   - Macro data **must not** be sent to the AI provider (Company Card context, NL Screener or any future AI text), because FRED's terms prohibit that.

## Acceptance Scenarios

### Scenario 1: Disabled is inert
Given `MACRO_ENABLED=false`
When `macro:refresh` runs, the pipeline runs on a trading day, or the schedule is evaluated
Then no HTTP request is sent, the command prints one info line, logs once (`Log::info`) and exits 0, the pipeline prints "Macro series disabled; skipped." and its exit code and request count are unchanged, and the `macro-refresh` event does not pass its filter.

### Scenario 2: Fixtures to observations
Given enabled + a valid key and the FRED fixtures (including `"."` on a holiday)
When `macro:refresh` runs on an empty database
Then each enabled series is requested from today − 5 years and stored with the exact values (6-dp strings). There is no row for the `"."` dates. Series rows carry `title`, `units`, `last_observation_date`, `last_refreshed_at` and `last_error = null`. `NFCI` and `VIXCLS` are not requested.

### Scenario 3: Incremental, revisions and deletions
Given stored observations
When it reruns
Then `observation_start` = last date − `refetch_days`; a revised value is updated; a date now returned as `"."` is deleted; a second identical run changes nothing.

### Scenario 4: One series fails
Given `DGS2` returns 500 on every attempt
Then after 2 retries (asserted on the fake `Sleeper`, no real sleep) `DGS2.last_error = unavailable` and its old data stays, the other series are stored and the exit code is 0. When every series fails the exit code is 1. A 400 with an `api_key` message stops the run with `invalid_key` (exit 1).

### Scenario 5: Key never leaks
Given a `ConnectionException` whose message contains the full request URL, and a 400 error body
Then the fake key appears in no captured log record, command output, `macro_series` row or exception reported by the pipeline.

### Scenario 6: Derived readings
Given observations inserted directly
Then every Rule 5 reading equals its hand-computed value, including these cases:
- the curve at `spread_10y3m` = 0 vs −0.01;
- a missing `WLRRAOL` Wednesday falls back to the previous complete Wednesday;
- a missing 4-week base gives `null`;
- a claims gap gives `null`;
- the stale boundaries (7/8, 16/17, 100/101 days);
- with `pointInTime`, an observation published after `asOf` is ignored;
- the Fed raise/cut and an unchanged series (`last_change = null`).

### Scenario 7: Calendar
Then the config dates are valid, ascending, `start <= decision <= start + 1 day`. `next`/`last` handle `asOf` = decision day (next = that meeting). A real-clock test fails when either bank has no decision date at least 60 days after today (reminder to add the next year).

### Scenario 8: Pipeline isolation
Given enabled and FRED failing (or `macro:refresh` throwing)
When `ingestion:pipeline` runs
Then the screener refresh and alerts still run and the exit code equals the run without macro; a warning is logged with stage and exit only.

## Repository Research

### Files Inspected

- `app/Console/Commands/RunIngestionPipeline.php`: thin wrapper; `runMarketContext()` sits between `patterns:detect` and `screener:refresh`, with an isolation pattern (try/catch, `warn`, `Log::warning`, exit ignored).
- `config/market_context.php`, `app/Console/Commands/ComputeMarketContext.php`, the `2026_10_22_*` migrations: Feature 1 conventions (date option parsing, `startOfDay()` dates, docblocks).
- `app/Services/Fundamentals/SecEdgarClient.php`, `Sleeper.php`, `config/fundamentals.php`, `RefreshFundamentals.php`, `tests/Concerns/FakesSecEdgar.php`: the only Laravel-side external HTTP source. It shows opt-in exits, the lock, per-item isolation, spacing and retries through `Sleeper`, and code-only errors.
- `engine/app/sources/stooq.py`: the engine scrapes market data (CSV/HTML, browser UA) and holds no secrets.
- `routes/console.php`: the `ingestion-pipeline` and `fundamentals-refresh` events (`->when(config)`, `withoutOverlapping`, `runInBackground`).
- `routes/api.php` and `Admin/IngestionRunController`: the Admin ingestion area covers Ingestion Runs only. Feature 1 added no Admin UI, and the Market Context Admin section is Feature 4.
- `CopySqliteToPgsql.php` (`SKIPPED_TABLES`), `CONSTRAINTS.md` (Market Context, SEC, Scheduling), `ARCHITECTURE.md`, `CONTEXT.md` (Macro Series term already present).
- `phpunit.xml` (no `MACRO_*` overrides yet), `.env.example`, `deploy/configure-*.py`, `.github/workflows/ci-deploy.yml` (secrets piped over SSH).
- Tests: `IngestionPipelineCommandTest` (`Http::assertSentCount(4|8)`), `IngestionScheduleTest` (filters events by name), `FundamentalsRefreshCommandTest` (schedule event assertion).

### Current Gaps

No macro config, tables, client, command, calendar or event. There is no FRED key in development, so no real-data smoke is possible locally.

## Resolved Ambiguities And Deviations From The Umbrella

1. **The HTTP client lives in Laravel, not the engine.** FRED is an official keyed JSON API with no scraping or parsing risk. The key stays in Laravel's server config instead of travelling to the stateless engine, and the SEC client is the precedent. Cboe (Feature 6) stays in the engine because it is page scraping.
2. **Curve spreads are computed locally** from the public-domain H.15 yields instead of fetching `T10Y2Y`/`T10Y3M` (St. Louis Fed, "Copyrighted: Citation Required"). This means two fewer requests. The values can differ from FRED's by about 0.01 pp, because FRED's spreads use Treasury's own par yields.
3. **Net liquidity uses only H.4.1 Wednesday levels**: `WALCL − WDTGAL − WLRRAOL`. All three are public domain, in the same units, on the same date. This replaces `WTREGEN` (a week average, inconsistent with a Wednesday level) and `RRPONTSYD` (NY Fed copyright). The weekly/daily alignment becomes an exact-date join, and a missing leg skips that Wednesday.
4. **NFCI ships off** (Chicago Fed non-commercial terms); owner decision needed. The OECD CLI is **included** as `USALOLITOAASTSAM` (current, CC BY 4.0).
5. Column `group` → **`category`** (`group` is an SQL reserved word). Values are stored as published; the conversion to billions happens in the reading, which also keeps the ECB requirement that data stay unaltered.
6. `--series` accepts several values (`--series=*`). Incremental fetches re-read a revision window, and the first run backfills by itself.
7. Disabled → exit **0** (umbrella "no-op that logs once"). Missing or malformed key while enabled → exit 1 with no request.
8. "Last move raise/cut/hold" → last **change** + last/next meeting (Rule 7); no inferred hold.
9. Monthly staleness 75 → 100 days (Rule 6). The calendar test warns 60 days ahead instead of only when no date is left.
10. `release_lag_days` per series is added for Feature 3's no-look-ahead backfill.
11. *(Implementation, 2026-10-22.)* **Run time budget** `run_budget_seconds` = 300 in `config/macro.php`: once spent, `macro:refresh` starts no new series (they are printed as skipped, logged with their codes only, and picked up by the next run; skipped series do not count as failures). Reason: the owner had already set the key and `MACRO_ENABLED=true` in production, so the first run backfills inside the EOD pipeline; with 20 s timeouts and 5 s + 20 s backoff one series can cost about 170 s during an outage, so without a budget 12 series could hold the pipeline for half an hour. The worst case is now about 300 + 170 s, below the 900 s lock TTL.
12. *(Implementation.)* The CI step and `configure-macro-env.py` are both no-ops on an empty key (the step exits 0 before SSH; the script also leaves `shared/.env` untouched on empty stdin), so a key set by hand in `shared/.env` is never removed, blanked or overwritten when the secret does not exist.
13. *(Implementation, reading shape.)* `curve.inverted` is a plain `?bool`, not a reading. "A disabled series gives null for its whole block" is applied per reading: a disabled series' readings are `null` (e.g. `leading.nfci`), and a block whose series are all disabled is `null` (e.g. `volatility` while VIX is off). A reading that cannot be computed (missing base, gap, too few observations) is `{null, null, false}`. The snapshot also returns `as_of` and `point_in_time` at the top level.

## Technical Approach

1. **Schema/models.** Migrations `2026_10_23_000001_create_macro_series_table.php` and `…_000002_create_macro_observations_table.php`. Models and factories. Both tables go in `CopySqliteToPgsql::SKIPPED_TABLES`: they postdate the cut-over and can be fetched again.
2. **Config.** `config/macro.php` (Rule 1, with a header comment citing this spec and the FRED terms) and `config/central_banks.php` (Rule 7). `.env.example` gets `MACRO_ENABLED=false`, `FRED_API_KEY=`, `MACRO_NFCI_ENABLED=false` and `MACRO_VIXCLS_ENABLED=false`. `phpunit.xml` forces `MACRO_ENABLED=false` and `FRED_API_KEY=""`.
3. **Services** in `app/Services/Macro/`: `FredClient`, `FredUnavailable`, `MacroSeriesRefresher` (Rule 4, returns `updated|failed` + count), `MacroSnapshotService` (Rule 5) and `CentralBankCalendar` (Rule 7).
4. **Command** `RefreshMacroSeries`, signature `macro:refresh {--series=* : Only these codes} {--backfill : Re-fetch the full backfill window}`.
   - Disabled → info + `Log::info('Macro refresh skipped: disabled.')` and exit 0.
   - Invalid key → error, exit 1.
   - An unknown or disabled `--series` → error listing the codes, exit 1, no request.
   - `Cache::lock('macro:refresh', lock_ttl_seconds)`; a held lock is a warning and exit 0.
   - Prints one line per series (`DGS10: 21 stored, latest 2026-10-20` / `DGS2: failed (unavailable)`) and a summary.
   - Exits 1 on `invalid_key` (which stops the run) or when every attempted series failed; otherwise 0.
5. **Pipeline.** A private `runMacro()` called right after `runMarketContext()` (before `screener:refresh`):
   - If `! config('macro.enabled')`, print the info line and return.
   - Otherwise `$this->call('macro:refresh')` in `try/catch (Throwable)`. On a non-zero exit or exception, `warn` + `Log::warning('Macro stage failed.', ['stage' => 'macro:refresh', 'exit' => …])`, then `report($e)` for an exception. `FredClient` never lets a key-bearing message out.
   - Update the docblock. Feature 3 will add `market-regime:compute` after it.
6. **Schedule.** In `routes/console.php`: `Schedule::command('macro:refresh')->name('macro-refresh')->dailyAt(config('macro.schedule_time'))->timezone($timezone)->when(fn (): bool => (bool) config('macro.enabled'))->withoutOverlapping(30)->runInBackground();`. It runs every day, weekends included, so weekly releases are picked up without a session.
7. **Deploy.**
   - `deploy/configure-macro-env.py` follows the `configure-ai-env.py` contract: the key arrives on stdin, is validated as `^[a-z0-9]{32}$` and is never printed. It writes `FRED_API_KEY="…"` and `MACRO_ENABLED=true`.
   - `ci-deploy.yml` gets a step with `env: FRED_API_KEY: ${{ secrets.FRED_API_KEY }}` that **skips with an echo when the secret is empty**, so deploys never break before the owner adds it.
   - `deploy/README.md`: how to create the key and add the secret; the first run backfills 5 years by itself.

## Expected File Changes

- Create:
  - `database/migrations/2026_10_23_00000{1,2}_*.php`;
  - `app/Models/MacroSeries.php`, `MacroObservation.php`, `database/factories/MacroSeriesFactory.php`, `MacroObservationFactory.php`;
  - `config/macro.php`, `config/central_banks.php`;
  - `app/Services/Macro/{FredClient,FredUnavailable,MacroSeriesRefresher,MacroSnapshotService,CentralBankCalendar}.php`;
  - `app/Console/Commands/RefreshMacroSeries.php`;
  - `deploy/configure-macro-env.py`.
- Modify: `app/Console/Commands/RunIngestionPipeline.php`, `routes/console.php`, `app/Console/Commands/CopySqliteToPgsql.php`, `.env.example`, `phpunit.xml`, `.github/workflows/ci-deploy.yml`, `deploy/README.md`.
- Tests, created:
  - `tests/Concerns/FakesFred.php`: `Http::preventStrayRequests()`, fixture routing by `series_id` and endpoint, and a fake `Sleeper` binding;
  - `tests/Feature/MacroRefreshCommandTest.php`, `MacroSnapshotServiceTest.php`, `CentralBankCalendarTest.php`, `MacroScheduleTest.php`;
  - fixtures `tests/fixtures/fred/series_<CODE>.json` and `observations_<CODE>.json`.
- Tests, modified: `IngestionPipelineCommandTest.php` (Scenarios 1 and 8), `MarketDataSchemaTest.php` (unique, cascade, `decimal:6`).

**Fixture strategy.**
- Fixtures use the documented API JSON shape (`observations[]{realtime_start, realtime_end, date, value}`, `seriess[]{…}`), each under 20 KB.
- Public-domain series (DGS*, DFEDTAR*, WALCL, WDTGAL, WLRRAOL, ICSA, PERMIT) use real values copied from the keyless website download `https://fred.stlouisfed.org/graph/fredgraph.csv?id=<CODE>` for a short window, with the source and date in a `README.md` next to them.
- ECBDFR, OECD CLI, NFCI and VIXCLS fixtures use **synthetic** values, so no third-party copyrighted data is committed.
- Edge cases are small synthetic files: `"."`, a missing Wednesday leg, changed units, a bad value, and error bodies (`{"error_code":400,"error_message":"Bad Request.  The value for variable api_key is not registered…"}`).

## Visual Design Impact

UI involved: no.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update.
  - New "Macro Series (FRED)" section: client in Laravel and why, tables, refresh window, readings, calendar, opt-in.
  - Scheduling: the `runMacro()` stage and the `macro-refresh` event.
  - Configuration: `macro`, `central_banks`.
- `CONSTRAINTS.md`: update, with a new "Macro Series (FRED)" section:
  - FRED is reached only via `FredClient`;
  - the key never appears in logs, exceptions, output, tables or the repo;
  - the feature ships disabled;
  - the series list is closed;
  - `restricted` series stay off until cleared;
  - values are stored as published;
  - the pipeline stage is isolated;
  - tests are offline (`Http::preventStrayRequests`);
  - no macro data goes to the AI provider;
  - the calendar is refreshed yearly;
  - the client-only retry exception, like SEC's.
- `CONTEXT.md`: update the Macro Series term (spreads computed by Chartiko; Cboe codes later) and add **Central Bank Calendar**.
- `docs/domain-model.md`: add the Macro Series and Macro Observation entities.
- `docs/risks-and-open-questions.md`: FRED terms (notice, ToU clause in Chartiko's terms of use before Feature 4), NFCI permission, VIX off, OECD CLI resolved (included, may be discontinued), and no real-data smoke before the key exists.
- `deploy/README.md`: key and secret setup.
- `AGENTS.md`: not needed.
- `docs/specs/market-context.md`: corrected on 2026-10-22 (done by the planner).

## Implementation Plan

1. Schema, models, config, `.env.example`, `phpunit.xml`, copy-command classification.
2. `FredClient` + fixtures + `FakesFred`; refresher and command with Scenarios 1–5.
3. `MacroSnapshotService` + `CentralBankCalendar` with Scenarios 6–7.
4. Pipeline `runMacro()`, schedule event, Scenario 8, schedule test.
5. Deploy script/step, durable docs, `.\init.ps1`.

## Implementation Tasks

- [ ] Migrations, models, factories, `SKIPPED_TABLES`.
- [ ] `config/macro.php` (Rule 1 exactly), `config/central_banks.php` (Rule 7 dates), env files.
- [ ] `FredClient` (spacing, retries, error codes, no key in messages) + `FredUnavailable`.
- [ ] `MacroSeriesRefresher` (window, units guard, upsert, `"."` deletion, transaction, error recording).
- [ ] `macro:refresh` command (exits, lock, output).
- [ ] `MacroSnapshotService` (every Rule 5 reading, `pointInTime`, staleness) and `CentralBankCalendar`.
- [ ] Pipeline `runMacro()` + schedule event.
- [ ] Tests and fixtures listed above; existing pipeline tests unchanged.
- [ ] `deploy/configure-macro-env.py`, guarded CI step, durable docs.

## Verification Plan

- `php artisan test --filter="Macro|CentralBankCalendar|IngestionPipelineCommand|IngestionSchedule|MarketDataSchema|CopySqliteToPgsql"`.
- `.\init.ps1` exit 0.
- `php artisan schedule:list` shows `macro-refresh`. `php artisan macro:refresh` with the default `.env` prints the disabled line and sends no request.
- Secret scan: `git grep -nE "[a-z0-9]{32}" -- config deploy tests .env.example` shows only the documented fake key.
- Real-data smoke (`macro:refresh` then snapshot spot-checks against the FRED pages) is **deferred** until the owner provides the production key. It must not be done with a key in development unless the owner adds one locally.
- E2E: none. There is no user-visible flow.

## Evidence To Capture

Test names and counts, the `.\init.ps1` summary, `schedule:list` output, the disabled-run output and the secret-scan result, recorded in `feature_list.json` evidence and `PROGRESS.md`.

## Key Implementation Risks

- Guzzle exception messages contain the URL and key: catch them, never `report()` them, and test it (Scenario 5).
- FRED units or JSON strings assumed from the docs and website: the fixtures follow the documented shape. The first production run may show `units_changed` in `last_error` if a real string differs; that is visible and harmless.
- OECD CLI has no announced next release (possible discontinuation): staleness hides it from the regime.
- Calendars are hand-kept; 2027 FOMC dates are tentative.

## Validator Checklist

- [ ] Scope stays inside Feature 2 (no API, page, Admin UI or regime).
- [ ] Scenarios 1–8 pass offline; hand-computed readings are visible in the tests.
- [ ] The series list and decisions match the verification table; NFCI/VIX off by default; no `T10Y*`, `WTREGEN` or `RRPONTSYD` requests.
- [ ] The key never appears anywhere (Scenario 5 + secret scan); a missing CI secret does not break deploy.
- [ ] Pipeline exit code and stages are unchanged by macro failures or the disabled state.
- [ ] New tables classified; SQLite/PostgreSQL-portable SQL; durable docs updated; `feature_list.json` and `PROGRESS.md` updated.
