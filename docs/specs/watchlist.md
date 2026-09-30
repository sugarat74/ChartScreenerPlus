# Feature Implementation Spec: Manage a personal watchlist

## Source Feature

- `id`: `watchlist`
- `area`: `watchlist`
- `depends_on`: `auth-registration-login`, `instrument-detail-api`, `app-shell-navigation` (all `accepted`)
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

A Registered User follows and unfollows instruments and sees their own current watchlist. Ownership
is enforced server-side: every request is scoped to the authenticated user, no `user_id` is ever
accepted from the client, and two users can never see or modify each other's entries. Anonymous
users are redirected to sign-in by the SPA and rejected with `401` by the API. This is the owned
resource that `user-portal` later consolidates with Saved Screeners.

## Non-Goals

- No Saved Screeners / "Guardar Screener" (`saved-screeners`).
- No user-portal dashboard, P&L, PRO plan, alerts or notifications (`user-portal`; alerts/PRO are out of MVP scope).
- No admin or cross-user access, no sharing, no public watchlists. Admin has no special path.
- No watchlist enrichment (price/change/RVOL/pivot columns, sparkline) — entries are ticker + company metadata only (future hook).
- No screener-table or screener-page change, and no `instrument-detail-api` contract change (it stays frozen).
- No engine change, no market-data schema change, no new dependency.
- No `?redirect=` "preserve the attempted action" handling (deferred to `access-control-guard`).

## Job Story

When I am signed in and inspecting an instrument or reviewing my radar,
I want to follow/unfollow it and see my current watchlist,
so I can keep a short personal list of the names I care about.

## Users And Permissions

- **Visitor (anonymous):** may browse the Screener and Chart. `GET/POST/DELETE /api/watchlist*` return `401`; `/portal` redirects to `/login`.
- **Registered User:** full add/list/remove over **their own** watchlist only.
- **Admin:** identical to a Registered User here; no access to another user's watchlist.

## Decisions (explicit)

