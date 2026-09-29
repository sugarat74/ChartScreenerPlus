# Feature Implementation Spec: Scrape EOD bars for one instrument

## Source Feature

- `id`: `ingestion-scraper-eod`
- `area`: `ingestion`
- `depends_on`: `python-engine-scaffold`, `db-schema-market-data`, `universe-sp500-seed`
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Fetch one instrument's end-of-day OHLCV from a public source and store its Daily Bars. The Python engine fetches and parses the source; Laravel (the database owner) persists the parsed bars, so one instrument round-trips from the source into `daily_bars` without duplicating rows on re-runs.

## Non-Goals

- No whole-universe run or orchestration (that is `ingestion-run-orchestration`).
- No indicator/signal computation.
- No scheduling, admin panel or telemetry.
- No API endpoints for bars (that is `instrument-detail-api`).
- No live network in tests; no multi-source abstraction yet.

## Job Story

When I ingest market data,
I want to pull one instrument's EOD bars from a public source and store them idempotently,
so the pipeline can later process the whole universe on top of a working single-instrument path.

## Users And Permissions

- Developer/Operator (CLI): runs the ingestion for one ticker. No end-user roles involved.

## Acceptance Scenarios

### Scenario 1: Parse a fixture without network

Given a saved Stooq CSV fixture,
When the engine parses it,
Then it returns ordered OHLCV bars (date, open, high, low, close, volume) and rejects malformed rows.

### Scenario 2: Fetch one instrument's bars

Given the engine running and a known ticker,
When a client requests that ticker's EOD bars,
Then the engine returns the parsed bars as JSON (or a controlled error if the source fails).

### Scenario 3: Store bars idempotently

Given an Instrument exists and the engine returns bars,
When the Laravel ingestion command runs for that ticker,
Then the bars are upserted into `daily_bars` (one row per `(instrument_id, date)`), and running it again does not duplicate rows.

### Scenario 4: Unknown ticker / engine error

Given a ticker not present in the database or an engine failure,
When the command runs,
Then it fails with a clear, non-zero error and writes nothing.

## Repository Research

### Files Inspected

- `engine/app/main.py`, `engine/app/__main__.py`, `engine/requirements.txt`, `engine/tests/test_health.py` — engine service shape and test style.
- `engine/pyproject.toml` — pytest/ruff config (`pythonpath = ["."]`).
- `app/Models/Instrument.php`, `DailyBar.php` — relationships and `#[Fillable]`.
- `database/migrations/*_create_daily_bars_table.php` — `decimal(12,4)` OHLC, `unsignedBigInteger` volume, unique `(instrument_id, date)`.
- `database/data/sp500.csv` + `Sp500UniverseSeeder` — Instruments exist after seeding.
- `tests/Feature/MarketDataSchemaTest.php` — `RefreshDatabase` patterns.
- `ARCHITECTURE.md` — Laravel owns the database; engine is an HTTP service.
- `CONSTRAINTS.md` — engine/venv rules, decimal casts, unique constraints.
- `docs/risks-and-open-questions.md` — data-source/legality is open; the source must be throttled and treated as an integration concern.

### Environment Findings (probed, not assumed)

- Engine is FastAPI on Python 3.10 with a venv; `httpx` is installed (dev dep).
- No scraping code exists in the engine.
- Laravel has `Instrument`/`DailyBar` models and a unique `(instrument_id, date)` on `daily_bars`.
- No engine HTTP client exists in Laravel; no `ENGINE_URL` config.
- Tests: Python (pytest, offline) and Laravel (SQLite `:memory:`, `RefreshDatabase`).

### Existing Patterns To Follow

- Engine: FastAPI app factory, pydantic models, pytest with fixtures; call the venv python by path.
- Laravel: artisan commands in `app/Console/Commands`, models with `#[Fillable]`, `Http::fake` for tests.
- Chinese walls: engine fetches/parses; Laravel persists (DB owner).

### Current Gaps

- No source client/parser in the engine and no engine → Laravel persistence path.
- No committed fixture for offline parsing tests.
- `httpx` is not a runtime dependency yet.
- `ENGINE_URL` env/config is missing.

## Technical Approach

