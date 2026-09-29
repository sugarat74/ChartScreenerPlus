# Constraints

Durable MUST / MUST NOT rules for future agents working in this repository.

## Runtime

- **MUST** target **PHP 8.4.8** (Laragon) and the latest **Laravel 13.x**. Reason: this is the installed local runtime; Laravel 11+ requires PHP >= 8.2 and Laravel 13 requires PHP ^8.3.
- **MUST** keep `php` and `composer` on PATH (Laragon PHP 8.4.8, Composer 2.10.3). Reason: `init.ps1`, `artisan` and Composer scripts assume them; `self-update` under `ProgramData` needs admin.
- **MUST NOT** rely on Composer versions older than 2.x for scaffolding. Reason: old Composer emits deprecations and can fail on PHP 8.4.

## Database

- **MUST** use **SQLite** (`pdo_sqlite`, enabled) for local development and tests unless a feature explicitly requires another connection. Reason: it is the enabled default and keeps the gate DB-free of external services.
- **MUST NOT** commit the local SQLite database file; `/database/*.sqlite*` is git-ignored. Reason: it is local state, not source.
- Framework tables (`users`, `cache`, `jobs`, `sessions`) come from Laravel's default migrations. Market-data domain tables (`universes`, `instruments`, `instrument_universe`, `daily_bars`, `indicator_snapshots`, `signals`) are created by `db-schema-market-data`.
- **MUST** store prices and indicator values as `decimal` columns (`decimal(12,4)`; `rvol` as `decimal(8,4)`) and cast them with `decimal:4`, never as floats/reals. Reason: monetary/indicator precision must be stable and portable to MySQL/PostgreSQL later; `decimal:4` renders fixed 4-decimal strings (`61.5` -> `"61.5000"`), so tests compare 4-decimal strings.
- **MUST** keep `daily_bars` and `indicator_snapshots` unique per `(instrument_id, date)`. Reason: EOD ingestion upserts one bar/snapshot per instrument per trading day; duplicates would corrupt indicators and signals.
- **MUST** give every market-data domain table `timestamps()` and use `cascadeOnDelete` foreign keys to `instruments`/`universes`. Reason: consistent audit columns and no orphan rows when an instrument or universe is removed.
- **MUST** write schema/persistence tests with `RefreshDatabase` against the in-memory SQLite connection configured in `phpunit.xml`. Reason: tests stay isolated and need no local DB file.
- Market-data Eloquent models **MUST** follow the Laravel 13 style: `#[Fillable([...])]` attributes, a `casts()` method, `HasFactory`, and the relationships defined in `docs/domain-model.md`. Reason: keeps Eloquent code consistent with `app/Models/User.php` and avoids legacy `$fillable` arrays.

## Dataset And Seeding

- The S&P 500 constituent dataset **MUST** live at `database/data/sp500.csv` with the header `ticker,company,sector,exchange` (UTF-8) and one row per constituent. Reason: a committed dataset keeps the universe reproducible without an external list at runtime.
- The dataset **MUST** record its source and generation date in the seeder docblock (`database/seeders/Sp500UniverseSeeder.php`); regenerate it only from a named public source and update the date. Reason: dataset provenance and accuracy are the main risk of this slice.
- Universe seeding **MUST** be offline: the seeder reads only the committed CSV and **MUST NOT** call the network. Reason: seeding and tests must work without external services.
- Universe seeding **MUST** be idempotent: upsert the universe by unique `slug`, instruments by unique `ticker`, and attach membership with `syncWithoutDetaching` (add-only). Reason: re-running must never duplicate instruments/pivots or detach existing members.
- Feature tests that touch the dataset **MUST NOT** use the network and **MUST** derive the expected row count from the CSV rather than hardcoding it. Reason: the dataset is the source of truth for the count.

## Frontend

