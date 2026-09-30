# Feature Implementation Spec: Save and re-apply screeners

## Source Feature

- `id`: `saved-screeners`
- `area`: `screener`
- `depends_on`: `auth-registration-login`, `screener-api`, `app-shell-navigation` (all `accepted`)
- `status`: `passing`
- `source`: `feature_list.json`

## Goal

Let a Registered User persist the current Screener filter set as a **named Screener** and re-apply it
later. The stored definition is the canonical filter object (the same vocabulary the frozen
`GET /api/screener` consumes), so "apply" restores the filters, the ranking and therefore the
Candidate results. Ownership is enforced server-side: every query is scoped to the authenticated
user, no `user_id` is ever accepted from the client, and two users can never read or delete each
other's Screeners.

The whole lifecycle (save / list / apply / delete) is reachable from `/screener`; `/portal` keeps its
existing watchlist-only behavior and the full portal dashboard is deferred to `user-portal`.

## Non-Goals

- No user-portal dashboard, PRO plan, P&L, alerts or notifications (`user-portal`; alerts/PRO are out of MVP scope).
- No Screener editing/rename/update endpoint (`PUT/PATCH`) — not required by the feature; delete + save again is the MVP path.
- No sharing, public/published Screeners, copy-to-another-user, or Admin visibility into user Screeners.
- No new Screener criteria, no `limit`/`universe`/search control, and **no change** to the frozen
  `GET /api/screener` (public, anonymous, `throttle:60,1`) or `ScreenerController` semantics.
- No access-control consolidation (`access-control-guard`), no chart change, no watchlist change,
  no `/portal` change, no new route/nav tab.
- No engine/indicator/signal change, no new npm dependency, and no frontend test runner/E2E harness
  (none exists).
- No scheduled/automatic re-execution of a saved Screener and no "last executed / hits" metadata
  (the prototype shows these; they are later scope).

## Job Story

When I have tuned a filter set on the Screener,
I want to save it under a name and re-apply it later,
so I can return to the same shortlist without rebuilding every criterion.

## Users And Permissions

- **Visitor (anonymous):** may still browse and filter `/screener` exactly as today. `GET/POST/DELETE /api/screeners*`
  return `401`; the SPA save/apply panel offers a sign-in **link** only (never a redirect or modal), so the Screener stays anonymous.
- **Registered User:** full create/list/delete over **their own** Screeners only.
- **Admin:** identical to a Registered User here; no special path and no access to another user's Screeners.

## Decisions (explicit)

1. **Storage — a real `saved_screeners` table (not a pivot).** `id`, `user_id` (FK `users`,
   `cascadeOnDelete`), `name` (string), `filters` (json), `timestamps()`, `unique(['user_id','name'])`.
   A Saved Screener has its own attributes (a name and a definition), unlike a follow relationship,
   so it gets a table + `SavedScreener` model; ownership is `user_id` and deletion is a hard delete
   (`docs/domain-model.md`: `saved -> deleted`, no soft delete in the MVP).
2. **`name` is unique per user.** A Saved Screener is identified by its name in the list, so two
   identical names for the same user would make "apply the one I mean" ambiguous and the list
   confusing. Uniqueness is `(user_id, name)` — two different users may reuse the same name. The
   name is `trim`-ed and compared exactly (a case variant is a distinct name; SQLite's default
   collation is case-sensitive). A collision is a `422` with `errors.name` (no silent upsert).
3. **Stored `filters` = the canonical filter object with `sort` included.** The stored JSON is the
   seven-key definition in **API param names** (snake_case) — the same vocabulary as the URL/API:
   `{signal: [...], rsi_min, rsi_max, min_rvol, price_above_sma200, ma_cross, sort}`. `sort` **is**
   stored: applying must restore the result view the user saved, and the ranking is part of that view
   (the URL state source of truth already includes `sort`). Dropping it would silently reset the
   ranking to `rvol_desc` and violate "restores its filters and results". `signal` is a JSON array
   of the 9 fixed type strings (deduped + canonicalised), never the comma form.
