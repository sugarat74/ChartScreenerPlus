# Feature Implementation Spec: User portal for screeners and watchlist

## Source Feature

- `id`: `user-portal`
- `area`: `portal`
- `depends_on`: `watchlist`, `saved-screeners`, `app-shell-navigation` (all `accepted`)
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Extend the existing guarded `/portal` route so a Registered User can see and manage both of their
owned resource types in one place: Saved Screeners and Watchlist Entries. The portal must use the
already accepted ownership-scoped APIs; it must not introduce another data model, endpoint, or
authorization mechanism.

From the portal, applying a Saved Screener takes the user to `/screener` with its canonical stored
filters reconstructed in the URL. The existing Screener URL/fetch flow then restores the server-side
Candidate list and ranking. Deleting a Saved Screener uses the protected delete endpoint and refreshes
only the current session user's Saved Screener list.

## Non-Goals

- No new Laravel migration, model, controller, route, API contract, or ownership/access-control
  consolidation; `access-control-guard` remains later work.
- No Saved Screener create/rename/edit UI in the portal. Saving remains where filters are built,
  on `/screener`; portal management here means list, apply, and delete.
- No Watchlist add flow or enrichment (price, P&L, RVOL, pivots, sparklines); add remains on the
  chart and the existing Portal watchlist list/remove behavior remains authoritative.
- No prototype-only PRO plan, subscription, observed capital, P&L, alerts, notifications, Copilot,
  fake categories/descriptions, hit counts, or automatic execution.
- No new npm dependency, frontend test/E2E framework, route/tab, engine work, or `init.ps1` change.

## Job Story

When I am a Registered User reviewing my personal trading setup,
I want one Portal that shows my Saved Screeners and Watchlist,
so I can re-run or remove a Saved Screener and inspect or remove followed instruments without
searching through separate surfaces.

## Users And Permissions

- **Visitor:** `/portal` continues through `RequireAuth` to `/login`; protected API requests still
  receive `401`. The redirect is UI affordance, not enforcement.
- **Registered User:** may list, apply, and delete only their Saved Screeners and list/remove only
  their Watchlist Entries.
- **Admin:** has the same per-user portal scope; admin status never grants access to another user's
  private Saved Screeners or Watchlist.

## Acceptance Scenarios

### Scenario 1: Portal shows the signed-in user's two collections

Given user A owns Saved Screeners and Watchlist Entries and user B owns different records,
When A opens `/portal`,
Then the Portal renders A's Saved Screeners and A's Watchlist Entries in distinct labelled sections,
with their existing deterministic server order, and no B record is displayed.

### Scenario 2: Apply a Saved Screener from the Portal

Given A has a Saved Screener with the canonical seven-key definition including `sort`,
When A selects `Aplicar` in the Portal,
Then the SPA navigates to `/screener` with the definition reconstructed through the existing URL
filter helper, and the Screener's existing effect requests and renders the corresponding ranked
Candidate list; no client-side filtering or ranking is added.

### Scenario 3: Delete the owner's Saved Screener

Given A's Portal shows Saved Screener `Momentum`,
When A selects `Eliminar` and `DELETE /api/screeners/{id}` succeeds with `204`,
Then `Momentum` no longer appears after the caller's Saved Screener list is refreshed, while A's
Watchlist and any other user's data are unchanged.

### Scenario 4: Ownership and expired session behavior

Given user B does not own A's Saved Screener or Watchlist Entry,
When B loads their Portal or attempts A's Saved Screener id through the API,
Then B sees only B's lists and the delete is `404`, leaving A's row intact.
Given the Portal's session expires during a protected list or mutation,
Then the SPA redirects to `/login`; server-side `auth:sanctum` remains the authority.

### Scenario 5: Anonymous Portal access

Given no authenticated session,
When a Visitor opens `/portal`,
Then `RequireAuth` shows its checking state while auth bootstraps and redirects to `/login` once the
Visitor state is known; it must not render either collection or call an unprotected substitute API.

## Repository Research

### Files Inspected

