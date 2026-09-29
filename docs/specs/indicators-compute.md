# Feature Implementation Spec: Compute indicator snapshots

## Source Feature

- `id`: `indicators-compute`
- `area`: `engine`
- `depends_on`: `ingestion-run-orchestration`
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Compute and store Indicator Snapshots (SMA/EMA, RSI, MACD, ADX, Bollinger, RVOL) for each stored Daily Bar. The Python engine computes from a bar series; Laravel (the database owner) persists one snapshot per `(instrument, date)`. Indicators with insufficient history are left null rather than wrong, and snapshots are reproducible from stored bars with no network.

## Non-Goals

- No signal detection (that is `signals-detect`).
- No scheduling, admin panel, API endpoints or charting.
- No parameter tuning UI.
- No live network in tests.

## Job Story

When I screen or chart an instrument,
I want its technical indicators computed from the stored daily bars,
so signals and screens can rely on correct, reproducible indicator values.

## Users And Permissions

- Developer/Operator (CLI): triggers the computation. No end-user roles involved.

## Acceptance Scenarios

### Scenario 1: Indicators match hand-derived values

Given a small bar series with a closed-form expected result,
When the engine computes indicators,
Then SMA/EMA/RSI/MACD/Bollinger/RVOL match the expected values (monotonic series → RSI 100; constant series → zero Bollinger width; known SMA/EMA averages).

### Scenario 2: Insufficient history yields nulls

Given a series shorter than an indicator's window,
When indicators are computed,
Then the affected indicator values are `null` (not 0 or a wrong value) and the available indicators are still computed.

### Scenario 3: Snapshots are stored per (instrument, date)

Given an instrument with stored daily bars and the engine returning snapshots,
When the Laravel command runs,
Then one `indicator_snapshots` row per bar is upserted (unique `(instrument_id, date)`), and re-running does not duplicate rows.

### Scenario 4: Reproducible without network (tests)

Given the committed fixtures,
When the engine tests run,
Then they compute from the fixture series only (no network), and Laravel tests use `Http::fake`.

## Repository Research

### Files Inspected

- `engine/app/models.py` (`Bar`), `engine/app/sources/stooq.py`, `engine/app/main.py` — engine shape.
- `engine/tests/test_stooq.py`, `engine/tests/fixtures/stooq_nvda.csv` — fixture/test patterns (252 real NVDA bars).
- `engine/pyproject.toml` (pytest/ruff), `engine/requirements.txt` (fastapi, uvicorn, httpx).
- `database/migrations/*_create_indicator_snapshots_table.php` — nullable `decimal(12,4)` columns `sma20,sma50,sma200,ema21,ema55,rsi14,adx,macd,macd_signal,macd_hist,bb_upper,bb_middle,bb_lower` + `decimal(8,4)` `rvol`, unique `(instrument_id, date)`.
- `app/Models/DailyBar.php`, `IndicatorSnapshot.php`, `Instrument.php`.
- `app/Services/Ingestion/InstrumentIngestor.php`, `app/Services/Engine/EngineClient.php` — Laravel service patterns.
- `tests/Feature/IngestionRunTest.php` — `Http::fake` patterns.
- `docs/domain-model.md` — Indicator Snapshot definition.
- `ARCHITECTURE.md`, `CONSTRAINTS.md` — engine computes (no DB write); Laravel persists; offline tests.

### Environment Findings (probed, not assumed)

- Engine has no indicator code and does not depend on pandas/numpy.
- `indicator_snapshots` already exists (accepted schema) with the exact columns above and a `(instrument_id, date)` unique index.
- Daily bars are stored as `decimal(12,4)`; dates as `date`.
- The live source is blocked, but indicators compute from stored bars (fixtures), so they are unaffected.

### Existing Patterns To Follow

- Engine: pure functions + pydantic models; fixture-driven pytest; run with the venv python.
- Laravel: service + artisan command; idempotent `updateOrCreate`; `Http::fake` tests.
- Engine never writes the DB; Laravel owns persistence.

