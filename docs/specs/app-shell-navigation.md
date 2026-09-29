# Feature Implementation Spec: Build the app shell and navigation

## Source Feature

- `id`: `app-shell-navigation`
- `area`: `frontend`
- `depends_on`: `repo-scaffold-frontend`
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Give the SPA its product chrome: a sticky header (brand, market/EOD status, primary action, user area) and a tab navigation that switches between the main surfaces using real routes. After this feature a user can navigate Screener / Chart / Admin / Portal by URL or tab, and each surface renders a placeholder until its own feature lands.

## Non-Goals

- No real product screens (screener filters, charts, portal data) — those are later features.
- No authentication, login or role gating (that is `access-control-guard` / `auth-registration-login`); the shell renders all tabs.
- No API calls, data fetching or TanStack Query.
- No AI Copilot tab (out of MVP scope).
- No EOD ingestion action: the "Actualizar EOD" control is inert/placeholder this slice.
- No deployment/SPA-fallback server configuration.

## Job Story

When I open the app,
I want a consistent header and tabs that move me between surfaces and reflect the current URL,
so I can navigate the product without full page reloads and can deep-link to a surface.

## Users And Permissions

- Visitor (anonymous): can navigate all shell routes this slice. Real per-route access control is enforced server-side in later features.

## Acceptance Scenarios

### Scenario 1: Header chrome renders

Given the SPA running,
When I open any route,
Then I see the header with the `αP` brand, the "Mercado Cerrado (EOD)" status, a primary "Actualizar EOD" button and the user area, styled with `DESIGN.md` tokens.

### Scenario 2: Tabs navigate without reload

Given the header is visible,
When I click a tab (Screener / Chart / Admin / Portal),
Then the URL changes to that route and the surface swaps without a full page reload, and the clicked tab is marked active.

### Scenario 3: Deep links resolve

Given the app is running,
When I open `/portal` (or `/screener`, `/chart`, `/admin`) directly,
Then that surface renders and its tab is active.

### Scenario 4: Unknown route

Given the app is running,
When I open an unknown path,
Then a token-styled not-found placeholder renders (no crash).

### Scenario 5: Responsive tab bar

Given a narrow (tablet/mobile-width) viewport,
When the header is shown,
Then the tab bar stays usable (horizontal scroll) and the brand/status remain readable.

## Repository Research

### Files Inspected

- `frontend/package.json` — scripts and deps; **no router installed** yet.
- `frontend/src/main.tsx` — mounts `<App />` inside `StrictMode`.
- `frontend/src/App.tsx` — current single token-styled placeholder (to be replaced by the routed shell).
- `frontend/src/index.css` — `@theme` tokens ported from `DESIGN.md`.
- `DESIGN.md` — header/tab/button tokens and neo-brutalist rules.
- `alphapulse/src/components/Header.tsx` — reference for the header and 5-tab bar (Copilot tab is out of MVP scope).
- `alphapulse/src/App.tsx` — reference for the surface set and tab labels.
- `ARCHITECTURE.md` / `CONSTRAINTS.md` — SPA lives in `frontend/`; keeps its own toolchain.
- `init.ps1` — already runs the SPA `lint` + `build`.

### Environment Findings (probed, not assumed)

- Node v22.21.0 / npm 11.8.0; Vite 8.3.1, React 19.3.0, Tailwind 4.3.3, TypeScript 6.0.2, oxlint.
- `react-router` latest is **8.4.0** (v7+ ships DOM bindings; `react-router-dom` is legacy). Not installed.
- **Engine caveat found at install time:** `react-router@8.4.0` declares `engines.node >=22.22.0`, but the environment runs Node **v22.21.0**. A bare `npm install react-router` therefore resolves to **7.18.4**; installing `react-router@8.4.0` explicitly succeeds with an `EBADENGINE` warning. `package.json` pins `^8.4.0`. See "Findings During Implementation".
- No test runner (no Vitest) and no E2E harness in the frontend.

### Existing Patterns To Follow

- Tokens and component look come from `DESIGN.md` and `frontend/src/index.css` (`bg-surface`, `text-on-surface`, `border-outline`, `bg-primary-container`, `font-headline`, `font-mono`, hard shadows).
- `alphaP` brand mark and 2px-border/hard-shadow treatment are already used in the scaffold placeholder.
- PowerShell 5.1-compatible commands; two independent Vite pipelines (Laravel root vs `frontend/`).

### Current Gaps

