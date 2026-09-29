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

## Engine

- **MUST** keep the Python engine in `engine/` targeting **Python 3.10**, as a **FastAPI** HTTP service with its own venv and `requirements*.txt`. Reason: it is a separate runtime surface and the Laravel <-> engine boundary is HTTP.
- **MUST** invoke the engine venv Python by path (`engine\.venv\Scripts\python.exe`) and run engine commands with `engine/` as the working directory; do not rely on venv activation. Reason: Windows activation is shell-dependent and imports resolve from `engine/`.
- **MUST NOT** commit the venv or Python caches; `engine/.gitignore` covers `.venv/`, `__pycache__/`, `.pytest_cache/`, `.ruff_cache/`. Reason: local state, not source.
- **MUST** pin exact dependency versions in `requirements*.txt`. Reason: reproducible engine installs.

## Harness

- **MUST** keep `init.ps1` a non-blocking gate: it runs the Laravel checks (`php artisan --version`, `php artisan test`), the SPA typecheck/lint/build when `frontend/` exists, and the engine tests when `engine/requirements.txt` exists. It **MUST NOT** start long-running processes such as `php artisan serve`, the Vite dev server or uvicorn.
- **MUST** keep the repository restartable via `.\init.ps1` after every accepted feature.
