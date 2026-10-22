# Feature Implementation Spec: Sector ETF universe with daily sector and market breadth snapshots

## Source Feature

- `id`: `market-context-sectors`
- `area`: `backend` (plus a small chart-page guard in the SPA)
- `depends_on`: `ingestion-scheduler`, `indicators-compute`, `screener-precomputed` (all `accepted`)
- `status`: `not_started` at planning time (2026-10-22)
- `source`: `feature_list.json`. Product decisions: `docs/specs/market-context.md` → "Domain Decisions (shared)" and "Feature 1". This spec is the implementation contract; where it is more precise than the umbrella, this spec wins (see "Resolved Ambiguities And Deviations").

## Goal

Every daily run also ingests SPY and the 11 SPDR sector ETFs into a separate `market-context` Universe, computes their Indicator Snapshots, and stores two derived daily tables computed in Laravel: per-sector returns, relative strength vs SPY, trend and sector breadth (`market_sector_snapshots`), and S&P 500 Breadth (`market_breadth_snapshots`). A backfill fills at least one year of history. ETFs never leak into stock features. Later features (`market-regime`, `market-context-ui`, `market-context-patterns`) read these tables.

## Non-Goals

- No FRED / macro series, no Market Regime, no `/mercado` page or API, no Admin Market Context section, no Screener sector filters, no `screener_rows` sector columns (Features 2–5).
- No signals or chartist patterns for ETFs; no Company Card, fundamentals, forecast or Watchlist for ETFs.
- No engine change, no new data vendor, no new public endpoint.
- No change to the pipeline's main-universe behaviour, exit codes or alert gating.

## Job Story

When the daily data update finishes,
I want sector and breadth readings stored with their own dates,
so the Market Context features can show and score them without recomputing anything per request.

## Users And Permissions

- Visitor / Registered User: no new surface. `/instruments/XLK` stays public and shows the chart, without Watchlist, forecast or Company Card.
- Admin: sees the `market-context` Ingestion Runs in the existing Admin ingestion list (unchanged UI) and can trigger one with the existing `POST /api/admin/ingestion/runs {universe: "market-context"}`.
- Operator: `php artisan market-context:compute {--date=} {--from=}`.

## Domain Rules (contract for the implementer and validator)

1. **Instrument kind.** `instruments.kind` `string(16)`, default `stock`, indexed; values `stock` | `etf`. Constants `Instrument::KIND_STOCK` / `KIND_ETF`, a `scopeStocks()` local scope and `isEtf()`. `kind` added to `#[Fillable]`.
2. **Universe.** Slug `market-context`, name `Market Context`, from `config('market_context.universe')`; benchmark ticker `config('market_context.benchmark')` = `SPY`. The 12 rows of the umbrella table (`kind = etf`, exchange `NYSE Arca`, `sector` = exact GICS string from `database/data/sp500.csv`, SPY `null`).
3. **Session dates.** A candidate date is a distinct `daily_bars.date` of the main Universe's (`config('ingestion.universe')`) `stock` members. It is a **session** only when at least 50 % of those members have a bar on it (`MIN_SESSION_COVERAGE = 0.5`), so a stray bar of one ticker never creates a breadth row.
4. **Per-instrument series.** All "n sessions back" values use the instrument's own bars ordered by date (`close[k-n]`). A row for date `d` needs a bar exactly on `d`.
5. **Sector snapshot** (one row per **sector ETF** and session; SPY gets no row):
   - `ret_n` (n = 5, 20, 60) = `(close[d] / close[d-n] − 1) × 100`; `null` with fewer than n + 1 bars.
   - `rs_n` (n = 20, 60 only, as in the umbrella) = `ret_n(ETF) − ret_n(SPY)`; `null` when either is `null`. Rows for `d` are written only when SPY has a bar on `d` (otherwise skipped with a warning).
   - `rs_rank` = 1..k over that date's rows with non-null `rs_60`, by `rs_60` (rounded to 4 dp) desc, ties by ticker asc; `null` when `rs_60` is `null`.
   - `trend` from the ETF's bar and Indicator Snapshot on `d`: `uptrend` if `close > sma50 > sma200`; `downtrend` if `close < sma50 < sma200`; otherwise `mixed`; `null` when there is no snapshot on `d` or `sma50`/`sma200` is `null`.
   - `breadth_200` = % of main-Universe `stock` members with `sector` = ETF `sector` that have a bar and a non-null `sma200` on `d`, whose close is `> sma200`; `null` when that denominator is 0.
