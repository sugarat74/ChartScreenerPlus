# Feature Implementation Spec: Enforce anonymous vs registered access

## Source Feature

- `id`: `access-control-guard`
- `area`: `security`
- `depends_on`: `watchlist`, `saved-screeners`, `screener-filters-ui` (all accepted)
- `status`: `not_started` at planning time
- `source`: `feature_list.json`

## Goal

Consolidate and prove the existing access boundary without rebuilding accepted resources: a Visitor can
browse the Screener and Charts, while only a session user can save Screeners or maintain their own
Watchlist. Admin ingestion remains an admin-only, server-enforced boundary. UI guards and sign-in
affordances must accurately reflect those rules, but Laravel remains the authorization authority.

## Non-Goals

- No auth/Sanctum redesign, destination/return-to implementation, role/RBAC expansion, or new endpoint.
- No Saved Screener or Watchlist API, schema, ownership-query, or behavior redesign.
- No User Portal, Admin panel, Screener, Chart, or visual redesign beyond narrow guard/affordance fixes.
- No policy framework, generic ownership middleware, new client cache, frontend test framework, or E2E harness.

## Job Story

When I browse market data as a Visitor or act on my personal workspace as a Registered User,
I want access rules to be predictable and private,
so I can freely inspect Candidates while personal and operator actions cannot expose or change another user's data.

## Users And Permissions

- **Visitor:** may use `/screener`, `/chart`, `/instruments/:ticker`, `GET /api/screener`, and `GET /api/instruments/:ticker`; cannot use owned-resource or admin APIs.
- **Registered User (including Admin for owned resources):** may save/list/delete only their Saved Screeners and list/add/remove only their Watchlist.
- **Admin:** retains the existing global ingestion boundary only through `auth:sanctum` then `admin`; admin status grants no access to another user's owned resources.

## Consolidation Decisions

1. **Route strategy is deliberately small.** `/portal` is the only route that contains exclusively registered-user content and remains wrapped once by `RequireAuth`. It waits for auth bootstrap, then uses `<Navigate to={LOGIN_ROUTE} replace />` for a Visitor. Do not introduce router loaders or a global protected-route registry.
2. **No destination preservation in this slice.** The existing redirect does not append `?redirect=`, router state, or another return target. `replace` avoids leaving inaccessible `/portal` in browser history. Login/register continue their existing post-auth behavior. This is intentional scope control; preserving destinations remains a future UX enhancement.
3. **`/admin` is not a Registered-User route guard.** Keep its existing loading, guest sign-in-link, and authenticated-non-admin restricted states; it does not redirect. Its API group remains the enforcement mechanism and must keep the guest `401` / non-admin `403` ordering.
4. **Embedded actions stay in place.** The Saved Screeners panel on anonymous `/screener` and the Watchlist button on anonymous chart pages show a plain `LOGIN_ROUTE` link, never redirect, modal, disabled fake mutation, or page-level login prompt. A protected API `401` during a previously authenticated interaction continues to be handled as session expiry by redirecting to `/login`.
5. **Existing explicit ownership scoping is sufficient.** Retain `$request->user()->watchlist()` and `$request->user()->savedScreeners()` for every owned-resource query and the existing no-`user_id` APIs. Do not add policies or ownership middleware: neither resource has generic route-model binding, their identifiers differ (ticker vs id), and the current explicit scope is tested and prevents existence leaks.

## Acceptance Scenarios

### Scenario 1: Anonymous browsing remains public

Given no authenticated session,
When a Visitor opens `/screener`, `/chart`, or `/instruments/NVDA` and calls the two public GET APIs,
Then the pages remain browseable without a login redirect and `GET /api/screener` / `GET /api/instruments/NVDA` are not protected by `auth:sanctum` or `admin`.

### Scenario 2: Protected route and in-place affordances

Given auth bootstrap has resolved to no user,
When a Visitor opens `/portal`,
Then `RequireAuth` shows its checking state only while loading and replaces the location with `/login` once known, without a preserved destination.

Given the Visitor stays on `/screener` or `/instruments/NVDA`,
When they reach the Saved Screener or Watchlist action,
Then that action offers its sign-in link and the public screen remains visible and usable.

### Scenario 3: Guest APIs and admin status contract

Given no session,
When the Visitor calls every Watchlist or Saved Screener route,
Then each response is `401` and no owned row is written or removed.

Given an admin API action,
When the caller is a guest, a non-admin, or an admin,
Then the existing boundary returns `401`, `403`, and the documented success response respectively; UI visibility never substitutes for this check.

### Scenario 4: Ownership is hidden server-side

Given user A owns a Watchlist Entry and a Saved Screener and user B owns neither,
When B lists both collections and attempts the foreign Watchlist ticker deletion and foreign Saved Screener id deletion,
Then both lists exclude A's records, both mutations return `404`, and A's rows remain unchanged.

## Repository Research

### Files Inspected

