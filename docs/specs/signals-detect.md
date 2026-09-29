# Feature Implementation Spec: Detect deterministic signals

## Source Feature

- `id`: `signals-detect`
- `area`: `engine`
- `depends_on`: `indicators-compute` (accepted)
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Detect deterministic Signals per Instrument from the stored Daily Bars and persist the current active set. The Python engine owns the signal rules (stdlib only, reusing the indicator math) and returns the signals detected on the **as-of bar** (the latest stored bar). Laravel (the database owner) replaces the instrument's signal rows so the stored set always reflects the latest run: a signal that no longer holds disappears, and re-running the same bars is idempotent.

Signals are derived from indicators (SMA/EMA, RSI, MACD, RVOL) plus the prior price pivot. Geometric chartist patterns (Cup & Handle, Double Top, flags) are explicitly later scope.

## Non-Goals

- No geometric/chartist pattern detection (Cup & Handle, Double Top, flags, divergences).
- No screener API, instrument-detail API, charting, scheduling or admin panel.
- No signal history/append log and no signal editing by users.
- No parameter tuning; RVOL/RSI/pivot thresholds are fixed in this feature.
- No network in tests; no pandas/numpy; the engine never touches the database.

## Job Story

When a new EOD run finishes,
I want the engine to recompute which deterministic setups currently hold for each Instrument,
so the screener and chart can rely on a correct, date-stamped set of signals that reflects today's bars.

## Users And Permissions

- Developer/Operator (CLI): runs the detection command. No end-user roles involved.

## Domain Decisions

1. **Signal vocabulary is the 9 types already declared in `database/factories/SignalFactory.php`** (fixed strings): `golden_cross`, `death_cross`, `ma_alignment_bullish`, `ma_alignment_bearish`, `pivot_breakout_rvol`, `rsi_overbought`, `rsi_oversold`, `macd_bullish_cross`, `macd_bearish_cross`. The engine owns the rules; the strings are the shared contract.
2. **A signal is evaluated on the as-of (latest) bar.** The engine computes indicators for the whole series but emits signals only for the last bar, using the previous bar where a rule needs a crossing. `signals.date` is the as-of bar date. This directly implements the domain rule that signals are recomputed per run, date-stamped, and can disappear.
3. **Recompute = idempotent replace per instrument (delete-then-insert), not append.** Within one `DB::transaction`, all `signals` rows for the instrument are deleted and the freshly detected set inserted. Rationale: the table holds the *current active* set; append would grow unbounded, need a run FK plus "latest run" queries, and could never make a stale signal disappear. Fetch the engine response **before** deleting so an engine failure leaves the previous set intact. A unique key on `(instrument_id, date, type)` makes the invariant explicit.
4. **Wire-up is a dedicated command, not `ingestion:run`.** Signals are recomputed by `php artisan signals:detect` over the latest stored bars (the output of the latest Ingestion Run), matching how `indicators:compute` works today. `ingestion-scheduler` (later, depends on this feature) composes ingestion → indicators → signals.

### Signal rule definitions (fixed contract)

| Type | Fires on the as-of bar `i` when | Metadata |
| --- | --- | --- |
| `golden_cross` | `sma50[i-1] <= sma200[i-1]` and `sma50[i] > sma200[i]` (all non-null) | `sma50`, `sma200` |
| `death_cross` | `sma50[i-1] >= sma200[i-1]` and `sma50[i] < sma200[i]` (all non-null) | `sma50`, `sma200` |
| `ma_alignment_bullish` | `sma20[i] > sma50[i] > sma200[i]` (all non-null) | `sma20`, `sma50`, `sma200` |
| `ma_alignment_bearish` | `sma20[i] < sma50[i] < sma200[i]` (all non-null) | `sma20`, `sma50`, `sma200` |
| `pivot_breakout_rvol` | `close[i] > max(high[i-20 : i])` and `rvol[i] >= 2.0` (`rvol` non-null; pivot = prior 20 sessions, excluding `i`) | `pivot`, `close`, `rvol` |
| `rsi_overbought` | `rsi14[i] >= 70` | `rsi14` |
| `rsi_oversold` | `rsi14[i] <= 30` | `rsi14` |
| `macd_bullish_cross` | `macd[i-1] <= macd_signal[i-1]` and `macd[i] > macd_signal[i]` (all non-null) | `macd`, `macd_signal` |
| `macd_bearish_cross` | `macd[i-1] >= macd_signal[i-1]` and `macd[i] < macd_signal[i]` (all non-null) | `macd`, `macd_signal` |

