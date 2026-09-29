# Feature Implementation Spec: Orchestrate a full Ingestion Run

## Source Feature

- `id`: `ingestion-run-orchestration`
- `area`: `ingestion`
- `depends_on`: `ingestion-scraper-eod`
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Process a whole universe in one Ingestion Run that records an overall status plus a per-instrument success/failure ledger, and can be re-run for just the failed instruments. A partial failure must be recorded, not crash the run.

## Non-Goals

- No indicator/signal computation.
- No scheduling or the admin panel/telemetry UI (those are `ingestion-scheduler` / `admin-ingestion-panel`).
- No API endpoints for runs.
- No multi-universe breadth beyond selecting an existing universe by slug.
- No live network in tests.

## Job Story

When I ingest market data,
I want to run the ingestion across an entire universe and see exactly which instruments succeeded or failed,
so I can trust the dataset and cheaply re-run only what failed.

## Users And Permissions

- Developer/Operator (CLI): triggers a run. No end-user roles involved.

## Acceptance Scenarios

### Scenario 1: A run over a universe completes

Given a universe with instruments and a working source,
When the ingestion run command executes,
Then one `IngestionRun` exists with status `completed`, `total`/`succeeded`/`failed` counts, and one success item per instrument with the number of bars stored.

### Scenario 2: Partial failure is recorded, not a crash

Given some instruments succeed and some fail (engine error),
When the run executes,
Then it finishes with status `partial`, the failing instruments have `failed` items with a message, the successful ones have stored bars, and the command exits without an unhandled crash.

### Scenario 3: All-failure status

Given every instrument fails,
When the run executes,
Then the run status is `failed`.

### Scenario 4: Re-run failed instruments only

Given a `partial` (or `failed`) run,
When the re-run command is used for that run,
Then a new run is created that processes only the previously failed instruments, and previously succeeded instruments are not reprocessed.

## Repository Research

### Files Inspected

- `app/Console/Commands/ScrapeInstrument.php` — current single-ticker command and its upsert logic.
- `app/Services/Engine/EngineClient.php` — `eodBars(ticker)` HTTP client.
- `app/Models/Instrument.php`, `DailyBar.php`, `Universe.php` — relationships and `#[Fillable]`.
- `database/migrations/*_create_daily_bars_table.php` — `(instrument_id, date)` unique, decimal casts.
- `database/seeders/Sp500UniverseSeeder.php` / `database/data/sp500.csv` — the `sp500` universe exists after seeding.
- `tests/Feature/ScrapeInstrumentCommandTest.php` — `Http::fake` patterns and the date-normalization finding.
- `docs/domain-model.md` — Ingestion Run lifecycle `queued -> running -> completed` / `failed` / `partial`.
- `ARCHITECTURE.md`, `CONSTRAINTS.md` — Laravel owns the DB and orchestrates; engine returns bars.

### Environment Findings (probed, not assumed)

- No `ingestion_runs` / items tables exist; the domain model describes the lifecycle but the schema was deferred.
- The single-ticker path (`EngineClient` + `DailyBar::updateOrCreate`) already works and is tested with fakes.
- The live source is blocked from this environment, so runs are developed/verified against `Http::fake` and the controlled-error path.
- Tests use SQLite `:memory:` + `RefreshDatabase`.

### Existing Patterns To Follow

- Ledger-style tables (status + counts + timestamps) with enum casts.
- Commands in `app/Console/Commands`; extract shared logic into a service.
- `Http::fake` for engine responses; never hit the network in tests.
- Idempotent bar upsert keyed on `Carbon::parse($date)->startOfDay()`.

### Current Gaps

- No run/ledger storage, no orchestration command, no shared ingestion service.

## Technical Approach

1. **Schema.** Two migrations:
   - `ingestion_runs`: `status` (string), `universe_id` nullable FK, `started_at`/`finished_at` (nullable timestamps), `total`/`succeeded`/`failed` (unsigned ints, default 0), `timestamps`.
   - `ingestion_run_items`: `ingestion_run_id` FK cascade, `instrument_id` FK cascade, `status` (string), `bars_stored` unsigned int default 0, `message` text nullable, `timestamps`, unique `(ingestion_run_id, instrument_id)`.