4. **API shape — always scoped to `$request->user()`.** `GET /api/screeners` → `200 {screeners:[screener]}`;
   `POST /api/screeners {name, filters}` → `201 {screener}`; `DELETE /api/screeners/{screener}` →
   `204` / `404 {"message":"Screener not found."}`. `screener = {id, name, filters}`. All three routes
   live in the existing `Route::middleware('auth:sanctum')->group(...)` in `routes/api.php` (guest → `401`),
   with **no** `admin` middleware and **no** throttle. **No endpoint accepts a `user_id`**; `destroy`
   resolves the row through `$request->user()->savedScreeners()` so another user's id is a `404`
   (no existence leak). No `GET /{id}` is added — the list already carries each definition, so apply
   needs no second fetch.
5. **`user_id` is not mass-assignable.** `SavedScreener` is `#[Fillable(['name','filters'])]` and the
   row is created through `$request->user()->savedScreeners()->create([...])`, so a client-supplied
   `user_id` cannot be written (same structural guard as `role`).
6. **Create validation (whitelist + reject unknown keys).** `name` `required|string|max:60` (trimmed
   first, non-string left untouched so the rule produces a controlled `422`); `filters` `required` with
   the `array:signal,rsi_min,rsi_max,min_rvol,price_above_sma200,ma_cross,sort` rule so any extra key
   is rejected; every canonical key is `present` so the stored definition is always complete and
   re-appliable without defaults (the SPA serializer always emits all seven). Value rules mirror the
   frozen screener API: `signal` array of the 9 known types, RSI `nullable|numeric|between:0,100`,
   `min_rvol` `nullable|numeric|min:0`, `price_above_sma200` boolean, `ma_cross` `nullable|in:bullish,bearish`,
   `sort` `in:` the six API values. `signal` is deduped + ordered canonically before storage; no
   clamping is applied server-side (invalid values are rejected, not silently repaired). Duplicate
   name → `422` `errors.name`.
7. **Ordering.** `GET` orders by `name` ASC then `id` ASC (deterministic, matching the repo's
   ticker-ASC convention). "Most recently created first" is a possible future tweak, not MVP.
8. **SPA location: everything on `/screener`, no new route/tab.** Saving happens where the filters are
   built and applying must write the same page's URL, so a single auth-aware `SavedScreenersPanel`
   is rendered on `/screener` (below the filter panel). Building the panel into `/portal` would
   pre-build the `user-portal` dashboard; a dedicated route would add navigation for one action.
   `router.tsx`/`nav.ts`/`AppHeader.tsx`/`PortalPage.tsx` are untouched.
9. **Applying = patching the URL with the stored filters.** A pure helper deserializes the stored
   object into `Partial<ScreenerFilters>` and the page applies it with the existing
   `patchScreenerFilters(prev, patch)` (`replace: true`). The fetch effect keyed on `screenerFiltersKey`
   then reissues `GET /api/screener` with exactly the stored params, so the Candidate list (and the
   `Mostrando N de M` line) is restored server-side. If the stored filters already equal the current
   URL, the key is unchanged and no redundant request fires.
10. **Guest behavior mirrors the watchlist toggle.** The panel calls `useAuth()` internally (never the
    page) and renders a sign-in **link** (`LOGIN_ROUTE`) for a Visitor; `/screener` stays anonymous
    (`CONSTRAINTS.md`). A `401` during a signed-in mutation is treated as session expiry → redirect to `/login`.
11. **Delete semantics.** Per-row `removing` flag; `204` removes the row locally; a `404` is treated as
    "already gone" and also removes it locally. Delete is not silently idempotent at the API layer.