### Current Gaps

- No indicator math, no indicator endpoint, no Laravel indicator command/client method.
- No hand-derived indicator fixtures.

## Technical Approach

1. **Engine indicator math (pure Python, stdlib only).** Add `engine/app/indicators/` with per-indicator functions over a list of bars: `sma(values, n)`, `ema(values, n)`, `rsi(values, n=14)`, `macd(values, 12, 26, 9)` (macd/signal/hist), `adx(highs, lows, closes, 14)`, `bollinger(values, 20, 2)` (upper/middle/lower), `rvol(volumes, 50)`. Do not add pandas/numpy; keep it deterministic.
2. **Snapshot builder.** `compute_snapshots(bars: list[Bar]) -> list[Snapshot]` returns one snapshot per bar in order, with **nulls** for any indicator that lacks enough history at that bar (e.g. `sma200` null before bar 200; `sma20` null before bar 20). No partial/wrong values.
3. **Engine endpoint.** `POST /indicators/compute` accepts `{bars: [{date, open, high, low, close, volume}, ...]}` and returns `{snapshots: [{date, sma20, ..., rvol}, ...]}` (JSON `null`s for unavailable values). Validate input with pydantic; controlled 422 for malformed bodies.
4. **Committed fixtures.** Add a small hand-derived series fixture (e.g. `engine/tests/fixtures/indicator_series.csv`) with the expected values documented in the test (monotonic, constant and short cases). Reuse the existing real NVDA fixture for a longer-series smoke.
5. **Laravel client.** Add `computeIndicators(array $bars): array` to `EngineClient` (POST to `/indicators/compute`, `->throw()`).
6. **Laravel command.** `indicators:compute {--ticker=} {--universe=}`: for each target instrument, load its `DailyBar`s ordered by date, POST them to the engine, and `IndicatorSnapshot::updateOrCreate(['instrument_id'=>..., 'date'=>Carbon::parse($date)->startOfDay()], [...])` inside a transaction. Report instruments processed and snapshots written. Support a single `--ticker` or all instruments of a `--universe` that have bars.
7. **Tests.** Engine: `engine/tests/test_indicators.py` for each indicator + the snapshot builder (including insufficient-history nulls) using fixtures. Laravel: `tests/Feature/ComputeIndicatorsCommandTest.php` with `Http::fake` (stores snapshots, idempotent re-run, insufficient-history nulls stored, engine error handled).

## Expected File Changes

Engine:

- `engine/app/indicators/__init__.py`, `engine/app/indicators/core.py` — create; indicator math.
- `engine/app/indicators/snapshots.py` — create; `compute_snapshots`.
- `engine/app/models.py` — modify; add `Snapshot` + request/response models.
- `engine/app/main.py` — modify; `POST /indicators/compute`.
- `engine/tests/fixtures/indicator_series.csv` — create; hand-derived fixture.
- `engine/tests/test_indicators.py` — create.

Laravel:

- `app/Services/Engine/EngineClient.php` — modify; `computeIndicators`.
- `app/Console/Commands/ComputeIndicators.php` — create; `indicators:compute`.
- `tests/Feature/ComputeIndicatorsCommandTest.php` — create.
- `ARCHITECTURE.md`, `CONSTRAINTS.md` — update.
- `PROGRESS.md`, `feature_list.json` — update with evidence.

## Visual Design Impact

- UI involved: no. `DESIGN.md` is not applicable to this slice.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — record the indicators path (engine computes from bars, Laravel persists snapshots) and that the engine does not read/write the DB.
- `CONSTRAINTS.md`: update — MUST rules: engine owns indicator math (stdlib only, no pandas), engine does not write the DB, Laravel persists snapshots, insufficient history → null (never a wrong value), tests are offline (fixtures + `Http::fake`), snapshot upsert keyed on the start-of-day date.
- `AGENTS.md`: not needed — no workflow/startup change.
- Other docs: `PROGRESS.md` and `feature_list.json` — update with evidence.