- **MUST** keep the React SPA in `frontend/` as a standalone npm project (own `package.json`, `vite.config.ts`, tsconfigs, lockfile) running on Node v22. Reason: it is a separate runtime surface from Laravel.
- **MUST NOT** add SPA code to Laravel's root Vite pipeline (`resources/`, root `vite.config.js`, root `package.json`). Reason: two independent build pipelines must not be entangled.
- **MUST** style the SPA with the `DESIGN.md` tokens defined in `frontend/src/index.css` (`@theme`); do not invent colors or non-token styling.
- **MUST** navigate between SPA surfaces with **react-router** routes (URL-backed), not component state/tab variables; deep links must resolve. Reason: screens are shareable/deep-linkable and later features (auth, admin) depend on route-based access.
- **MUST** keep route paths and tab labels single-sourced in `frontend/src/nav.ts` (`NAV_ITEMS`, `DEFAULT_ROUTE`) and derive the header `NavLink`s and the router from it. Reason: duplicated strings drift and break the active-tab/route contract.
- **MUST NOT** add a Copilot route or surface to the SPA. Reason: the AI Copilot is explicitly out of MVP scope.
- **MUST** pin `react-router` explicitly instead of a bare `npm install react-router`. Reason: react-router v8 declares `node >=22.22.0` while the local runtime is Node v22.21.0, so npm auto-resolves the bare install to v7; the intended fix is either an explicit `react-router@^8.4.0` install (current, works with an `EBADENGINE` warning) or upgrading Node to >= 22.22.0.

## Auth

- **MUST** authenticate the SPA with **Laravel Sanctum first-party SPA auth** (session cookie + CSRF) and **MUST NOT** issue or accept API tokens / Bearer auth for the SPA. Reason: the SPA is same-origin with Laravel; tokens would add rotation/expiry surface the product does not need.
- **MUST** call `GET /sanctum/csrf-cookie` before a mutating auth request and send the returned `XSRF-TOKEN` back in the `X-XSRF-TOKEN` header, with `credentials: 'include'` / cookies on every API call. Reason: Sanctum's stateful middleware validates CSRF; omitting it yields HTTP 419.
- **MUST** keep `$middleware->statefulApi()` in `bootstrap/app.php`. Reason: Laravel 13's `php artisan install:api` adds `routes/api.php` and Sanctum but does **not** enable the stateful API middleware, so without this line the API group never starts the session and cookie login silently fails.
- **MUST** keep the Vite dev proxy forwarding `/api` and `/sanctum` to the Laravel dev server (`http://127.0.0.1:8000`) and list the dev SPA origins in `SANCTUM_STATEFUL_DOMAINS`. Reason: same-origin in dev means no CORS + credentialed-cookie complexity.
- **MUST** enforce auth server-side with the `auth:sanctum` middleware on protected API routes; hiding UI is not access control. Reason: the API is reachable independently of the SPA.
- **MUST** fail fast (`abort_unless($request->hasSession(), 400, ...)` or equivalent) at the top of every session-only auth endpoint, before validation or any database write. Reason: Sanctum only attaches the session/CSRF middleware to requests that look like the first-party SPA, so a non-matching `Origin`/`Referer` request otherwise reaches a controller with no session store and dies with a 500 (`Session store not set on request`) after potentially writing a user row.
- **MUST** write session-auth feature tests by sending an `Origin`/`Referer` that matches a `sanctum.stateful` domain (set it explicitly in the test) and by calling `$this->app['auth']->forgetGuards()` between simulated requests. Reason: Sanctum only applies the session middleware to stateful-looking requests, and a single test app instance caches guard users across requests unlike real HTTP.
- Passwords **MUST** be hashed via the `User` model's `hashed` cast and **MUST NOT** appear in JSON responses (the model's `#[Hidden]` keeps `password`/`remember_token` out).

## Authorization

- `users.role` **MUST** stay limited to `user`/`admin`, default `user`, and **MUST NOT** be added to `User`'s `#[Fillable]`. Reason: `role` is the privilege boundary; keeping it out of mass assignment makes privilege escalation via a request payload structurally impossible (registration validates only `name`/`email`/`password` and the DB/model default applies).
- `role` **MUST** only change **out of band**: the `php artisan app:make-admin {email}` command for an existing account, or `UserFactory::admin()` in tests. There **MUST NOT** be any HTTP endpoint that sets or accepts a role. Reason: admin is operator-granted, never self-service.
- Admin endpoints **MUST** be enforced server-side by the `admin` middleware alias (`App\Http\Middleware\EnsureUserIsAdmin`), applied **after** `auth:sanctum` on the admin route group. Guests **MUST** get 401 and authenticated non-admins **MUST** get 403; hiding admin UI is not access control. Reason: the API is reachable independently of the SPA, and the middleware ordering defines the status contract.
- New admin surfaces **MUST** be added under the existing `Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')` group rather than registering a separate guard. Reason: one boundary keeps the 401/403 contract consistent.
- **MUST NOT** expand beyond the two roles or introduce a permissions matrix/teams/ownership/revocation here. Reason: multi-role RBAC is out of scope for this feature.

## Engine

