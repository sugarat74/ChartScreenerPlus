# Feature Implementation Spec: Screener filter UI

## Source Feature

- `id`: `screener-filters-ui`
- `area`: `screener`
- `depends_on`: `screener-api`, `app-shell-navigation` (both `accepted`)
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Turn the `/screener` placeholder into the product's primary surface: a Visitor applies technical filters and immediately sees the Candidate list returned by the accepted, anonymous `GET /api/screener` endpoint. The page owns the filter controls, the request, and the loading / empty / error / result rendering. Filtering and ranking stay server-side; the SPA only serializes filters and renders the response.

The filter set is the subset of the prototype's `FilterState` that the frozen screener API can actually evaluate, so the UI never invents a criterion the backend does not support.

## Non-Goals

- No sorting controls, no client-side re-sort (`candidate-list-ranking` is a later feature). This feature sends no `sort` param and uses the API default (`rvol_desc`).
- No chart navigation, modal or sparkline (`chart-interactive`); candidate rows are not clickable links to `/chart`.
- No saved screeners, no "Guardar Screener", no watchlist, no auth gate (`saved-screeners`, `watchlist`, `access-control-guard`).
- No text search by ticker/company/sector (the API has no search param; the prototype's `searchQuery` is unsupported).
- No universe selector (there is no universes-list endpoint and only `sp500` is seeded); the API default is used and the resolved universe is shown read-only.
- No API, Laravel, engine, schema or `nav.ts`/router change (the screener API is accepted and frozen; `/screener` already exists).
- No new npm dependency and no frontend test runner/E2E harness.
- No pagination/`limit` control: the API default (`limit=50`) is used and the UI discloses `returned` of `total`.

## Job Story

When I have a shortlist to build after the EOD run,
I want to toggle technical criteria on the Screener and see the Candidate list refresh against the real data,
so I can narrow the S&P 500 to a few charts without checking ticker by ticker.

## Users And Permissions

- Visitor (no session): full use of the Screener. `GET /api/screener` is anonymous (`api` + `throttle:60,1` only) and the SPA must not gate the page (`docs/user-and-access-model.md`: "Browsing requires no session").
- Registered User / Admin: identical anonymous read access; no ownership is involved.
- No `useAuth()` check, no redirect, no login prompt anywhere on this page.

## Decisions (explicit)

1. **Route/page:** keep the existing `/screener` route (`nav.ts` `NAV_ITEMS[0]`, `/` redirects there). Replace `frontend/src/pages/ScreenerPage.tsx` in place; no router/nav change. Deep links keep working.
2. **Filter state = URL search params (source of truth).** The page reads filters from `useSearchParams()` (confirmed exported by `react-router@8.4.0`), parses them with a strict `parseScreenerFilters`, and writes them back with `replace: true`, preserving search params this feature does not own (so a future `sort` survives). This makes filter sets shareable/deep-linkable, keeps browser back/forward working, and is the cheapest path to the later `saved-screeners` feature. Query keys are exactly the API param names (`signal`, `rsi_min`, `rsi_max`, `min_rvol`, `price_above_sma200`, `ma_cross`), so a copied URL is directly usable as an API call.
3. **Filter control set → API params:**

   | Control | UI | API param |
   | --- | --- | --- |
   | Signal techniques | multi-select toggle chips, 9 fixed types (OR) | `signal=golden_cross,death_cross,…` (canonical/selected order) |
   | RSI (14) | two number inputs (0–100), labeled Min/Max | `rsi_min`, `rsi_max` (inclusive; empty = off) |
   | Min RVOL | preset toggle buttons 1.0 / 1.5 / 2.0 / 3.0 (+ "Todos" = off) | `min_rvol` (inclusive) |
   | Price above SMA200 | single toggle chip | `price_above_sma200=1` only when on; otherwise omitted |
   | MA cross | 3-state segmented control: Cualquiera / Alcista / Bajista | `ma_cross=bullish` / `ma_cross=bearish`; off = omitted |
   | Universe | not a control; read-only chip from `response.universe.name` | not sent (API default `sp500`) |

   No `limit`, no `sort`, no `universe`, no `search` are sent. Signal labels are Spanish (matching the existing UI) but the values are always the 9 raw type strings.
4. **Default result on first load:** no explicit "Buscar" button. On mount with no filters the page issues `GET /api/screener` immediately and shows the default `rvol_desc` ranked list. Filter changes refetch automatically.
5. **Refetch trigger:** the fetch effect is keyed on a canonical serialization of the parsed filters (`screenerFiltersKey`). The in-flight request is aborted (`AbortController` via `RequestInit.signal`) and a `lastKeyRef` suppresses a duplicate identical query. Toggles/selects commit to the URL immediately; the numeric RSI inputs keep a local draft and commit on blur/Enter (a ~300 ms debounce is an acceptable equivalent), so typing never fires a request per keystroke. All URL writes use `replace: true` and the functional `setSearchParams(prev => …)` form so simultaneous edits merge instead of clobbering.
6. **Loading / empty / error:** four statuses — `loading` (first load, skeleton rows), `refreshing` (previous list kept visible with an "Actualizando…" indicator), `ready` (list or empty panel), `error` (alert + Reintentar). Empty is `200 candidates: []` and is split by whether filters are active. Details in "State Machine" below.
7. **Candidate list:** a fixed-column table using the API's default ranking (not the ranking feature). No row actions.

## Acceptance Scenarios

### Scenario 1: Anonymous default load

Given the seeded `sp500` universe has stored bars, snapshots and signals,
When a Visitor opens `/screener` without a session,
Then a request to `/api/screener` is issued with no filter params, the Candidate table renders ranked by RVOL desc, the universe name is shown, and the page never asks for login.

### Scenario 2: Changing filters refreshes the list

Given the Screener is showing results,
When the Visitor selects a signal chip, a MA cross, toggles Price > SMA200, picks Min RVOL, and/or sets an RSI range,
Then the URL gains the matching params (`signal=`, `ma_cross=`, `price_above_sma200=1`, `min_rvol=`, `rsi_min=`/`rsi_max=`) and a new `/api/screener` request carrying exactly those params updates the table and the `Mostrando N de M` line.

### Scenario 3: Loading and refreshing are visible

Given a request is in flight,
When the response has not arrived,
Then the first load shows a visible "Cargando candidatos…" state (skeleton/bordered panel) and a refetch with a previous list shows an "Actualizando…" indicator while the list stays rendered (`aria-busy`).

### Scenario 4: Empty result is explained, not an error

Given a valid filter combination matches nothing (e.g. `rsi_min=99`),
When the response is `200 candidates: []`,
Then an empty panel renders ("Sin candidatos con estos filtros.") with a "Limpiar filtros" action — not a 404/500 message.

### Scenario 5: Error state

Given the network fails, or the API returns `404 {"message":"Universe not found."}` (e.g. the default universe was never seeded) or `429`,
When the request settles,
Then a `role="alert"` panel shows the API message and a "Reintentar" button that reissues the request.

### Scenario 6: Shareable / restorable filter URL

Given a URL like `/screener?signal=golden_cross,pivot_breakout_rvol&min_rvol=2&ma_cross=bullish`,
When it is opened directly,
Then the controls reflect those filters and the request carries exactly those params.

### Scenario 7: Clear filters

Given active filters,
When the Visitor clicks "Limpiar filtros",
Then the owned params are removed from the URL (unrelated params preserved) and the default ranked list reloads.

## Repository Research

### Files Inspected

- `feature_list.json` — `screener-filters-ui` entry + verification criteria + "Anonymous browsing must work without login"; `candidate-list-ranking`/`chart-interactive` are separate.
- `frontend/src/pages/ScreenerPage.tsx` — current placeholder to replace.
- `frontend/src/components/PagePlaceholder.tsx` — the placeholder it currently renders.
- `frontend/src/nav.ts`, `frontend/src/router.tsx` — `/screener` route already exists (`NAV_ITEMS[0]`); no route change needed.
- `frontend/src/lib/api.ts` — `request<T>` helper (`credentials: 'include'`, `X-XSRF-TOKEN`, JSON decode, `ApiError`); forwards `RequestInit`, so `{ signal }` works without changes; `authApi`/`adminIngestionApi` are the client-module pattern to mirror.
- `frontend/src/pages/AdminPage.tsx`, `frontend/src/components/admin/{RunHistoryTable,RunLogStream}.tsx` — the established loading/error/empty/data pattern (`role="alert"` errors, bordered empty rows, mono status, token classes, `overflow-x-auto` tables).
- `frontend/src/index.css` — `@theme` tokens (`bg-surface-bright`, `border-outline`, `shadow-[2px_2px_0px_#1a1a1a]`, `bg-primary-container`, `text-gain`) — no new tokens needed.
- `frontend/package.json` — scripts `dev`/`build`/`lint`/`typecheck`; deps React 19, `react-router@^8.4.0`; **no test runner** (no Vitest, no Playwright/Cypress).
- `frontend/node_modules/react-router/.../index.d.ts` — confirmed `useSearchParams` and `SetURLSearchParams` are exported.
- `docs/specs/screener-api.md`, `ARCHITECTURE.md` ("Screener API"), `CONSTRAINTS.md` ("Public API") — the frozen contract: params, inclusive bounds, `422` for bad semantic values, `limit` clamp, empty `200` vs unknown-universe `404`, `change_percent`/null rules, server-side ranking.
- `DESIGN.md` — visual source of truth (see "Visual Design Impact").
- `alphapulse/src/types.ts` `FilterState` + `alphapulse/src/components/ScreenerView.tsx` + `alphapulse/src/App.tsx` (initial filters) — intent reference for the control set and chip/table treatment. Prototype `searchQuery`, `selectedPatterns`, `ema21AboveEma55`, `adxAbove25`, `pullbackSma50`, view toggle, sort dropdown, sparkline and Copilot actions are **out of scope**.
- `init.ps1` — already runs SPA `lint` + `build`; must not change and starts no server.
- `docs/build-brief.md`, `docs/user-and-access-model.md` — validation ("Golden Cross + RVOL > 2"), anonymous browsing, basic throttle.

### Existing Patterns To Follow

- API client lives in `frontend/src/lib/api.ts` as a typed module object; components never call `fetch` directly.
- Errors surface as `ApiError` with `status`/`message`/`errors`; a `messageFor(error)` helper pattern already exists in `AdminPage`.
- Token-only styling; tables wrapped in `overflow-x-auto`; mono numerals; `role="alert"` for errors; border+shadow focus treatment (`focus:shadow-[4px_4px_0px_#ffcc00]`).
- File naming `PascalCase.tsx` for components, camelCase for `.ts` helpers, explicit `.ts`/`.tsx` import extensions.

### Current Gaps

- No screener API client, no filter/URL helpers, no Candidate table, no loading/empty/error states.
- No frontend test runner or E2E harness — behavior is verified by typecheck/lint/build + a live dev smoke + manual browser checks.
- The API's unnamed `throttle:60,1` shares Laravel's `sha1(domain|ip)` limiter key with the unnamed `throttle:6,1` login route (documented in `PROGRESS.md`/`docs/specs/screener-api.md`). With the API frozen, this feature cannot fix it; it must coalesce/abort requests and must not auto-retry. Recorded as a known risk, not changed.

## Technical Approach

### API client (`frontend/src/lib/api.ts`)

Add (mirroring `adminIngestionApi`):

```ts
export type ScreenerSignalType =
  | 'golden_cross' | 'death_cross' | 'ma_alignment_bullish' | 'ma_alignment_bearish'
  | 'pivot_breakout_rvol' | 'rsi_overbought' | 'rsi_oversold'
  | 'macd_bullish_cross' | 'macd_bearish_cross'

export type ScreenerFilters = {
  signals: ScreenerSignalType[]
  rsiMin: number | null
  rsiMax: number | null
  minRvol: number | null
  priceAboveSma200: boolean
  maCross: 'bullish' | 'bearish' | null
}

export type ScreenerCandidate = {
  ticker: string; company: string; sector: string; exchange: string; active: boolean
  date: string
  close: number | null; change_percent: number | null
  rvol: number | null; rsi14: number | null
  signals: string[]
}

export type ScreenerResponse = {
  universe: { slug: string; name: string }
  sort: string
  candidates: ScreenerCandidate[]
  meta: { limit: number; returned: number; total: number }
}

export const screenerApi = {
  // GET /api/screener — anonymous; `request` already forwards `RequestInit` (including `signal`).
  search(filters: ScreenerFilters, signal?: AbortSignal): Promise<ScreenerResponse>
}
```

`search` builds a `URLSearchParams` from only the active params (comma-joined `signal`, `'1'` for the boolean, no `sort`/`limit`/`universe`) and calls `request<ScreenerResponse>('/api/screener' + (qs ? '?' + qs : ''), { signal })`. A GET needs no CSRF cookie.

### Screener helpers (`frontend/src/lib/screenerFilters.ts`, new; pure, no React)

- `SCREENER_SIGNAL_TYPES` (canonical order, 9), `SIGNAL_LABELS` (Spanish), `MIN_RVOL_PRESETS` (`[1, 1.5, 2, 3]`), `EMPTY_SCREENER_FILTERS`.
- `parseScreenerFilters(params: URLSearchParams): ScreenerFilters` — strict and lenient: `signal` split/dedupe/keep-known; `rsi_min`/`rsi_max` finite numbers clamped to `0..100` (else `null`); `min_rvol` finite `>= 0` (else `null`); `price_above_sma200` truthy for `1`/`true`; `ma_cross` only `bullish`/`bearish`. Invalid values are dropped, never forwarded (so a hand-edited URL cannot produce an API `422`).
- `patchScreenerFilters(prev: URLSearchParams, patch: Partial<ScreenerFilters>): URLSearchParams` — `new URLSearchParams(prev)`, then `set`/`delete` only the six owned keys in canonical form; unrelated keys are preserved untouched.
- `hasActiveFilters(filters): boolean` and `screenerFiltersKey(filters): string` (deterministic, for effect deps + duplicate-request suppression).
- Small formatters: `formatPrice`, `formatChangePercent` (explicit `+`/`−`), `formatRvol` (`x`), `formatRsi`, all returning `'—'` for `null`; `signalLabel(type)` falls back to the raw type for unknown strings. Use fixed decimals (`toFixed`), not locale grouping, so output is deterministic.

### Page and components

- `frontend/src/pages/ScreenerPage.tsx` — orchestrator:
  - `const [searchParams, setSearchParams] = useSearchParams()`; `filters = useMemo(() => parseScreenerFilters(searchParams), [searchParams])`.
  - `update(patch)` → `setSearchParams(prev => patchScreenerFilters(prev, patch), { replace: true })`.
  - Fetch effect on `screenerFiltersKey(filters)`: abort cleanly on cleanup, ignore `AbortError`, set `loading`/`refreshing`/`ready`/`error`, store `{candidates, meta, universe}`.
  - Renders `ScreenerFilterPanel` + the results region (`CandidateResults`), plus the `Mostrando {meta.returned} de {meta.total} candidatos · {universe.name}` status line.
- `frontend/src/components/screener/ScreenerFilterPanel.tsx` — the controls (props: `filters`, `activeCount`, `onChange`, `onClear`, `onCommitNumber`); a bordered `surface-bright` card with 2px border + hard shadow, control groups wrapping. "Limpiar filtros" is enabled only when a filter is active.
- `frontend/src/components/screener/CandidateResults.tsx` — switches on status: error alert (`role="alert"` + Reintentar), first-load skeleton rows, empty panel (message depends on `hasActiveFilters`), or `CandidateTable`. Wraps the region with `aria-busy` and a `role="status"` "Actualizando…" line while refreshing.
- `frontend/src/components/screener/CandidateTable.tsx` — the data table (columns below). Rows are non-interactive.

### State machine (per request cycle)

| Status | When | Rendered |
| --- | --- | --- |
| `loading` | first request (no previous data) | skeleton rows / "Cargando candidatos…" panel |
| `refreshing` | a request while data already exists | previous table kept + "Actualizando…" (`aria-busy`) |
| `ready` | success with rows | `CandidateTable` + count/universe line |
| `ready` (empty) | success, `candidates.length === 0` | filters active → "Sin candidatos con estos filtros." + Limpiar; no filters → "El universo todavía no tiene candidatos EOD." |
| `error` | non-aborted rejection / non-2xx | `role="alert"` with `ApiError.message` (or network copy) + Reintentar (manual only, no auto-retry) |

### Candidate table (fixed columns)

| Column | Field | Treatment |
| --- | --- | --- |
| Símbolo / Empresa | `ticker`, `company`, `exchange` | headline ticker + mono exchange badge + body company |
| Cierre EOD | `close` | mono, right-aligned, 2 decimals, `—` when null |
| Var % | `change_percent` | mono, right-aligned, explicit `+`/`−` and `text-gain`/`text-secondary` (never color alone) |
| RVOL | `rvol` | mono, right-aligned, 1 decimal + `x`; accent badge when `>= 2` |
| RSI (14) | `rsi14` | mono, right-aligned, 1 decimal |
| Señales | `signals[]` | bordered mono chips (mapped labels); `—` when empty |

## Expected File Changes

- `frontend/src/lib/api.ts` — modify; add screener types + `screenerApi.search`.
- `frontend/src/lib/screenerFilters.ts` — create; constants, parse/patch/key/format helpers.
- `frontend/src/pages/ScreenerPage.tsx` — modify; replace the placeholder with the real page.
- `frontend/src/components/screener/ScreenerFilterPanel.tsx` — create; filter controls + Limpiar.
- `frontend/src/components/screener/CandidateResults.tsx` — create; loading/empty/error/table switch.
- `frontend/src/components/screener/CandidateTable.tsx` — create; the Candidate table.
- `ARCHITECTURE.md`, `CONSTRAINTS.md` — update (see below).
- `docs/specs/screener-filters-ui.md` (this file), `PROGRESS.md`, `feature_list.json` — update at implementation.
- Not changed: `router.tsx`, `nav.ts`, `AppHeader.tsx`, Laravel/engine/schema, `init.ps1`.

## Visual Design Impact

- UI involved: yes. Design source: `DESIGN.md` (source of truth) and `alphapulse/src/components/ScreenerView.tsx` (intent reference only).
- Screens/states affected: `/screener` in loading, refreshing, ready (populated/empty) and error states; responsive widths.
- New design artifact required: no.
- `DESIGN.md` references to honor:
  - Layout (line 109): "Screener: filters/inline criteria at the top, ranked table below."; content inside the existing `max-w-7xl` shell (`AppLayout`).
  - Shapes (112–117): 2px `#1a1a1a` borders, 4–6px radii, hard offset shadows (`2px 2px 0 #1a1a1a`; `4px 4px 0 #ffcc00` for emphasis), never blurred shadows; focus = border + accent offset (`focus:shadow-[4px_4px_0px_#ffcc00]`, as in `AdminPage`'s input).
  - Components (119–124): primary button (yellow `#ffcc00` → `bg-primary-container`, 2px border, hard shadow, uppercase headline); card/tile (`surface-bright` + 2px border + hard shadow); table (mono numerals, right-aligned figures, colored change values); badge/chip (small, bordered, mono, color-coded).
  - Typography (97–102) / Colors (88–95): mono for every number and status; `text-gain` `#059669` and `text-secondary` `#e63b2e` for change, paired with a sign; do not introduce hues.
  - Accessibility (142–147): strong contrast, no color-only state, visible focus, table with proper headers.
  - Responsive (136–140): desktop-first, filter controls wrap/stack, table scrolls horizontally.
- Feature-specific states the implementer must handle: selected vs unselected chip, active-filter count, disabled "Limpiar filtros" when nothing is active, `—` for null metrics, empty vs error disambiguation, `refreshing` over an existing table.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — change the `/screener` route line from "Screener placeholder" to the real screen, and add a short "Screener UI" note (anonymous; URL-backed filter state; request sent straight to `GET /api/screener`; ranking stays server-side).
- `CONSTRAINTS.md`: update — add Frontend MUST rules: the Screener is anonymous (no SPA gate); filter state is URL-backed and uses the API param names; the SPA MUST NOT sort/re-rank or re-implement filtering (the API owns ranking); only `DESIGN.md` tokens.
- `AGENTS.md`: not needed — no workflow/startup/operating-rule change; `init.ps1` unchanged.
- `DESIGN.md`: not needed — existing tokens/components cover the screen.
- `docs/user-and-access-model.md`, `docs/domain-model.md`, `docs/build-brief.md`: not needed — browsing is already documented as anonymous and Screener/Result are defined.
- Other: `PROGRESS.md` and `feature_list.json` — update with evidence at implementation.

## Implementation Plan

1. Add the screener types + `screenerApi.search` to `frontend/src/lib/api.ts`.
2. Add `frontend/src/lib/screenerFilters.ts` (constants, parse/patch/key/format helpers).
3. Build `ScreenerFilterPanel`, `CandidateResults` and `CandidateTable` with token-only styling.
4. Replace `ScreenerPage` with the URL-backed orchestrator (fetch effect, abort/dedupe, status machine).
5. Verify: `npm --prefix frontend run lint`, `run build`; live dev smoke against seeded data; `.\init.ps1`.
6. Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [x] Add `ScreenerSignalType`/`ScreenerFilters`/`ScreenerCandidate`/`ScreenerResponse` and `screenerApi.search` to `frontend/src/lib/api.ts` (accepts an `AbortSignal`).
- [x] Create `frontend/src/lib/screenerFilters.ts`: 9 types + Spanish labels, RVOL presets, `parseScreenerFilters`, `patchScreenerFilters`, `hasActiveFilters`, `screenerFiltersKey`, formatters.
- [x] Build `ScreenerFilterPanel.tsx`: signal chips, RSI min/max inputs, RVOL presets, Price>SMA200 toggle, MA-cross segmented control, active count, Limpiar.
- [x] Build `CandidateTable.tsx`: the six columns per DESIGN.md, nulls as `—`, sign+label+color for change, signal chips.
- [x] Build `CandidateResults.tsx`: loading skeleton, refreshing indicator, empty panel (filters vs no data), error alert + Reintentar.
- [x] Replace `ScreenerPage.tsx`: `useSearchParams` source of truth, `update(patch)` with `replace`, fetch effect keyed on `screenerFiltersKey` with `AbortController` + duplicate suppression, count/universe status line.
- [x] Confirm no auth gate and that the page fetches with no session.
- [x] Run `npm --prefix frontend run lint` and `run build` (0 warnings/errors, exit 0); confirm no new dependency.
- [x] Run the live dev smoke (Laravel + Vite + seeded data): default list, each filter, empty, error; confirm the URL and request params match and no redundant requests fire.
- [x] Run `.\init.ps1` (exit 0, no server started).
- [x] Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Findings

- **oxlint enforces more React rules than the configured two.** `frontend/.oxlintrc.json` configures `react/rules-of-hooks` and `react/only-export-components`, but oxlint's React plugin additionally enforces `react-hooks(exhaustive-deps)` and `react(set-state-in-effect)`. The first draft hit 3 warnings and they were fixed rather than silenced:
  - the fetch effect now reads the latest filters through a `filtersRef` (updated by a tiny effect) and depends only on `screenerFiltersKey` + `retryToken`, because the memoized `filters` object identity changes on *any* search-param change and including it would refetch on unrelated params;
  - the RSI inputs are uncontrolled drafts (`defaultValue` plus a `key` derived from the committed value) instead of draft `useState` synced by an effect. Typing still never writes the URL; blur/Enter commits; an external change (clear/back/forward/deep link) remounts the input so the draft matches the URL.
- **Two helpers beyond the spec's list:** `countActiveFilters` (the panel's active-filter count / disabled Limpiar state) and `parseRsiInput` (reused by `parseScreenerFilters` and by the RSI blur/Enter commit). Both are pure and were exercised by the throwaway Node helper smoke.
- **`patchScreenerFilters` preserves an untouched owned key's raw value.** It rewrites only the keys present in the patch, so editing an unrelated filter leaves e.g. `signal=golden_cross,unknown` in the URL; the unknown token is dropped by `parseScreenerFilters` and never sent to the API, and patching `signals` canonicalizes/cleans the whole key. This satisfies "only API-valid params are sent" without rewriting params the user did not touch.
- **"Simultaneous edits in the same tick" caveat.** react-router's functional `setSearchParams` closes over the render's `searchParams`, so two calls in the *same* synchronous tick would still clobber. In practice a blur commit and a chip click are separate DOM events, so the component re-renders (and `searchParams` refreshes) between them and both survive. All writes still use the functional form and `replace: true`.
- **Vite dev server binds `::1`.** On this machine Vite listens on `[::1]:5173` while Laravel listens on `127.0.0.1:8000`; the dev smoke must probe `http://localhost:5173` (the `127.0.0.1:5173` probe never connects). The proxy target is unchanged.
- **No frontend test runner/E2E harness (gap restated).** Verification was `lint` + `build` + a throwaway Node `--experimental-strip-types` check of the pure helpers + an HTTP/URL live dev smoke (anonymous default list, one request per filter, empty `200 []`, `404`, SPA/module transforms). Pixel-level DESIGN fidelity and the interactive browser checklist remain manual for a human/validator; no framework or dependency was added.


## Verification Plan

No frontend test runner or E2E harness exists, so the closest available verification is typecheck/lint/build + a live dev smoke + a manual browser checklist. There is no persistent E2E command to add/update; record that explicit gap in `PROGRESS.md`.

- `npm --prefix frontend run lint` → `tsc -b` + oxlint, 0 warnings/0 errors, exit 0.
- `npm --prefix frontend run build` → production build succeeds, exit 0 (dist produced, no new dependency added).
- Pure-helper behavior (`parseScreenerFilters`/`patchScreenerFilters`/`screenerFiltersKey`) is covered by `tsc -b` plus the dev smoke; do **not** add a test runner or any new dependency to exercise it. A temporary throwaway script may be used for a manual check but must be removed before finishing.
- **Live dev smoke** (temporary, torn down): seed a small fixture universe with bars + snapshots + signals (temporary script + cleanup, as in prior live smokes), start `php artisan serve` and `npm --prefix frontend run dev`, then verify through the Vite proxy that:
  - `GET /api/screener` (no params) returns a ranked list anonymously;
  - `/screener` and the new modules transform (200) and the built JS contains the six param names;
  - the page issues requests carrying the selected params as the URL changes;
  - `GET /api/screener?min_rvol=99` returns `200 candidates: []` (empty path) and an unknown default universe returns the `404` JSON (error path).
  - Stop all servers and delete the temporary rows.
- **Manual browser checklist** (acceptance scenarios 1–7): default list without login; each control changes the URL and the table; loading and refreshing states; empty panel with Limpiar; error panel + Reintentar; a pasted filtered URL restores controls + results; DESIGN fidelity (borders/shadows/mono/right-alignment/focus/contrast).
- `.\init.ps1` → exit 0 (Laravel tests + SPA lint/build + engine tests), starts no server; `init.ps1` unchanged.
- Startup script rule: `init.ps1` stays a non-blocking gate and must not start a dev server.

## Evidence To Capture

- `npm --prefix frontend run lint` / `run build` output (0 errors, exit 0) and confirmation no dependency changed in `frontend/package.json`.
- Dev-smoke results: the request URLs observed per filter change, the empty/`404`/`429` paths, and that `/screener` served 200 with teardown.
- Manual per-scenario results (pass/fail) for scenarios 1–7, including a note that visual fidelity was checked by hand (no browser automation).
- `.\init.ps1` exit status.
- Confirmation that `router.tsx`/`nav.ts`, Laravel, engine, schema and `init.ps1` were untouched.

## Key Implementation Risks

- **Race conditions / out-of-order responses** — rapid filter changes can settle out of order. Abort the previous request and suppress an identical repeated query; ignore `AbortError`.
- **Request storm / shared throttle** — each committed filter change hits `throttle:60,1`; the unnamed limiter shares its key with `throttle:6,1` login (frozen API, documented follow-up). Keep one request per committed state, no keystroke fetches, no auto-retry.
- **Invalid URL → API 422** — parse strictly and drop unknown/invalid values so only API-valid params are sent.
- **Empty vs error** — a valid-but-empty match is a `200 []` empty state, not an error; only a real non-2xx rejection is the error state.
- **Stale URL writes** — always patch via the functional `setSearchParams(prev => …)` form so a blur commit and a chip click in the same tick both survive.
- **Scope creep** — do not add sort controls, row navigation, saved screeners, watchlist, universe selector, search, sparkline or pagination.
- **DESIGN without a browser** — fidelity is manual; use the exact token classes and the established `AdminPage`/`RunHistoryTable` patterns rather than inventing styles.

## Validator Checklist

- [ ] Implementation stays within scope (no sort controls, chart navigation, saved screeners, watchlist, universe selector, search, pagination, API/engine/schema change).
- [ ] Acceptance scenarios 1–7 pass; anonymous browsing works with no login prompt.
- [ ] Every control maps to its API param; changing a filter issues a refreshed request and updates the list.
- [ ] Loading, refreshing, empty and error states are all visible and distinguishable; empty is not shown as an error.
- [ ] Filter state is URL-backed and restorable; only the six owned params are written and unrelated params survive.
- [ ] The candidate list follows `DESIGN.md` (2px borders, hard shadows, mono right-aligned numerals, sign+color change, bordered chips, visible focus, table headers) and handles null metrics as `—`.
- [ ] No new npm dependency; `lint`/`build`/`.\init.ps1` pass; `init.ps1` unchanged and starts no server.
- [ ] No frontend test runner/E2E harness exists, and the spec's lint/build + dev smoke + manual checklist is the documented closest verification.
- [ ] `ARCHITECTURE.md`/`CONSTRAINTS.md` updated; `PROGRESS.md`/`feature_list.json` updated with evidence.
- [ ] No unrelated product behavior or extra feature work was added.