12. **Rendering.** A bordered `surface-bright` card: an inline save row (labelled name `<input>` + a
    yellow "Guardar screener" button) and the user's list (name + a mono summary of the active
    criteria, with "Aplicar" and "Eliminar" actions). Loading skeleton, empty state, `role="alert"`
    error + Reintentar. Token-only styling per `DESIGN.md`.

## Acceptance Scenarios

### Scenario 1: Save persists the definition under ownership

Given a signed-in user and a URL with `?signal=golden_cross&min_rvol=2&sort=rsi_desc`,
When the client `POST`s `/api/screeners` with `{name:"Cruce dorado RVOL", filters:{...the seven keys...}}`,
Then the response is `201 {screener:{id,name,filters}}`, the stored `filters` JSON equals the canonical
object (including `sort:"rsi_desc"`), and exactly one `saved_screeners` row exists with that user's `user_id`.

### Scenario 2: Applying restores the filters and the results

Given a saved Screener whose definition matches `?signal=golden_cross&min_rvol=2&sort=rsi_desc`,
When the SPA applies it (the URL gains exactly those owned params, other params preserved),
Then the refetch calls `GET /api/screener?signal=golden_cross&min_rvol=2&sort=rsi_desc` and the
Candidate table and `Mostrando N de M` line match the result of that query.

### Scenario 3: Ownership is enforced

Given user A saved "Momentum" and user B has none,
When B `GET`s `/api/screeners` and `DELETE`s `/api/screeners/{A's id}`,
Then B's list is `[]`, B's delete is `404 {"message":"Screener not found."}`, and A's row is untouched.

### Scenario 4: Anonymous access

Given no session,
When a client calls any `/api/screeners*` route it receives `401`, and
When a Visitor opens `/screener` the Screener still works and the save/apply panel shows a sign-in link (no redirect, no login prompt).

### Scenario 5: Duplicate name per user

Given user A already has a Screener named "Momentum",
When A `POST`s `/api/screeners` with the same name,
Then the response is `422` with `errors.name` and no second row is written; user B can still create a Screener named "Momentum".

### Scenario 6: Invalid filter payload

Given a signed-in user,
When `POST /api/screeners` sends an unknown filter key (`filters.unknown`), a missing canonical key, an unknown signal type, `rsi_min:150`, or `sort:"confidence_desc"`,
Then the response is `422` with the offending `filters.*` error and no row is written.

### Scenario 7: Delete own Screener

Given the user owns a Screener,
When the client `DELETE`s `/api/screeners/{id}`,
Then the response is `204` and the row is gone; deleting the same id again is `404`.

### Scenario 8: List states on `/screener`

Given a signed-in user with no saved Screeners,
Then the panel shows "No tienes screeners guardados."; after saving one it appears in name order with Aplicar/Eliminar; on a request failure a `role="alert"` error with Reintentar renders.

## Repository Research

### Files Inspected

