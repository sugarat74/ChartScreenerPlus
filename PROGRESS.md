# Progress Log

## Current Verified State

- Repository root: `C:\laragon\www\ChartScreenPlus`
- Standard startup path: `.\init.ps1`
- Standard verification path: `.\init.ps1` — runs Laravel (`php artisan --version`, `php artisan test`), the SPA lint/build when `frontend/` exists, and the engine tests when `engine/` exists (Laravel 13.34.0 on PHP 8.4.8; React 19 + Vite 8 + Tailwind 4 on Node 22; FastAPI on Python 3.10)
- Current next ready feature: `ingestion-scraper-eod`
- Current blocker: none
- Last verified at: 2026-09-29 (`.\init.ps1` exit 0; Laravel 29 tests incl. `Sp500UniverseSeederTest`; SPA lint 0 warnings/errors + build; engine 1 test)

## Session Log

### Session 001

- Date: 2026-09-25
- Goal: Create the minimal startup harness.
- Completed: `AGENTS.md`, `init.ps1`, `PROGRESS.md`, and `feature_list.json` created or updated.
- Verification run: `.\init.ps1` (informational pre-bootstrap run); `feature_list.json` parsed as JSON.
- Evidence captured: every `depends_on` references an existing feature id, no self-references, no cycles.
- Files or artifacts updated: `AGENTS.md`, `init.ps1`, `PROGRESS.md`, `feature_list.json`
- Known risk or unresolved issue: the data source/legality and charting-library decisions block the ingestion and chart features (see `docs/risks-and-open-questions.md`).
- Next best step: start any dependency-ready feature — `repo-scaffold-laravel`, `repo-scaffold-frontend`, or `python-engine-scaffold`.

### Session 002

- Date: 2026-09-29
- Goal: Implement `repo-scaffold-laravel`.
- Completed: Laravel **13.34.0** scaffolded at the repository root (temp dir + selective copy, `--no-scripts`); `.env` + APP_KEY; framework migrations (`users`/`cache`/`jobs`); `init.ps1` converted to a real non-blocking gate; `CONSTRAINTS.md` created; `AGENTS.md` Environment updated; spec updated with the framework-migration finding.
- Verification run: `php -v` (8.4.8), `composer --version` (2.10.3), `php artisan --version` (13.34.0), `php artisan test` (2 passed), `php artisan migrate --force`, serve smoke (`/up` 200, `/` 200), `.\init.ps1` exit 0.
- Evidence captured: recorded in `feature_list.json` under `repo-scaffold-laravel`.
- Files or artifacts updated: Laravel skeleton at root, `init.ps1`, `.gitignore`, `AGENTS.md`, `CONSTRAINTS.md`, `docs/specs/repo-scaffold-laravel.md`, `PROGRESS.md`, `feature_list.json`.
- Known risk or unresolved issue: the next frontend feature should decide the React SPA location to avoid confusion with Laravel's default Vite assets in the root.
- Validator verdict: independent `accept` (reran `.\init.ps1`, `/up` and `/` 200, framework-only migrations). Persisted: `repo-scaffold-laravel` → `accepted`.
- Next best step: `db-schema-market-data`, `app-shell-navigation` or `python-engine-scaffold`.

### Session 003

- Date: 2026-09-29
- Goal: Plan and implement `repo-scaffold-frontend`.
- Completed: SPA scaffolded in **`frontend/`** (create-vite `react-ts`: Vite 8.3.1, React 19.3.0, TS 6.0.2, oxlint) with Tailwind 4.3.3 via `@tailwindcss/vite`; AlphaPulse tokens ported into `frontend/src/index.css` `@theme`; token-styled placeholder `App.tsx`; scripts `dev`/`build`/`lint`/`typecheck`/`preview`; `init.ps1` extended with the SPA lint/build; `ARCHITECTURE.md` created; `CONSTRAINTS.md`/`AGENTS.md` updated.
- Verification run: `npm --prefix frontend run lint` (0 errors), `npm --prefix frontend run build` (dist produced), dev smoke (`GET /` 200 on 5173), `.\init.ps1` exit 0.
- Evidence captured: recorded in `feature_list.json` under `repo-scaffold-frontend`.
- Files or artifacts updated: `frontend/**`, `init.ps1`, `ARCHITECTURE.md`, `CONSTRAINTS.md`, `AGENTS.md`, `docs/specs/repo-scaffold-frontend.md`, `PROGRESS.md`, `feature_list.json`.
- Known risk or unresolved issue: none blocking. Laravel's default Vite assets still exist in the root but are unrelated to the SPA.
- Validator verdict: independent `accept` (reran `.\init.ps1`, dev smoke on 5176, built CSS token check). Persisted: `repo-scaffold-frontend` → `accepted`.
- Next best step: `app-shell-navigation` or `python-engine-scaffold`.