Boundary rules: strict inequalities decide the cross/alignment (equality is "no signal"); the comparisons are inclusive where shown (`rvol >= 2.0`, `rsi >= 70`, `rsi <= 30`). Any required indicator being `null` (insufficient history) means the rule does not fire — never a false signal.

## Acceptance Scenarios

### Scenario 1: Each rule detects a positive and negative case

Given a series/fixture whose as-of bar satisfies a rule,
When the engine detects signals,
Then the rule's signal is returned with its type, the as-of `date` and numeric `metadata`.
Given a series whose as-of bar does not satisfy the rule (or the needed indicator is `null`),
When the engine detects signals,
Then that signal type is absent.

### Scenario 2: Signals are date-stamped

Given stored Daily Bars whose latest date is `D`,
When `signals:detect` runs,
Then every stored signal row for the instrument has `date = D` and a valid `type`.

### Scenario 3: A signal that no longer holds disappears

Given an instrument whose last bar triggered a signal and a stored signal row exists,
When the bars advance so the condition no longer holds and `signals:detect` runs again,
Then the stale row is gone and no row for that type/date remains.

### Scenario 4: Recompute is idempotent and failure-safe

Given the same bars,
When `signals:detect` runs twice,
Then the stored set is identical (no duplicates).
When the engine call fails,
Then no signal is written and the previously stored set is left untouched, and the command exits `1`.

## Repository Research

### Files Inspected

- `database/factories/SignalFactory.php` — already defines the 9 signal types; the vocabulary source of truth.
- `database/migrations/2026_09_29_100006_create_signals_table.php` / `app/Models/Signal.php` — `signals(id, instrument_id, date, type, metadata json, timestamps)` with indexes on `type` and `(instrument_id, date)`; no unique key, no run FK.
- `engine/app/indicators/core.py`, `engine/app/indicators/snapshots.py`, `engine/app/models.py`, `engine/app/main.py` — indicator math, `Snapshot`, request/response models, `POST /indicators/compute` pattern.
- `engine/tests/test_indicators.py`, `engine/tests/fixtures/*.csv` — offline fixture/test style (`constant_bars` helper + committed CSVs).
- `app/Console/Commands/ComputeIndicators.php`, `app/Services/Engine/EngineClient.php` — command/upsert/client patterns to mirror.
- `tests/Feature/ComputeIndicatorsCommandTest.php` — `Http::fake` test patterns.
- `docs/domain-model.md` — Signal is derived per Instrument/Daily Bar and recomputed each run; it can appear or disappear.
- `docs/build-brief.md` — MVP signals are deterministic; geometric patterns are later.
- `ARCHITECTURE.md`, `CONSTRAINTS.md` — engine computes / Laravel persists Chinese wall; offline tests.

### Existing Patterns To Follow

- Engine: pure stdlib functions + pydantic models; endpoint validates with pydantic (`422` on malformed); pytest with venv Python.
- Laravel: `EngineClient` method + artisan command; fetch from the engine before writing; `DB::transaction`; `Http::fake` tests; `Carbon::parse($date)->startOfDay()` for the `date` key.
- The engine has no DB access; Laravel owns all persistence.

### Current Gaps

- No signal rules, no `/signals/detect` endpoint, no `EngineClient::detectSignals`, no `signals:detect` command.
- No unique key on `signals(instrument_id, date, type)` and no replace logic; the table is currently empty.

