# Feature Implementation Spec: Detect chartist patterns

## Source Feature

- `id`: `chart-patterns-detect`
- `area`: `engine`
- `depends_on`: `signals-detect`, `ingestion-scheduler` (both accepted)
- `status`: `not_started` (planned 2026-10-10 at the user's explicit request; chartist patterns were "later than MVP Signal work" in `AGENTS.md`)
- `source`: `feature_list.json`

## Goal

Detect a first, closed set of geometric Chartist Patterns per Instrument from stored Daily Bars and persist the **current active set**, exactly like Signals: the Python engine owns deterministic, auditable rules (stdlib only) and returns the patterns that are forming or recently confirmed as of the latest bar; Laravel replaces the instrument's pattern rows. The daily pipeline runs the new stage after `signals:detect`.

First vocabulary (fixed strings, the shared contract): `double_top`, `double_bottom`, `cup_with_handle`, `bull_flag`.

## Non-Goals

- No screener filter, API exposure, chart drawing or UI (that is `chart-patterns-ui`).
- No alerts (`alerts-engine`).
- No price targets, measured moves, stops or probabilities. Patterns describe geometry only.
- No triangles, head-and-shoulders, wedges, bear flags or pennants in this slice (add later by extending the vocabulary).
- No machine learning, no pattern history log, no user editing, no parameter tuning UI. No pandas/numpy.

## Job Story

When the daily pipeline has fresh bars,
I want the engine to find the classic formations traders look for, with explicit rules,
so the screener and chart can later show them consistently and explainably.

## Users And Permissions

- Developer/Operator (CLI and scheduler): runs `patterns:detect`. No end-user surface in this slice.

## Domain Decisions

1. **Swing points.** A bar `j` is a swing high when `high[j]` is the strict maximum of `high[j-5 .. j+5]` (swing low: strict minimum of `low`). The last 5 bars therefore cannot be swing points. Ties are not swings. `k = 5` is a module constant.
2. **Evaluation window.** Only the last 330 bars are scanned (cup max length + margin). Fewer than 60 bars → no patterns.
3. **One pattern per type per instrument:** the most recent qualifying instance (latest `end_date`). Unique `(instrument_id, as_of_date, type)`.
4. **Status.** `forming` = geometry complete and not invalidated, breakout not yet happened; `confirmed` = breakout close happened within the last 10 sessions (inclusive of the as-of bar). A breakout older than 10 sessions, or an invalidation, drops the pattern from the active set (replace semantics make it disappear).
5. **Breakout level** is the single price line the pattern exposes (neckline or pivot). No target is computed or stored.
6. **Replace semantics per instrument** (fetch from engine first, then delete + insert in one transaction), as in `signals-detect`.

### Pattern rule definitions (fixed contract, initial parameters)

All percentages are relative to the referenced price. `i` = as-of index.

| Type | Geometry | Breakout level | Confirmed when (within last 10 sessions) | Invalidated when |
| --- | --- | --- | --- | --- |
| `double_top` | Swing highs `H1` (t1) < `H2` (t2) in index, `15 <= t2-t1 <= 120`; `abs(H2-H1)/max(H1,H2) <= 3%`; trough `L = min(low[t1..t2])` with `(min(H1,H2)-L)/min(H1,H2) >= 10%`; prior rise: `H1 >= 1.15 * min(low[t1-60 .. t1])`; `i - t2 <= 60` | `L` (neckline) | a close `< L` after `t2` | any close `> max(H1,H2) * 1.03` after `t2` |
| `double_bottom` | Mirror of `double_top` with swing lows `L1`, `L2`, peak `H = max(high[t1..t2])`, rise `(H-max(L1,L2))/max(L1,L2) >= 10%`; prior decline `L1 <= 0.85 * max(high[t1-60 .. t1])` | `H` (neckline) | a close `> H` after `t2` | any close `< min(L1,L2) * 0.97` after `t2` |
| `cup_with_handle` | Left rim swing high `A` (ta), cup low `B = min(low[ta..tc])` at tb, right rim swing high `C` (tc): `30 <= tc-ta <= 325`; depth `12% <= (A-B)/A <= 35%`; `0.95A <= C <= 1.05A`; `tb` in the middle 20–80% of `[ta, tc]`; handle: `5 <= i-tc` and handle span `tc..i` (or up to the breakout) `<= 25` sessions, handle low `D >= B + (A-B)/2` and `(C-D)/C <= 12%` | pivot `P = max(high[tc..breakout-1])` (C when no handle highs exceed it) | a close `> P` with `rvol >= 1.5` | handle low falls below `B + (A-B)/2`, or handle exceeds 25 sessions without breakout |
| `bull_flag` | Pole: from swing low `S` (ts) to swing high `T` (tt), `tt-ts <= 15`, rise `(T-S)/S >= 15%`; flag: `5 <= flag length <= 20` sessions after `tt`; flag low `>= T - 0.5*(T-S)`; least-squares slope of flag closes `<= 0`; mean flag volume `<` mean pole volume | `F = max(high` over the flag`)` | a close `> F` | flag low `< T - 0.5*(T-S)` or flag longer than 20 sessions without breakout |

Boundary rules: inequalities as written; any `null` input (e.g. `rvol` before 50 bars) means the rule cannot confirm, never a false pattern. Parameters live as named constants in one module so a later spec can tune them with tests.

## Acceptance Scenarios

### Scenario 1: Each pattern detected from a synthetic series
Given a committed synthetic fixture that draws a textbook instance of a type,
When the engine detects patterns,
Then that type is returned with `status`, `start_date`, `end_date`, `breakout_level` and its key points with roles.

### Scenario 2: Near misses are rejected
Given fixtures violating one rule each (tops 5% apart, cup 40% deep, pole +10%, flag retracing 60%…),
When the engine detects patterns,
Then that type is absent.

### Scenario 3: Status transitions and disappearance
Given a forming `double_bottom`, when bars advance with a close above the neckline, the stored status becomes `confirmed`; 11 sessions later the pattern disappears; an invalidation close removes it at once.

### Scenario 4: Pipeline, idempotence and failure safety
Given `ingestion:pipeline` runs, `patterns:detect` runs after `signals:detect`; running it twice stores the same set; an engine failure for one instrument keeps its previous rows and the command exits `1` without aborting other instruments.

## Repository Research

### Files Inspected

- `engine/app/signals/{rules,detect}.py`, `engine/app/models.py`, `engine/app/main.py` — rule registry, as-of evaluation, pydantic models, endpoint pattern.
- `engine/app/indicators/snapshots.py` — `compute_snapshots` (reuse for `rvol`).
- `engine/app/sources/stooq.py` — Yahoo `range=2y`: about 500 bars per instrument, enough for the 330-bar window.
- `app/Console/Commands/DetectSignals.php`, `app/Services/Engine/EngineClient.php`, `app/Console/Commands/RunIngestionPipeline.php`, `routes/console.php` — command, client and pipeline stage patterns.
- `docs/specs/signals-detect.md`, `CONSTRAINTS.md`, `ARCHITECTURE.md`, `CONTEXT.md` — replace semantics, Chinese wall, glossary ("Chartist Pattern").

### Existing Patterns To Follow

- Pure stdlib predicates + registry; `POST /<thing>/detect`; pydantic `422` on malformed input; offline pytest with synthetic fixtures.
- Laravel `EngineClient` method + artisan command with `--ticker`/`--universe`; `DB::transaction`; `Http::fake`/`Http::fakeSequence` tests; `Carbon::parse($date)->startOfDay()`.

### Current Gaps

- No pattern table, endpoint, client method, command or pipeline stage.

## Technical Approach

1. **Engine** `engine/app/patterns/`: `swings.py` (swing highs/lows), `rules.py` (one detector per type returning the latest qualifying instance or `None`, with `PARAMS` constants), `detect.py` (`detect_patterns(bars) -> list[Pattern]`, window 330, `compute_snapshots` for `rvol`).
2. **Models/endpoint:** `PatternPoint(date, price, role)`, `Pattern(type, status, as_of_date, start_date, end_date, breakout_level, points, metadata: dict[str, float])`, `PatternsDetectRequest/Response`, `POST /patterns/detect`.
3. **Schema:** migration `create_chart_patterns_table`: `id, instrument_id (FK cascade), as_of_date (date), type (string 32), status (string 16), start_date, end_date, breakout_level (decimal 14,4), points (json), metadata (json), timestamps`; unique `(instrument_id, as_of_date, type)`; index `(type, status)`. Model `App\Models\ChartPattern` + `Instrument::chartPatterns()` + factory.
4. **Laravel:** `EngineClient::detectPatterns(array $bars)`; command `patterns:detect {--ticker=} {--universe=}` mirroring `signals:detect` (lenient normalization: drop malformed items, keep numeric metadata, roles as strings).
5. **Pipeline:** `RunIngestionPipeline` calls `patterns:detect` after `signals:detect`; a pattern-stage failure is reported and makes the pipeline exit `1`, like the signals stage, without undoing earlier stages.

## Expected File Changes

- Engine: `engine/app/patterns/{__init__,swings,rules,detect}.py` (create); `engine/app/models.py`, `engine/app/main.py` (modify); `engine/tests/test_patterns.py` + synthetic fixture builders (create).
- Laravel: `database/migrations/2026_10_xx_create_chart_patterns_table.php`, `app/Models/ChartPattern.php`, `database/factories/ChartPatternFactory.php`, `app/Console/Commands/DetectPatterns.php`, `tests/Feature/DetectPatternsCommandTest.php` (create); `app/Models/Instrument.php`, `app/Services/Engine/EngineClient.php`, `app/Console/Commands/RunIngestionPipeline.php`, `tests/Feature/IngestionPipelineCommandTest.php` (modify).
- `deploy/` not affected (the scheduled event already runs the pipeline).

## Visual Design Impact

- UI involved: no.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — "Patterns Path" (engine detects, Laravel replaces, pipeline stage, endpoint/command names).
- `CONSTRAINTS.md`: update — fixed vocabulary and parameters, as-of + 10-session confirmation window, no targets, replace semantics, unique key, offline tests.
- `CONTEXT.md`: update — Chartist Pattern is now in scope for the four listed types; define `forming`/`confirmed` and Breakout level.
- `AGENTS.md`: update — MVP scope guard: chartist patterns no longer "later" for these four types.
- `docs/domain-model.md`: update — Chart Pattern entity and lifecycle.

## Implementation Plan

1. Swing detection + tests. 2. One detector per type + positive/near-miss tests. 3. `detect_patterns`, models, endpoint. 4. Migration/model/factory. 5. Client + command + feature tests. 6. Pipeline stage + test. 7. Docs, `init.ps1`, evidence.

## Implementation Tasks

- [ ] `swings.py` with strict k=5 swing highs/lows (ties are not swings) and tests.
- [ ] Detectors for the four types with the parameter table as constants; positive, near-miss and invalidation tests per type.
- [ ] Status logic (`forming`/`confirmed`, 10-session window) and "latest instance per type".
- [ ] `POST /patterns/detect` + `422` on malformed input.
- [ ] `chart_patterns` migration (round-trip), model, factory, relation.
- [ ] `EngineClient::detectPatterns` and `patterns:detect` (fetch-before-delete, transaction, exit codes as `signals:detect`).
- [ ] Pipeline stage after signals; extend the pipeline test.
- [ ] Docs listed above; `PROGRESS.md`, `feature_list.json`.

## Verification Plan

- `engine\.venv\Scripts\python.exe -m pytest -q` and `-m ruff check .`: pattern tests pass offline.
- `php artisan test`: `DetectPatternsCommandTest` (store, idempotent, status change, disappear, failure-safe, target resolution) and pipeline test.
- `php artisan migrate`, `migrate:rollback`, `migrate` round-trip; the test-pgsql CI job passes (PostgreSQL).
- `php artisan list` shows `patterns:detect`; `.\init.ps1` exits 0 without starting servers.
- Optional local smoke: run the engine and `patterns:detect --universe=sp500` on the dev DB; record counts per type/status and spot-check 3 instances visually against the chart (no production run in this slice).
- Persistent E2E: none exists; CLI/engine flow covered by engine and feature tests.

## Evidence To Capture

- Test names per type (positive, near miss, invalidation, status), command/pipeline test output, migration round-trip, `init.ps1` exit 0, optional smoke counts.

## Key Implementation Risks

- **False positives/negatives:** geometric rules are heuristic; keep them explicit and test-covered, do not silently tune. Record smoke counts so a later spec can calibrate.
- **Look-ahead:** swing points need 5 future bars; the as-of logic must never use bars after `i`.
- **Performance:** 500 instruments × 330 bars is cheap in stdlib, but avoid O(n³) pair scans (limit candidate pairs by the distance windows).
- **Scope:** no UI, no alerts, no targets.

## Validator Checklist

- [ ] Only the four types; parameters match the table; no targets stored or computed.
- [ ] Each type has positive, near-miss and invalidation tests; swings ignore ties and never look ahead.
- [ ] Replace semantics, idempotence and failure safety proven; unique key exists.
- [ ] Pipeline runs the stage after signals; engine has no DB access; no new Python dependencies.
- [ ] Docs updated; `init.ps1` exit 0; `feature_list.json` and `PROGRESS.md` updated.