2. **Enums + models.** `App\Enums\IngestionRunStatus` (`queued`, `running`, `completed`, `failed`, `partial`) and `App\Enums\IngestionRunItemStatus` (`success`, `failed`); `IngestionRun` (belongsTo `Universe`, hasMany items, casts status + dates) and `IngestionRunItem` (belongsTo run/instrument, casts). Follow the Laravel 13 `#[Fillable]`/`casts()` idiom.
3. **Shared service.** Extract `App\Services\Ingestion\InstrumentIngestor::ingest(Instrument $instrument): int` (bars stored) from `ScrapeInstrument` (engine fetch + idempotent `DailyBar` upsert). Refactor `ingestion:scrape` to use it (no behavior change).
4. **Command.** `ingestion:run {--universe=sp500} {--retry=}`:
   - Normal mode: create a run (`running`, `started_at`, `total = instruments count`), process each instrument, record an item (`success` + `bars_stored`, or `failed` + message), accumulate counts, finalize (`completed` if failed=0, `failed` if succeeded=0, else `partial`; set `finished_at`).
   - `--retry=<runId>`: create a NEW run containing only the instruments whose items in `<runId>` are `failed`, then process them the same way.
   - Wrap each instrument in its own try/catch so one failure cannot abort the run; use a transaction per item, not per run.
5. **Tests.** `tests/Feature/IngestionRunTest.php` with `Http::fake`: all-success → `completed`; mixed → `partial` with a failed item message and the good bars stored; all-fail → `failed`; `--retry` creates a new run with only the failed instruments. Assert ledger counts and item rows.
6. **Do not** add scheduling/UI; the scheduler feature calls this command later.

## Expected File Changes

- `database/migrations/*_create_ingestion_runs_table.php`, `*_create_ingestion_run_items_table.php` — create.
- `app/Enums/IngestionRunStatus.php`, `app/Enums/IngestionRunItemStatus.php` — create.
- `app/Models/IngestionRun.php`, `app/Models/IngestionRunItem.php` — create.
- `app/Services/Ingestion/InstrumentIngestor.php` — create (shared).
- `app/Console/Commands/ScrapeInstrument.php` — modify; use the shared service.
- `app/Console/Commands/RunIngestion.php` — create; `ingestion:run`.
- `database/factories/IngestionRunFactory.php`, `IngestionRunItemFactory.php` — create (optional but useful).
- `tests/Feature/IngestionRunTest.php` — create.
- `ARCHITECTURE.md`, `CONSTRAINTS.md` — update.
- `PROGRESS.md`, `feature_list.json` — update with evidence.

## Visual Design Impact

- UI involved: no. `DESIGN.md` is not applicable to this slice.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — record the ingestion ledger (runs/items), the orchestration command and statuses.
- `CONSTRAINTS.md`: update — MUST rules: run statuses and the per-item ledger; per-instrument try/catch (one failure cannot abort a run); Laravel orchestrates; tests offline (`Http::fake`).
- `AGENTS.md`: not needed — no workflow/startup change.
- Other docs: `PROGRESS.md` and `feature_list.json` — update with evidence.

## Key Implementation Risks

- **No partial writes on failure** — each instrument is independent; a failure must still leave the run and other instruments consistent.
- **Status semantics** — `completed` only when `failed=0`; `failed` only when nothing succeeded; otherwise `partial`. Cover all three in tests.
- **Retry scoping** — `--retry` must select only failed items and must not reprocess succeeded instruments; prove with a test.
- **Schema duplication of indicator logic** — do not compute indicators here.
- **Live source blocked** — verify against `Http::fake`; do not depend on the network.

## Implementation Plan

