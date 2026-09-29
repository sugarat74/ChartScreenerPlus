# Architecture

Runtime surfaces, directory boundaries and dependency direction for ChartScreenPlus.

## Runtime Surfaces

- **Laravel (repository root)** — API, auth and admin. PHP 8.4 / Laravel 13. Owns the database, the framework migrations and Laravel's own Vite assets under `resources/`.
- **React SPA (`frontend/`)** — the trader surface (Screener, Chart, Portal). React 19 + Vite + Tailwind 4 with its own toolchain and dev server.
- **Python engine (`engine/`)** — FastAPI HTTP service that owns scraping, indicators, and (later) signals. Python 3.10 with its own venv and requirements files; exposes `/health`, `GET /eod/{symbol}` (fetch + parse only) and `POST /indicators/compute` (pure indicator math). It has no database access.

## Directory Boundaries

- `frontend/` is a standalone npm project: its own `package.json`, `vite.config.ts`, `tsconfig*.json` and lockfile. It MUST NOT be wired into Laravel's root Vite pipeline.
- Laravel's root `package.json`, `vite.config.js` and `resources/` belong to Laravel assets only.
- `engine/` is a standalone Python project: its own `.venv`, `requirements*.txt` and `pyproject.toml`. It MUST NOT be entangled with Laravel's PHP code.
- `alphapulse/` is a design/intent reference prototype; it is not a product runtime surface.

## SPA Routing And Shell

- The SPA uses **react-router** (`createBrowserRouter` + `RouterProvider`) as a client-side data router; the shell is a layout route (`frontend/src/layouts/AppLayout.tsx`) that renders `AppHeader` plus an `<Outlet />`.
- Route list:
  - `/` -> redirect to `/screener`
  - `/screener` -> Screener placeholder
  - `/chart` -> Chart placeholder
  - `/admin` -> Admin placeholder
  - `/portal` -> Portal placeholder
  - `/login` -> sign-in screen
  - `/register` -> account-creation screen
  - `*` -> token-styled Not Found placeholder (rendered inside the shell)
- Tab labels and paths are single-sourced in `frontend/src/nav.ts` (`NAV_ITEMS`, `DEFAULT_ROUTE`); auth paths are exported there too (`LOGIN_ROUTE`, `REGISTER_ROUTE`) and the router derives child segments from them with `routeSegment`.
- The header (brand, EOD status, inert primary action, user pill) lives in `frontend/src/components/AppHeader.tsx`; it is auth-aware and shows the signed-in user plus a sign-out control, or an "Iniciar sesión" link for a Visitor.
- History routing needs a server-side SPA fallback when the SPA is deployed behind Laravel or another host; the Vite dev server already provides it. Configuration is deferred to deployment work.

## Authentication (First-Party SPA)

- The SPA authenticates with **Laravel Sanctum first-party SPA auth**: an HTTP-only session cookie plus CSRF. **No API tokens / Bearer auth** are issued or used for the SPA. `laravel/sanctum` v4 is installed, `config/sanctum.php` is published, and `bootstrap/app.php` calls `$middleware->statefulApi()`.
- Endpoints (`routes/api.php`, `app/Http/Controllers/Auth/AuthController.php`):
  - `POST /api/register` -> 201 + `{ user }`, creates the account and starts the session.
  - `POST /api/login` -> 200 + `{ user }` (throttled `6,1`); invalid credentials -> 422.
  - `POST /api/logout` (`auth:sanctum`) -> 204, invalidates the session.
  - `GET /api/user` (`auth:sanctum`) -> 200 + `{ user }`, or 401 for a guest.
  - `GET /sanctum/csrf-cookie` (registered by Sanctum) seeds the `XSRF-TOKEN` cookie.
- Login uses `Auth::attempt` on the `web` guard; password hashing comes from the `User` model's `hashed` cast; `#[Hidden]` keeps `password`/`remember_token` out of responses.
- The SPA client is `frontend/src/lib/api.ts` (fetch, `credentials: 'include'`, `X-XSRF-TOKEN` echoed from the `XSRF-TOKEN` cookie, `/sanctum/csrf-cookie` before mutations). Auth state is `frontend/src/auth/` (`AuthContext.tsx` provider, `context.ts`, `useAuth`), bootstrapped from `GET /api/user`.
- **Dev same-origin model:** the Vite dev server proxies `/api` and `/sanctum` to `http://127.0.0.1:8000`, so the browser is same-origin and cookies/CSRF work without CORS. `SANCTUM_STATEFUL_DOMAINS` lists `localhost`, `127.0.0.1`, `localhost:5173` and `127.0.0.1:5173`. Production is expected to serve the SPA and API from the same origin.

