# Feature Implementation Spec: Scaffold the React SPA

## Source Feature

- `id`: `repo-scaffold-frontend`
- `area`: `bootstrap`
- `depends_on`: `[]`
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Create the React single-page application that will host the AlphaPulse trader surface (Screener, Chart, Portal). After this feature, a developer can install, typecheck, build and run the SPA locally and see a placeholder page styled with the AlphaPulse design tokens.

This is the second bootstrap slice of the target stack (Laravel + Python engine + React SPA). It must not build product screens beyond a token-styled placeholder.

## Non-Goals

- No app shell, header or tab navigation (that is `app-shell-navigation`).
- No API calls, screener filters, charts, auth or admin surfaces.
- No integration with Laravel routing, CORS, proxies or deployment.
- No change to Laravel's own root Vite assets (`resources/`, root `vite.config.js`, root `package.json`).
- No Python engine work (`python-engine-scaffold`).

## Job Story

When I start frontend work on ChartScreenPlus,
I want an isolated React SPA project with the design tokens already wired,
so I can build product screens without mixing them into Laravel's asset pipeline.

## Users And Permissions

- Developer (local): can install dependencies, typecheck, build and run the SPA. No end-user roles exist yet.

## Acceptance Scenarios

### Scenario 1: SPA installs and typechecks

Given the `frontend/` project,
When the developer runs `npm --prefix frontend install` and `npm --prefix frontend run lint`,
Then dependencies install and TypeScript reports no errors.

### Scenario 2: SPA builds

Given installed dependencies,
When the developer runs `npm --prefix frontend run build`,
Then Vite produces a production build under `frontend/dist` with exit code 0.

### Scenario 3: SPA serves a token-styled placeholder

Given installed dependencies,
When the developer runs the dev server and requests the root URL,
Then the response is HTTP 200 and renders a placeholder page that uses the AlphaPulse tokens (paper background, ink text, accent).

### Scenario 4: The standard harness gate covers the SPA

Given the scaffolded SPA,
When the developer runs `.\init.ps1`,
Then it runs the Laravel checks plus the SPA typecheck/build (installing frontend deps if missing), exits 0, and does not start a dev server.

## Repository Research

### Files Inspected

- `feature_list.json` — selected feature metadata and ordering.
- `PROGRESS.md` — next-ready features; flags the SPA-location decision.
- `AGENTS.md` — Windows/PowerShell rules and `.\init.ps1` startup path.
- `docs/technical-discovery.md` — target stack and the Laravel/engine/SPA split.
- `DESIGN.md` — visual source of truth (tokens and neo-brutalist rules).
- `alphapulse/src/index.css` — the working Tailwind v4 `@theme` token block to port.
- `alphapulse/package.json` and `alphapulse/vite.config.ts` — reference SPA setup (React 19, Tailwind via `@tailwindcss/vite`).
- `package.json` / `vite.config.js` at the root — Laravel's own Vite pipeline (must stay untouched).
- `init.ps1` — the current Laravel-only gate.

### Environment Findings (probed, not assumed)

- Node **v22.21.0** and npm **11.8.0** are on PATH — support Vite 7 and React 19.
- `frontend/` does not exist yet.
- The Laravel root already uses Vite 7 + Tailwind 4 for its own assets; the SPA must not reuse that pipeline.

### Existing Patterns To Follow

- Design tokens and visual language come from `DESIGN.md`, mirrored from `alphapulse/src/index.css`.
- Commands must be PowerShell 5.1-compatible; no `&&`, `chmod`, `rm -rf`.
- `alphapulse/` is a reference only; it must not be modified or shipped.
- One feature at a time; the harness gate is `.\init.ps1`.

### Current Gaps

- No `frontend/` project, `package.json` or lockfile.
- `init.ps1` does not exercise any frontend check.
- No `ARCHITECTURE.md` recording the repository's runtime surfaces.

## Technical Approach