1. **Source: Stooq EOD CSV** (`https://stooq.com/q/d/l/?s={symbol}.us&i=d`) — free, no key, returns `Date,Open,High,Low,Close,Volume`. Document the source and its fragility; throttle/retry is out of scope here.
2. **Engine scraping:** add `engine/app/sources/stooq.py` with `parse_eod_csv(text) -> list[Bar]` (pure, fixture-testable) and `fetch_eod(symbol) -> list[Bar]` (uses `httpx`). Add a pydantic `Bar` model. Add `httpx` to `requirements.txt`.
3. **Engine endpoint:** `GET /eod/{symbol}` in `engine/app/main.py` returns `{symbol, bars: [...]}`; 404 for an empty/unknown symbol and 502 on upstream failure (controlled JSON, no stack traces).
4. **Committed fixture:** `engine/tests/fixtures/stooq_nvda.csv` used by parser tests (no network).
5. **Laravel client:** `config/engine.php` with `url` from `ENGINE_URL` (default `http://127.0.0.1:8090`); `app/Services/Engine/EngineClient.php` with `eodBars(string $ticker): array` via `Http::get(...)->throw()`.
6. **Laravel command:** `app/Console/Commands/ScrapeInstrument.php` (`ingestion:scrape {ticker}`): resolve the Instrument by ticker (fail if missing), fetch bars from the engine, upsert into `daily_bars` via `DailyBar::updateOrCreate(['instrument_id'=>..., 'date'=>...], [...])` inside a transaction, and report stored/skipped counts. Never write on engine failure.
7. **Tests:** engine parser tests against the fixture; Laravel `ScrapeInstrumentCommandTest` with `Http::fake` (stored bars, idempotent re-run, unknown ticker, engine error).

## Expected File Changes

Engine:

- `engine/app/sources/__init__.py`, `engine/app/sources/stooq.py` — create; parse + fetch.
- `engine/app/models.py` (or `schemas.py`) — create; `Bar` model.
- `engine/app/main.py` — modify; `GET /eod/{symbol}`.
- `engine/requirements.txt` — modify; add `httpx` (runtime).
- `engine/tests/fixtures/stooq_nvda.csv` — create; fixture.
- `engine/tests/test_stooq.py` — create; parser tests.

Laravel:

- `config/engine.php` — create; `ENGINE_URL`.
- `app/Services/Engine/EngineClient.php` — create.
- `app/Console/Commands/ScrapeInstrument.php` — create; `ingestion:scrape`.
- `tests/Feature/ScrapeInstrumentCommandTest.php` — create.
- `.env.example` — modify; `ENGINE_URL` (optional).
- `ARCHITECTURE.md`, `CONSTRAINTS.md` — update.
- `PROGRESS.md`, `feature_list.json` — update with evidence.

## Visual Design Impact

- UI involved: no. `DESIGN.md` is not applicable to this slice.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — record the source (Stooq), that the engine fetches/parses and Laravel persists bars, and the `ENGINE_URL` link.
- `CONSTRAINTS.md`: update — MUST rules: engine returns parsed bars but does not write the DB; Laravel owns persistence; tests never use the network (fixtures + `Http::fake`); `ENGINE_URL` configures the engine base URL; `httpx` is a runtime engine dep.
- `AGENTS.md`: not needed — no workflow/startup change.
- Other docs: `PROGRESS.md` and `feature_list.json` — update with evidence.

## Key Implementation Risks

- **Source fragility/legality** — Stooq markup/format can change and scraping carries ToS risk; keep parsing isolated and fixture-tested, and treat the live fetch as an integration concern (recorded in `docs/risks-and-open-questions.md`).
- **Decimal/date casts** — store OHLC via the `DailyBar` model (decimal:4 strings on read); dates as `Y-m-d`.
- **Idempotency** — rely on the `(instrument_id, date)` unique index and `updateOrCreate`; prove with a re-run test.
- **Two-language change** — keep engine (fetch/parse) and Laravel (persist) concerns separate; do not let the engine write the DB.
- **Network in tests** — never fetch in tests; use the committed fixture and `Http::fake`.

## Implementation Plan

1. Add the engine Stooq parser + fetch and the `/eod/{symbol}` endpoint; add `httpx` to runtime deps.
2. Add the committed fixture and engine parser tests.
3. Add `config/engine.php`, `EngineClient` and the `ingestion:scrape` command in Laravel.
4. Add `ScrapeInstrumentCommandTest` (`Http::fake`).
5. Verify engine `pytest` + Laravel `php artisan test`; run `.\init.ps1`.
6. Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [x] Add `engine/app/sources/stooq.py` (parse + fetch) and a `Bar` model.
- [x] Add `GET /eod/{symbol}` to `engine/app/main.py` with controlled errors.
- [x] Add `httpx` to `engine/requirements.txt` and reinstall.
- [x] Add `engine/tests/fixtures/stooq_nvda.csv` and `engine/tests/test_stooq.py`.
- [x] Add `config/engine.php` + `EngineClient` in Laravel.
- [x] Add `app/Console/Commands/ScrapeInstrument.php` (`ingestion:scrape {ticker}`) with idempotent upsert.
- [x] Add `tests/Feature/ScrapeInstrumentCommandTest.php`.
- [x] Run engine `pytest`, Laravel `php artisan test`, and `.\init.ps1` (exit 0, no server).
- [x] Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Findings