- `feature_list.json` (`saved-screeners` + `user-portal`/`access-control-guard`), `PROGRESS.md`, `AGENTS.md`, `ARCHITECTURE.md`, `CONSTRAINTS.md`, `DESIGN.md`.
- `docs/domain-model.md` — Saved Screener lifecycle `draft -> saved -> deleted`, `applied` without leaving `saved`; `docs/user-and-access-model.md` — "Save / apply Saved Screeners: own", ownership/cascade/report.
- `docs/specs/{screener-filters-ui,candidate-list-ranking,watchlist}.md` — URL-backed state, the seven owned keys, and the owned-resource API/SPA pattern to mirror.
- `frontend/src/lib/screenerFilters.ts` — `ScreenerFilters`, `parseScreenerFilters`, `patchScreenerFilters`, `screenerFiltersKey`, `EMPTY_SCREENER_CRITERIA`, `EMPTY_SCREENER_FILTERS`, `normalizeSignals`, `parseRsiInput`, `parseScreenerSort`, `SCREENER_SIGNAL_TYPES`, `SCREENER_SORT_OPTIONS`, `DEFAULT_SCREENER_SORT`.
- `frontend/src/lib/api.ts` — `ScreenerFilters`/`ScreenerSort`/`ScreenerSignalType`, `screenerApi.search`, `watchlistApi` + `request()`/`ensureCsrfCookie()`/`ApiError` patterns.
- `frontend/src/pages/ScreenerPage.tsx` — `useSearchParams` source of truth, `update(patch)`, fetch effect keyed on `screenerFiltersKey`, results header.
- `frontend/src/components/screener/ScreenerFilterPanel.tsx`, `ScreenerSortControl.tsx`, `CandidateResults.tsx`, `CandidateTable.tsx` — token/control/state patterns.
- `frontend/src/pages/PortalPage.tsx`, `frontend/src/auth/RequireAuth.tsx`, `components/watchlist/WatchlistButton.tsx` — auth-aware panel, guest sign-in link, `401` → `/login`, list/empty/error pattern.
- `frontend/src/nav.ts`, `router.tsx`, `components/AppHeader.tsx` — confirm no route/nav change is needed.
- `routes/api.php`, `app/Http/Controllers/WatchlistController.php`, `tests/Feature/WatchlistApiTest.php` — the `auth:sanctum` group, scoped-query controller, guard/validation/response conventions.
- `app/Models/User.php`, `database/migrations/2026_09_30_120000_create_watchlist_items_table.php` — model/relationship and anonymous-migration style.
- `alphapulse/src/components/UserPortalView.tsx` (Saved Screeners sub-tab: name, category, description, Aplicar/Eliminar) — design/intent only; the category/description/hits fields are **not** requirements.

### Existing Patterns To Follow

- Owned resource: every query runs through `$request->user()-><relation>()`; no client `user_id`; explicit JSON `404`; unique pair/index at the DB level.
- `#[Fillable]`/`casts()` Laravel 13 models; anonymous-class migrations; cascade FKs; `HasFactory` + factory.
- Controllers return explicit arrays shaped in private helpers (no Resource classes).
- SPA: `request()` client module + `ensureCsrfCookie()` before mutations; auth-aware child component; `role="alert"` errors + Reintentar; token-only styling; oxlint enforces `react-hooks(exhaustive-deps)` and `react(set-state-in-effect)`.
- URL is the sole Screener state source of truth; only the seven owned keys are written and unrelated params survive.

### Current Gaps

- No `saved_screeners` table/model/relationship, no `/api/screeners` routes/controller/tests.
- No `savedScreenersApi` client, no serialize/deserialize helpers, no panel on `/screener`.
- No frontend test runner / E2E harness; SPA behavior is verified by lint/build + live smoke + manual browser checks.

## Technical Approach

### Backend

1. Migration `database/migrations/2026_09_30_130000_create_saved_screeners_table.php` exactly as in Decision 1.
2. `app/Models/SavedScreener.php`: `#[Fillable(['name','filters'])]`, `casts(): ['filters' => 'array']`, `belongsTo(User)`, `HasFactory`; `SavedScreenerFactory` with a name and a complete default `filters` object.
3. `User::savedScreeners(): HasMany` (default FK `user_id`).
4. `app/Http/Controllers/SavedScreenerController.php` (`index`/`store`/`destroy`), always via `$request->user()->savedScreeners()`, private `screenerPayload(SavedScreener)` → `{id, name, filters}`:
   - `index`: `orderBy('name')->orderBy('id')->get()` → `['screeners' => ...]`.
   - `store`: trim a string name in place, validate per Decision 6, canonicalise `signal`, `create` through the relationship → `201`.
   - `destroy`: `whereKey($id)->first()`; `null` → `404 {'message':'Screener not found.'}`; else `delete()` → `204`.
5. `routes/api.php`: add the three routes inside the existing `auth:sanctum` group (`/screeners` does not collide with the public `/screener`).

### Frontend