- `AGENTS.md`, `PROGRESS.md`, `feature_list.json` — baseline passed on 2026-09-30 (150 Laravel tests, SPA lint/build, 45 engine tests); feature metadata and dependencies are accepted.
- `docs/user-and-access-model.md`, `docs/domain-model.md`, `DESIGN.md`, `ARCHITECTURE.md`, `CONSTRAINTS.md` — access vocabulary, ownership, route/API boundaries, and UI/a11y requirements.
- `docs/specs/auth-registration-login.md`, `watchlist.md`, `saved-screeners.md`, `user-portal.md` — accepted auth, ownership, portal, guest-affordance, and verification decisions.
- `routes/api.php` — public reads, one `auth:sanctum` owned-resource group, and the ordered `['auth:sanctum', 'admin']` admin group.
- `frontend/src/router.tsx`, `auth/RequireAuth.tsx`, `pages/{PortalPage,AdminPage}.tsx` — `/portal` redirect guard and `/admin` restricted-state pattern.
- `frontend/src/components/{watchlist/WatchlistButton,screener/SavedScreenersPanel}.tsx` — guest links and existing `401` session-expiry handling.
- `app/Http/Controllers/{WatchlistController,SavedScreenerController}.php` and `tests/Feature/{WatchlistApiTest,SavedScreenersApiTest,AdminAccessTest,AdminIngestionApiTest}.php` — server-side scoping and present per-resource 401/ownership coverage.

### Existing Patterns To Follow

- Sanctum session auth with `credentials: 'include'` and CSRF before mutations; API middleware is authoritative.
- `RequireAuth` is component-level because the data router has no auth loaders; its `replace` redirect is already established.
- Owned controllers resolve through the authenticated user's relationship; foreign rows yield `404`, not `403` or an existence leak.
- Components use DESIGN tokens, real links/buttons, focus shadows, `role="status"` while loading, and `role="alert"` for failures.

### Current Gaps

- The behavior is distributed across accepted features; there is no focused security-consolidation test/audit that exercises the public, guest, ownership, and admin contracts together.
- There is no browser/E2E runner, so route redirects and in-place link rendering require focused manual browser/live-proxy evidence.

## Technical Approach

First audit the existing routes, components, controllers, and feature tests against the decisions above. Make only corrective changes found by that audit (for example, a missing `RequireAuth` wrapper, an incorrect guest redirect, or a missing session-expiry branch). Do not refactor correct controllers into policies/middleware or alter frozen public/API contracts.

Add a focused Laravel feature test (prefer `tests/Feature/AccessControlGuardTest.php`) for cross-cutting assertions not already proved: public GET routes remain accessible to a guest; guest requests to every owned-resource route are `401`; and one two-user fixture proves both owned resource types return caller-only lists plus cross-owner `404` mutations with owner rows intact. Keep the established resource suites; this test is a consolidation regression test, not a replacement. Reuse or extend `AdminAccessTest`/`AdminIngestionApiTest` only if their current every-endpoint 401/403 coverage has an actual gap.

On the SPA, retain the present component strategy. Add no raw fetch calls: `RequireAuth`, `savedScreenersApi`, `watchlistApi`, and `adminIngestionApi` remain the only relevant seams. A UI edit is justified only if the audit finds behavior inconsistent with the decisions.

## Expected File Changes

- `tests/Feature/AccessControlGuardTest.php` — create; narrow cross-cutting public/guest/dual-owned-resource regression coverage.
- `frontend/src/auth/RequireAuth.tsx` — modify only if needed to preserve the explicit loading/replace/no-destination contract.
- `frontend/src/pages/{PortalPage,AdminPage}.tsx` and `frontend/src/components/{watchlist/WatchlistButton,screener/SavedScreenersPanel}.tsx` — modify only if audit finds a guard/link/session-expiry mismatch.
- `routes/api.php`, owned controllers, models, migrations, `frontend/src/router.tsx`, `frontend/src/lib/api.ts` — normally not changed; change only to correct a demonstrated contract defect.
- `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/user-and-access-model.md` — update only if implementation reveals a durable rule not already documented; expected outcome is **not needed** because they already state the selected decisions.
- `AGENTS.md`, `DESIGN.md`, `init.ps1`, engine, and `alphapulse/` — not changed.
- `PROGRESS.md`, `feature_list.json`, this spec — update after implementation and verification with actual evidence/status; do not mark accepted without independent validation.

## Visual Design Impact

- UI involved: yes, narrow guard and sign-in-link states only.
- Design source: `DESIGN.md` and current token-styled components.
- States: Portal session checking and redirect; Screener/Chart guest links; Admin loading/guest/restricted states.
- New design artifact required: no. Preserve 2px borders, hard shadows, yellow focus offsets, semantic links/buttons, and keyboard reachability; do not add a modal or new color.

## Durable Documentation Impact

- `ARCHITECTURE.md`: not needed — its routing, authorization, Watchlist, Saved Screeners, and Admin sections already describe the target boundary.
- `CONSTRAINTS.md`: not needed — its auth, authorization, owned-resource, chart, and public API MUST rules already freeze the target behavior.
- `AGENTS.md`: not needed — no workflow or startup change.
- `docs/user-and-access-model.md`: not needed — already defines Visitor/Registered/Admin capabilities and concrete endpoint behavior.