- **MUST** keep the Python engine in `engine/` targeting **Python 3.10**, as a **FastAPI** HTTP service with its own venv and `requirements*.txt`. Reason: it is a separate runtime surface and the Laravel <-> engine boundary is HTTP.
- **MUST** invoke the engine venv Python by path (`engine\.venv\Scripts\python.exe`) and run engine commands with `engine/` as the working directory; do not rely on venv activation. Reason: Windows activation is shell-dependent and imports resolve from `engine/`.
- **MUST NOT** commit the venv or Python caches; `engine/.gitignore` covers `.venv/`, `__pycache__/`, `.pytest_cache/`, `.ruff_cache/`. Reason: local state, not source.
- **MUST** pin exact dependency versions in `requirements*.txt`. Reason: reproducible engine installs.
- **MUST** keep `httpx` as a pinned **runtime** dependency in `engine/requirements.txt` (not only in `requirements-dev.txt`). Reason: `app/sources/stooq.py` uses it when the process runs, so it is not test-only.

## Ingestion

- The engine **MUST** only fetch and parse source data and **MUST NOT** write the database. Laravel **MUST** own persistence (the Chinese wall for ingestion). Reason: one database owner; the engine is a stateless HTTP service.
- The Laravel <-> engine base URL **MUST** come from `ENGINE_URL` via `config/engine.php` (default `http://127.0.0.1:8090`); callers **MUST** go through `App\Services\Engine\EngineClient` rather than hardcoding URLs. Reason: one place to reconfigure the engine for other environments.
- EOD persistence **MUST** be idempotent on the unique `(instrument_id, date)` key and **MUST NOT** write when the engine call fails. Reason: re-running ingestion must not duplicate bars or leave partial data.
- Upserts into `daily_bars` **MUST** key the `date` column with a date object (e.g. `Carbon::parse($date)->startOfDay()`), not a raw `Y-m-d` string. Reason: the model's `date` cast stores/compares `Y-m-d H:i:s`, so a string key misses the stored row and violates the unique index on re-run.
- Engine tests **MUST** be offline and read the committed fixture (`engine/tests/fixtures/stooq_nvda.csv`); Laravel engine tests **MUST** use `Http::fake`. No test may hit the network. Reason: the external source is unreliable; tests must be deterministic.
- The Stooq source is a **single, fragile, unofficial** public source: parser/endpoint behavior **MUST** stay isolated (`app/sources/stooq.py`) and strict (a non-CSV payload raises, and the endpoint answers `404` for no data and `502` for upstream failure) so a source change or anti-bot block becomes a controlled error, not bad data or a crash. Reason: the source can change markup, block IPs (observed `Access denied` on the CSV download path) or throttle; live fetching is an integration concern.
- Every universe ingestion **MUST** be recorded as one `ingestion_runs` row plus one `ingestion_run_items` row per attempted instrument (status + `bars_stored`/nullable `message`, unique per `(ingestion_run_id, instrument_id)`). Reason: the ledger is the source of truth for which instruments succeeded, and `admin-ingestion-panel`/`ingestion-scheduler` build on it.
- A finished run's status **MUST** follow exactly: `completed` when `failed = 0`, `failed` when `succeeded = 0`, otherwise `partial`; `queued`/`running` are transient. Reason: `docs/domain-model.md` defines the lifecycle and the admin re-run flow depends on it.
- Each instrument **MUST** be processed inside its own try/catch (and each bar upsert/item write in its own transaction), so one instrument failure is recorded as a failed item and **MUST NOT** abort the run or block other instruments. Reason: the source is unreliable per-symbol; a partial failure must be recorded, not crash the run.
- Re-running failures **MUST** use `ingestion:run --retry=<runId>`, which creates a NEW run containing only the previously failed instruments and **MUST NOT** reprocess succeeded instruments. Reason: cheap, correct retries without redoing successful work.
- Run/item enums **MUST** stay the two backed enums (`IngestionRunStatus`, `IngestionRunItemStatus`) and models **MUST** cast them; **MUST NOT** store raw status strings or add extra statuses here without updating `docs/domain-model.md`. Reason: one vocabulary for the ledger, the CLI and the future admin panel.

## Harness

- **MUST** keep `init.ps1` a non-blocking gate: it runs the Laravel checks (`php artisan --version`, `php artisan test`), the SPA typecheck/lint/build when `frontend/` exists, and the engine tests when `engine/requirements.txt` exists. It **MUST NOT** start long-running processes such as `php artisan serve`, the Vite dev server or uvicorn.
- **MUST** keep the repository restartable via `.\init.ps1` after every accepted feature.