## Technical Approach

1. **Engine rules (`engine/app/signals/rules.py`).** One pure predicate per signal type, each taking the indicator values it needs (scalars or the `Snapshot` objects for `i-1`/`i`) and returning `(bool, metadata dict)` or `None`. No I/O. Keep the 9 definitions above in one module with a `RULES` registry so the set is easy to audit.
2. **Detector (`engine/app/signals/detect.py`).** `detect_signals(bars: list[Bar]) -> list[Signal]` computes snapshots via the existing `compute_snapshots(bars)`, then evaluates every rule at the last index (using `i-1` for crosses) and returns the detected signals. Fewer than 2 bars → `[]`; empty series → `[]`.
3. **Models/endpoint (`engine/app/models.py`, `engine/app/main.py`).** Add `Signal(date, type, metadata: dict[str, float])`, `SignalsDetectRequest(bars)`, `SignalsDetectResponse(signals)` and `POST /signals/detect` (pydantic-validated, controlled `422`). Reuse the `Bar` model; do not add dependencies.
4. **Laravel client + persistence.** Add `EngineClient::detectSignals(array $bars): array` (POST `/signals/detect`, `->throw()`). Add `php artisan signals:detect {--ticker=} {--universe=}` mirroring `indicators:compute` target resolution, then per instrument: load bars ordered by date → engine call → `DB::transaction` delete all `Signal` rows for the instrument and insert the normalized new set. Nothing is deleted when the engine call fails.
5. **Migration.** Add `database/migrations/2026_09_29_190000_add_unique_index_to_signals_table.php` adding `unique(['instrument_id', 'date', 'type'])` (drop it in `down()`). This is the only schema change; the existing columns are sufficient.
6. **Tests.** Engine `engine/tests/test_signals.py` (each rule positive + negative, boundary and null-history cases, end-to-end `detect_signals`, endpoint). Laravel `tests/Feature/DetectSignalsCommandTest.php` (`Http::fake`: store, idempotent, disappear, failure-safe, target resolution).

## Expected File Changes

Engine:

- `engine/app/signals/__init__.py` — create; package marker.
- `engine/app/signals/rules.py` — create; the 9 pure rule predicates.
- `engine/app/signals/detect.py` — create; `detect_signals` over the as-of bar.
- `engine/app/models.py` — modify; `Signal`, `SignalsDetectRequest`, `SignalsDetectResponse`.
- `engine/app/main.py` — modify; `POST /signals/detect`.
- `engine/tests/test_signals.py` — create; positive/negative per rule + endpoint.

Laravel:

- `database/migrations/2026_09_29_190000_add_unique_index_to_signals_table.php` — create; unique `(instrument_id, date, type)`.
- `app/Services/Engine/EngineClient.php` — modify; `detectSignals`.
- `app/Console/Commands/DetectSignals.php` — create; `signals:detect`.
- `tests/Feature/DetectSignalsCommandTest.php` — create.

