# Architecture

Runtime surfaces, directory boundaries and dependency direction for ChartScreenPlus.

## Runtime Surfaces

- **Laravel (repository root)** — API, auth and admin. PHP 8.4 / Laravel 13. Owns the database, the framework migrations and Laravel's own Vite assets under `resources/`.
- **React SPA (`frontend/`)** — the trader surface (Screener, Chart, Portal). React 19 + Vite + Tailwind 4 with its own toolchain and dev server.
- **Python engine (`engine/`)** — FastAPI HTTP service that will own scraping, indicators and signals. Python 3.10 with its own venv and requirements files; exposes `/health`.

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

## Dependency Direction

- The SPA talks to Laravel over HTTP (JSON API); the auth endpoints above are the first ones. There is no code sharing between `frontend/` and the Laravel app.
- The Python engine is invoked by Laravel over HTTP (internal service), not directly by the SPA. The engine exposes `/health`; endpoints for scraping/indicators/signals are added by later features.

## Configuration

- Local dev/test database is SQLite (`database/database.sqlite`, git-ignored).
- Dev servers: Laravel on 8000 (default), SPA on 5173 (Vite default, proxying `/api` + `/sanctum` to `127.0.0.1:8000`), engine on 8090 (`python -m app`).
- `SANCTUM_STATEFUL_DOMAINS` (`.env`/`.env.example`) controls which dev SPA origins Sanctum treats as stateful.
- Harness gate: `.\init.ps1` runs the Laravel checks, the SPA typecheck/lint/build, and the engine tests. It starts no server.
