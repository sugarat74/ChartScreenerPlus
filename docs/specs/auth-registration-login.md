# Feature Implementation Spec: Register and log in a user

## Source Feature

- `id`: `auth-registration-login`
- `area`: `auth`
- `depends_on`: `repo-scaffold-laravel`
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Let a Visitor create an account and sign in, and give Registered Users an authenticated session the SPA can rely on. This adds Laravel Sanctum first-party SPA auth (session cookie + CSRF) with register/login/logout/user endpoints, plus minimal login and register screens in the React SPA and authenticated state in the header.

## Non-Goals

- No role/admin enforcement (that is `auth-roles-admin`).
- No password reset, email verification, remember-me, 2FA or social login.
- No protected resources yet: no watchlist, saved screeners or per-route authorization (that is `access-control-guard` and later features).
- No API tokens / Bearer auth, no mobile clients.
- No profile editing.
- No change to the engine or market-data schema.

## Job Story

When I visit the app,
I want to create an account or sign in and stay signed in across the SPA,
so I can later use registered-only features (saved screeners, watchlist) against my own data.

## Users And Permissions

- Visitor (anonymous): can open the login and register screens.
- Registered User: has an authenticated session; the header shows their identity and a sign-out control.
- No roles are assigned here; every new account is a plain user.

## Acceptance Scenarios

### Scenario 1: Register

Given a Visitor on the register screen,
When they submit a valid name, email and password,
Then the account is created, they become authenticated, and the SPA shows them signed in.

### Scenario 2: Register validation

Given a Visitor,
When they submit an already-registered email, a weak password or mismatching confirmation,
Then the request is rejected with validation errors (HTTP 422) and no account is created.

### Scenario 3: Log in and log out

Given an existing account,
When the user logs in with correct credentials, then logs out,
Then login returns the user and an authenticated session, and logout invalidates it so `GET /api/user` returns 401 afterwards.

### Scenario 4: Wrong credentials

Given an existing account,
When the user submits a wrong password,
Then login fails with an auth error and no session is established.

### Scenario 5: Current user

Given an authenticated session,
When the SPA requests `GET /api/user`,
Then it returns the authenticated user; when unauthenticated it returns 401.

## Repository Research

### Files Inspected

- `composer.json` — only framework + tinker; **Sanctum not installed**.
- `routes/web.php` — only `/` (welcome); **no `routes/api.php`**.
- `config/auth.php` — session guard + eloquent provider defaults.
- `app/Models/User.php` — Laravel 13 style: `#[Fillable]`, `#[Hidden]`, `casts()` (password `hashed`).
- `database/migrations/..._create_users_table.php`, `database/factories/UserFactory.php` — users schema + factory available.
- `phpunit.xml` — tests run on SQLite `:memory:`.
- `frontend/src/nav.ts`, `router.tsx`, `layouts/AppLayout.tsx`, `components/AppHeader.tsx` — shell, routes and header user pill.
- `frontend/vite.config.ts` — Vite dev server (no proxy yet).
- `ARCHITECTURE.md`, `CONSTRAINTS.md`, `DESIGN.md` — surfaces, rules and tokens.

### Environment Findings (probed, not assumed)

- Laravel 13.34.0 / PHP 8.4.8; SQLite local DB; SPA on Vite 8 (Node 22).
- No Sanctum, no `config/sanctum.php`, no `config/cors.php`, no auth controllers.
- The SPA currently renders a static "Invitado" pill and no login route.

### Existing Patterns To Follow

- Laravel 13 model idioms (`#[Fillable]`, `casts()`); anonymous-class migrations.
- Tests use `RefreshDatabase` on in-memory SQLite.
- SPA: react-router routes from `frontend/src/nav.ts`; styling via `DESIGN.md` tokens.
- Vite dev server and Laravel are different origins unless proxied.

### Current Gaps

- Auth backend (Sanctum, routes, controllers) missing.
- SPA has no auth state, API client or login/register screens.
- Dev must bridge the two origins (Vite proxy or CORS + credentials).

## Technical Approach

1. **Install Sanctum** via `php artisan install:api --no-interaction` (adds `laravel/sanctum`, `routes/api.php`, the migration and the stateful middleware wiring). Keep the SPA cookie/session flow; tokens are unused.
2. **Endpoints** in `routes/api.php`:
   - `POST /api/register` — `name, email, password, password_confirmation`; validate (unique email, `Password` rules, confirmed); create the user; log in; return 201 + user.
   - `POST /api/login` — `email, password`; `Auth::attempt` in the `web` guard; regenerate session; return user (throttled).
   - `POST /api/logout` (auth) — guard logout, invalidate session, regenerate CSRF; return 204.
   - `GET /api/user` (auth) — return the authenticated user.
   - Use a small `AuthController` (or invokable controllers) returning JSON; no blade views.