## Key Implementation Risks

- **Indicator correctness** — the core risk. Use closed-form, fixture-backed tests (monotonic/constant/short series) and document the exact periods; avoid hand-waving.
- **Insufficient history** — always emit `null`, never a partial-window value; prove with a short-series test.
- **Date key** — the snapshot date must match the bar date via `Carbon::parse($date)->startOfDay()` (the earlier ingestion finding) to keep the unique index idempotent.
- **Scope** — no signals, no scheduling, no API/UI.
- **Two-language change** — engine computes only; Laravel persists; do not let the engine touch the DB.
- **Fixture size** — keep the hand-derived fixture tiny and documented; use the real NVDA fixture only for a longer-series smoke.

## Implementation Plan

1. Implement the engine indicator functions and `compute_snapshots` (stdlib only).
2. Add `POST /indicators/compute` and the pydantic models.
3. Add the hand-derived fixture and engine indicator tests (including insufficient-history nulls).
4. Add `EngineClient::computeIndicators` and the `indicators:compute` command; refactor nothing else.
5. Add `ComputeIndicatorsCommandTest` (`Http::fake`).
6. Verify engine `pytest`/`ruff`, Laravel `php artisan test`, and `.\init.ps1`.
7. Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [x] Implement `engine/app/indicators/core.py` (sma, ema, rsi, macd, adx, bollinger, rvol) and `snapshots.py`.
- [x] Add `Snapshot` + request/response models and `POST /indicators/compute`.
- [x] Add `engine/tests/fixtures/indicator_series.csv` and `engine/tests/test_indicators.py` (closed-form + insufficient-history).
- [x] Add `EngineClient::computeIndicators` and `app/Console/Commands/ComputeIndicators.php` (`--ticker`/`--universe`).
- [x] Add `tests/Feature/ComputeIndicatorsCommandTest.php` (stores, idempotent, nulls, engine error).
- [x] Run engine `pytest` + `ruff`, Laravel `php artisan test`, and `.\init.ps1` (exit 0, no server).
- [x] Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Verification Plan

- Engine: `engine\.venv\Scripts\python.exe -m pytest -q` → indicator tests pass (closed-form expected values; insufficient history → null); `ruff check` clean.
- Laravel: `php artisan test` → `ComputeIndicatorsCommandTest` passes: snapshots stored per bar, re-run idempotent (no duplicates), nulls stored for insufficient history, engine error handled without writing.
- `php artisan list` → `indicators:compute` registered.
- `.\init.ps1` → Laravel + SPA + engine checks, exit 0, no server started.
- Persistent E2E: none exists; CLI/engine flows covered by feature tests. Record the gap.
- Startup script rule: `init.ps1` stays non-blocking and starts no server.

## Evidence To Capture

- Engine `pytest`/`ruff` output (exit 0) and the fixture path with the documented expected values.
- Laravel `php artisan test` output (command test + suite green, exit 0).
- `php artisan list` showing `indicators:compute`.
- Evidence that insufficient history stores nulls (not wrong values) and that re-runs do not duplicate.
- `.\init.ps1` output (exit 0).
- Confirmation `frontend/`, `alphapulse/` and unrelated schema were not modified.

## Implementation Findings

Findings are additive clarifications; the design (engine computes, Laravel persists, insufficient history → null) was implemented as specified.