6. **Market breadth** (one row per main Universe and session):
   - `members` = `stock` members with a bar on `d`.
   - `pct_above_sma50` / `pct_above_sma200` = % of members with a non-null SMA on `d` whose close is above it; `null` when the denominator is 0.
   - `new_highs` = eligible members (≥ 251 bars before `d`) with `high[d] >= max(high)` of the previous 251 bars; `new_lows` mirrors with `low[d] <= min(low)`. Both `null` when no member is eligible.
   - `advancers` / `decliners` compare `close[d]` with the member's previous bar; equal or no previous bar counts in neither.
   - `spy_trend` = rule 5 `trend` for SPY on `d` (`null` if SPY has no bar/snapshot on `d`).
7. **Storage precision.** Percentages and returns are `decimal(10,4)` (`pct_*`, `breadth_200`: `decimal(7,4)`), rounded to 4 dp in PHP, cast `decimal:4` (CONSTRAINTS). Counts unsigned integers. Dates written as `Carbon::parse($d)->startOfDay()` like `daily_bars`; read dates normalized with `substr(…, 0, 10)` (SQLite returns `Y-m-d H:i:s`, PostgreSQL `Y-m-d`).
8. **Isolation.** Market Context stages never change the pipeline's exit code, never skip the Screener refresh or alerts, and are logged (`Log::warning`, stage and exit code only) and printed. An ETF ingestion failure is also recorded as its own `failed`/`partial` Ingestion Run row.

## Acceptance Scenarios

### Scenario 1: Hand-computed figures
Given a `sp500` universe with 4 stocks (2 Information Technology, 2 Energy, ≥ 252 bars, snapshots inserted directly) and a seeded `market-context` universe with bars/snapshots for SPY, XLK and XLE
When `market-context:compute --date=<d>` runs
Then the stored `ret_5/20/60`, `rs_20`, `rs_60`, `rs_rank`, `trend`, `breadth_200` and every breadth column equal the hand-computed values (4-dp strings).

### Scenario 2: Nulls are never guesses
Given an ETF with `sma200 = null` on `d`, a stock with fewer than 252 bars and an ETF with 30 bars
When the command runs
Then that ETF's `trend` is `null`, the short stock is excluded from new highs/lows, and `ret_60`/`rs_60`/`rs_rank` of the 30-bar ETF are `null`.

### Scenario 3: Idempotent and backfill
Given computed rows
When the command reruns for the same date, and `--from=<d-10 sessions>` runs
Then values and row counts are unchanged by the rerun, and the backfill writes exactly one breadth row per session in range and one sector row per ETF and session.

### Scenario 4: ETFs stay out of stock features
Given XLK with bars, a snapshot and (forced) a signal
Then `screener:refresh` builds no row for it and deletes a pre-existing one; `GET /api/screener?universe=market-context` is `200` with `candidates: []`; `POST /api/watchlist {ticker: XLK}` is `422 errors.ticker`; `GET`/`POST /api/instruments/XLK/company-card` and `POST /api/instruments/XLK/forecast` are `404`; `fundamentals:refresh --ticker=XLK` touches nothing; `AlertEvaluator::asOf()` ignores ETF bars; `GET /api/instruments/XLK` is `200` with `instrument.kind = "etf"`.