- `AGENTS.md`, `PROGRESS.md`, `feature_list.json` — workflow, baseline and selected feature metadata;
  `user-portal` is dependency-ready and baseline `./init.ps1` passed (150 Laravel tests, SPA lint/build,
  45 engine tests) on 2026-09-30.
- `docs/domain-model.md`, `docs/user-and-access-model.md` — private ownership and Saved Screener/
  Watchlist lifecycles and access rules.
- `DESIGN.md`, `ARCHITECTURE.md`, `CONSTRAINTS.md` — Portal design, route/auth structure, owned API
  contracts, URL-backed screener state, and durable constraints.
- `docs/specs/watchlist.md`, `docs/specs/saved-screeners.md` — accepted resource/API/UI decisions and
  verification conventions.
- `alphapulse/src/components/UserPortalView.tsx` — prototype layout intent only; its PRO/P&L, alerts,
  notification settings, categories, descriptions, hit counts, and fake data are explicitly rejected.
- `frontend/src/pages/PortalPage.tsx`, `auth/RequireAuth.tsx`, `components/watchlist/WatchlistTable.tsx`
  — existing guarded portal and watchlist rendering/removal pattern.
- `frontend/src/components/screener/SavedScreenersPanel.tsx`, `pages/ScreenerPage.tsx` — current save,
  list/apply/delete UI and URL patch flow on the anonymous Screener surface.
- `frontend/src/lib/{api.ts,screenerFilters.ts}`, `router.tsx`, `nav.ts`, `index.css` — API clients/types,
  canonical filter helpers, unchanged `/portal` route/tab, and design tokens.

### Existing Patterns To Follow

- `RequireAuth` is the component-level `/portal` guard; its redirect does not replace API enforcement.
- `watchlistApi` and `savedScreenersApi` already centralize credentialed requests, CSRF before every
  mutation, response parsing, and `ApiError`; use them rather than raw `fetch` or copied API wrappers.
- Both API lists are server-scoped to `$request->user()`; `GET /api/watchlist` orders ticker ASC and
  `GET /api/screeners` orders name then id. Preserve received order.
- `deserializeScreenerFilters` plus `patchScreenerFilters` are the canonical safe Saved Screener
  replay path. `ScreenerPage` already refetches from `screenerFiltersKey`.
- Existing panels use an active-flag effect, per-row busy state, `role="alert"`, `Reintentar`, and a
  `401` -> `LOGIN_ROUTE` redirect compatible with current oxlint rules.

### Current Gaps

- `PortalPage` currently says Saved Screeners will arrive later and renders only its private inline
  `WatchlistPanel`.
- The existing `SavedScreenersPanel` is deliberately coupled to saving the current `/screener` filters;
  it cannot be mounted unchanged as the Portal's list/apply section.
- No browser test runner or persistent E2E harness exists.

## Technical Approach

Keep `/portal`, `NAV_ITEMS`, and the router unchanged. Retain its outer `RequireAuth` and its existing
watchlist section. Add a **portal-specific Saved Screeners list component/panel**, rather than moving
the `/screener` save panel or creating a second API layer. This component reuses `SavedScreener`,
`savedScreenersApi.list/remove`, `ApiError`, and `deserializeScreenerFilters`; it does not duplicate
request/CSRF/error parsing logic. Its responsibilities are portal-only list loading, per-row delete
state, and apply navigation. The `/screener` `SavedScreenersPanel` remains the save-and-apply affordance
for current filters and is not refactored unless a small presentation-only extraction demonstrably
reduces duplication without changing its behavior.

On apply, deserialize the stored definition and construct a fresh `/screener` search string with
`patchScreenerFilters(new URLSearchParams(), patch)`, then `navigate` to `/screener` plus that query.
This deliberately does not preserve Portal query parameters. The canonical helper omits default `sort`
from the URL while `ScreenerPage` still sends it on every API request. Do not call `screenerApi.search`
from the portal and do not reconstruct filter query names manually.

On delete, call `savedScreenersApi.remove(id)`. After `204` (and after a `404`, treated as already gone),
reload only the authenticated caller's list via `savedScreenersApi.list()`; do not mutate any shared or
cross-user cache. A `401` on either collection request/mutation redirects to `LOGIN_ROUTE`; other
failures remain an inline alert/retry. Existing Watchlist removal behavior remains unchanged.