- **RVOL baseline excludes the current bar.** "Volume vs 50-bar average" was ambiguous, so RVOL is defined as `volume[i] / mean(volume[i-50..i-1])`. The first value therefore needs **51** bars (index 50); a zero-volume baseline yields `null`. Documented in `engine/app/indicators/core.py` and `CONSTRAINTS.md`.
- **ADX needs `2 * period` bars.** The first DX is measured at index `period` and ADX averages `period` DX values, so the first ADX is at index `2 * period - 1` = **27** for period 14. A window with no true range (a flat series) yields DX/ADX `0` instead of a division error.
- **RSI conventions.** Wilder smoothing, first value at index `period` (14). No losses in the window → **100** (the standard trading-tool convention, which also covers a flat series); no gains → **0**.
- **MACD offsets.** The MACD line is available from index `slow - 1` = **25**; the signal line is the EMA of the available MACD values, so it starts at index `slow - 1 + signal - 1` = **33**; the histogram is `macd - signal`. On a linear ramp the EMA seed already equals the steady-state lag, so MACD = `(26-1)/2 - (12-1)/2` = **7** exactly, with signal 7 and histogram 0 (used as a closed-form test).
- **Bollinger** uses the **population** standard deviation (`/period`), so a flat window has zero width; bands are available from index `period - 1` = 19.
- **A second fixture was added** — `engine/tests/fixtures/indicator_constant.csv` (60 flat bars) — because the spec's single monotonic fixture could not also cover the constant closed-form case. The short-series cases are derived by truncating the monotonic fixture.
- **Command behavior clarifications.** `--ticker` takes precedence; without it, `--universe` (default `sp500`) targets every member that has stored bars. Instruments are processed in their own try/catch (one failed engine call does not abort the others) and the command exits `1` if any instrument failed. One row is written per returned snapshot, **including bars whose indicators are all `null`** (Scenario 3 requires a row per bar).
- **No DB access from the engine**: the endpoint is pure and stateless; Laravel performs every `IndicatorSnapshot` write.

### Verification Evidence (implementer self-check)

- Engine: `engine\.venv\Scripts\python.exe -m pytest -q` → **26 passed** (exit 0); `-m ruff check .` → **All checks passed** (exit 0), offline fixtures only.
- Laravel: `php artisan test` → **48 passed (259 assertions)**, exit 0 (`ComputeIndicatorsCommandTest` = 7 cases, `Http::fake` only).
- `php artisan list` shows `indicators:compute`.
- Live engine smoke (`python -m app` on 8090, torn down, port released): 60-bar monotonic series → 60 snapshots; bar 20 `sma20=109.5`, `bb_middle=109.5`, `rsi14=100`; last `adx=100`, `macd=7`, `macd_signal=7`, `macd_hist=0`, `rvol=1`; malformed body → `422`.
- Live Laravel → engine chain (real engine on 8090, temporary instrument `ZZTEST` with 60 monotonic bars, then cleaned up): `indicators:compute --ticker=ZZTEST` wrote 60 snapshots, a second run left the count at 60 (idempotent), stored values matched the closed forms above (`sma20=109.5000`, `rsi14=100.0000`, `adx=100.0000`, `macd=7.0000`, `macd_signal=7.0000`, `macd_hist=0.0000`, `rvol=1.0000`); engine stopped, port 8090 free, cleanup left 0 instrument/bars/snapshots.
- `.\init.ps1` → exit 0 (Laravel 48 tests + SPA lint/build + engine 26 tests), starts no server.
- Persistent E2E: none exists in the repo; the CLI/engine flows are covered by feature tests plus the live smoke above. Scope kept to the engine + Laravel command (no signals/scheduling/admin/API/UI; `frontend/` and `alphapulse/` untouched).

## Validator Checklist

- [ ] Implementation stays within this feature's scope (no signals/scheduling/admin/API/UI).
- [ ] Acceptance scenarios pass.
- [ ] Verification evidence is present.
- [ ] Persistent E2E coverage was added/updated when the feature has an observable user/API flow and an E2E harness exists, or the spec explains why it is not needed.
- [ ] `feature_list.json` and `PROGRESS.md` were updated correctly.
- [ ] No unrelated product behavior or extra feature work was added.
- [ ] Indicator values are proven against closed-form/fixture expectations; insufficient history yields null.
- [ ] The engine computes and does NOT write the DB; Laravel persists snapshots idempotently by `(instrument_id, date)`.
- [ ] Tests are offline (fixtures + `Http::fake`); no pandas/numpy added.