- No routing layer, no layout component, no per-surface pages.
- No shared header/nav component.
- Deep-link production fallback is not configured (deferred; Vite dev server already serves history fallback).

## Technical Approach

1. **Add `react-router` (v8).** Install `react-router` and use the data router (`createBrowserRouter` + `RouterProvider`) with a layout route that renders the header and an `<Outlet />`.
2. **Routes.** `/` redirects to `/screener`; routes: `/screener`, `/chart`, `/admin`, `/portal`; `*` → Not Found.
3. **Layout.** `AppLayout` renders `AppHeader` + `<Outlet />` and keeps the page background/token frame.
4. **Header.** `AppHeader` renders the sticky brand block (`αP` + `ALPHAPULSE` + `EOD` badge), an EOD status chip, an inert "Actualizar EOD" primary button, and a placeholder user pill. Tabs are `NavLink`s whose active style matches `DESIGN.md` (2px bottom border + surface fill).
5. **Pages.** Placeholder pages (`ScreenerPage`, `ChartPage`, `AdminPage`, `PortalPage`, `NotFoundPage`) are token-styled stubs naming the future feature; no data.
6. **Map tabs to routes** in one array so labels and paths stay in sync (no duplicated strings).
7. **`init.ps1` unchanged** (already runs SPA `lint` + `build`).

## Expected File Changes

- `frontend/package.json`, `frontend/package-lock.json` — modify; add `react-router`.
- `frontend/src/main.tsx` — modify; mount `RouterProvider`.
- `frontend/src/App.tsx` — modify/replace; define the router/routes (or move to `src/router.tsx`).
- `frontend/src/components/AppHeader.tsx` — create; header + tab nav (`NavLink`).
- `frontend/src/layouts/AppLayout.tsx` — create; header + `<Outlet />` frame.
- `frontend/src/pages/ScreenerPage.tsx`, `ChartPage.tsx`, `AdminPage.tsx`, `PortalPage.tsx`, `NotFoundPage.tsx` — create; token-styled placeholders.
- `frontend/src/nav.ts` (or similar) — create; tab/route definitions.

Modified durable docs:

- `ARCHITECTURE.md` — update; record the routing approach and the route list.
- `CONSTRAINTS.md` — update; router/route conventions.
- `PROGRESS.md`, `feature_list.json` — update with evidence.

## Visual Design Impact

- UI involved: yes (product chrome).
- Design source: `DESIGN.md` (source of truth) and `alphapulse/src/components/Header.tsx` (reference).
- Screens or states affected: header, tab bar, per-surface placeholders, not-found state.
- New design artifact required: no.
- The header must match `DESIGN.md`: 2px ink borders, hard offset shadows, accent (`#ffcc00`) primary button, mono status text, headline tabs with a 2px active underline.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — record that the SPA uses `react-router` and list the shell routes; note the future SPA-fallback need.
- `CONSTRAINTS.md`: update — MUST rules: navigation uses `react-router` routes (not state-only tabs); tab labels/paths come from one source; no Copilot route in the MVP.
- `AGENTS.md`: not needed — the frontend location and commands are already documented, and the startup path is unchanged.
- `DESIGN.md`: not needed — existing tokens/components cover the header and tabs.
- Other docs: `PROGRESS.md` and `feature_list.json` — update with evidence.

## Key Implementation Risks

- **react-router v8 API drift** — v8 is new; confirm the exact exports (`createBrowserRouter`, `RouterProvider`, `NavLink`, `Outlet`, `Navigate`) at install time before coding.
- **Deep links in production** — history routing needs a server SPA fallback when deployed; not in this slice, but record it so `admin`/deployment work does not ship broken links.
- **Scope creep into real screens** — placeholders must stay stubs; do not add filters, charts or data.
- **Admin/Portal visible without auth** — acceptable now; `access-control-guard` will enforce access. Do not fake protection in the UI.

## Implementation Plan

1. Install `react-router` in `frontend/`.
2. Define the route/tab map and the router (`createBrowserRouter`) with the layout route and the `/` redirect.
3. Build `AppHeader` (brand, status, inert primary action, user pill, `NavLink` tabs).
4. Build `AppLayout` and the placeholder pages + Not Found.
5. Wire `main.tsx` to `RouterProvider`.
6. Verify: `lint`, `build`, dev-server route smoke; run `.\init.ps1`.
7. Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [x] `npm --prefix frontend install react-router` and confirm the v8 API surface.
- [x] Create the route/tab definitions and the router with `/` → `/screener` redirect and `*` → NotFound.
- [x] Create `AppLayout` (header + `Outlet`) and `AppHeader` with active-tab styling.
- [x] Create the four surface placeholders + Not Found, all token-styled.
- [x] Wire `RouterProvider` in `main.tsx`; remove the old placeholder `App`.
- [x] Confirm clicking tabs and deep-linking update the URL without a full reload.
- [x] Run `npm --prefix frontend run lint` and `run build`; run `.\init.ps1`.
- [x] Update `ARCHITECTURE.md` and `CONSTRAINTS.md`.
- [x] Update `PROGRESS.md` and `feature_list.json`.

