# Feature Implementation Spec: Admin panel to control ingestion

## Source Feature

- `id`: `admin-ingestion-panel`
- `area`: `admin`
- `depends_on`: `ingestion-run-orchestration`, `auth-roles-admin`, `app-shell-navigation` (all `accepted`)
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Give an Admin a real control surface over EOD ingestion: trigger an Ingestion Run, watch it move through `queued -> running -> completed` (or `partial`/`failed`), re-run only the failed instruments, and read the per-instrument run log — all backed by the existing `ingestion_runs` / `ingestion_run_items` ledger. The panel is admin-only in the UI (tab hidden, restricted page state) and **enforced server-side** under the existing `auth:sanctum` + `admin` route group.

## Non-Goals

- No new data source, no scheduler/cron change, no indicator/signal trigger from the panel (the panel triggers the **ingestion stage / ledger**, not `ingestion:pipeline`; the daily scheduler still owns the full pipeline).
- No SSE/WebSockets; the "log stream" is a polled view of the run's items.
- No universe management, instrument add/edit, cache purge, proxy/worker telemetry, or alerts/notifications (the prototype's decorative tiles are not requirements).
- No new DB schema (the ledger already exists); no queue daemon started by `init.ps1`.
- No new auth mechanism; `role` is only surfaced to the SPA for UI visibility.
- No delete/archive of runs, no run pagination UI, no multi-universe bulk actions.

## Job Story

When an EOD run fails or I want to refresh the dataset now,
I want to trigger or re-run ingestion from a panel and follow its status and per-instrument log,
so I can trust the data and cheaply recover the instruments that failed without a terminal.

## Users And Permissions

- Visitor (no session): admin endpoints return **401**; panel tab hidden and page shows a sign-in state.
- Registered non-admin (`role=user`): admin endpoints return **403**; panel tab hidden and page shows a restricted state. Hiding is never access control.
- Admin (`role=admin`): may list runs, trigger a run, retry failed instruments and read the run log.

## Execution Model Decision (critical, explicit)

Chosen: **(c) hybrid — a queued `RunIngestionJob` is the single execution unit, dispatched by the trigger endpoint; the queue connection decides sync vs async.**

- The trigger endpoint resolves the universe (or the failed instruments for a retry), creates the `IngestionRun` row with status `queued` and commits it, then dispatches `App\Jobs\RunIngestionJob` with the run id and instrument ids, and returns the run resource.
- The Job sets the run to `running` (`started_at`) at its start, processes each instrument (per-instrument try/catch) and finalizes (`completed` / `failed` / `partial`, `finished_at`) using the shared runner.
- **Default is worker-free:** `.env.example` changes `QUEUE_CONNECTION` to **`sync`** (`phpunit.xml` already sets `sync`). On `sync`, `dispatch()` executes the Job inline in the same request, so a run always completes with **no queue worker**, tests need none, and `init.ps1` is unchanged and starts no daemon.
- **Async is opt-in:** with `QUEUE_CONNECTION=database` + `php artisan queue:work` (dev/ops command, never started by `init.ps1`) the trigger returns `202` immediately with a `queued` run and the SPA genuinely observes `queued -> running -> completed` by polling.
- **Worker-free safety:** the only way a run stays `queued` is if an operator explicitly chose the database queue and did not start a worker; the default has no such dependency, and the UI treats `queued` as a normal pending state (hint + keep polling), never an error.

**Why not (a) synchronous-only:** it would make the run reach terminal inside the trigger request and never expose `queued`/`running`, failing the observable status requirement. **Why not (b) queue-only:** it would make the feature appear broken in dev without a worker. The hybrid reuses one Job for both, keeps the default safe, and makes tests cover the exact production path.

**Status observation:** the SPA polls `GET /api/admin/ingestion/runs/{id}` on an interval (default ~2500 ms) while the selected run is non-terminal; no queue job-progress API, no push. **Log stream:** the run detail embeds its `ingestion_run_items`; the UI renders them as the log and refreshes by the same poll. **Re-run:** the retry endpoint reuses the exact `ingestion:run --retry=<id>` selection semantics (only previously failed instruments, new run, inherited universe).

## Acceptance Scenarios

### Scenario 1: An admin triggers a run and it progresses through the lifecycle

Given an admin session and a `sp500` universe with instruments,
When the admin `POST`s to trigger a run,
Then a run row exists as `queued` at creation, the job sets it `running` while instruments are processed, and it finishes `completed` with per-instrument items — observable on `GET /api/admin/ingestion/runs/{id}`.

### Scenario 2: A partial run and a re-run of only the failed instruments

Given a run with some failed items (status `partial`),
When the admin retries that run,
Then a **new** run is created with `total` equal to the failed count, only those instruments are requested from the engine, and previously succeeded instruments are not reprocessed.

### Scenario 3: The log stream shows per-instrument results

Given a run (any terminal status),
When the admin reads `GET /api/admin/ingestion/runs/{id}`,
Then the response includes one item per attempted instrument with `ticker`, `status`, `bars_stored` and `message`.

### Scenario 4: Non-admins are denied

Given a guest or a `role=user` session,
When it calls any `/api/admin/ingestion/*` endpoint,
Then it receives 401 (guest) or 403 (non-admin), and the SPA never shows the Admin tab for a non-admin.

### Scenario 5: Invalid trigger / retry input

Given an admin session,
When it triggers a run for an unknown universe slug, or retries an unknown run or a run with no failed items,
Then it receives `422` (unknown universe / no failed items) or `404` (unknown run) and no new run is created.

## Repository Research

### Files Inspected

- `routes/api.php` — existing admin group `Route::middleware(['auth:sanctum','admin'])->prefix('admin')` with `GET /ping`; new endpoints extend this group.
- `app/Console/Commands/RunIngestion.php` — the orchestration to extract: create run, per-instrument try/catch, `statusFor()`, `--universe` / `--retry`.
- `app/Models/IngestionRun.php`, `IngestionRunItem.php`, `app/Enums/IngestionRunStatus.php`, `IngestionRunItemStatus.php` — ledger fields, casts, relationships (`universe()`, `items()`).
- `app/Models/Universe.php` / `Instrument.php` — `instruments()` belongsToMany; `Instrument` has `ticker`.
- `app/Services/Ingestion/InstrumentIngestor.php`, `app/Services/Engine/EngineClient.php` — the ingestion unit to reuse.
- `app/Http/Middleware/EnsureUserIsAdmin.php`, `app/Models/User.php`, `tests/Feature/AdminAccessTest.php` — 401/403 contract and `role` serialization (`/api/user` already returns `role`; `#[Hidden]` hides only password/token).
- `frontend/src/nav.ts`, `router.tsx`, `components/AppHeader.tsx`, `pages/AdminPage.tsx`, `lib/api.ts`, `auth/context.ts` — single-sourced nav, existing `/admin` route/placeholder, fetch+CSRF client, auth state.
- `alphapulse/src/components/AdminScrapingView.tsx` — intent reference: status banner, telemetry tiles, trigger controls, filtered terminal log (~3.5 s poll). Its fake telemetry/proxy/worker/cache widgets are **not** requirements.
- `phpunit.xml` (`QUEUE_CONNECTION=sync`, `RefreshDatabase`, in-memory SQLite), `.env.example` (`QUEUE_CONNECTION=database`), `CONSTRAINTS.md`, `ARCHITECTURE.md`, `DESIGN.md`, `docs/domain-model.md`, `docs/user-and-access-model.md`.

### Existing Patterns To Follow

- Admin controllers/endpoints under the existing group; server-side role enforcement only.
- Service extraction precedent (`InstrumentIngestor` was extracted from `ingestion:scrape` with unchanged behavior) — extract the run loop into a service and keep the command a thin wrapper.
- Feature tests: `RefreshDatabase`, `Http::fake`, `Queue::fake()`, `actingAs(User::factory()->admin()->create())`.
- Controllers return explicit JSON arrays (the repo has no API resource classes yet).
- SPA: token-only Tailwind classes from `frontend/src/index.css`; mono for numbers/logs; hard shadows/2px borders; `lib/api.ts` with CSRF for mutations.

### Current Gaps

- No admin API endpoints beyond `/ping`; run orchestration is CLI-only and private to the command.
- No SPA admin data layer, no `role` on `AuthUser`, no admin-only nav filtering; `AdminPage` is a placeholder.
- No jobs directory; `QUEUE_CONNECTION=database` in `.env.example` would strand a dispatched job without a worker (the hybrid fixes the default).

## Technical Approach

1. **Extract the orchestration** into `App\Services\Ingestion\IngestionRunner` and make `RunIngestion` a thin wrapper (behavior unchanged):
   - `prepareUniverseRun(string $slug): array{run: IngestionRun, instrumentIds: list<int>}` — resolve universe (throw if missing, no run row) then create the run `queued` with `total`.
   - `prepareRetryRun(int $runId): array{run: IngestionRun, instrumentIds: list<int>}` — resolve the referenced run (throw if missing), select its **failed** instrument ids and inherit its `universe_id`, create the `queued` run.
   - `process(int $runId, array $instrumentIds): IngestionRun` — set `running` + `started_at`, loop with per-instrument try/catch recording `success`/`failed` items, finalize with the existing `completed`/`failed`/`partial` rule + `finished_at`.
   - `startUniverseRun()` / `startRetryRun()` = prepare + process synchronously (used by the command, preserving exit-code behavior).
2. **Job** `App\Jobs\RunIngestionJob` (`implements ShouldQueue`) carrying `runId` + `instrumentIds`; `handle(IngestionRunner)` calls `process()`. No retries/backoff (`$tries = 1`) so a failed run is not silently re-executed.
3. **Controller** `App\Http\Controllers\Admin\IngestionRunController` with `index`, `store`, `show`, `retry`; validation and explicit JSON payloads; all under the existing admin group in `routes/api.php`.
4. **Trigger flow:** create `queued` run → `RunIngestionJob::dispatch($run->id, $instrumentIds)` → `$run->refresh()` → respond `202` with the run (terminal when the connection is `sync`). **Retry:** same, but guard the source run (404 unknown; 422 when not terminal or has no failed items).
5. **SPA:** add `role` to `AuthUser`; add `adminOnly` to the Admin nav item and filter in `AppHeader`; build the real `AdminPage` (guard, runs list, trigger/retry, telemetry tiles, polled log stream) plus small admin components; add `adminIngestionApi` to `lib/api.ts`.
6. **Config:** set `QUEUE_CONNECTION=sync` in `.env.example` (and the local `.env`) with a comment that `database` + `php artisan queue:work` enables true async observation.

## API Contract

All under `Route::middleware(['auth:sanctum','admin'])->prefix('admin')` in `routes/api.php`:

- `GET /api/admin/ingestion/runs?limit=20` → `200 {runs: [run]}` (newest first; no items; includes `universe`).
- `POST /api/admin/ingestion/runs` body `{universe?: string}` (default `config('ingestion.universe')` = `sp500`) → `202 {run}`; `422` unknown universe; creates one `queued` run and dispatches the job.
- `GET /api/admin/ingestion/runs/{run}` → `200 {run}` incl. `items` ordered by `instrument.ticker`; `404` unknown run. `run = {id, status, universe:{id,slug,name}|null, started_at, finished_at, total, succeeded, failed}`; `item = {id, ticker, status, bars_stored, message}`.
- `POST /api/admin/ingestion/runs/{run}/retry` → `202 {run}` (the new run); `404` unknown run; `422` when the run is not terminal or has no failed items.
- Guest `401`; authenticated non-admin `403`.

## Expected File Changes

- `app/Services/Ingestion/IngestionRunner.php` — create; extracted orchestration + prepare/process.
- `app/Console/Commands/RunIngestion.php` — modify; thin wrapper over `IngestionRunner` (behavior/output/exit codes unchanged).
- `app/Jobs/RunIngestionJob.php` — create; queued execution unit.
- `app/Http/Controllers/Admin/IngestionRunController.php` — create; index/store/show/retry + payload shaping.
- `routes/api.php` — modify; add the four admin ingestion routes.
- `.env.example` (and local `.env`) — modify; `QUEUE_CONNECTION=sync` with a documented async switch.
- `tests/Feature/AdminIngestionApiTest.php` — create; auth, trigger/queued+dispatch, retry scoping, detail/list, invalid input.
- `tests/Feature/RunIngestionJobTest.php` — create; `running` transition + terminal statuses via `Http::fake`.
- `frontend/src/lib/api.ts` — modify; `role` on `AuthUser`, admin ingestion types + `adminIngestionApi` (CSRF-aware).
- `frontend/src/nav.ts`, `frontend/src/components/AppHeader.tsx` — modify; `adminOnly` + admin filtering.
- `frontend/src/pages/AdminPage.tsx` — modify; real panel (guard, runs, trigger/retry, polling).
- `frontend/src/components/admin/RunStatusBadge.tsx`, `RunTelemetry.tsx`, `RunHistoryTable.tsx`, `RunLogStream.tsx` — create.
- `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json` — update.

## Visual Design Impact

- UI involved: yes. Source of truth: `DESIGN.md` + `alphapulse/src/components/AdminScrapingView.tsx` (intent only).
- Screens/states: `/admin` — admin view (status banner, 4 telemetry tiles, trigger card, run history table, dark terminal log with `ALL/SUCCESS/FAILED` filter chips), plus loading, empty ("sin runs"), error, `queued/running` (pending) and restricted/guest states.
- Tokens only: `#1a1a1a` banner with `4px 4px 0 #ffcc00` shadow, `#ffcc00` primary button, surface tokens, 2px borders, hard shadows, mono for status/tickers/bars/messages.
- New design artifact required: no — the prototype and `DESIGN.md` cover it. Do not surface fake proxy/worker/cache telemetry.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — add "Admin Ingestion Panel" (endpoints, execution model, sync-default/async-opt-in, polling, admin-only UI + server enforcement).
- `CONSTRAINTS.md`: update — add Admin Panel MUST rules (endpoints under the existing group; server-side 401/403; job-as-execution-unit; default `sync` keeps it worker-free; `init.ps1` starts no worker; no SSE; panel triggers ingestion, not the full pipeline; tests offline with `Http::fake`/`Queue::fake`).
- `AGENTS.md`: not needed — no workflow/startup change (`init.ps1` untouched).
- Other docs: `docs/user-and-access-model.md` already states Admin triggers runs and is server-enforced — not needed. `docs/domain-model.md` already defines the `queued/running/...` lifecycle — not needed.

## Key Implementation Risks

- **Scope of the runner extraction:** `RunIngestion` behavior, output and exit codes must not change; `IngestionRunTest` and `ScrapeInstrumentCommandTest` must stay green.
- **Stranded `queued` runs:** only possible with the explicit `database` queue and no worker; default `sync` avoids it; the UI must degrade gracefully.
- **Racy retry:** retrying a still-`running` source run must be rejected; only terminal runs with failed items are retryable.
- **Long sync request:** a large universe in `sync` mode blocks the HTTP request; acceptable for dev/MVP, documented, with the async mode as the mitigation.
- **UI vs server auth:** `role` is exposed for visibility only; every admin call must still be protected by the middleware.
- **JSON size:** the detail embeds up to ~503 items; fine for MVP, but keep the payload field list tight.
- **No frontend test runner:** SPA correctness relies on lint/build + a live dev smoke; record the gap.

## Implementation Plan

1. Extract `IngestionRunner`; refactor `RunIngestion`; run `IngestionRunTest` to confirm unchanged behavior.
2. Add `RunIngestionJob` and the admin controller; wire the routes; set `QUEUE_CONNECTION=sync`.
3. Add `AdminIngestionApiTest` (Queue::fake + Http::fake) and `RunIngestionJobTest` (running + terminal transitions).
4. Add the SPA data layer (`AuthUser.role`, `adminIngestionApi`), admin-only nav filtering, and the `AdminPage` + components.
5. Verify: `php artisan test`, `php artisan route:list --path=api -v`, `npm --prefix frontend run lint`, `npm --prefix frontend run build`, `.\init.ps1`.
6. Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`; optional live smoke (admin session + engine unreachable) showing a run with failure messages in the UI.

## Implementation Tasks

- [ ] Extract `IngestionRunner` (`prepareUniverseRun`, `prepareRetryRun`, `process`, sync `startUniverseRun`/`startRetryRun`) and make `RunIngestion` a thin wrapper with unchanged output/exit codes.
- [ ] Add `App\Jobs\RunIngestionJob` (`ShouldQueue`, `$tries = 1`) calling `IngestionRunner::process`.
- [ ] Add `IngestionRunController` (`index`, `store`, `show`, `retry`) with validation, explicit payloads and the 202/404/422 contract.
- [ ] Register the four routes inside the existing admin group; set `QUEUE_CONNECTION=sync` in `.env.example`/`.env` with the async note.
- [ ] Add `tests/Feature/AdminIngestionApiTest.php` (guest 401, non-admin 403, admin trigger creates `queued` + dispatches, retry reports only failed ids, detail items shape, 422/404 cases).
- [ ] Add `tests/Feature/RunIngestionJobTest.php` (`running` asserted inside an `Http::fake` closure; `completed`/`partial`/`failed`; `started_at`/`finished_at`).
- [ ] Extend `AuthUser` with `role`; add `adminIngestionApi` + types to `lib/api.ts`.
- [ ] Add `adminOnly` to the Admin nav item and filter `NAV_ITEMS` in `AppHeader`; build `AdminPage` (guard, polling, trigger/retry, telemetry, log) + `admin/` components.
- [ ] Run `php artisan test`, `php artisan route:list --path=api -v`, SPA lint/build, `.\init.ps1` (exit 0, no server/worker).
- [ ] Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Verification Plan

- `php artisan test` → `AdminIngestionApiTest`: guest 401 and non-admin 403 on all four endpoints; admin `POST` with `Queue::fake()` creates a `queued` run with `total` = member count and pushes `RunIngestionJob`; `POST` unknown universe → 422, no run; `retry` on a seeded `partial` run creates a new `queued` run and dispatches only the failed instrument ids; `retry` on `completed` → 422; unknown run → 404; `GET` detail includes `items` with `ticker/status/bars_stored/message`; `GET` list returns runs newest-first.
- `php artisan test` → `RunIngestionJobTest`: with `Http::fake`, the run is `running` at the moment the engine is called (assert inside the fake closure) and finishes `completed`, `partial` (mixed) or `failed` (all fail) with `started_at`/`finished_at`; no network.
- `php artisan test` → existing `IngestionRunTest` / `ScrapeInstrumentCommandTest` stay green (runner refactor behavior-preserving).
- `php artisan route:list --path=api -v` → the four routes show `Authenticate:sanctum` + `EnsureUserIsAdmin`.
- `npm --prefix frontend run lint` → 0 warnings/errors; `npm --prefix frontend run build` → success.
- Optional live smoke (manual): Laravel on :8000 + SPA dev server, admin session, engine unreachable → trigger shows `failed`/items with messages; then engine reachable (or `Http::fake` only) — tear down servers and release ports.
- `.\init.ps1` → exit 0 with Laravel tests + SPA lint/build + engine tests; it must remain unchanged and start no server or queue worker.
- Persistent E2E: none exists; no frontend test runner. API/authorization/status behavior is covered by feature tests and the SPA by lint/build + live smoke — record the gap. Startup script rule: non-blocking, no long-running process.

## Evidence To Capture

- `php artisan test` counts for `AdminIngestionApiTest`, `RunIngestionJobTest` and the suite (exit 0).
- The `Queue::fake()` dispatch assertion and the retry "only failed ids" assertion.
- The `running`-during-engine-call assertion and the terminal-status assertions.
- `php artisan route:list --path=api -v` rows for the four admin routes.
- SPA lint/build output.
- `.\init.ps1` output (exit 0) and confirmation it was not changed and no worker/server was started.
- Confirmation `engine/`, `alphapulse/`, unrelated schema and non-admin surfaces were not modified.

## Validator Checklist

- [ ] Scope respected: endpoints live under the existing admin group; server-side 401/403; no new data source, schema, scheduler change or SSE.
- [ ] Acceptance scenarios pass (trigger lifecycle, retry scoping, log items, denial, invalid input).
- [ ] `IngestionRun` is created `queued`, set `running` while processing, then reaches a terminal status; proven by tests.
- [ ] Execution is a single `RunIngestionJob`; with the default `sync` connection no worker is required; `init.ps1` starts no worker/server.
- [ ] Retry reuses `ingestion:run --retry` semantics (only failed instruments, new run, inherited universe) and rejects non-retryable runs.
- [ ] The Admin tab is hidden for non-admins and the page guards, but the API remains the enforcement point.
- [ ] Tests are offline (`Http::fake`, `Queue::fake`) and the runner refactor keeps `ingestion:run` behavior unchanged.
- [ ] `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json` updated correctly.

## Implementation Findings

- **Controller-owned 404, not implicit route-model binding.** The first implementation type-hinted `IngestionRun $run` on `show`/`retry`. A non-admin then hit route-model binding before the `admin` middleware and got `404` instead of the required `403` (the `api` group's `SubstituteBindings` is in Laravel's middleware priority list; the custom `admin` alias is not, so it runs after binding). The actions now take the raw `int $run` route parameter and do `IngestionRun::query()->find($run)` + `abort(404)`, so `auth:sanctum`/`admin` always precede the existence check. `AdminIngestionApiTest` asserts guest 401 / non-admin 403 for all four endpoints, including nonexistent run ids.
- **`QUEUE_CONNECTION` default changed to `sync`.** `.env.example` (and the local `.env`) now ship `sync` with a comment documenting the `database` + `php artisan queue:work` async opt-in. `phpunit.xml` already pinned `sync`; `init.ps1` is unchanged and starts no worker. The `adminIngestionApi.triggerRun` response is terminal under the default, `queued` under a worker.
- **`limit` clamping.** `GET /api/admin/ingestion/runs` accepts `?limit=` and clamps it to `1..100` (default `20`) rather than adding a validation-error contract the spec did not define.
- **`--color-gain` token added.** DESIGN.md documents gains green `#059669` (line 93) but `frontend/src/index.css` had no green token, so status chips/log lines would have invented a hue. It is now `--color-gain` in `@theme` (the only CSS addition; all other styling uses existing tokens).
- **Polling implementation.** The panel polls only while the selected run is non-terminal and stops the interval as soon as a terminal status is rendered; each poll refreshes both the detail and the (cheap) run list. `queued` is rendered as a pending hint, never an error. No SSE/WebSockets/job-progress API.
- **Retry guard placement.** `IngestionRunner::prepareRetryRun` intentionally still creates a run with `total=0` when a finished run has no failures (this preserves the CLI `--retry` behavior); the admin endpoint rejects that case with `422` before calling the runner.
- **Live smoke CSRF note (verification only).** `AuthController::login` calls `session()->regenerate()`, which also rotates the CSRF token, so a live HTTP client must re-fetch `/sanctum/csrf-cookie` after login before a mutation — the SPA already re-reads the `XSRF-TOKEN` cookie on every request, so no product change was needed.
- **No frontend test runner/E2E harness.** SPA correctness is covered by `tsc`/oxlint/build plus the live Laravel+Vite smoke; browser rendering itself remains manual. Recorded as a standing gap.
- **Async serialization not unit-tested.** The default `sync` path is exercised end to end and `Queue::fake()` asserts the dispatch, but a real `database`-queue round trip (job serialized/deserialized by `queue:work`) is not automated; it is opt-in dev/ops behavior, not the default.