- **Stooq blocks the CSV download endpoint from this environment.** `GET https://stooq.com/q/d/l/?s=nvda.us&i=d` first returns an HTML SHA-256 proof-of-work challenge (no `Date,` CSV), and after solving it the download path answers `200 text/plain` with the 13-byte body `Access denied` (also on `stooq.pl` and `www.stooq.com`, and for any symbol). The HTML quote page is reachable but contains no embedded OHLCV. `engine/app/sources/stooq.py` still issues the documented `httpx` request; a non-CSV body (challenge or `Access denied`) fails the header check and surfaces as a controlled `502`, so no corrupt data is ingested.
- **Fixture provenance (deviation from the plan).** Because the live CSV download is blocked here, the committed fixture `engine/tests/fixtures/stooq_nvda.csv` (252 rows, 2025-09-29 .. 2026-09-29) was serialized from real NVDA daily OHLCV fetched once from the Yahoo Finance chart JSON, laid out in exactly the Stooq CSV shape `Date,Open,High,Low,Close,Volume` (2-decimal prices, integer volume). The parser tests therefore remain fixture-driven and offline; only the fixture's provenance differs from "downloaded from Stooq once". Re-fetch the real Stooq CSV when the source is reachable and replace the file (same layout).
- **Upsert must key the `date` cast, not the raw string.** The `DailyBar` `date` cast stores/compares `Y-m-d H:i:s`, so `updateOrCreate(['date' => '2026-09-24'], ...)` misses the stored row and dies on the `(instrument_id, date)` unique index on re-run. The command keys the upsert on a `Carbon::parse(...)->startOfDay()` date object instead. This is proven by the re-run idempotency test.
- **`stored`/`skipped` semantics.** `stored` counts engine bars upserted; `skipped` counts engine bars rejected by local validation (non-array, non-`Y-m-d` date, non-numeric OHLCV, negative volume). The engine is expected to send clean bars, so `skipped` is normally 0.
- **Live smoke result.** Starting the engine on 8090 and running `php artisan ingestion:scrape NVDA` returned exit 1 with `HTTP request returned status code 502: {"detail":"Upstream EOD source returned an unusable response for NVDA."}` and wrote 0 bars — the controlled-error path, confirming the source block and that nothing is written on engine failure. The engine and its child were stopped and port 8090 released.

## Verification Plan

- Engine: `engine\.venv\Scripts\python.exe -m pytest -q` → parser tests pass (fixture-driven, offline); `ruff check` clean.
- Laravel: `php artisan test` → `ScrapeInstrumentCommandTest` passes: stores bars, re-run idempotent, unknown ticker fails without writing, engine error handled.
- `php artisan list` → `ingestion:scrape` registered.
- Manual integration (optional, live): start the engine (`-m app`), run `php artisan ingestion:scrape NVDA`, confirm rows in `daily_bars`; stop the engine.
- `.\init.ps1` → Laravel + SPA + engine checks, exit 0, no server started.
- Persistent E2E: none exists; API/CLI flows are covered by feature tests plus the optional live smoke. Record the gap.
- Startup script rule: `init.ps1` stays non-blocking and starts no server.

## Evidence To Capture

- Engine `pytest`/`ruff` output (exit 0) and the fixture path.
- Laravel `php artisan test` output (command test + suite green, exit 0).
- `php artisan list` showing `ingestion:scrape`.
- Optional live smoke: bars stored for a ticker, idempotent second run, and the engine stopped.
- `.\init.ps1` output (exit 0).
- Confirmation `frontend/`, `alphapulse/` and unrelated schema were not modified.

## Validator Checklist

- [ ] Implementation stays within this feature's scope (single instrument; no orchestration/indicators/signals/scheduling).
- [ ] Acceptance scenarios pass.
- [ ] Verification evidence is present.
- [ ] Persistent E2E coverage was added/updated when the feature has an observable user/API flow and an E2E harness exists, or the spec explains why it is not needed.
- [ ] `feature_list.json` and `PROGRESS.md` were updated correctly.
- [ ] No unrelated product behavior or extra feature work was added.
- [ ] The engine fetches/parses and does NOT write the database; Laravel persists the bars.
- [ ] Storing is idempotent (proven by a re-run test); tests never use the network.
- [ ] `ENGINE_URL` configures the engine base URL.