6. `frontend/src/lib/api.ts`: `SavedScreenerFilters` (seven snake_case keys), `SavedScreener` (`{id, name, filters}`) and `savedScreenersApi.list()/create(name, filters)/remove(id)` (CSRF before mutations).
7. `frontend/src/lib/screenerFilters.ts`: `serializeScreenerFilters(filters: ScreenerFilters): SavedScreenerFilters` (all seven keys, canonical) and `deserializeScreenerFilters(payload): Partial<ScreenerFilters>` (lenient: reuse `normalizeSignals`/`parseRsiInput`/`parseScreenerSort`, drop unknown keys/values so a stored payload can never produce an invalid URL or API `422`).
8. `frontend/src/components/screener/SavedScreenersPanel.tsx` (`{filters, onApply, reloadToken?}`): auth-aware panel per Decisions 8/10/11/12; owns the name input, the save call, the list fetch (keyed on `[user, reloadToken]` like `PortalPage`), per-row removing and the empty/error states; `onSaved` bumps a local reload token.
9. `frontend/src/pages/ScreenerPage.tsx`: render `<SavedScreenersPanel filters={filters} onApply={applySavedFilters} />` below `ScreenerFilterPanel`; `applySavedFilters(patch)` = `setSearchParams(prev => patchScreenerFilters(prev, patch), { replace: true })`. No change to the fetch effect or the anonymous behavior.

### API Contract

```
GET    /api/screeners             (auth:sanctum) -> 200 {screeners:[{id,name,filters}]} ordered by name, id
POST   /api/screeners             (auth:sanctum) body {name, filters} -> 201 {screener} | 422 {message, errors:{name|filters.*}}
DELETE /api/screeners/{screener}  (auth:sanctum) -> 204 | 404 {message:"Screener not found."}
guest -> 401 on all three
filters = {signal: string[], rsi_min: number|null, rsi_max: number|null, min_rvol: number|null,
           price_above_sma200: boolean, ma_cross: "bullish"|"bearish"|null, sort: <6 API sorts>}
```

## Expected File Changes

- `database/migrations/2026_09_30_130000_create_saved_screeners_table.php` — create.
- `app/Models/SavedScreener.php` — create.
- `database/factories/SavedScreenerFactory.php` — create.
- `app/Models/User.php` — modify; add `savedScreeners()`.
- `app/Http/Controllers/SavedScreenerController.php` — create.
- `routes/api.php` — modify; three routes in the `auth:sanctum` group.
- `tests/Feature/SavedScreenersApiTest.php` — create.
- `frontend/src/lib/api.ts` — modify; types + `savedScreenersApi`.
- `frontend/src/lib/screenerFilters.ts` — modify; serialize/deserialize helpers.
- `frontend/src/components/screener/SavedScreenersPanel.tsx` — create.
- `frontend/src/pages/ScreenerPage.tsx` — modify; mount the panel + `applySavedFilters`.
- `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/user-and-access-model.md` — update.
- `docs/specs/saved-screeners.md` (this file), `PROGRESS.md`, `feature_list.json` — update at implementation.
- Not changed: `ScreenerController.php` and the public `GET /api/screener` route, `ScreenerFilterPanel.tsx`/`CandidateResults.tsx`/`CandidateTable.tsx`/`ScreenerSortControl.tsx`, `router.tsx`/`nav.ts`/`AppHeader.tsx`, `PortalPage.tsx`, `frontend/package.json`/lockfile, `engine/`, `alphapulse/`, `init.ps1`.

## Visual Design Impact