1. Add the two migrations, the two enums and the two models (+ factories).
2. Extract `InstrumentIngestor` and refactor `ingestion:scrape` onto it.
3. Implement `ingestion:run` with `--universe` and `--retry`.
4. Add `IngestionRunTest` (`Http::fake`) covering completed/partial/failed/retry.
5. Verify `php artisan test` and `.\init.ps1`.
6. Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [x] Create `ingestion_runs` and `ingestion_run_items` migrations (with the `(ingestion_run_id, instrument_id)` unique).
- [x] Create the run/item enums and models with casts and relationships.
- [x] Extract `InstrumentIngestor`; refactor `ingestion:scrape` to use it.
- [x] Implement `ingestion:run {--universe=sp500} {--retry=}` with per-instrument try/catch and final status computation.
- [x] Add factories for run/items.
- [x] Add `tests/Feature/IngestionRunTest.php` (completed, partial, failed, retry).
- [x] Run `php artisan migrate`, `php artisan test`, and `.\init.ps1` (exit 0, no server).
- [x] Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Findings (implementer)

- **Shared service returns more than the stored count.** The spec's `InstrumentIngestor::ingest(Instrument): int` could not preserve `ingestion:scrape`'s existing `"Stored N bars ...; skipped M malformed rows"` output on its own. `ingest()` still returns the stored count (and delegates), and an added `ingestDetailed(Instrument): array{stored:int, skipped:int}` carries the skipped count for the single-ticker command. No behavior change.
- **Dedicated empty-response exception.** `App\Exceptions\EmptyIngestionResponseException` signals "engine returned no bars" so the scrape command reports the exact legacy message and the run records a `failed` item, instead of misclassifying an empty payload as success with 0 bars (or catching an unrelated DB `QueryException` via a broad `RuntimeException`).
- **Exit codes (not specified by the spec).** `completed`/`partial` finalize with exit `0`; a `failed` run returns `1`; a pre-flight error (unknown universe slug or `--retry` run id) returns `1` and creates no run row. Per-instrument failures never throw out of the run.
- **`--retry` inherits the run's universe.** The new run copies `previous->universe_id`; a retry with zero failed items finalizes as `completed` with `total = 0`.
- **Test-only detail.** `$this->artisan(..., ['--retry' => $id])` passes the option value as an `int`; the command guards with `$retry !== null && $retry !== ''` and casts, rather than `is_string()`.
- The nullable `ingestion_runs.universe_id` uses `nullOnDelete` (not cascade) so the run ledger survives removing a universe.

## Verification Plan

- `php artisan migrate` / `migrate:rollback` → both new tables create and roll back cleanly.
- `php artisan test` → `IngestionRunTest` passes: run `completed` with items; mixed → `partial` with a failed item message and stored good bars; all-fail → `failed`; `--retry` creates a new run with only the failed instruments. Existing `ScrapeInstrumentCommandTest` stays green after the refactor.
- `php artisan list` → `ingestion:run` registered.
- `.\init.ps1` → Laravel + SPA + engine checks, exit 0, no server started.
- Persistent E2E: none exists; CLI/API flows covered by feature tests. Record the gap.
- Startup script rule: `init.ps1` stays non-blocking and starts no server.

## Evidence To Capture

- Migration up/rollback output.
- `php artisan test` output (IngestionRunTest + suite green, exit 0).
- `php artisan list` showing `ingestion:run`.
- Test evidence for completed/partial/failed and the retry-scoping assertion.
- `.\init.ps1` output (exit 0).
- Confirmation `frontend/`, `engine/`, `alphapulse/` and unrelated schema were not modified.

## Validator Checklist

- [ ] Implementation stays within this feature's scope (no scheduler/admin/UI/indicators/signals/API).
- [ ] Acceptance scenarios pass.
- [ ] Verification evidence is present.
- [ ] Persistent E2E coverage was added/updated when the feature has an observable user/API flow and an E2E harness exists, or the spec explains why it is not needed.
- [ ] `feature_list.json` and `PROGRESS.md` were updated correctly.
- [ ] No unrelated product behavior or extra feature work was added.
- [ ] Run statuses follow the rules (completed/partial/failed) and are proven by tests.
- [ ] The per-instrument ledger records success/failure + bars stored, and one failure cannot abort the run.
- [ ] `--retry` processes only previously failed instruments into a new run.
- [ ] Tests are offline (`Http::fake`); `ingestion:scrape` behavior is unchanged after the refactor.