### Session 004

- Date: 2026-09-29
- Goal: Implement `app-shell-navigation`.
- Completed: Added `react-router` **8.4.0** and built the SPA shell. `frontend/src/nav.ts` single-sources the tabs/routes (`NAV_ITEMS`, `DEFAULT_ROUTE`). `frontend/src/router.tsx` builds the data router (`createBrowserRouter`) with `/` → `/screener`, routes `/screener` `/chart` `/admin` `/portal`, and `*` → Not Found inside the shell. `frontend/src/layouts/AppLayout.tsx` renders `AppHeader` + `<Outlet />`; `frontend/src/components/AppHeader.tsx` renders the sticky brand (`αP` + ALPHAPULSE + EOD badge), mono "Mercado Cerrado (EOD)" status, a disabled/inert "Actualizar EOD" primary button, an `Invitado` user pill and `NavLink` tabs with a 2px active underline. Added `PagePlaceholder` plus `ScreenerPage`/`ChartPage`/`AdminPage`/`PortalPage`/`NotFoundPage` token-styled stubs; `main.tsx` now mounts `RouterProvider` and the old placeholder `App.tsx` was deleted. No data, no API calls, no auth, no Copilot.
- Verification run: `npm --prefix frontend run lint` (0 warnings/0 errors, exit 0), `npm --prefix frontend run build` (101 modules, exit 0), dev route smoke on port 5177 (`/`, `/screener`, `/chart`, `/admin`, `/portal`, `/does-not-exist` all 200; `/src/router.tsx` and `/src/main.tsx` transformed 200; router module contains all four routes; `main` uses `RouterProvider`; server tree killed, port released, no orphan vite process), built-CSS/JS token+route check, `.\init.ps1` exit 0 (starts no server).
- Evidence captured: recorded in `feature_list.json` under `app-shell-navigation`.
- Files or artifacts updated: `frontend/package.json`, `frontend/package-lock.json`, `frontend/src/main.tsx`, `frontend/src/nav.ts`, `frontend/src/router.tsx`, `frontend/src/layouts/AppLayout.tsx`, `frontend/src/components/AppHeader.tsx`, `frontend/src/components/PagePlaceholder.tsx`, `frontend/src/pages/*.tsx` (5), `frontend/src/App.tsx` (deleted), `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/specs/app-shell-navigation.md`, `PROGRESS.md`, `feature_list.json`.
- Known risk or unresolved issue: `react-router@8.4.0` declares `engines.node >=22.22.0` while the environment runs Node v22.21.0, so the explicit install emits an `npm warn EBADENGINE`; a bare `npm install react-router` would resolve to 7.18.4. Build and dev smoke pass. Mitigation: upgrade Node to >= 22.22.0 (preferred) or pin `react-router@7.18.4`. Recorded in `CONSTRAINTS.md` and the spec findings.
- Validator verdict: independent `accept` (reran `.\init.ps1` exit 0, lint/build exit 0, dev smoke port 57708 all routes 200, root Vite assets untouched, generated artifacts ignored; engine mismatch accepted as a documented low-risk note). Persisted: `app-shell-navigation` → `accepted`.
- Next best step: `db-schema-market-data` or `python-engine-scaffold`.

### Session 005