- UI involved: yes. Design source: `DESIGN.md` (source of truth) + `alphapulse/src/components/UserPortalView.tsx` Saved Screeners sub-tab (intent reference only).
- Screens/states affected: `/screener` below the filter panel — guest sign-in card; loading; empty; list ready (save row + row actions); save idle/saving/saved/error; per-row removing; list error.
- New design artifact required: no — reuse tokens/components.
- `DESIGN.md` references to honor: `2px solid #1a1a1a` borders (`border-outline`), `surface-bright` cards with `shadow-[2px_2px_0px_#1a1a1a]`, yellow primary buttons (`bg-primary-container` `#ffcc00` + `text-on-primary-container` + hard shadow), `rounded-md`, uppercase headline labels, mono for names/counts/status, `focus:shadow-[4px_4px_0px_#ffcc00]`, errors `border-secondary bg-secondary-container text-on-secondary-container`, list rows with real headers; no new hues, no soft shadows.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — add a "Saved Screeners (Owned Resource)" section (table/model, `auth:sanctum` endpoints, scoped ownership, the canonical `filters` JSON, apply = URL round-trip) and note the `/screener` save/apply affordance.
- `CONSTRAINTS.md`: update — add a "Saved Screeners (Owned Resource)" MUST block (scoped to `$request->user()`; no client `user_id`; unique `(user_id, name)`; canonical filters JSON with the API param vocabulary incl. `sort`; no update endpoint; the Screener stays anonymous and the panel is a registered-only affordance with a sign-in link).
- `AGENTS.md`: not needed — no workflow/startup change; `init.ps1` unchanged.
- `docs/user-and-access-model.md`: update (minor) — name the concrete `saved_screeners` storage and the `/api/screeners` endpoints.
- `docs/domain-model.md`: not needed — the Saved Screener lifecycle is already defined.
- `DESIGN.md`: not needed — existing tokens/components cover the panel.

## Implementation Plan

1. Add the `saved_screeners` migration, `SavedScreener` model + factory and `User::savedScreeners()`; run the migrate/rollback/migrate round-trip.
2. Add `SavedScreenerController` + the three routes; add `SavedScreenersApiTest` and run `php artisan test` + `route:list`.
3. Add the SPA types/`savedScreenersApi` and the `serialize`/`deserialize` helpers.
4. Build `SavedScreenersPanel` and mount it in `ScreenerPage`; run lint/build.
5. Live dev smoke through the Vite proxy (two users + guest, apply equivalence, teardown); manual browser checklist.
6. Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/user-and-access-model.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [x] Create the `saved_screeners` migration (`user_id` cascade FK, `name`, `filters` json, `timestamps`, `unique(['user_id','name'])`).
- [x] Add `SavedScreener` (`#[Fillable(['name','filters'])]`, `casts()`, `belongsTo(User)`) + `SavedScreenerFactory` + `User::savedScreeners()`.
- [x] Create `SavedScreenerController` (`index`/`store`/`destroy`, `screenerPayload`, scoped queries, whitelist validation, `422`/`201`/`204`/`404`).
- [x] Register `GET|POST /screeners` and `DELETE /screeners/{screener}` in the `auth:sanctum` group.
- [x] Add `SavedScreenersApiTest`: guest 401 ×3, save + stored JSON, ownership isolation, cross-user delete 404, client `user_id` ignored, duplicate name 422, unknown key/missing key/unknown signal/out-of-range/unknown sort 422, list ordering, delete 204 then 404, user-delete cascade.
- [x] Add `SavedScreenerFilters`/`SavedScreener` + `savedScreenersApi` (`list`/`create`/`remove`) in `frontend/src/lib/api.ts`.
- [x] Add `serializeScreenerFilters`/`deserializeScreenerFilters` to `frontend/src/lib/screenerFilters.ts`.
- [x] Build `SavedScreenersPanel` (guest link, save row, list, apply, delete, loading/empty/error) with token-only styling.
- [x] Mount the panel in `ScreenerPage` and wire `applySavedFilters` through `patchScreenerFilters`; confirm `/screener` stays anonymous.
- [x] Run `php artisan test`, `route:list --path=api -v`, pint, frontend lint/build, the live smoke, the manual checklist and `.\init.ps1`; update `ARCHITECTURE.md`/`CONSTRAINTS.md`/`docs/user-and-access-model.md`/`PROGRESS.md`/`feature_list.json`.