1. **Storage — pivot table, no `watchlists` table and no dedicated model.** New `watchlist_items` table: `id`, `user_id` (FK `users`, `cascadeOnDelete`), `instrument_id` (FK `instruments`, `cascadeOnDelete`), `timestamps()`, `unique(['user_id','instrument_id'])`. A "watchlist" is implicit (one per user); a `watchlists` table would add a row/join for zero MVP benefit. Ownership is `user_id`. No soft-delete/status column (`docs/domain-model.md`: `active -> removed`, "No soft-delete requirement in the MVP" → hard delete).
2. **Model access.** `User::watchlist(): BelongsToMany` → `belongsToMany(Instrument::class, 'watchlist_items')->withTimestamps()`. Follows the existing `instrument_universe` precedent (pivot, no model). The explicit table name is required (Laravel would guess `instrument_user`). No `WatchlistItem` model and no `Instrument::watchers()`.
3. **API shape — always scoped to `$request->user()`.** `GET /api/watchlist` → `200 {items:[entry]}`; `POST /api/watchlist {ticker}` → `201 {item}` (new) / `200 {item}` (already present); `DELETE /api/watchlist/{ticker}` → `204` (removed) / `404 {"message":"..."}` (not in the user's list). `entry = {ticker, company, sector, exchange, active}` (same field set as the instrument-detail payload). **No endpoint accepts a `user_id`**; cross-user access is impossible because every query runs through the authenticated user's relationship (another user's ticker simply does not match → `404`).
4. **Middleware.** All three routes go in the existing `Route::middleware('auth:sanctum')->group(...)` block in `routes/api.php`; a guest gets `401`. No `admin` middleware, no throttle (avoids the shared unnamed limiter key).
5. **Add semantics.** Ticker normalized `trim` + `strtoupper`, then `required|string|max:20`; an unknown ticker is `422` with `errors.ticker` (the SPA shows it inline). Idempotent: `syncWithoutDetaching([$id])` returns `attached` (non-empty → `201`, empty → `200`), so a double request cannot duplicate and never violates the unique pair.
6. **Remove semantics.** Instrument looked up by normalized ticker; `detach($id)` returns `0` when the row was not the user's (or absent) → `404`; otherwise `204`. `DELETE` is not silently idempotent: the API distinguishes "removed" from "was not yours/absent". The SPA treats `404` on remove as "already removed" and drops the row.
7. **Ordering.** `GET` orders by `instruments.ticker` ASC (deterministic, matches the repo's ticker-ASC tie-break convention). Most-recently-added order is a possible future tweak, not MVP.
8. **SPA route + nav:** reuse the existing **`/portal`** route and the existing **Portal** tab (`NAV_ITEMS` unchanged, still visible to guests). `DESIGN.md` defines the Portal as the home of the Watchlist and `user-portal` will extend the same page; a separate `/watchlist` tab would create two surfaces for the same data (rejected). `PortalPage.tsx` replaces its `PagePlaceholder` with the real watchlist. Nav is unchanged — the anonymous redirect is observable by visiting `/portal`.
9. **Guard:** **component-level redirect**, not a react-router `loader`. The router is element-based with no loaders, auth state lives in React context (`AuthContext`), and `AdminPage` already established the component-level guard pattern. New small reusable `RequireAuth` renders a "Comprobando sesión" panel while `status==='loading'`, `<Navigate to={LOGIN_ROUTE} replace />` when `user===null`, else children. Destination preservation is out of scope (see Non-Goals).
10. **Add path:** a **"Seguir"/"Siguiendo" toggle on the instrument chart page** (`/instruments/:ticker`), which the accepted `chart-interactive` spec explicitly deferred to this feature ("Watchlist integration ('Seguir') … adds its own action to this page"). The Portal page only lists + removes. This requires amending the chart-anonymity MUST rule (below): the page stays browseable and never redirects, but the toggle may read auth state and offer a sign-in **link** to guests.
11. **Rendering "current entries":** a token-styled table/card showing Ticker (headline, a `Link` to `/instruments/{ticker}` matching `CandidateTable`) + Empresa + Quitar. Empty state, loading skeleton and `role="alert"` error + Reintentar. No price/signal columns.

## Acceptance Scenarios

### Scenario 1: Add an instrument
Given a signed-in user with an empty watchlist and a stored instrument `NVDA`,
When the client `POST`s `/api/watchlist {"ticker":"NVDA"}`,
Then the response is `201 {item:{ticker:"NVDA",company,...}}` and exactly one `watchlist_items` row exists for that user and instrument.

### Scenario 2: Add is idempotent
Given the user already follows `NVDA`,
When the client `POST`s `/api/watchlist {"ticker":"NVDA"}` again,
Then the response is `200` with the same item and the row count is still `1` (no duplicate, no unique-index error).

### Scenario 3: Remove an instrument
Given the user follows `NVDA` and `AAPL`,
When the client `DELETE`s `/api/watchlist/NVDA`,
Then the response is `204` and only the `AAPL` row remains.

### Scenario 4: Remove something not in the list
Given `MSFT` is not in the user's watchlist,
When the client `DELETE`s `/api/watchlist/MSFT`,
Then the response is `404` JSON and no row is changed.

### Scenario 5: Ownership is enforced
Given user A follows `NVDA` and user B has an empty watchlist,
When B `GET`s `/api/watchlist` and `DELETE`s `/api/watchlist/NVDA`,
Then B's list is `[]`, B's delete is `404`, and A's `NVDA` row is untouched.

### Scenario 6: Anonymous access
Given no session,
When a client calls any `/api/watchlist*` route it receives `401`, and
When a browser opens `/portal` the SPA redirects to `/login` (the API is the enforcement point; the redirect is a UI affordance).

### Scenario 7: Unknown ticker
Given no instrument `ZZZZ`,
When the client `POST`s `/api/watchlist {"ticker":"ZZZZ"}`,
Then the response is `422` with `errors.ticker` and no row is written.

### Scenario 8: Chart toggle reflects and changes membership
Given a signed-in user on `/instruments/NVDA` who does not follow `NVDA`,
When the page loads, Then the toggle reads "Seguir"; when it is clicked the item is added and the toggle reads "Siguiendo"; clicking again removes it.
Given a Visitor, Then the toggle renders a sign-in link and the chart still renders (no redirect).

## Repository Research

### Files Inspected

- `feature_list.json` (`watchlist` entry + `user-portal`/`access-control-guard`), `PROGRESS.md`, `AGENTS.md`.
- `docs/domain-model.md` — Watchlist Entry is a follow relationship; `active -> removed`, no soft delete.
- `docs/user-and-access-model.md` — ownership boundaries, "Maintain Watchlist (own)", cascade delete on account deletion, anonymous redirect edge case.
- `DESIGN.md` — Portal = Saved Screeners + Watchlist; tokens/components/a11y baseline.
- `ARCHITECTURE.md` — SPA routes, auth/authorization boundary, Public API and Chart UI sections.
- `CONSTRAINTS.md` — Auth/Authorization/Public API/Frontend/Frontend Chart MUST rules (incl. the chart-anonymity rule to amend).
- `routes/api.php` — the `auth:sanctum` group and the admin group; where the new routes belong.
- `app/Models/User.php`, `app/Models/Instrument.php`, `database/migrations/2026_09_29_100003_create_instrument_universe_table.php` — pivot/FK/`#[Fillable]` conventions.
- `tests/Feature/{AuthTest,AdminAccessTest}.php` — `RefreshDatabase`, `actingAs`, guest `401`, ownership/role assertions.
- `frontend/src/lib/api.ts`, `auth/{AuthContext.tsx,useAuth.ts,context.ts}`, `components/AppHeader.tsx`, `layouts/AppLayout.tsx`, `pages/{AdminPage,PortalPage,InstrumentChartPage,LoginPage}.tsx`, `nav.ts`, `router.tsx`, `main.tsx` — client, guard pattern, route/nav and page conventions.
- `docs/specs/{instrument-detail-api,auth-registration-login,admin-ingestion-panel,chart-interactive}.md` — spec conventions and the deferred chart follow action.
- `alphapulse/src/components/UserPortalView.tsx` (watchlist sub-tab) and `alphapulse/src/App.tsx` (`handleToggleWatchlist`) — design/intent only.

### Existing Patterns To Follow

- `#[Fillable]`/`casts()` Laravel 13 models; anonymous-class migrations; cascade FKs; `unique([...])` on pivots.
- Controllers return explicit arrays shaped in private helpers (no Resource classes); explicit JSON `404`s; normalize ticker with `trim`+`strtoupper`.
- `auth:sanctum` for authenticated routes; `actingAs()` in tests; guest `401`.
- SPA: `frontend/src/lib/api.ts` `request()` helper (`credentials:'include'`, `X-XSRF-TOKEN`, 204 → `undefined`, `ApiError`), `ensureCsrfCookie()` before mutations; `useAuth()`; component-level guard like `AdminPage`; DESIGN tokens in `frontend/src/index.css`.

### Current Gaps

- No watchlist table/model/relationship, no watchlist routes/controller/tests.
- `PortalPage.tsx` is still `PagePlaceholder`; no `RequireAuth`, no `watchlistApi`, no chart follow action.

## Technical Approach

### Backend

1. Migration `database/migrations/2026_09_30_120000_create_watchlist_items_table.php` (anonymous class) exactly as in Decision 1.
2. `User::watchlist(): BelongsToMany` (`belongsToMany(Instrument::class, 'watchlist_items')->withTimestamps()`).
3. `app/Http/Controllers/WatchlistController.php` (`index`, `store`, `destroy`) using `$request->user()`, private `entryPayload(Instrument)` returning `{ticker, company, sector, exchange, active}`:
   - `index`: `$items = $user->watchlist()->orderBy('instruments.ticker')->get()` → `['items' => ...]`.
   - `store`: normalize ticker → validate `required|string|max:20` → look up `Instrument` (miss → `ValidationException::withMessages(['ticker' => 'Instrumento desconocido.'])`, i.e. `422`) → `syncWithoutDetaching` → `201` when `attached` is non-empty else `200`, body `['item' => ...]`.
   - `destroy`: normalize → instrument lookup miss → `404`; `detach($id) === 0` → `404`; else `204`.
4. `routes/api.php`: add the three routes inside the existing `auth:sanctum` group.

### Frontend

5. `frontend/src/lib/api.ts`: `WatchlistEntry` type + `watchlistApi.list()` (`GET`), `.add(ticker)` (CSRF + `POST`), `.remove(ticker)` (CSRF + `DELETE`, path-encoded).
6. `frontend/src/auth/RequireAuth.tsx`: guard per Decision 9.
7. `frontend/src/pages/PortalPage.tsx`: wrap content in `RequireAuth`; on mount (`user` + `reloadToken` deps, async IIFE + `active` flag, per `AdminPage`) call `watchlistApi.list()`; states `loading` / `ready` (table) / `empty` / `error` (`role="alert"` + Reintentar); remove handler sets a per-row `removing` flag, calls `.remove(ticker)`, filters the row out on `204` and on `404` (already gone), else inline error; treat `ApiError` `401` as session expiry → redirect to `/login`.
8. `frontend/src/components/watchlist/WatchlistTable.tsx`: header row + `<th scope="col">`, ticker `Link` to `/instruments/{encodeURIComponent(ticker)}`, company/sector, a "Quitar" button (`aria-label` includes the ticker).
9. `frontend/src/components/watchlist/WatchlistButton.tsx`: `useAuth()`; `status==='loading'` → disabled chip; `user===null` → `<Link to={LOGIN_ROUTE}>Inicia sesión para seguir</Link>`; else load membership via `watchlistApi.list()` and render a toggle button (`aria-pressed`, label `Seguir`/`Siguiendo`), busy/disabled state and inline `role="alert"` error; add on `201`/`200`, remove on `204`/treat `404` as removed.
10. `frontend/src/pages/InstrumentChartPage.tsx`: render `<WatchlistButton ticker={current.instrument.ticker} />` in the header action cluster only when `current !== null` (never on `/chart`). No other page change.

### API Contract

```
GET  /api/watchlist            (auth:sanctum)  -> 200 {items:[{ticker,company,sector,exchange,active}]}
POST /api/watchlist            (auth:sanctum)  body {ticker} -> 201 {item} | 200 {item} | 422 {message,errors:{ticker}}
DELETE /api/watchlist/{ticker} (auth:sanctum)  -> 204 | 404 {message}
guest -> 401 on all three
```

## Expected File Changes

- `database/migrations/2026_09_30_120000_create_watchlist_items_table.php` — create.
- `app/Models/User.php` — modify; add `watchlist()`.
- `app/Http/Controllers/WatchlistController.php` — create.
- `routes/api.php` — modify; three routes in the `auth:sanctum` group.
- `tests/Feature/WatchlistApiTest.php` — create.
- `frontend/src/lib/api.ts` — modify; `WatchlistEntry` + `watchlistApi`.
- `frontend/src/auth/RequireAuth.tsx` — create.
- `frontend/src/pages/PortalPage.tsx` — modify; real watchlist (drops `PagePlaceholder`).
- `frontend/src/components/watchlist/{WatchlistTable,WatchlistButton}.tsx` — create.
- `frontend/src/pages/InstrumentChartPage.tsx` — modify; render the toggle.
- `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/user-and-access-model.md` — update.
- `docs/specs/watchlist.md` (this file), `PROGRESS.md`, `feature_list.json` — update at implementation time.
- `frontend/src/components/PagePlaceholder.tsx` becomes unreferenced; leave it untouched (future surfaces may reuse it).

## Visual Design Impact

- UI involved: yes.
- Design source: `DESIGN.md` + `frontend/src/index.css` `@theme` tokens (reference prototype: `alphapulse/src/components/UserPortalView.tsx` watchlist sub-tab, intent only).
- Screens/states affected: `/portal` (loading / ready table / empty / error + per-row removing) and the `/instruments/:ticker` header toggle (guest link / loading / follow / following / busy / error).
- New design artifact required: no — reuse tokens/components.
- Token rules: 2px `border-outline`; `bg-surface-bright` card with `shadow-[2px_2px_0px_#1a1a1a]`; `bg-primary-container` (`#ffcc00`) action buttons with hard shadow and `hover:-translate-y-px`; `rounded-md`; headline uppercase labels; mono for tickers/counts (right-aligned where numeric); `focus:shadow-[4px_4px_0px_#ffcc00]`; errors `border-secondary bg-secondary-container text-on-secondary-container`; table with real headers for screen readers; no new hues, no soft shadows.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — add a "Watchlist (Owned Resource)" section (table/relationship, `auth:sanctum` endpoints, ownership scoping, add/remove semantics) and update the SPA route list (`/portal` now the watchlist; anonymous → `/login`).
- `CONSTRAINTS.md`: update — add a "Watchlist Ownership" MUST block (owned resources scoped to `$request->user()`; no client `user_id`; unique `(user_id, instrument_id)`; `auth:sanctum`; idempotent add, `204`/`404` remove) and **amend** the Frontend Chart anonymity rule to allow a registered-only affordance that reads auth state and links to sign-in while the chart stays browseable and never redirects.
- `AGENTS.md`: not needed — no workflow/startup change; `init.ps1` unchanged (its `php artisan test` step already covers the new suite).
- `docs/user-and-access-model.md`: update (minor) — name the concrete `watchlist_items` storage and the `/api/watchlist` endpoints.
- `docs/domain-model.md`: not needed — already defines the Watchlist Entry and its lifecycle.
- `DESIGN.md`: not needed — the Portal already documents the Watchlist; tokens/components unchanged.

## Implementation Plan

1. Add the `watchlist_items` migration and `User::watchlist()`; run `migrate` + rollback round-trip.
2. Add `WatchlistController` (`index`/`store`/`destroy`) and register the three routes in the `auth:sanctum` group.
3. Add `tests/Feature/WatchlistApiTest.php` covering the scenarios; run `php artisan test` + `route:list`.
4. Add the SPA client/guard/pages/components and the chart toggle.
5. Run `npm --prefix frontend run lint` + `build`.
6. Live dev smoke (Laravel + Vite proxy) for the API + SPA modules; clean up temp data; release ports.
7. Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/user-and-access-model.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [x] Create the `watchlist_items` migration (cascade FKs, `unique(['user_id','instrument_id'])`, timestamps).
- [x] Add `User::watchlist()` `BelongsToMany` with the explicit `watchlist_items` table and `withTimestamps()`.
- [x] Create `WatchlistController` with `index`/`store`/`destroy` and `entryPayload` (ticker normalization, `422`/`201`/`200`/`204`/`404`).
- [x] Register `GET|POST /watchlist` and `DELETE /watchlist/{ticker}` in the `auth:sanctum` group.
- [x] Add `WatchlistApiTest` (guest 401 ×3, list/isolation, empty, add, idempotent add, case normalization, unknown ticker 422, missing ticker 422, remove 204, remove absent 404, cross-user remove 404, cascade on user delete).
- [x] Add `WatchlistEntry` + `watchlistApi` (`list`/`add`/`remove`) in `frontend/src/lib/api.ts`.
- [x] Add `RequireAuth` and make `PortalPage` the guarded watchlist page (loading/ready/empty/error + remove).
- [x] Add `WatchlistTable` and `WatchlistButton`; render the toggle on `InstrumentChartPage` for a loaded ticker only.
- [x] Amend the chart-anonymity rule and add the watchlist ownership rules in `CONSTRAINTS.md`; update `ARCHITECTURE.md` + `docs/user-and-access-model.md`.
- [x] Run `php artisan test`, `php artisan route:list --path=api -v`, frontend lint/build, the live smoke and `.\init.ps1`; update `PROGRESS.md`/`feature_list.json`.

## Verification Plan

- `php artisan migrate --force` → `watchlist_items` created; `php artisan migrate:rollback --force` → rolled back; `php artisan migrate --force` → re-applied (clean round-trip). `php artisan db:table watchlist_items` confirms the unique pair + cascade FKs.
- `php artisan test --filter=WatchlistApiTest` → all cases pass; `php artisan test` → full suite green (baseline 117 passed / 799 assertions).
- `php artisan route:list --path=api -v` → the three watchlist routes show `auth:sanctum` only (guests 401; no `admin`, no throttle).
- `npm --prefix frontend run lint` → 0 warnings/0 errors; `npm --prefix frontend run build` → built, exit 0.
- **No frontend test runner or E2E harness exists**, so SPA behavior is verified at the HTTP/transformed-module level plus the live smoke (the component-level redirect and the toggle's visual state are not browser-automatable): live `php artisan serve` :8000 + Vite :5173 through the proxy → register/login a temp user (stateful `Origin`), guest `GET /api/watchlist` `401`, `POST {ticker:'AAPL'}` `201` then `200` (idempotent), `GET` lists it, unknown ticker `422`, `DELETE` `204` then `404`, second user sees `[]` and gets `404` deleting the first user's ticker, `/portal` and `/instruments/NVDA` serve 200 and the transformed `PortalPage.tsx`/`WatchlistButton.tsx`/`api.ts` contain the guard/API strings; then kill servers, free ports 8000/5173 and delete temp users/rows.
- `.\init.ps1` → exit 0 and unchanged (Laravel tests + SPA lint/build + engine tests); it stays a non-blocking gate and starts no server.
- Startup script: no `init.ps1` change is required; the new suite is covered by its existing `php artisan test` step.

## Evidence To Capture

- Migration up/rollback/up output and `php artisan db:table watchlist_items`.
- `php artisan test --filter=WatchlistApiTest` and full-suite counts/assertions; `route:list` rows.
- Frontend lint/build output; live smoke request/status log + transformed-module findings; ports released and DB restored.
- `.\init.ps1` exit status and confirmation it was not modified.
- Confirmation `engine/`, `alphapulse/`, market-data schema and the frozen `instrument-detail-api`/`screener-api` contracts were not modified.

## Implementation Findings

- **Test coverage exceeded the enumerated cases.** `tests/Feature/WatchlistApiTest.php` has **16** cases / 61 assertions (the task list enumerated 12): it also proves a non-string ticker is a `422` (not a `TypeError`), an unknown-ticker delete is a `404`, a client-supplied `user_id` is ignored, entries are ordered by ticker, and deleting an instrument cascades its rows.
- **Add-request normalization.** `store()` merges a normalized ticker only when the raw input is a string (`trim` + `strtoupper`); a non-string is left untouched so the `required|string|max:20` validation returns a controlled `422` instead of failing inside `strtoupper`.
- **`PortalPage` splits into a guard + an inner `WatchlistPanel`.** `RequireAuth` wraps the page, but hooks stay unconditional: the fetch effect lives in the inner component, which re-checks `user === null` and keys on `[user, reloadToken]`. The `RequireAuth` loading state is a small token-styled "Comprobando sesión" panel; a Visitor gets `<Navigate to="/login" replace />`.
- **Stale-toggle prevention.** `WatchlistButton` keys its membership to `user.id|ticker` (and its error to the same key), matching the keyed-result pattern `AdminPage`/`InstrumentChartPage` already use (required by oxlint's `react-hooks` `set-state-in-effect` rule): an async check never renders a stale "Siguiendo" while a fresh check is in flight.
- **Live smoke CSRF nuance (verification-script learning, not product behavior).** A mutating request - including a body-less `DELETE` - must send the `X-XSRF-TOKEN` header; without it the API correctly answers `419 CSRF token mismatch`. The first smoke run's cross-user delete was a `419` because the script only attached the header when a JSON body was present; after attaching it to every non-GET request the cross-user delete returned the specified `404`. This is Laravel's normal CSRF enforcement (`ValidateCsrfToken` reads only `_token`/`X-CSRF-TOKEN`/`X-XSRF-TOKEN`).
- **No frontend test runner or E2E harness.** As in `auth-registration-login`/`chart-interactive`, SPA behavior (the component-level redirect and the toggle's visual states) was verified at the HTTP/transformed-module level plus the live smoke, not in a real browser. The unit of enforcement (the API) is fully covered by feature tests.
- **`PagePlaceholder.tsx` is now unreferenced** but deliberately left in place, as the spec anticipated (future surfaces may reuse it). The SPA build went 127 -> 129 modules (three new files added, the placeholder dropped, one transitive module gained).

## Validator Checklist

- [ ] Implementation stays within scope (no saved screeners/portal/alerts/enrichment/engine/market-data change).
- [ ] Acceptance scenarios 1–8 pass; add is idempotent; remove is `204`/`404`.
- [ ] Ownership is server-side scoped to `$request->user()`; no endpoint accepts `user_id`; cross-user read/remove is denied (`404`/absent).
- [ ] `watchlist_items` has the unique `(user_id, instrument_id)` pair and cascade FKs; deleting a user/instrument removes its rows.
- [ ] `/portal` redirects anonymous users to sign-in; the chart stays browseable and its toggle only links guests to sign-in (no redirect).
- [ ] Verification evidence is present; the no-E2E-harness gap is recorded and explained.
- [ ] `ARCHITECTURE.md`/`CONSTRAINTS.md`/`docs/user-and-access-model.md` updated; `feature_list.json`/`PROGRESS.md` updated with evidence.
- [ ] No unrelated product behavior or extra feature work was added.