- Date: 2026-09-29
- Goal: Plan and implement `python-engine-scaffold`.
- Completed: Created **`engine/`** as a standalone **FastAPI** service on Python 3.10 with its own venv and pinned `requirements.txt`/`requirements-dev.txt`; `engine/app/main.py` exposes `GET /health`; `engine/app/__main__.py` runs uvicorn; `engine/tests/test_health.py` uses `TestClient`; `engine/pyproject.toml` (pytest + ruff) and `engine/.gitignore`. Extended `init.ps1` with the engine test step. Resolved the Laravel <-> engine boundary as HTTP and updated `ARCHITECTURE.md`/`CONSTRAINTS.md`/`AGENTS.md`.
- Verification run: `python --version` (3.10.6); `pytest -q` -> 1 passed (exit 0); `ruff check` -> All checks passed; uvicorn smoke `GET /health` -> 200 `{"status":"ok",...}` on port 8090 with teardown; `.\init.ps1` exit 0 (Laravel + SPA + engine).
- Evidence captured: recorded in `feature_list.json` under `python-engine-scaffold`.
- Files or artifacts updated: `engine/**`, `init.ps1`, `ARCHITECTURE.md`, `CONSTRAINTS.md`, `AGENTS.md`, `docs/specs/python-engine-scaffold.md`, `PROGRESS.md`, `feature_list.json`.
- Known risk or unresolved issue: pytest emits a Starlette deprecation warning (`httpx` -> `httpx2`) from `TestClient`; non-blocking. Data-source/legality for real scraping remains open (`docs/risks-and-open-questions.md`).
- Validator verdict: independent `accept` (reran `pytest -q` 1 passed + `ruff check` clean on Python 3.10.6; `/health` 200 exact JSON via uvicorn 8091 and `-m app` 8090 with teardown; `.\init.ps1` exit 0; pins match requirements files; venv/caches ignored; other surfaces untouched). Persisted: `python-engine-scaffold` → `accepted`.
- Next best step: `db-schema-market-data` or `auth-registration-login`.

### Session 006

- Date: 2026-09-29
- Goal: Implement `db-schema-market-data`.
- Completed: Six Laravel 13 anonymous-class migrations (`universes`, `instruments`, `instrument_universe`, `daily_bars`, `indicator_snapshots`, `signals`) and five Eloquent models using `#[Fillable]` + `casts()` + `HasFactory`. `daily_bars` stores `decimal(12,4)` OHLC + `unsignedBigInteger` volume and is unique per `(instrument_id, date)`; `indicator_snapshots` stores nullable `decimal(12,4)` indicators + `decimal(8,4)` `rvol` and is unique per `(instrument_id, date)`; `signals` has an indexed `type`, nullable `json` `metadata` and an `(instrument_id, date)` index; all domain tables have `timestamps()` and cascade FKs. Five factories with coherent defaults. `tests/Feature/MarketDataSchemaTest.php` (`RefreshDatabase`) covers the round-trip, both unique constraints and the pivot.
- Verification run: `php artisan migrate:fresh` (exit 0, 6 domain tables created), `php artisan migrate:rollback` (exit 0, all 9 rolled back cleanly; re-ran `migrate:fresh` to leave a working DB, `php artisan db:show` lists the 6 domain tables), `php artisan test` (6 passed, 27 assertions, exit 0), `.\init.ps1` (Laravel 13.34.0 + 6 tests + SPA lint 0 errors + SPA build + engine 1 test, exit 0, no server).
- Evidence captured: recorded in `feature_list.json` under `db-schema-market-data`.
- Files or artifacts updated: `database/migrations/2026_09_29_10000{1..6}_*.php`, `app/Models/{Universe,Instrument,DailyBar,IndicatorSnapshot,Signal}.php`, `database/factories/*Factory.php` (5), `tests/Feature/MarketDataSchemaTest.php`, `CONSTRAINTS.md`, `docs/specs/db-schema-market-data.md`, `PROGRESS.md`, `feature_list.json`. `frontend/`, `engine/`, `alphapulse/` untouched.
- Known risk or unresolved issue: the `decimal:4` cast renders fixed 4-decimal strings (`61.5` → `"61.5000"`), recorded in `CONSTRAINTS.md` and the spec; signal-type vocabulary is intentionally only a string until `signals-detect`.
- Validator verdict: independent `accept` (reran `migrate:fresh`/`rollback`/`migrate:fresh` exit 0, `php artisan test` 6 passed / 27 assertions, `.\init.ps1` exit 0 no server; inspected the SQLite schema for both unique indexes, cascade FKs and decimal/json types; verified cascade deletes with `PRAGMA foreign_keys=ON`; other surfaces untouched; `decimal:4` fixed-string behavior confirmed as expected). Persisted: `db-schema-market-data` → `accepted`.
- Next best step: `auth-registration-login` or `universe-sp500-seed`.

### Session 007