### Scenario 5: Pipeline isolation
Given the engine fails every ETF request (or `market-context:compute` throws)
When `ingestion:pipeline` runs on a trading day
Then the main stages, `screener:refresh` and `alerts:evaluate` run, the exit code is the same as without Market Context, a `failed` run for the `market-context` universe exists and a warning is logged. Given the `market-context` universe is not seeded, the block is skipped with one info line and no engine request.

### Scenario 6: Chart page for an ETF
Given `GET /api/instruments/XLK` returns `kind: "etf"`
When `/instruments/XLK` renders
Then the chart, signal/pattern panels and header render; the Watchlist button, Forecast panel and Company Card panel do not; a `null` sector does not print an empty "· ·".

## Repository Research

### Files Inspected

- `database/migrations/2026_09_29_10000{1..5}_*` — `universes`, `instruments` (no kind), pivot, `daily_bars`/`indicator_snapshots` (`decimal(12,4)`, unique `(instrument_id, date)`).
- `database/seeders/Sp500UniverseSeeder.php`, `DatabaseSeeder.php`, `database/data/sp500.csv` — idempotent offline seeding, refreshes screener rows; the CSV has exactly the 11 GICS sectors of the umbrella table.
- `app/Console/Commands/RunIngestionPipeline.php`, `RunIngestion.php`, `ComputeIndicators.php`, `app/Services/Ingestion/IngestionRunner.php` — `--universe` already supported; runner refreshes screener rows after every run; unknown universe = exit 1, no run row.
- `engine/app/sources/stooq.py` — Yahoo chart (`range=2y`) then Stooq `{sym}.us`; both serve SPY/XL* with no change. 2 years ≈ 500 bars: enough for SMA200 and a one-year backfill (new highs need 251 prior bars, so the oldest backfill dates may have `null` highs/lows).
- `app/Services/Screener/ScreenerRowBuilder.php` — iterates **all** Instruments (not universe-scoped); `CandidateQuery.php` and `ScreenerController.php` are universe-scoped.
- `app/Http/Controllers/WatchlistController.php`, `CompanyCardController.php`, `ForecastController.php`, `InstrumentController.php`; `app/Services/Alerts/AlertEvaluator.php` (`asOf()` = global `max(daily_bars.date)`); `app/Services/Fundamentals/FundamentalsRefresher.php` (`--ticker` path not scoped).
- `app/Console/Commands/CopySqliteToPgsql.php` — refuses unclassified tables (CONSTRAINTS line 18).
- `routes/console.php`, `routes/api.php`, `config/ingestion.php`, `deploy/deploy.sh`, `deploy/README.md`.
- `frontend/src/pages/InstrumentChartPage.tsx`, `frontend/src/lib/api.ts` (`InstrumentDetailResponse`).
- Tests: `IngestionPipelineCommandTest` (asserts `IngestionRun::sole()` and `Http::assertSentCount(4)`), `ScreenerRowBuilderTest`, `tests/TestCase.php::refreshScreenerRows()`.

### Existing Patterns To Follow

- Commands are thin; logic in `app/Services/...`; date upserts keyed on `startOfDay()` Carbon; explicit `orderBy`; SQLite/PostgreSQL portable SQL.
- Pipeline composes stages with `$this->call()`; offline `Http::fake` for the engine in tests.
- Models: `#[Fillable]`, `casts()`, `HasFactory`, FKs `cascadeOnDelete`, `timestamps()`.

### Current Gaps

- No `kind`, no `market-context` universe, no market tables, no config, no command.
- The umbrella's "Watchlist search" does not exist: following happens via `POST /api/watchlist` from the chart header and Candidate rows.

## Resolved Ambiguities And Deviations From The Umbrella