## Findings During Implementation

- **react-router v8 engine mismatch (not in the original spec).** `react-router@8.4.0` declares `engines.node >=22.22.0`; the repo runs Node **v22.21.0**. Consequences and decision:
  - A bare `npm install react-router` silently installs **7.18.4** (npm honors the engine field), so an explicit `react-router@8.4.0` install is required and was used.
  - The explicit install prints `npm warn EBADENGINE` but succeeds; `createBrowserRouter`, `RouterProvider`, `NavLink`, `Outlet` and `Navigate` all resolve and the router works in `build` + dev smoke on Node 22.21.0.
  - Residual risk: v8 is unsupported on this exact Node patch. Mitigation is to upgrade Node to >= 22.22.0 (preferred) or fall back to `react-router@7.18.4` if a future upgrade surfaces a runtime failure. Recorded in `CONSTRAINTS.md`.
- **Shared placeholder component added.** A small `frontend/src/components/PagePlaceholder.tsx` was introduced so the four surface stubs share one token-styled layout; the spec's expected-file list is otherwise unchanged.
- **Extra route wiring module.** The route objects and `createBrowserRouter` call live in `frontend/src/router.tsx` (the spec allowed moving the router out of `App.tsx`); `App.tsx` was deleted because `main.tsx` now mounts `RouterProvider`.
- **Not Found renders inside the shell** (as a child of the layout route) so the header/tabs remain available instead of showing a bare page.
- The header's user pill is a static `Invitado` placeholder and the "Actualizar EOD" button is `disabled`/inert, per the non-goals (no auth, no ingestion action).
- No browser-level visual check was executed (no browser/E2E tooling in the repo); token fidelity was verified through the source classes and the compiled CSS, and client-side tab switching follows from `NavLink` + `<Outlet />`.

## Verification Plan

- `npm --prefix frontend run lint` → `tsc -b` + oxlint report no errors.
- `npm --prefix frontend run build` → production build succeeds, exit 0.
- Dev smoke: start `npm --prefix frontend run dev -- --host 127.0.0.1 --port 5173`; `GET /screener`, `/chart`, `/admin`, `/portal` and an unknown path all return HTTP 200 (Vite history fallback) and the app mounts; stop the server and its child process.
- Manual UI check: header renders per `DESIGN.md`; clicking tabs swaps the surface without reload and marks the active tab; narrow viewport keeps the tab bar usable.
- `.\init.ps1` → Laravel checks + SPA lint/build, exit 0, no server started.
- Persistent E2E: none exists and there is no test runner in the frontend; the spec relies on typecheck/build plus a dev-server smoke. A frontend test/E2E harness is a separate future concern; record it as a gap rather than blocking this slice.
- Startup script rule: `init.ps1` still runs the non-blocking gate and must not start a dev server.

## Evidence To Capture

- `react-router` version installed and the chosen router API used.
- `npm --prefix frontend run lint` / `run build` output (exit 0).
- Dev-server route smoke results (HTTP 200 for `/screener`, `/chart`, `/admin`, `/portal`, unknown).
- Confirmation tab clicks change the URL without a full reload (manual note or screenshot).
- `.\init.ps1` output (exit 0).

## Validator Checklist

- [ ] Implementation stays within this feature's scope (no real screens, no data, no auth, no Copilot).
- [ ] Acceptance scenarios pass.
- [ ] Verification evidence is present.
- [ ] Persistent E2E coverage was added/updated when the feature has an observable user/API flow and an E2E harness exists, or the spec explains why it is not needed.
- [ ] `feature_list.json` and `PROGRESS.md` were updated correctly.
- [ ] No unrelated product behavior or extra feature work was added.
- [ ] Navigation uses `react-router` routes; deep links resolve; the active tab matches the URL.
- [ ] The header and tabs follow `DESIGN.md` tokens.
- [ ] `init.ps1` still passes and starts no server.
