# Constraints

Durable MUST / MUST NOT rules for future agents working in this repository.

## Runtime

- **MUST** target **PHP 8.4.8** (Laragon) and the latest **Laravel 13.x**. Reason: this is the installed local runtime; Laravel 11+ requires PHP >= 8.2 and Laravel 13 requires PHP ^8.3.
- **MUST** keep `php` and `composer` on PATH (Laragon PHP 8.4.8, Composer 2.10.3). Reason: `init.ps1`, `artisan` and Composer scripts assume them; `self-update` under `ProgramData` needs admin.
- **MUST NOT** rely on Composer versions older than 2.x for scaffolding. Reason: old Composer emits deprecations and can fail on PHP 8.4.

## Database

- **MUST** use **SQLite** (`pdo_sqlite`, enabled) for local development and tests unless a feature explicitly requires another connection. Reason: it is the enabled default and keeps the gate DB-free of external services.
- **MUST NOT** commit the local SQLite database file; `/database/*.sqlite*` is git-ignored. Reason: it is local state, not source.
- Framework tables (`users`, `cache`, `jobs`, `sessions`) come from Laravel's default migrations. Domain schema (instruments, daily bars, snapshots, signals) is added by later features, not here.

## Frontend

- **MUST** keep the React SPA in `frontend/` as a standalone npm project (own `package.json`, `vite.config.ts`, tsconfigs, lockfile) running on Node v22. Reason: it is a separate runtime surface from Laravel.
- **MUST NOT** add SPA code to Laravel's root Vite pipeline (`resources/`, root `vite.config.js`, root `package.json`). Reason: two independent build pipelines must not be entangled.
- **MUST** style the SPA with the `DESIGN.md` tokens defined in `frontend/src/index.css` (`@theme`); do not invent colors or non-token styling.
- **MUST** navigate between SPA surfaces with **react-router** routes (URL-backed), not component state/tab variables; deep links must resolve. Reason: screens are shareable/deep-linkable and later features (auth, admin) depend on route-based access.
- **MUST** keep route paths and tab labels single-sourced in `frontend/src/nav.ts` (`NAV_ITEMS`, `DEFAULT_ROUTE`) and derive the header `NavLink`s and the router from it. Reason: duplicated strings drift and break the active-tab/route contract.
- **MUST NOT** add a Copilot route or surface to the SPA. Reason: the AI Copilot is explicitly out of MVP scope.
- **MUST** pin `react-router` explicitly instead of a bare `npm install react-router`. Reason: react-router v8 declares `node >=22.22.0` while the local runtime is Node v22.21.0, so npm auto-resolves the bare install to v7; the intended fix is either an explicit `react-router@^8.4.0` install (current, works with an `EBADENGINE` warning) or upgrading Node to >= 22.22.0.

## Harness

- **MUST** keep `init.ps1` a non-blocking gate: it runs the Laravel checks (`php artisan --version`, `php artisan test`) and, when `frontend/` exists, the SPA typecheck/lint/build. It **MUST NOT** start long-running processes such as `php artisan serve` or the Vite dev server.
- **MUST** keep the repository restartable via `.\init.ps1` after every accepted feature.