## Expected File Changes

- `frontend/src/pages/PortalPage.tsx` — modify: replace the deferred Saved Screeners copy with the
  combined portal layout; retain `RequireAuth` and the watchlist behavior.
- `frontend/src/components/portal/PortalSavedScreeners.tsx` — create: portal-specific authenticated
  Saved Screeners list with loading/empty/error/apply/delete states and caller-list refresh.
- `ARCHITECTURE.md` — modify: change `/portal` and Portal UI documentation from watchlist-only to both
  owned collections, including portal apply navigation/reuse of existing endpoints.
- `CONSTRAINTS.md` — modify: add a narrow Portal rule that it reuses the accepted owned-resource APIs,
  preserves `/portal` guarding, and replays Saved Screeners through the canonical URL helper (no new
  portal API/data model or client-side screening).
- `docs/user-and-access-model.md` — not needed: private ownership/endpoints already state the required
  behavior; no permission changes.
- `frontend/src/lib/api.ts`, `frontend/src/lib/screenerFilters.ts`, `routes/api.php`, Laravel code,
  migrations, `router.tsx`, `nav.ts`, `init.ps1`, `engine/`, and `alphapulse/` — not changed.
- `docs/specs/user-portal.md`, `PROGRESS.md`, `feature_list.json` — update at implementation/verification
  time; no feature status change until self-verification evidence exists.

## Visual Design Impact

- UI involved: yes. Design source: `DESIGN.md`; `alphapulse/UserPortalView.tsx` informs two-collection
  grouping only, not data/feature requirements.
- Screens/states: guarded `/portal`; Saved Screeners loading, ready, empty, list error/retry, removing,
  apply navigation; existing Watchlist states remain.
- Layout: a `max-w-7xl` Portal header followed by two clearly headed, responsive sections/cards. A
  compact sub-navigation or stacked sections is acceptable; do not copy prototype tabs for alerts or
  notification settings. Maintain a usable desktop/tablet layout and horizontal overflow for tables.
- Use the existing warm surfaces, 2px outline borders, 2px hard shadows, small radii, yellow primary
  action, outlined secondary delete action, uppercase Space Grotesk headings, mono names/counts/status,
  visible yellow focus offsets, semantic headings, real table headers where a table is used, and
  `role="alert"` errors. Do not add hues, soft shadows, PRO badges, P&L, or decorative metrics.
- New design artifact required: no.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — Portal now aggregates both already-owned resources and documents apply
  navigation without changing the backend boundary.
- `CONSTRAINTS.md`: update — preserve the narrow, durable reuse/no-new-API/canonical-URL Portal rule.
- `AGENTS.md`: not needed — no workflow, runtime, or startup change.
- `docs/user-and-access-model.md`: not needed — its implemented private resource permissions already
  cover this aggregation.
- `DESIGN.md`: not needed — existing Portal and token guidance is sufficient.

## Implementation Plan

1. Review the accepted owned-resource clients and current Portal, then add the portal-specific Saved
   Screener component using only the existing API/types/helpers.
2. Integrate it into `/portal` beside the retained Watchlist section; wire apply to canonical
   `/screener` navigation and delete to caller-list refresh.
3. Add focused frontend coverage only if the repository gains a runner during implementation; otherwise
   run lint/build, live authenticated two-user smoke, and manual browser checks.
4. Update durable documentation and record verification evidence/status in the harness artifacts.

## Implementation Tasks

- [x] Create `PortalSavedScreeners` using `savedScreenersApi.list/remove`, `ApiError`, and
  `deserializeScreenerFilters`; implement loading, empty, error/retry, per-row removal, and `401` expiry.
- [x] Make portal apply navigate to the canonical reconstructed `/screener` URL; verify the existing
  Screener fetch effect (not Portal code) obtains the results.
- [x] Extend `PortalPage` to render the Saved Screeners and existing Watchlist as one guarded Portal;
  remove the "added later" copy without changing Watchlist add/remove semantics.