3. **Session across origins (dev):** add a Vite dev proxy in `frontend/vite.config.ts` forwarding `/api` and `/sanctum` to `http://127.0.0.1:8000` so the browser is same-origin in dev (cookies/CSRF work without CORS complexity). Production is expected same-origin behind Laravel. Set `SANCTUM_STATEFUL_DOMAINS` to include `localhost:5173,127.0.0.1:5173` for the proxied dev flow.
4. **SPA auth client:** `frontend/src/lib/api.ts` using `fetch` with `credentials: 'include'` and the `X-XSRF-TOKEN` header read from the `XSRF-TOKEN` cookie; call `GET /sanctum/csrf-cookie` before login/register.
5. **SPA auth state:** a small `AuthProvider`/context (`frontend/src/auth/AuthContext.tsx`) exposing `user`, `login`, `register`, `logout`, and bootstrapping from `GET /api/user`.
6. **SPA screens/routes:** add `/login` and `/register` routes and pages; the header shows the signed-in user + sign-out when authenticated, otherwise a "Iniciar sesión" link. Follow `DESIGN.md`.
7. **Keep routes single-sourced:** extend `frontend/src/nav.ts` (or a sibling) consistently; do not add a Copilot or unrelated route.

## Expected File Changes

- `composer.json`, `composer.lock` — modify; add `laravel/sanctum`.
- `config/sanctum.php`, `config/cors.php` (if published), `.env`/`.env.example` — modify; stateful domains.
- `routes/api.php` — create; auth routes.
- `bootstrap/app.php` — modify only if `install:api` requires middleware wiring.
- `app/Http/Controllers/Auth/AuthController.php` (or `RegisterController`/`LoginController`/`LogoutController`) — create.
- `app/Http/Requests/*` (optional) — create; validation.
- `database/migrations/*_create_personal_access_tokens_table.php` — created by `install:api` (unused but present).
- `tests/Feature/AuthTest.php` — create; register/login/logout/user tests.
- `frontend/vite.config.ts` — modify; dev proxy.
- `frontend/src/lib/api.ts` — create; fetch client.
- `frontend/src/auth/AuthContext.tsx` — create; auth state.
- `frontend/src/pages/LoginPage.tsx`, `frontend/src/pages/RegisterPage.tsx` — create.
- `frontend/src/router.tsx`, `frontend/src/components/AppHeader.tsx`, `frontend/src/nav.ts` — modify; routes + auth-aware header.
- `ARCHITECTURE.md`, `CONSTRAINTS.md`, `DESIGN.md` (only if new UI states need guidance), `PROGRESS.md`, `feature_list.json` — update.

## Visual Design Impact

- UI involved: yes.
- Design source: `DESIGN.md` (source of truth).
- Screens or states affected: `/login`, `/register`, and the header auth state (signed-in user + sign-out vs "Iniciar sesión").
- New design artifact required: no — reuse existing tokens (bordered inputs, accent primary button, mono labels, error text in the loss color).
- Handle form states: idle, submitting, validation error, server error, success.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — record Sanctum first-party SPA auth, the auth endpoints and the dev proxy/same-origin model.
- `CONSTRAINTS.md`: update — MUST rules: SPA auth is Sanctum cookie/session (no tokens); credentials `include` + CSRF for auth calls; dev uses the Vite proxy; auth is enforced server-side.
- `AGENTS.md`: update — one line noting the auth dev flow (proxy + `SANCTUM_STATEFUL_DOMAINS`).
- `DESIGN.md`: update only if new component states need guidance; otherwise reference the existing tokens.
- Other docs: `PROGRESS.md` and `feature_list.json` — update with evidence.

## Key Implementation Risks

- **Cross-origin sessions** — cookies/CSRF are the fragile part. Prefer the Vite dev proxy over CORS to keep the browser same-origin in dev.
- **CSRF flow** — must call `/sanctum/csrf-cookie` and send `X-XSRF-TOKEN`; forgetting it yields 419 errors.
- **Sanctum installation breadth** — `install:api` touches composer, routes, config and a migration; keep the diff scoped and do not add unrelated API surface.
- **Scope** — do not add role checks, protected resources or password reset here.
- **Frontend has no test runner** — SPA auth is verified by typecheck/build plus a manual dev smoke; note the gap.

## Implementation Plan

1. `php artisan install:api` and confirm Sanctum wiring; configure stateful domains.
2. Implement the auth controller(s) and `routes/api.php` endpoints.
3. Add `tests/Feature/AuthTest.php` (register/login/logout/user, including validation and wrong-credentials).
4. Add the Vite dev proxy; implement the SPA API client and auth context.
5. Add `/login` and `/register` pages; make the header auth-aware.
6. Verify backend tests, SPA lint/build, and a manual dev smoke; run `.\init.ps1`.
7. Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `AGENTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [x] Install and configure Sanctum (stateful domains, session/CSRF).
- [x] Add `POST /api/register`, `POST /api/login`, `POST /api/logout`, `GET /api/user`.
- [x] Add `tests/Feature/AuthTest.php` (valid/invalid register, valid/invalid login, logout, current user).
- [x] Add the Vite dev proxy for `/api` and `/sanctum`.
- [x] Add the SPA API client + `AuthContext` and bootstrap from `/api/user`.
- [x] Add `/login` and `/register` pages and make `AppHeader` auth-aware.
- [x] Verify `php artisan test`, `npm --prefix frontend run lint`/`build`, and a manual register/login/logout smoke; `.\init.ps1` exit 0.
- [x] Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `AGENTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Findings