## Authorization And Admin Boundary

- Accounts carry a single `role` string on `users.role`, defaulting to `user`. Two roles exist: `User::ROLE_USER` (`user`) and `User::ROLE_ADMIN` (`admin`); `User::isAdmin()` is the only check callers need. Multi-role/RBAC, teams and ownership are out of scope.
- The role is **not** mass-assignable (`role` is deliberately absent from `User`'s `#[Fillable]`), so it can never be set through registration or any request payload. The DB/model default keeps new accounts at `user`.
- Admin is enforced **server-side** by `App\Http\Middleware\EnsureUserIsAdmin` (alias `admin`, registered in `bootstrap/app.php`): the authenticated user must exist and be an admin, otherwise `abort(403)`.
- Admin-only routes live in a dedicated group in `routes/api.php`: `Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')`. Ordering matches the status contract — guests fail `auth:sanctum` with **401**, authenticated non-admins fail `admin` with **403**, admins pass.
- `GET /api/admin/ping` is the current member of that group and exists only as a guard probe (`{ok:true}`); later admin features (`admin-ingestion-panel`) extend the same group. It is not product behavior.
- Admin is granted **out of band only** via `php artisan app:make-admin {email}` (promotes an existing account, fails if the email is unknown) or `UserFactory::admin()` in tests. There is no HTTP path to grant admin.

## Ingestion Path (EOD)

- **Source:** Stooq daily EOD CSV (`https://stooq.com/q/d/l/?s={symbol}.us&i=d`, columns `Date,Open,High,Low,Close,Volume`). It is a free public source with no key; its markup/availability can change and it may serve an HTML anti-bot challenge instead of CSV, so parsing is isolated and strict (a non-CSV payload becomes a controlled upstream error, never bad data).
- **Chinese wall — engine fetches/parses, Laravel persists.** The engine does **not** touch the database. `engine/app/sources/stooq.py` exposes a pure `parse_eod_csv(text)` (fixture-tested) and `fetch_eod(symbol)` (httpx); the `Bar` pydantic model lives in `engine/app/models.py`. `GET /eod/{symbol}` returns `{symbol, bars: [{date, open, high, low, close, volume}]}`; empty/unknown data is `404` and an upstream failure/unusable payload is `502` (controlled JSON `{detail}`).
- **Laravel persistence.** `App\Services\Engine\EngineClient::eodBars($ticker)` calls `Http::get(config('engine.url').'/eod/'.$ticker)->throw()`. The artisan command `ingestion:scrape {ticker}` resolves the `Instrument` by ticker, fetches the bars, and upserts them into `daily_bars` by the unique `(instrument_id, date)` key inside a transaction; it writes nothing when the engine fails. Re-running is idempotent.
- Tests never use the network: the engine parser runs against the committed fixture `engine/tests/fixtures/stooq_nvda.csv`, and Laravel uses `Http::fake`.

### Ingestion Runs (Orchestration Ledger)

- Laravel owns the run ledger: `ingestion_runs` (one row per run: `status`, nullable `universe_id`, `started_at`/`finished_at`, `total`/`succeeded`/`failed` counters) and `ingestion_run_items` (one row per instrument: `status`, `bars_stored`, nullable `message`, unique `(ingestion_run_id, instrument_id)`). Item FKs cascade; `ingestion_runs.universe_id` is nullable with `nullOnDelete` so run history survives a universe removal.
- `App\Enums\IngestionRunStatus` is `queued -> running -> completed`, with `failed` (nothing succeeded) and `partial` (some instruments failed) as the terminal outcomes of a finished run; `App\Enums\IngestionRunItemStatus` is `success`/`failed`. Models `IngestionRun` (belongsTo `Universe`, hasMany items) and `IngestionRunItem` (belongsTo run/instrument) cast the enums and the counters/dates.
- Shared ingestion logic lives in `App\Services\Ingestion\InstrumentIngestor`: `ingest(Instrument): int` returns the bars stored, `ingestDetailed(Instrument)` also returns the skipped malformed-row count. Both wrap the engine fetch + idempotent `daily_bars` upsert; an empty engine payload raises `App\Exceptions\EmptyIngestionResponseException`. `ingestion:scrape` uses `ingestDetailed`, so the single-ticker behavior is unchanged.
- `php artisan ingestion:run {--universe=sp500} {--retry=<runId>}` orchestrates a run: it creates the run row (`running`, `started_at`, `total`), processes each instrument in its own try/catch (one failure cannot abort the run), records a `success` item (with `bars_stored`) or a `failed` item (with `message`), and finalizes (`completed` when `failed=0`, `failed` when `succeeded=0`, otherwise `partial`) with `finished_at`. `--retry` creates a NEW run containing only the previously failed instruments of the referenced run and inherits its `universe_id`; succeeded instruments are never reprocessed.
- Exit codes: `0` for `completed`/`partial`, `1` for a `failed` run or a pre-flight error (unknown universe slug or retry run id, no run row created). Scheduling (`ingestion-scheduler`) and the admin panel (`admin-ingestion-panel`) are separate features and do not exist yet.

## Indicators Path

- **Chinese wall — engine computes, Laravel persists.** The engine receives a bar series and returns one Indicator Snapshot per bar; it has **no** database access. Laravel (the DB owner) persists the snapshots into `indicator_snapshots`.
- **Engine math (`engine/app/indicators/`).** `core.py` holds pure, stdlib-only per-indicator functions over plain lists: `sma` (20/50/200), `ema` (21/55), `rsi` (14, Wilder), `macd` (12/26/9 line/signal/histogram), `adx` (14, Wilder), `bollinger` (20/2, population std dev) and `rvol` (current volume vs the previous 50-session average). `snapshots.py::compute_snapshots(bars)` returns a value-aligned `Snapshot` per bar. No pandas/numpy; the math is deterministic.
- **Insufficient history is `null`, never a wrong value.** Every function returns a value-aligned list and emits `None` wherever the window is not fully available (e.g. `sma200` before bar 200, `macd_signal` before bar 34, `adx` before bar 28, `rvol` before bar 51). `POST /indicators/compute` serializes those as JSON `null`s; pydantic validation rejects a malformed body with `422`.
- **Laravel persistence.** `App\Services\Engine\EngineClient::computeIndicators(array $bars)` POSTs `{bars: [...]}` to `/indicators/compute` and returns `{snapshots: [...]}`. `php artisan indicators:compute {--ticker=} {--universe=}` loads an instrument's `DailyBar`s ordered by date, calls the engine, and upserts one `IndicatorSnapshot` per `(instrument_id, date)` inside a transaction, keying the date with `Carbon::parse($date)->startOfDay()` so re-runs are idempotent. `--ticker` targets one instrument; otherwise `--universe` (default `sp500`) targets every member that has stored bars. Nothing is written when the engine fails.
- Tests never use the network: the engine math/endpoint tests read the committed fixtures (`engine/tests/fixtures/indicator_series.csv`, `indicator_constant.csv`, `stooq_nvda.csv`) and Laravel uses `Http::fake`.

## Dependency Direction

- The SPA talks to Laravel over HTTP (JSON API); the auth endpoints above are the first ones. There is no code sharing between `frontend/` and the Laravel app.
- The Python engine is invoked by Laravel over HTTP (internal service), not directly by the SPA. The engine exposes `/health`, `GET /eod/{symbol}` (fetch + parse) and `POST /indicators/compute` (indicator math); a signals endpoint is added by a later feature. Laravel talks to the engine through `App\Services\Engine\EngineClient` using the `ENGINE_URL` base URL.

## Configuration

- Local dev/test database is SQLite (`database/database.sqlite`, git-ignored).
- Dev servers: Laravel on 8000 (default), SPA on 5173 (Vite default, proxying `/api` + `/sanctum` to `127.0.0.1:8000`), engine on 8090 (`python -m app`).
- `SANCTUM_STATEFUL_DOMAINS` (`.env`/`.env.example`) controls which dev SPA origins Sanctum treats as stateful.
- `ENGINE_URL` (`.env`/`.env.example`, default `http://127.0.0.1:8090`) is the engine base URL used by `EngineClient`; it is read through `config/engine.php`.
- Harness gate: `.\init.ps1` runs the Laravel checks, the SPA typecheck/lint/build, and the engine tests. It starts no server.