## Implementation Plan

1. Audit the listed backend routes/controllers/tests and SPA guards/affordances against the consolidation decisions; record any genuine mismatch.
2. Add the focused cross-cutting Laravel regression test and make the smallest UI/guard correction only if the audit requires it.
3. Run focused/full verification, a live two-user/guest proxy smoke, and manual browser checks; update harness evidence and durable docs only if behavior changed.

## Implementation Tasks

- [x] Confirm `/portal` is the sole Registered-User route redirect, `/admin` retains restricted states, and public routes have no auth middleware.
- [x] Confirm guest affordances are links in the anonymous Screener/Chart, while a `401` after a signed-in protected action redirects to login.
- [x] Add `AccessControlGuardTest` covering public guest reads, owned-route guest `401`s, and both cross-owner `404` cases with owner rows intact.
- [x] Audit existing admin feature tests for every endpoint's guest `401` and non-admin `403`; add only a missing assertion.
- [x] Make only audit-proven narrow UI/guard fixes; do not introduce policies/middleware or refactor scoped controllers.
- [x] Run verification and record evidence in `PROGRESS.md` and `feature_list.json` (`passing` only after self-verification).

## Implementation Findings

- The backend route/controller audit matched the frozen access contract. Existing resource suites already cover every admin ingestion endpoint's guest `401` and non-admin `403`, so no backend change was warranted.
- `WatchlistButton` was the sole correction: its authenticated membership read and toggle previously rendered an inline error on a `401`; both now redirect to `LOGIN_ROUTE` with `replace`, matching the existing Saved Screeners and Portal session-expiry handling. Visitor behavior remains the existing in-place sign-in link.
- No browser/E2E runner exists. API behavior is covered by Laravel tests; the guard/link/no-destination behavior was audited from the routed components and typechecked/built.

## Verification Plan

- `php artisan test --filter=AccessControlGuardTest` and `php artisan test --filter="WatchlistApiTest|SavedScreenersApiTest|AdminAccessTest|AdminIngestionApiTest"` pass; then `php artisan test` is green.
- `php artisan route:list --path=api -v` confirms public `GET api/screener` / `GET api/instruments/{ticker}`, owned routes with `Authenticate:sanctum`, and all admin ingestion routes with `Authenticate:sanctum` then `EnsureUserIsAdmin`.
- `npm --prefix frontend run lint` and `npm --prefix frontend run build` pass with zero errors/warnings.
- Live stateful proxy smoke (Laravel `:8000`, Vite `:5173`, then teardown): guest public Screener/detail GETs succeed; guest owned GET/POST/DELETE calls receive `401` (seed CSRF for mutations to avoid a 419 masking the auth result); user A owns one item of each type; B lists neither and gets `404` on both foreign deletes while A remains; guest `/portal` redirects to `/login`; guest `/screener` and `/instruments/NVDA` remain visible with their sign-in links; non-admin admin API is `403`, admin succeeds. Remove smoke users/rows and release ports.
- Manual browser check: while auth loads Portal shows the status state; once guest it redirects with no destination query/state; browser Back does not leave the inaccessible Portal as the current page; keyboard can reach each guest sign-in link and public content remains usable.
- Persistent E2E: none exists. Do not add a framework in this consolidation feature; Laravel API tests plus live proxy/manual browser evidence are the available coverage.
- `./init.ps1` (`.\init.ps1` in PowerShell) passes unchanged, performs the non-blocking gate, and starts no long-running server.

## Evidence To Capture

- Focused/full Laravel test counts and route middleware output.
- SPA lint/build output and `init.ps1` exit 0.
- Guest/A/B/admin smoke request status log, owner-row integrity assertions, redirect/link observations, teardown, released ports, and restored DB counts.
- Manual browser/accessibility findings, including explicit confirmation that destination preservation is intentionally absent.
- Any corrective file diff and confirmation that no policy/middleware refactor, new endpoint, or API contract change was made.

## Validator Checklist

- [ ] Implementation is a narrow consolidation/test/guard slice, not an auth or resource redesign.
- [ ] Visitor browsing works for both Screener and Chart routes/APIs without auth.
- [ ] `/portal` redirects a resolved Visitor to login with `replace` and no preserved destination; `/admin` retains its non-redirect restricted-state behavior.
- [ ] Anonymous Screener and Chart action affordances are sign-in links, never page redirects; signed-in `401` remains session-expiry handling.
- [ ] Every Watchlist and Saved Screener route returns `401` to guests; both resources prove caller-only listing and foreign delete `404` with owner data intact.
- [ ] Admin APIs remain server-enforced (`401` guest, `403` non-admin) across every endpoint.
- [ ] Explicit user-scoped relations remain in place; no unnecessary policy/middleware abstraction was added.
- [ ] No E2E harness exists; focused API tests, live smoke, and manual browser evidence are recorded.
- [ ] `feature_list.json` and `PROGRESS.md` are updated correctly; durable docs change only if implementation changes their existing rules.