## Verification Plan

- `php artisan migrate --force` → `saved_screeners` created; `php artisan migrate:rollback --force` → rolled back; `php artisan migrate --force` → re-applied. `php artisan db:table saved_screeners` confirms `saved_screeners_user_id_name_unique` and the cascade FK to `users`.
- `php artisan test --filter=SavedScreenersApiTest` → all cases pass; `php artisan test` → full suite green (baseline **133 passed / 860 assertions**).
- `php artisan route:list --path=api -v` → the three `/api/screeners*` routes show `api` + `Illuminate\Auth\Middleware\Authenticate:sanctum` only (no `admin`, no throttle); the public `GET api/screener` row is unchanged.
- `.\vendor\bin\pint --test <changed php files>` → pass.
- `npm --prefix frontend run lint` → 0 warnings/0 errors; `npm --prefix frontend run build` → built, exit 0; `frontend/package.json` unchanged.
- **No frontend test runner or E2E harness exists**, so SPA behavior is verified at the HTTP/URL/transformed-module level plus the live smoke (there is no persistent E2E command to add): live `php artisan serve` :8000 + Vite :5173 through the proxy → guest `401` ×3; user A save `201`, duplicate name `422`, unknown filter key `422`, `rsi_min=150` `422`, `sort=confidence_desc` `422`; `GET /api/screeners` ordered by name; user B list `[]` and delete of A's id `404` with A's row untouched; delete own `204` then `404`; a client-supplied `user_id` is ignored; **apply equivalence**: `GET /api/screener` with the stored filters returns the same candidate ticker order as the original query; `/screener` and the new/changed modules transform 200. Then kill servers, release ports 8000/5173 and delete temp users/rows.
- **Manual browser checklist** (Scenarios 1–8): guest sees the sign-in link and the Screener still works; save the current URL filters; the saved row appears; Aplicar restores the URL params + the ranking + the table + `Mostrando N de M`; other unrelated query params survive; duplicate name shows the inline error; Eliminar removes the row; empty/loading/error states; DESIGN fidelity (2px borders, hard shadow, mono, focus offset, wrapping).
- `.\init.ps1` → exit 0 and unchanged (Laravel tests + SPA lint/build + engine tests); it stays a non-blocking gate and starts no server.

## Evidence To Capture

- Migration up/rollback/up output and `php artisan db:table saved_screeners` (unique index + cascade FK).
- `php artisan test --filter=SavedScreenersApiTest` and full-suite counts/assertions; `route:list` rows for `/api/screeners*` and the unchanged public `/api/screener`.
- Frontend lint/build output; the live smoke request/status log including the apply-equivalence result; ports released and DB restored.
- Manual per-scenario results (pass/fail) with a note that visual fidelity was checked by hand.
- `.\init.ps1` exit status and confirmation it was not modified.
- Confirmation that the frozen `GET /api/screener`/`ScreenerController`, `engine/`, `alphapulse/`, the market-data schema, the watchlist and `frontend/package.json` were not modified.

## Implementation Findings