- Date: 2026-09-29
- Goal: Implement `auth-registration-login`.
- Completed: Sanctum **v4.3.3** first-party SPA auth (session cookie + CSRF, **no tokens**) via `php artisan install:api --no-interaction` plus a manual `$middleware->statefulApi()` (Laravel 13's `install:api` no longer adds it). `app/Http/Controllers/Auth/AuthController.php` + `routes/api.php`: `POST /api/register` (201 + user), `POST /api/login` (200, `throttle:6,1`, 422 on bad credentials), `POST /api/logout` (`auth:sanctum`, 204), `GET /api/user` (`auth:sanctum`, 200/401). `tests/Feature/AuthTest.php` (10 cases). SPA: Vite dev proxy `/api` + `/sanctum` -> `http://127.0.0.1:8000`; `frontend/src/lib/api.ts` (fetch, `credentials: include`, `X-XSRF-TOKEN` from the cookie, `/sanctum/csrf-cookie` before mutations); auth state in `frontend/src/auth/` (`context.ts`, `AuthContext.tsx`, `useAuth.ts`) bootstrapped from `GET /api/user`; `/login` + `/register` token-styled pages; auth-aware `AppHeader`; `SANCTUM_STATEFUL_DOMAINS` in `.env`/`.env.example`.
- Verification run: `php artisan test` -> **16 passed (70 assertions)**, exit 0; `php artisan route:list --path=api -v` -> 4 auth routes with expected middleware + Sanctum CSRF route; `npm --prefix frontend run lint` -> 0 warnings/0 errors (19 files), exit 0; `npm --prefix frontend run build` -> 108 modules, exit 0; live dev smoke (`php artisan serve` :8000 + Vite :5173 with proxy): register 201 -> `/api/user` 200 -> logout 204 -> `/api/user` 401 -> wrong-password login 422 -> correct login 200 -> `/api/user` 200 -> duplicate register 422, plus `/sanctum/csrf-cookie` 204 and SPA `/`/`/login` 200; `.\init.ps1` exit 0 (Laravel + SPA + engine, no server).
- Evidence captured: recorded in `feature_list.json` under `auth-registration-login`.
- Files or artifacts updated: `composer.json`, `composer.lock`, `bootstrap/app.php`, `config/sanctum.php`, `routes/api.php`, `app/Http/Controllers/Auth/AuthController.php`, `database/migrations/2026_09_29_162539_create_personal_access_tokens_table.php`, `tests/Feature/AuthTest.php`, `.env`, `.env.example`, `frontend/vite.config.ts`, `frontend/src/lib/api.ts`, `frontend/src/auth/{context.ts,AuthContext.tsx,useAuth.ts}`, `frontend/src/components/{AuthField.tsx,AppHeader.tsx}`, `frontend/src/pages/{LoginPage.tsx,RegisterPage.tsx}`, `frontend/src/{main.tsx,nav.ts,router.tsx}`, `ARCHITECTURE.md`, `CONSTRAINTS.md`, `AGENTS.md`, `docs/specs/auth-registration-login.md`, `PROGRESS.md`, `feature_list.json`. `engine/`, `alphapulse/` and the market-data schema untouched.
- Known risk or unresolved issue: no frontend test runner or E2E harness exists, so SPA behavior is verified by typecheck/lint/build plus the live dev smoke (browser rendering itself remains manual). Orphaned dev servers from a previous session were found on ports 8000/5173 and stopped; the smoke user created during verification was deleted from the local dev DB.
- Next best step: independent validation of `auth-registration-login`, then `universe-sp500-seed` (or `auth-roles-admin` once this is accepted).

### Session 008

- Date: 2026-09-29
- Goal: Repair `auth-registration-login` after the independent validator returned `revise` (Medium defect).
- Finding: `POST /api/register` with a non-matching `Origin`/`Referer` follows Sanctum's **non-stateful** path, so `statefulApi()` never attaches a session; the controller still validated, ran `User::create` and then threw `Session store not set on request` → HTTP 500 plus an orphaned user row. `login()` and `logout()` made the same session assumption.
- Completed: `app/Http/Controllers/Auth/AuthController.php` now calls a private `requireStatefulSession()` (`abort_unless($request->hasSession(), 400, 'A stateful session is required.')`) at the very top of `register()`, `login()` and `logout()`, before validation/writes; `register()` wraps `User::create` + `Auth::guard('web')->login()` + `session()->regenerate()` in `DB::transaction()` so no user can be committed without the session step. `tests/Feature/AuthTest.php` gained 4 negative cases (register/login × non-matching `Origin` and no `Origin`/`Referer`) asserting HTTP 400 (not 500), `assertDatabaseCount('users', 0)` + `assertGuest()`.
- Verification run: `php artisan test` -> **20 passed (82 assertions)**, exit 0 (was 16/70); `AuthTest` alone 14 passed (55 assertions); `php artisan route:list --path=api -v` -> 4 auth routes with unchanged middleware; `npm --prefix frontend run lint` -> 0 warnings/0 errors (19 files), exit 0; `npm --prefix frontend run build` -> 108 modules, exit 0; `.\init.ps1` -> Laravel 20 tests (82 assertions) + SPA lint/build + engine 1 test, exit 0, starts no server.
- Evidence captured: recorded in `feature_list.json` under `auth-registration-login` (REPAIR entries).
- Files or artifacts updated: `app/Http/Controllers/Auth/AuthController.php`, `tests/Feature/AuthTest.php`, `CONSTRAINTS.md`, `docs/specs/auth-registration-login.md`, `PROGRESS.md`, `feature_list.json`. SPA client, Vite proxy, the stateful SPA flow, `engine/` and `alphapulse/` unchanged.
- Known risk or unresolved issue: none new. SPA behavior is still covered by typecheck/lint/build plus the earlier live dev smoke (no frontend test runner/E2E harness).
- Status: `passing` again — ready for independent re-validation (not `accepted`).
- Re-validation verdict: independent `accept` (re-ran `php artisan test` 20 passed/82 assertions, `route:list`, SPA lint/build, `.\init.ps1` exit 0 no server; adversarial non-stateful register/login now return 400 with 0 users; stateful proxy flow 201/200/204/401/422/200; passwords hashed/never returned; other surfaces untouched). Persisted: `auth-registration-login` → `accepted`.
- Next best step: `universe-sp500-seed` or `auth-roles-admin`.

### Session 009

- Date: 2026-09-29
- Goal: Implement `auth-roles-admin`.
- Completed: Added `users.role` (string, default `user`, indexed) via `2026_09_29_170000_add_role_to_users_table.php`; `User::ROLE_USER`/`User::ROLE_ADMIN` constants, `User::isAdmin()` and a model-level default `role=user` (the DB default is not hydrated back into new instances, which would otherwise make register/factory echo `role: null`). `role` stays out of `#[Fillable]`. New `App\Http\Middleware\EnsureUserIsAdmin` (403 unless authenticated admin) registered as the `admin` alias in `bootstrap/app.php`. Admin route group in `routes/api.php`: `Route::middleware(['auth:sanctum','admin'])->prefix('admin')` with guard probe `GET /api/admin/ping` returning `{ok:true}`. Out-of-band grant: `php artisan app:make-admin {email}` (promotes an existing account via `forceFill`, fails for unknown email) and `UserFactory::admin()`. New `tests/Feature/AdminAccessTest.php` (7 cases).
- Verification run: `php artisan migrate --force` (role column + `users_role_index` present), `php artisan migrate:rollback --force` then `migrate --force` (clean round-trip), `php artisan test` → **27 passed (101 assertions)**, exit 0; `php artisan route:list --path=api -v` → `GET api/admin/ping` with `Authenticate:sanctum` + `EnsureUserIsAdmin`; `php artisan list` shows `app:make-admin`; manual tinker smoke (create → `role=user`, `app:make-admin` → `role=admin`, then deleted the smoke user); `.\init.ps1` → Laravel 27 tests + SPA lint 0 errors + build + engine 1 test, exit 0, no server started.
- Evidence captured: recorded in `feature_list.json` under `auth-roles-admin`.
- Files or artifacts updated: `database/migrations/2026_09_29_170000_add_role_to_users_table.php`, `app/Models/User.php`, `app/Http/Middleware/EnsureUserIsAdmin.php`, `app/Console/Commands/MakeAdmin.php`, `bootstrap/app.php`, `routes/api.php`, `database/factories/UserFactory.php`, `tests/Feature/AdminAccessTest.php`, `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/user-and-access-model.md`, `docs/specs/auth-roles-admin.md`, `PROGRESS.md`, `feature_list.json`. `engine/`, `frontend/`, `alphapulse/` and the market-data schema untouched.
- Known risk or unresolved issue: none blocking. `GET /api/admin/ping` is deliberately a guard probe, not product behavior; `admin-ingestion-panel` extends the same group. No frontend/E2E harness exists; the API flows are covered at the feature-test level (per the spec). Admin grants are not audit-logged (a future operations concern).
- Status: `passing` — ready for independent validation (not `accepted`).
- Validator verdict: independent `accept` (reran `php artisan test` 27 passed/101 assertions, `route:list`, migrate rollback/migrate round-trip, `.\init.ps1` exit 0 no server; live probe on :8126 torn down → guest 401, session non-admin 403, admin 200; registration `role=admin` → 201 with JSON/DB `role=user` and hashed password not serialized; model default confirmed not to override the DB-hydrated admin role; other surfaces untouched). Persisted: `auth-roles-admin` → `accepted`.
- Next best step: `universe-sp500-seed`.

### Session 010

- Date: 2026-09-29
- Goal: Implement `universe-sp500-seed`.
- Dataset source/date/row count: Wikipedia "List of S&P 500 companies" (https://en.wikipedia.org/wiki/List_of_S%26P_500_companies) component table, generated once on **2026-09-29**, **503 rows**; `exchange` derived from each symbol's NYSE/NASDAQ/CBOE listing link (344/158/1).
- Completed: Committed `database/data/sp500.csv` (header `ticker,company,sector,exchange`, UTF-8 without BOM, minimal RFC 4180 quoting). `database/seeders/Sp500UniverseSeeder.php` parses with `fgetcsv` (no CSV dependency; BOM/blank/quote handling), `updateOrCreate`s the universe by slug `sp500`, `updateOrCreate`s each Instrument by unique ticker (company/sector/exchange/active), and attaches membership with `syncWithoutDetaching` (add-only) inside one `DB::transaction`; source/date/exchange derivation documented in the seeder docblock. Wired into `database/seeders/DatabaseSeeder.php` via `$this->call(...)`.
- Verification run: `php artisan migrate:fresh --force` exit 0; `php artisan db:seed --class=Sp500UniverseSeeder` -> RUN 1 instruments=503 universes=1 pivots=503 name=S&P 500; second run -> RUN 2 unchanged (idempotent); `php artisan db:seed --force` (DatabaseSeeder wiring) -> users=1 instruments=503 universes=1 pivots=503; NVDA spot-check = Nvidia / Information Technology / NASDAQ; `php artisan test` -> **29 passed (123 assertions)**, exit 0; `.\init.ps1` exit 0 (Laravel 29 tests + SPA lint 0 errors + SPA build + engine 1 test, starts no server).
- Evidence captured: recorded in `feature_list.json` under `universe-sp500-seed`.
- Files or artifacts updated: `database/data/sp500.csv`, `database/seeders/Sp500UniverseSeeder.php`, `database/seeders/DatabaseSeeder.php`, `tests/Feature/Sp500UniverseSeederTest.php`, `docs/specs/universe-sp500-seed.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`. `frontend/`, `engine/`, `alphapulse/` and the auth/market-data schema untouched.
- Known risk or unresolved issue: the dataset is a point-in-time snapshot (503 names, 2026-09-29) with no refresh cadence, so index changes need a manual regeneration from the same source; `exchange` is derived from listing links rather than a dedicated source field. Neither blocks MVP.
- Status: `passing` — ready for independent validation (not `accepted`).
- Validator verdict: independent `accept` (reran migrate:fresh + seeder twice → 503/1/503 unchanged; `php artisan test` 29 passed/123 assertions; `.\init.ps1` exit 0 no server; dataset verified 503 unique uppercase tickers, valid UTF-8 no BOM, spot-checks NVDA/AAPL/MSFT/BXP; seeder/tests offline; other surfaces untouched). Low notes: the default `php artisan db:seed` is not re-runnable because it also creates a fixed `test@example.com` user (pre-existing; the idempotent path is `--class=Sp500UniverseSeeder`); a spec wording nit about the Brown–Forman en dash was corrected. Persisted: `universe-sp500-seed` → `accepted`.
- Next best step: `ingestion-scraper-eod` (its data-source/legality open question still stands per `docs/risks-and-open-questions.md`).