Docs/harness: `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Visual Design Impact

- UI involved: no. `DESIGN.md` is not applicable to this slice.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — add a "Signals Path" section (engine detects on the as-of bar, Laravel replaces the instrument's signal set, endpoint/command names, no DB access from the engine).
- `CONSTRAINTS.md`: update — Signals section: engine owns the rules (stdlib only, no DB); `/signals/detect` contract; fixed 9-type vocabulary and thresholds (RVOL `>= 2.0`, RSI `70/30`, pivot = prior 20-session high); signals evaluated on the as-of bar; replace semantics (fetch → delete → insert in one transaction); offline tests; unique `(instrument_id, date, type)`.
- `AGENTS.md`: not needed — no workflow, startup or operating-rule change.
- Other docs: `docs/domain-model.md` not needed (the Signal lifecycle is already stated); `PROGRESS.md` and `feature_list.json` updated with evidence.

## Implementation Plan

1. Add the engine rule predicates and `detect_signals` (stdlib only, reuse `compute_snapshots`).
2. Add the `Signal`/request/response models and `POST /signals/detect`.
3. Add `engine/tests/test_signals.py` (positive + negative per rule, boundaries/nulls, endpoint).
4. Add the `signals` unique-index migration.
5. Add `EngineClient::detectSignals` and `app/Console/Commands/DetectSignals.php` (replace semantics).
6. Add `tests/Feature/DetectSignalsCommandTest.php` (`Http::fake`).
7. Run engine `pytest`/`ruff`, `php artisan test`, `.\init.ps1`; update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [ ] Implement `engine/app/signals/rules.py` with the 9 rule predicates and thresholds.
- [ ] Implement `engine/app/signals/detect.py::detect_signals` (as-of bar, `i-1` for crosses, null-safe).
- [ ] Add `Signal`/`SignalsDetectRequest`/`SignalsDetectResponse` and `POST /signals/detect`.
- [ ] Add `engine/tests/test_signals.py` covering positive + negative for every rule, plus endpoint cases.
- [ ] Add the `signals` unique-index migration (up/down proven).
- [ ] Add `EngineClient::detectSignals` and `app/Console/Commands/DetectSignals.php` (`--ticker`/`--universe`, fetch-before-delete, transaction).
- [ ] Add `tests/Feature/DetectSignalsCommandTest.php` (store, idempotent, disappear, failure-safe, unknown ticker/universe, universe mode).
- [ ] Run engine `pytest`/`ruff`, Laravel `php artisan test`, `.\init.ps1` (exit 0, no server).
- [ ] Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Verification Plan

- Engine: `engine\.venv\Scripts\python.exe -m pytest -q` → signal tests pass (each rule positive/negative; boundary equality is no signal; null history is no signal); `-m ruff check .` clean.
- Engine endpoint: `POST /signals/detect` returns `{signals: [...]}` with the as-of date; empty series → `{signals: []}`; malformed body → `422`.
- Laravel: `php artisan test` → `DetectSignalsCommandTest` passes: rows stored with date/type/metadata, re-run idempotent, a no-longer-holding signal disappears, engine failure leaves the previous set intact and exits `1`, unknown ticker/universe fail with no HTTP.
- Migration: `php artisan migrate --force` then `migrate:rollback --force` then `migrate --force` clean round-trip; `php artisan db:table signals` shows the unique index.
- `php artisan list` → `signals:detect` registered.
- `.\init.ps1` → Laravel + SPA + engine checks, exit 0, no server started; the script needs no change (it already runs both suites).
- Optional live smoke: start the engine, run `signals:detect` against a temporary instrument with a controlled series, confirm the stored set matches the rules, then clean up and release the port.
- Persistent E2E: none exists; this is a CLI/engine flow covered by feature tests. State that explicitly in the evidence.

## Evidence To Capture

- Engine `pytest`/`ruff` output (exit 0) and the per-rule positive/negative test names.
- Laravel `php artisan test` output (exit 0) with the disappear/idempotent/failure-safe cases.
- Migration round-trip output and the `signals` unique index.
- `php artisan list` showing `signals:detect`.
- `.\init.ps1` output (exit 0) and confirmation `frontend/`, `alphapulse/` and unrelated schema were not modified.

## Key Implementation Risks

- **Signal correctness** — the core risk. Keep each rule a pure, closed-form predicate tested with explicit boundary values; do not tune thresholds silently.
- **Disappearance semantics** — must be replace-per-instrument (delete then insert), with the engine call made before the delete so failures are safe.
- **Date key** — normalize with `Carbon::parse($date)->startOfDay()` (the established `date` cast behavior), or the unique key/replace logic misbehaves.
- **Null history** — SMA200-based rules and RVOL cannot fire before the data exists; never emit a false signal from a null indicator.
- **Scope** — no chartist patterns, no screener/API/UI, no scheduling; do not touch `ingestion:run`.
- **Engine purity** — no DB access and no new dependencies; reuse the existing indicator math.

## Validator Checklist

- [ ] Implementation stays within this feature's scope (no chartist patterns, screener/API/UI or scheduling).
- [ ] Every one of the 9 rules has a positive and a negative test; boundaries and null history behave as specified.
- [ ] Signals are date-stamped with the as-of bar date and recomputed (replace) per run; a stale signal disappears.
- [ ] Engine failure writes nothing and preserves the previous signal set; re-running is idempotent.
- [ ] The unique `(instrument_id, date, type)` index exists and migrations roll back.
- [ ] The engine does NOT touch the DB; Laravel persists; tests are offline (`Http::fake` / fixtures); no pandas/numpy.
- [ ] `.\init.ps1` exits 0 and starts no server; `frontend/`, `alphapulse/` and unrelated schema untouched.
- [ ] `feature_list.json` and `PROGRESS.md` were updated correctly.
- [ ] No unrelated product behavior or extra feature work was added.

## Implementation Findings

Recorded during implementation (`signals-detect`, 2026-09-29). None changes the intended behavior; they make the implemented contract explicit.

1. **Uniform rule signature.** Every predicate in `engine/app/signals/rules.py` shares the signature `(previous: Snapshot | None, current: Snapshot, bars: list[Bar], index: int) -> tuple[bool, dict[str, float]] | None`. Rules that need no previous bar ignore those arguments; the uniform shape is what makes `RULES` a single auditable registry and lets `detect_signals` evaluate every rule with the same call. `None` means "a required indicator is unavailable" (null history), `(False, {})` means "evaluated, did not fire", `(True, metadata)` means "fired".
2. **RSI flat-series convention interaction.** The existing indicator convention returns RSI 14 = 100 for a window without losses (including a flat series). The rule `rsi14 >= 70` therefore fires `rsi_overbought` on a flat/constant series. This follows the fixed rule table literally; it is an interaction with the documented indicator convention, not a rule bug. If product wants flat series excluded, that is a threshold/convention change owned by a future spec.
3. **Type validation is lenient by design.** `DetectSignals::normalizeSignal()` accepts any non-empty string `type` and does not whitelist the 9 strings; the vocabulary is owned by the engine `RULES` registry (and mirrored in `SignalFactory`). An invalid date or an empty/non-string type drops the whole signal, and only numeric metadata values are kept. The normalized set is keyed by type so a malformed payload can never violate the unique `(instrument_id, date, type)` index.
4. **Console output and exit codes (unspecified in the spec).** The command mirrors `indicators:compute`: per instrument `Stored N signal(s) for TICKER.`, then `Processed N instrument(s); stored M signal(s).`; an engine failure for any instrument prints `Engine failed for TICKER: ...` and exits `1` (the failed instrument keeps its previous rows); an unknown `--ticker`/`--universe` is a pre-flight `1` with no HTTP call. `--ticker` takes precedence over `--universe`, and `--universe` defaults to `sp500`.
5. **Test-only note on `Http::fake`.** Laravel merges fake stubs, so calling `Http::fake()` twice with the same URL pattern in one test keeps the first stub. The disappear/failure-safe cases therefore use `Http::fakeSequence('*/signals/detect')` to return a different response on each command run.
6. **Live smoke (optional, performed).** A temporary `ZZSIG` instrument with 59 flat bars (close/high 100, volume 1000) plus one breakout bar (close 110, high 111, volume 3000) ran through the real engine on 8090: 3 signals stored, all `date = 2026-03-01` (the as-of bar): `pivot_breakout_rvol {pivot:100, close:110, rvol:3}`, `macd_bullish_cross`, `rsi_overbought`. A second run stored the same 3 (idempotent). The instrument, its bars and signals were deleted afterwards and the engine was stopped with port 8090 released.
7. **Persistent E2E.** No persistent E2E harness exists in this repository; this CLI/engine flow is covered by engine tests, Laravel feature tests (`Http::fake`) and the optional live smoke above.