- **Laravel 13 `install:api` does not enable `statefulApi()`.** It adds `laravel/sanctum` (v4.3.3), `routes/api.php`, the `personal_access_tokens` migration, `config/sanctum.php` and registers the api routing in `bootstrap/app.php`, but the api middleware group stays non-stateful. `$middleware->statefulApi()` was added manually; without it the API group never starts a session and SPA cookie login fails.
- **`config/cors.php` was not published** (not needed: the dev Vite proxy keeps the browser same-origin; no CORS configuration required for this feature).
- **`SANCTUM_STATEFUL_DOMAINS`** is set to `localhost,127.0.0.1,localhost:5173,127.0.0.1:5173` in `.env` and `.env.example`. Setting the env var replaces Sanctum's built-in defaults, so the base `localhost`/`127.0.0.1` hosts are included explicitly.
- **Testing Sanctum statefulness:** `EnsureFrontendRequestsAreStateful::fromFrontend` requires a matching `Referer`/`Origin`. `AuthTest::setUp` sets `sanctum.stateful` explicitly and sends `Origin: http://localhost:5173`; the logout test calls `$this->app['auth']->forgetGuards()` between simulated requests because one test app instance caches resolved guards (real HTTP requests get fresh containers).
- **SPA context split:** the auth context object lives in `frontend/src/auth/context.ts` so `frontend/src/auth/AuthContext.tsx` only exports its component; exporting the context object from a component file trips the repo's `react/only-export-components` lint rule (baseline is 0 warnings).
- The `/sanctum/csrf-cookie` route is registered by Sanctum with the `web` middleware group; `ValidateCsrfToken` adds the `XSRF-TOKEN` cookie even for its JSON 204 response.
- No frontend test runner or E2E harness exists; SPA behavior is covered by typecheck/lint/build plus the live dev smoke (documented in `PROGRESS.md`).
- **Session-only endpoints must fail fast (validator repair):** because `statefulApi()` only attaches the session/CSRF middleware when Sanctum matches the request as the first-party SPA, a non-matching `Origin`/`Referer` request reaches the controller without a session store. `register()` previously validated, ran `User::create` and then threw `Session store not set on request` (HTTP 500 + an orphaned user row); `login()`/`logout()` had the same session assumption. `AuthController` now calls `requireStatefulSession()` (`abort_unless($request->hasSession(), 400, ...)`) before any validation/write, and `register()` wraps create + login + regenerate in `DB::transaction()`. `AuthTest` adds four negative cases (non-matching `Origin` and no `Origin`/`Referer` for both register and login) asserting HTTP 400 and `assertDatabaseCount('users', 0)`.

## Verification Plan

- `php artisan test` → `AuthTest` passes: register creates + authenticates; invalid register 422; login valid returns user; wrong password rejected; logout invalidates (subsequent `GET /api/user` 401); `GET /api/user` 401 when guest.
- `php artisan route:list` → the four auth routes exist with the expected middleware.
- `npm --prefix frontend run lint` and `run build` → clean, exit 0.
- Manual dev smoke (two servers): start `php artisan serve` and `npm --prefix frontend run dev`; in the browser, register a user, confirm the header shows the user, reload to confirm the session persists, log out, and confirm login works. State the ports explicitly.
- `.\init.ps1` → Laravel + SPA + engine checks, exit 0, no server started.
- Persistent E2E: no E2E harness and no frontend test runner exist; the spec relies on backend feature tests plus a manual SPA smoke. Record this as a known gap.
- Startup script rule: `init.ps1` stays non-blocking and starts no server.

## Evidence To Capture

- `php artisan test` output (AuthTest + suite green, exit 0).
- `php artisan route:list` showing the auth routes and middleware.
- `npm --prefix frontend run lint`/`build` output (exit 0).
- Manual smoke result: register, authenticated header, session persists on reload, logout, login (with the ports used).
- `.\init.ps1` output (exit 0).
- Confirmation `engine/`, `alphapulse/` and the market-data schema were not modified.

## Validator Checklist

- [ ] Implementation stays within this feature's scope (no roles, protected resources or password reset).
- [ ] Acceptance scenarios pass.
- [ ] Verification evidence is present.
- [ ] Persistent E2E coverage was added/updated when the feature has an observable user/API flow and an E2E harness exists, or the spec explains why it is not needed.
- [ ] `feature_list.json` and `PROGRESS.md` were updated correctly.
- [ ] No unrelated product behavior or extra feature work was added.
- [ ] Auth uses Sanctum cookie/session; auth calls use credentials + CSRF; dev uses the Vite proxy.
- [ ] Passwords are hashed and never returned; `/api/user` excludes password/remember_token.
- [ ] Auth is enforced server-side (`auth` middleware on logout/user).
