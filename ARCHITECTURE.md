# Architecture

Runtime surfaces, directory boundaries and dependency direction for ChartScreenPlus.

## Runtime Surfaces

- **Laravel (repository root)** — API, auth and admin. PHP 8.4 / Laravel 13. Owns the database, the framework migrations and Laravel's own Vite assets under `resources/`.
- **React SPA (`frontend/`)** — the trader surface (Screener, Chart, Portal). React 19 + Vite + Tailwind 4 with its own toolchain and dev server.
- **Python engine (planned)** — scraping, indicators and signals. Not created yet.

## Directory Boundaries

- `frontend/` is a standalone npm project: its own `package.json`, `vite.config.ts`, `tsconfig*.json` and lockfile. It MUST NOT be wired into Laravel's root Vite pipeline.
- Laravel's root `package.json`, `vite.config.js` and `resources/` belong to Laravel assets only.
- `alphapulse/` is a design/intent reference prototype; it is not a product runtime surface.

## SPA Routing And Shell

- The SPA uses **react-router** (`createBrowserRouter` + `RouterProvider`) as a client-side data router; the shell is a layout route (`frontend/src/layouts/AppLayout.tsx`) that renders `AppHeader` plus an `<Outlet />`.
- Route list:
  - `/` -> redirect to `/screener`
  - `/screener` -> Screener placeholder
  - `/chart` -> Chart placeholder
  - `/admin` -> Admin placeholder
  - `/portal` -> Portal placeholder
  - `*` -> token-styled Not Found placeholder (rendered inside the shell)
- Tab labels and paths are single-sourced in `frontend/src/nav.ts` (`NAV_ITEMS`, `DEFAULT_ROUTE`) and consumed by both the header `NavLink`s and the router so they cannot drift.
- The header (brand, EOD status, inert primary action, user pill) lives in `frontend/src/components/AppHeader.tsx`; every surface below it is a placeholder stub until its own feature lands.
- History routing needs a server-side SPA fallback when the SPA is deployed behind Laravel or another host; the Vite dev server already provides it. Configuration is deferred to deployment work.

## Dependency Direction

- The SPA talks to Laravel over HTTP (API) once endpoints exist; there is no code sharing between `frontend/` and the Laravel app yet.
- The Python engine will be invoked by Laravel (internal API/queue), not directly by the SPA.

## Configuration

- Local dev/test database is SQLite (`database/database.sqlite`, git-ignored).
- Dev servers: Laravel on 8000 (default), SPA on 5173 (Vite default).
- Harness gate: `.\init.ps1` runs the Laravel checks plus the SPA typecheck/lint/build.