1. `ScreenerRowBuilder` is not universe-scoped, so the `kind` filter there (and deletion of non-stock rows) is the real gate.
2. "Watchlist search" → `POST /api/watchlist` rejects ETFs (`422`, new key `messages.instrument_not_followable`) and the chart page hides the button.
3. **Forecast added** to the exclusions (`404` + panel hidden): its state reads Signals, which are never computed for ETFs.
4. `AlertEvaluator::asOf()` is narrowed to `stock` bars so ETF bars cannot move the alert as-of date.
5. `market_sector_snapshots` holds only the 11 sector ETFs (`sector` not null); SPY's trend lives in `market_breadth_snapshots.spy_trend`.
6. Breadth is unique on `(universe_id, date)`, not `date` alone (the table already carries `universe_id`).
7. Pipeline position: after `patterns:detect`, before `screener:refresh` (inside the umbrella's "after indicators, before alerts"; lets Feature 5 read sectors in the refresh). Failures do not change the exit code; a missing universe skips the block.
8. Session coverage rule (Domain Rule 3), `null` (not 0) for empty denominators, and the default run recomputes the **last 5 sessions** so a one-day ETF failure heals itself.
9. `deploy/deploy.sh` runs the idempotent, offline seeder on every deploy; the one-year backfill is a documented one-time operator step.

## Technical Approach

1. **Schema.** Migrations: add `kind`; create `market_sector_snapshots` (`date`, `instrument_id` FK cascade, `sector`, `ret_5/20/60`, `rs_20`, `rs_60`, `rs_rank` tinyint null, `trend` string(10) null, `breadth_200`, timestamps; unique `(date, instrument_id)`, index `date`); create `market_breadth_snapshots` (`universe_id` FK cascade, `date`, `members`, `pct_above_sma50`, `pct_above_sma200`, `new_highs`, `new_lows` (nullable), `advancers`, `decliners`, `spy_trend`, timestamps; unique `(universe_id, date)`). Models `MarketSectorSnapshot`, `MarketBreadthSnapshot` (+ factories).
2. **Config + seeder.** `config/market_context.php` (`universe`, `benchmark`). `MarketContextUniverseSeeder`: in-code 12-row constant (source: SSGA Select Sector SPDR / SPDR S&P 500 fund pages, date in the docblock), `updateOrCreate` by slug/ticker with `kind = etf`, `syncWithoutDetaching`, then `ScreenerRowBuilder::refresh()`. Called from `DatabaseSeeder` and `deploy/deploy.sh` (`php artisan db:seed --class=MarketContextUniverseSeeder --force` after `migrate`).
3. **Calculator.** `App\Services\MarketContext\MarketContextComputer::compute(list<string> $dates): array{sectors:int, breadth:int, skipped:list<string>}` plus `sessionDates(?from, ?date, int $latest = 5)`. Stream the main universe's stock members one instrument at a time (bars `date, high, low, close` up to max date; snapshots `sma50, sma200` in range), accumulating per-date counters (breadth and per-sector breadth) — memory per instrument only. A naive 251-bar window is acceptable (one year ≈ 30M comparisons); a monotonic deque is optional. Then the 12 ETFs, then upsert both tables in one transaction per command run.
4. **Command.** `market-context:compute {--date=} {--from=}`: options are `Y-m-d`, mutually exclusive (both or a bad format → exit 1). `--date` must be a session (else exit 1). Missing main or market-context universe → error exit 1. Prints counts and each skipped date. Exit 0 otherwise.
5. **Pipeline.** In `RunIngestionPipeline`, a private `runMarketContext()` between `patterns:detect` and `screener:refresh`: if the universe slug does not exist → info "Market context universe not seeded; skipped." Else call `ingestion:run --universe=market-context`, `indicators:compute --universe=market-context`, `market-context:compute`, each in `try/catch (Throwable)`; a non-zero exit or exception → `warn` + `Log::warning('Market context stage failed.', ['stage'=>…, 'exit'=>…])` (or `report($e)`). Return values are ignored for the final exit code. Update the docblock.
6. **ETF exclusions.** `ScreenerRowBuilder` → `Instrument::query()->stocks()` and delete rows whose instrument is not `stock`; `WatchlistController::store` → `422`; `CompanyCardController::instrument()` and `ForecastController` → `stocks()` (404); `FundamentalsRefresher::instruments()` → `stocks()` in both paths; `AlertEvaluator::asOf()` joins `instruments` on `kind = stock`; watchlist/pattern states use `watchlist()->where('instruments.kind', 'stock')`. `InstrumentController::instrumentPayload()` adds `kind` (additive to the frozen payload).
7. **SPA.** `InstrumentDetailResponse.instrument.kind?: 'stock' | 'etf'` (missing = stock); `InstrumentChartPage` renders `WatchlistButton`, `ForecastPanel`, `CompanyCardPanel` only when not ETF and joins company/sector/exchange skipping nulls. No new text.
8. **Copy command.** Add both tables to `CopySqliteToPgsql::SKIPPED_TABLES` (derived, postdate the cut-over).

## Expected File Changes

- `database/migrations/2026_10_22_000001_add_kind_to_instruments_table.php`, `…_000002_create_market_sector_snapshots_table.php`, `…_000003_create_market_breadth_snapshots_table.php` — create.
- `app/Models/MarketSectorSnapshot.php`, `app/Models/MarketBreadthSnapshot.php`, `database/factories/MarketSectorSnapshotFactory.php`, `MarketBreadthSnapshotFactory.php` — create.
- `app/Models/Instrument.php`, `database/factories/InstrumentFactory.php` (`etf()` state) — modify.
- `config/market_context.php`, `database/seeders/MarketContextUniverseSeeder.php` — create; `database/seeders/DatabaseSeeder.php` — modify.
- `app/Services/MarketContext/MarketContextComputer.php`, `app/Console/Commands/ComputeMarketContext.php` — create.
- `app/Console/Commands/RunIngestionPipeline.php`, `app/Services/Screener/ScreenerRowBuilder.php`, `app/Http/Controllers/{Watchlist,CompanyCard,Forecast,Instrument}Controller.php`, `app/Services/Alerts/AlertEvaluator.php`, `app/Services/Fundamentals/FundamentalsRefresher.php`, `app/Console/Commands/CopySqliteToPgsql.php` — modify.
- `lang/es/messages.php`, `lang/en/messages.php` — add `instrument_not_followable`.
- `frontend/src/lib/api.ts`, `frontend/src/pages/InstrumentChartPage.tsx` — modify; `frontend/src/pages/InstrumentChartPage.test.tsx` — create.
- `deploy/deploy.sh`, `deploy/README.md` — modify.
- Tests: create `tests/Feature/MarketContextUniverseSeederTest.php`, `tests/Feature/MarketContextComputeCommandTest.php`, `tests/Feature/EtfExclusionTest.php`; modify `IngestionPipelineCommandTest.php`, `ScreenerRowBuilderTest.php`, `MarketDataSchemaTest.php`, `InstrumentDetailApiTest.php`.

## Visual Design Impact

- UI involved: minimal (elements hidden on ETF chart pages; no new component, text or style). Design source `DESIGN.md` unchanged. No design artifact needed.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — Scheduling composition (Market Context block, isolation), Screener precomputed rows (stocks only), Instrument Detail API (`kind`), new "Market Context (sectors and breadth)" section (tables, rules, command, backfill).
- `CONSTRAINTS.md`: update — ETFs (`kind = etf`) MUST NOT reach screener rows, Watchlist, alerts, Company Card, fundamentals or forecast; Market Context stages MUST NOT change the pipeline exit code or gate later stages; market tables are derived and computed only in Laravel.
- `CONTEXT.md`: update — the four terms of umbrella decision 1; Instrument gains "stock or ETF"; Universe mentions `market-context` (not a Screener universe).
- `docs/domain-model.md`: update — Instrument `kind`; Sector Snapshot and Breadth Snapshot entities.
- `docs/risks-and-open-questions.md`: update — ETFs share the unofficial-source licence risk.
- `deploy/README.md`: update — seeder in deploy; one-time backfill (`ingestion:run --universe=market-context`, `indicators:compute --universe=market-context`, `market-context:compute --from=<today − 1 year>`).
- `AGENTS.md`: not needed (scope paragraph already present).

## Implementation Plan

1. Schema, models, `kind`, config, seeder (+ seeder test).
2. ETF exclusions across Screener rows, Watchlist, Company Card, forecast, fundamentals, alerts, instrument payload (+ `EtfExclusionTest`).
3. `MarketContextComputer` and command with the hand-computed fixture tests.
4. Pipeline block with isolation tests; copy-command classification.
5. SPA guard + Vitest; deploy script and docs; `.\init.ps1`.

## Implementation Tasks

- [ ] Migrations, models, factories, `Instrument` kind constants/scope/fillable.
- [ ] `config/market_context.php`, `MarketContextUniverseSeeder`, `DatabaseSeeder`, seeder test (12 rows, `etf`, sectors ⊂ CSV sectors and each CSV sector maps to exactly one ETF, idempotent rerun).
- [ ] Exclusions per Technical Approach 6 with one test per surface.
- [ ] `MarketContextComputer` + `market-context:compute` (`--date`, `--from`, default last 5 sessions, validation exits).
- [ ] Fixture tests: Scenarios 1–3, SPY missing on `d`, coverage rule skips a stray date, PostgreSQL-safe date handling.
- [ ] Pipeline `runMarketContext()` + tests (Scenario 5, existing tests unchanged).
- [ ] `SKIPPED_TABLES` entries.
- [ ] SPA `kind` type, conditional panels, `InstrumentChartPage.test.tsx` (mock `InteractiveChart` and `instrumentApi`; ETF vs stock).
- [ ] `deploy/deploy.sh` seeder line; durable docs listed above.

## Verification Plan

- `php artisan test --filter="MarketContext|EtfExclusion|IngestionPipelineCommand|ScreenerRowBuilder|InstrumentDetailApi|MarketDataSchema|CopySqliteToPgsql"`.
- `npm --prefix frontend run test -- InstrumentChartPage`.
- `.\init.ps1` exit 0 (Laravel, Vitest, SPA lint + build, engine).
- Local real-data smoke (dev SQLite, network): seed; `php artisan ingestion:run --universe=market-context`; `php artisan indicators:compute --universe=market-context`; `php artisan market-context:compute --from=<today − 1 year>`; record row counts, the latest 11 sector rows and breadth row, and spot-check one ETF's `ret_20` and `trend` by hand; then `php artisan screener:refresh` and confirm no ETF in `screener_rows`.
- E2E: none exists in the repo; the only UI change is hiding panels, covered by Vitest.

## Evidence To Capture

- Test names and counts; `.\init.ps1` summary; smoke output (counts, latest date, sample rows); timing of the one-year backfill. Recorded in `feature_list.json` evidence and `PROGRESS.md`.

## Key Implementation Risks

- Date storage differs between SQLite and PostgreSQL; normalize to `Y-m-d` when reading, write Carbon `startOfDay()`.
- Floating-point equality in `trend`/rank: compare the stored-precision values; rank on 4-dp rounding.
- Pipeline duration grows by 12 ingestions + compute (seconds); the backfill is a one-off.
- Same unofficial data source (licence risk unchanged).

## Validator Checklist

- [ ] Scope stays inside Feature 1 (no FRED, regime, page, API or Screener filters).
- [ ] Scenarios 1–6 pass with offline tests; hand-computed values are visible in the test.
- [ ] Every ETF exclusion surface has a test; `kind` is in the instrument payload.
- [ ] Pipeline exit code and alert gating are unchanged by Market Context failures; skip when unseeded.
- [ ] New tables classified in `CopySqliteToPgsql`; CI-portable SQL.
- [ ] Durable docs updated as listed; `feature_list.json` and `PROGRESS.md` updated with evidence.