1. **Location: `frontend/`.** Keep the React SPA isolated from Laravel's root Vite assets. Laravel stays at the repository root (API/admin); the SPA lives in `frontend/`.
2. **Scaffold React 19 + TypeScript + Vite.** Base it on the Vite `react-ts` template, then add Tailwind v4 via `@tailwindcss/vite` (latest, not the prototype's pinned versions).
3. **Wire the design tokens.** Create `frontend/src/index.css` with `@import "tailwindcss";` and an `@theme { ... }` block ported from `alphapulse/src/index.css` / `DESIGN.md` (colors, fonts, radii). Do not invent new colors.
4. **Placeholder page.** A minimal `App.tsx` that renders a token-styled placeholder (paper background, ink text, an accent-marked "AlphaPulse SPA scaffold" heading). The real shell is `app-shell-navigation`.
5. **Scripts.** `dev` (Vite), `build` (`tsc -b && vite build`), `preview`, `lint` (`tsc -b && oxlint`), `typecheck` (`tsc -b`). The Vite `react-ts` template ships oxlint.
6. **Extend `init.ps1`.** After the Laravel checks, if `frontend/package.json` exists, install deps when `frontend/node_modules` is missing and then run `npm --prefix frontend run lint` and `npm --prefix frontend run build`. The gate must remain non-blocking and must not start a dev server.
7. **Record the layout.** Create `ARCHITECTURE.md` with the runtime surfaces and directory boundaries.

## Expected File Changes

New `frontend/` project (paths provisional until scaffolded):

- `frontend/package.json`, `frontend/package-lock.json` — create; React 19, Vite, TypeScript, Tailwind 4.
- `frontend/vite.config.ts` — create; `@vitejs/plugin-react` + `@tailwindcss/vite`.
- `frontend/tsconfig.json`, `frontend/tsconfig.node.json` — create; strict TS.
- `frontend/index.html` — create; app entry.
- `frontend/src/main.tsx`, `frontend/src/App.tsx` — create; token-styled placeholder.
- `frontend/src/index.css` — create; Tailwind import + `@theme` tokens from `DESIGN.md`.
- `frontend/.gitignore` — create; ignore `node_modules/`, `dist/`.

Modified by the implementer:

- `init.ps1` — modify; add the SPA typecheck/build to the gate.
- `ARCHITECTURE.md` — create; runtime surfaces and directory layout.
- `CONSTRAINTS.md` — update; SPA location and frontend tooling rules.
- `AGENTS.md` — update; note `frontend/` and the SPA commands.
- `PROGRESS.md` — update; verified state and evidence.
- `feature_list.json` — update; `repo-scaffold-frontend` status and evidence.

Not touched: root `package.json`, root `vite.config.js`, `resources/`, and everything under `alphapulse/`.

## Visual Design Impact

- UI involved: yes (a placeholder page only).
- Design source: `DESIGN.md` (source of truth) and `alphapulse/src/index.css` (token reference).
- Screens or states affected: a single placeholder page.
- New design artifact required: no.
- Note: the placeholder must look like an AlphaPulse scaffold surface (paper background, ink text, accent), not Vite's default template.

## Durable Documentation Impact

- `ARCHITECTURE.md`: create — records the runtime surfaces (Laravel root, `frontend/` SPA, future Python engine) and the directory boundary between them.
- `CONSTRAINTS.md`: update — MUST rules: SPA lives in `frontend/`; SPA styling uses the `DESIGN.md` tokens; do not add SPA code to Laravel's root Vite pipeline; Node is v22.
- `AGENTS.md`: update — mention `frontend/` and how to run/verify the SPA.
- Other docs: `PROGRESS.md` and `feature_list.json` — update with evidence.

## Key Implementation Risks

- **Two Vite pipelines** — Laravel's root pipeline and the SPA's `frontend/` pipeline both use Vite/Tailwind. Keep them separate; never point the SPA at the root `vite.config.js`.
- **Gate runtime** — installing/ building the SPA inside `init.ps1` can be slow; keep it to typecheck + build and never start the dev server.
- **Tooling drift** — use current Vite/React/Tailwind versions rather than the prototype's pinned ones; the prototype is a UI reference, not a dependency source.
- **Port collisions** — the Laravel dev server and the SPA dev server must use different ports (Laravel default 8000, SPA default 5173).

## Implementation Plan

1. Scaffold `frontend/` from the Vite `react-ts` template and add `tailwindcss` + `@tailwindcss/vite`.
2. Configure `frontend/vite.config.ts` (react + tailwind) and the tsconfig files.
3. Add `frontend/src/index.css` with the `@theme` tokens from `DESIGN.md`; build the token-styled placeholder `App.tsx`.
4. Set the npm scripts (`dev`, `build`, `preview`, `lint`).
5. Extend `init.ps1` to run the SPA typecheck/build.
6. Verify: install, lint, build, dev-server smoke; run `.\init.ps1`.
7. Create `ARCHITECTURE.md`; update `CONSTRAINTS.md`, `AGENTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [ ] Scaffold `frontend/` (Vite react-ts) and add Tailwind 4 (`tailwindcss`, `@tailwindcss/vite`).
- [ ] Point `frontend/src/index.css` at `@import "tailwindcss";` plus the `@theme` tokens from `DESIGN.md`.
- [ ] Implement the token-styled placeholder in `frontend/src/App.tsx`/`main.tsx`.
- [ ] Add scripts `dev`, `build`, `preview`, `lint`.
- [ ] Add `frontend/.gitignore` ignoring `node_modules/` and `dist/`.
- [ ] Extend `init.ps1` with the SPA typecheck/build and keep it non-blocking.
- [ ] Confirm `npm --prefix frontend install`, `lint`, `build` succeed and the dev server serves the placeholder.
- [ ] Create `ARCHITECTURE.md` and update `CONSTRAINTS.md`, `AGENTS.md`.
- [ ] Update `PROGRESS.md` and `feature_list.json`.

## Verification Plan

- `npm --prefix frontend install` → completes; lockfile created.
- `npm --prefix frontend run lint` → `tsc -b` and `oxlint` report no errors.
- `npm --prefix frontend run build` → `frontend/dist` produced, exit 0.
- Dev smoke: `npm --prefix frontend run dev -- --host 127.0.0.1 --port 5173`, then `Invoke-WebRequest http://127.0.0.1:5173/` returns 200 and the HTML/placeholder shows the token-styled scaffold; stop the server and its child process.
- `.\init.ps1` → runs Laravel checks plus SPA typecheck/build, exits 0, starts no server.
- Persistent E2E: none exists in this repo and there is no user flow yet; build + typecheck + a dev-server smoke are sufficient for a scaffold. E2E will be introduced when user-facing flows exist (e.g. `screener-filters-ui`).
- Startup script rule: `init.ps1` executes the non-blocking gate and must not start long-running processes.

## Evidence To Capture

- `node -v` / `npm -v` (v22.21.0 / 11.8.0).
- `npm --prefix frontend run lint` and `run build` output (exit 0, `dist` present).
- Dev-server smoke result (HTTP 200) and confirmation the server/child process were stopped.
- `.\init.ps1` output showing Laravel + SPA checks and exit 0.
- Confirmation that root `package.json`, root `vite.config.js` and `resources/` were not modified.

## Validator Checklist

- [ ] Implementation stays within this feature's scope.
- [ ] Acceptance scenarios pass.
- [ ] Verification evidence is present.
- [ ] Persistent E2E coverage was added/updated when the feature has an observable user/API flow and an E2E harness exists, or the spec explains why it is not needed.
- [ ] `feature_list.json` and `PROGRESS.md` were updated correctly.
- [ ] No unrelated product behavior or extra feature work was added.
- [ ] The SPA lives in `frontend/` and Laravel's root Vite pipeline is untouched.
- [ ] The placeholder uses the `DESIGN.md` tokens.
- [ ] `init.ps1` runs the SPA checks and starts no server.