- **Validation error keys.** The `array:signal,rsi_min,...,sort` whitelist reports an unexpected filter key on `errors.filters` (not `errors.filters.<key>`, since the whole array is rejected); a missing canonical key, an unknown signal type, an out-of-range value and an unknown `sort` land on `errors.filters.signal.N` / `errors.filters.rsi_min` / `errors.filters.min_rvol` / `errors.filters.sort`. The test suite asserts those exact keys.
- **Duplicate name uses `Rule::unique('saved_screeners','name')->where(user_id)`** (scoped to the caller) rather than a manual pre-check, so the `422 errors.name` is produced by the same validation pass as every other rule. The DB unique `(user_id, name)` index remains the backstop.
- **`user_id` is not mass-assignable**, but the controller never reads a `user_id` from the payload either: `store` only consumes `$validated['name']`/`$validated['filters']` and creates through the scoped relation. A client-supplied `user_id` is therefore ignored twice over.
- **`filters` JSON round-trips as the validated types** (booleans stay booleans, numbers stay numbers) on SQLite; SQLite stores a Laravel `json` column as `text`, which `php artisan db:table` reports as `text` — expected, not a schema deviation.
- **`deserializeScreenerFilters` returns all seven owned keys** (a complete patch) and re-parses RSI through `parseRsiInput` (clamped `0..100`), drops an unknown signal type, an unknown `ma_cross` and an unknown `sort` (falls back to the default), and rejects a negative `min_rvol`. That is what makes the stored payload unable to produce an invalid URL or an API `422`.
- **`applySavedFilters` and `update` are intentionally the same `patchScreenerFilters` call.** Applying restores the URL, and the existing fetch effect keyed on `screenerFiltersKey` refetches; no fetch-effect change was needed and no redundant request fires when the stored filters already equal the URL.
- **Panel reload pattern mirrors `watchlist`/`PortalPage`:** the list effect is keyed on `[user, reloadToken, localToken, navigate]` and does not synchronously set state (oxlint `react(set-state-in-effect)`); a save calls a local `reload()` that bumps `localToken`. The `reloadToken?` prop is supported for an external trigger but `ScreenerPage` does not need it.
- **No frontend test runner/E2E harness exists.** SPA behavior was verified by lint/build, a pure-helper check (16/16 assertions on `serialize`/`deserialize` via `node --experimental-strip-types`), the live smoke and a static/manual inspect of `DESIGN.md` fidelity; there is no persistent E2E command to add.
- **Live smoke assertion artifacts fixed twice** (not product defects): the smoke script first looked up the validation errors at `errors.rsi_min`/`errors.sort` instead of the actual `errors.filters.rsi_min`/`errors.filters.sort`, and checked the panel module for the literal `api/screeners` (which lives in `api.ts`). Corrected and re-run: 35/35 checks pass.
- **PowerShell 5.1 notes (reconfirmed):** Vite dev binds `localhost`/`::1`, so the smoke used `http://localhost:5173` (not `127.0.0.1`); every non-GET needs `X-XSRF-TOKEN` read from the session's `XSRF-TOKEN` cookie (guest non-GETs still get `401` because `auth:sanctum` precedes CSRF); query strings must be concatenated, not interpolated (`"$base?..."` parses `$base?` as a variable name).

## Validator Checklist

- [ ] Implementation stays within scope (no portal dashboard, no update/rename endpoint, no sharing, no new Screener criteria, no frozen screener API/controller change, no chart/watchlist/engine change, no new dependency).
- [ ] Acceptance scenarios 1–8 pass; `/screener` remains anonymous and the guest sees only a sign-in link.
- [ ] The stored `filters` JSON is the canonical seven-key API-param object including `sort`, and applying it restores the URL params and the API result order.
- [ ] Ownership is server-side scoped to `$request->user()`; no endpoint accepts `user_id`; a cross-user list is `[]` and a cross-user delete is `404` with the owner's row untouched.
- [ ] `saved_screeners` has the unique `(user_id, name)` pair and a cascade FK; deleting a user removes their Screeners.
- [ ] Create validation rejects unknown filter keys, missing canonical keys and invalid values with `422`; a duplicate name is a `422 errors.name`.
- [ ] The panel follows `DESIGN.md` and renders loading/empty/error/saving/removing states.
- [ ] No frontend test runner/E2E harness exists, and the lint/build + live smoke + manual checklist is the documented closest verification.
- [ ] `ARCHITECTURE.md`/`CONSTRAINTS.md`/`docs/user-and-access-model.md` updated; `PROGRESS.md`/`feature_list.json` updated with evidence.
- [ ] No unrelated product behavior or extra feature work was added.