- [x] Keep portal delete scoped to the session API and reload only the caller's Screener list after
  `204`/`404`; render no owner/user id and add no raw fetch/API module.
- [ ] Apply DESIGN.md token/a11y rules and manually validate responsive, keyboard/focus, loading/empty,
  error, and destructive-action busy states.
- [x] Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, and `feature_list.json` with actual
  verification evidence; do not modify `init.ps1`.

## Implementation Findings

- `PortalSavedScreeners` intentionally duplicates only the presentational criteria summary from the
  Screener save panel. It keeps the Portal independent of the current-filter save affordance while
  reusing the accepted API client, error type and canonical filter helpers.
- A Portal delete reloads `savedScreenersApi.list()` after both `204` and `404`; the latter is the
  accepted "already absent" response and avoids a client-owned or cross-user cache.
- No browser/E2E runner exists. The live stateful proxy smoke exercised the API ownership, delete and
  apply-result path and confirmed the Portal component module is served; visual/focus states were
  reviewed against the token classes and semantic table/buttons in code.

## Verification Plan

- `php artisan test --filter="WatchlistApiTest|SavedScreenersApiTest"` and `php artisan test` remain
  green; no new backend contract is expected. Confirm `php artisan route:list --path=api -v` still has
  the accepted `auth:sanctum`-only owned routes and no portal endpoint.
- `npm --prefix frontend run lint` and `npm --prefix frontend run build` succeed with zero lint warnings
  or errors.
- No frontend test runner/E2E command exists, so no persistent E2E test can be added. Live smoke with
  Laravel on `:8000` and Vite on `:5173` through the proxy: create/login A and B with stateful origin;
  seed A and B each with a Saved Screener and Watchlist Entry; A's Portal calls show only A's lists;
  B's show only B's; attempt B delete of A's Screener -> `404`, A remains; A delete -> `204`, then the
  portal reload/list omits it; apply A's remaining Screener and confirm `/screener` URL plus API result
  order matches the stored canonical filters; anonymous `/portal` redirects to `/login`. Tear down
  processes, free ports, and remove smoke fixtures/users.
- Manual browser checklist: the two collections are clear and token-faithful; apply restores filters,
  ranking and Candidate results; list/remove/error/retry/busy states work; tab/focus order and links are
  keyboard usable; no prototype PRO/P&L/alerts/notifications render.
- `./init.ps1` (PowerShell: `.\init.ps1`) exits 0, remains a non-blocking gate, and starts no server.

## Evidence To Capture

- Focused/full Laravel test output, route-list confirmation, SPA lint/build output, and `init.ps1` exit.
- Two-user/guest smoke status log proving private lists, cross-user `404`, own delete and caller-only
  refresh, apply URL/result equivalence, teardown/port release/DB restoration.
- Manual Portal state/design/accessibility checklist results and confirmation no new API, migration,
  access-control consolidation, or prototype-only product behavior was introduced.

## Validator Checklist

- [ ] `/portal` remains the single guarded Portal route and displays the current user's Saved Screeners
  and Watchlist in one place.
- [ ] The portal uses existing `savedScreenersApi`/`watchlistApi`, protected endpoints, and canonical
  filter helpers; no backend/API/data-model/access-control work was added.
- [ ] Portal apply navigates to `/screener` with reconstructed canonical filters and restores the
  server-ranked result, including `sort`.
- [ ] Own delete calls protected `DELETE`, refreshes only the caller list, and cross-user list/delete
  behavior remains server-enforced (`[]`/`404`, owner unchanged).
- [ ] Visitors redirect to login; a `401` while signed in is handled as session expiry.
- [ ] DESIGN.md and accessibility baseline are met; no PRO/P&L, alerts, notifications, or other
  prototype-only/out-of-scope behavior is rendered.
- [ ] No persistent E2E harness exists; lint/build, API tests, live smoke, and manual browser evidence
  are captured. `PROGRESS.md` and `feature_list.json` are updated correctly after implementation.
- [ ] Scope is limited to this feature and `init.ps1` remains a non-blocking gate.
