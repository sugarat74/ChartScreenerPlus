# Feature Implementation Spec: Rank and sort the candidate list

## Source Feature

- `id`: `candidate-list-ranking`
- `area`: `screener`
- `depends_on`: `screener-api`, `app-shell-navigation` (both `accepted`)
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Let a Visitor choose the ranking of the Candidate list on `/screener`. The six sort orders the accepted, anonymous `GET /api/screener` already supports (`rvol_desc`, `rsi_desc`, `rsi_asc`, `change_desc`, `change_asc`, `signal_count_desc`) become selectable from the results header, and every change is sent to the API as `sort=<value>` so the ranking stays server-side.

The selected sort is part of the URL-backed screener state: it survives filter changes, is restorable from a shared URL, and falls back to `rvol_desc` (the API default) when absent or invalid. The SPA never re-sorts the returned `candidates` array.

## Non-Goals

- No client-side sort/re-rank; the SPA renders `response.candidates` in the order the API returns (`ARCHITECTURE.md` -> Screener API, `CONSTRAINTS.md` -> Frontend).
- No API / Laravel / engine / schema change. `screener-api` is frozen; the six sort values already exist and `sort` is already a validated `422`-on-bad-value param.
- No new filter criteria, no `limit` control, no `universe` selector, no ticker/company search, no pagination.
- No sort control inside the filter panel and no change to filter semantics (the filter set is unchanged; sort is a ranking preference, not a criterion).
- No chart navigation, saved screeners, watchlist or auth gate.
- No new npm dependency and no frontend test runner / E2E harness (none exists).

## Job Story

When I have a shortlist on the Screener,
I want to re-rank the Candidate list by RVOL, confidence/signal strength, RSI or daily change,
so I can surface the names that matter for the setup I am trading without re-applying filters.

## Users And Permissions

- Visitor (no session): full use of the sort control. `GET /api/screener` stays anonymous (`api` + `throttle:60,1`); no `useAuth`, redirect or login prompt (`docs/user-and-access-model.md`).
- Registered User / Admin: identical anonymous read access; no ownership involved.

## Decisions (explicit)

1. **Control = native `<select>` in the results header.** DESIGN.md (line 109) puts "filters/inline criteria at the top, ranked table below", and the prototype's sorting bar (`alphapulse/src/components/ScreenerView.tsx` lines 249–268) places "Ordenar por:" next to "Mostrando N de M símbolos", immediately above the table. With six options a select is clearer and more accessible than six segmented buttons. It is placed in the existing results header row, left of the Universe chip, and that row now renders on every status (loading / refreshing / ready / error) so the control is always reachable; the "Mostrando …" count is only shown once data exists.
2. **Six options, API values only** (fixed order in the select):

   | `sort` value | Spanish label | Ranking follows |
   | --- | --- | --- |
   | `rvol_desc` | `Volumen relativo (RVOL mayor)` | `rvol` desc — **default** |
   | `signal_count_desc` | `Confianza (más señales)` | active signal count desc (the prototype's "Confianza Algorítmica") |
   | `change_desc` | `Variación diaria (mayor)` | `change_percent` desc |
   | `change_asc` | `Variación diaria (menor)` | `change_percent` asc |
   | `rsi_desc` | `RSI (mayor)` | `rsi14` desc |
   | `rsi_asc` | `RSI (menor)` | `rsi14` asc |

   The prototype's `confidence_desc` / `dolvol_desc` are **not** API sorts; `confidence_desc` maps to `signal_count_desc`, `dolvol_desc` is dropped.
3. **`sort` becomes an owned URL key of the screener state module.** `ScreenerFilters` gains `sort: ScreenerSort`; `parseScreenerFilters` reads it (absent / invalid / non-API value -> `DEFAULT_SCREENER_SORT = 'rvol_desc'`, so a hand-edited URL can never produce a `422`); `patchScreenerFilters` writes it (and **deletes** the key when the value is the default, keeping the default URL clean); `screenerFiltersKey` includes it so the fetch effect and duplicate suppression react to sort changes.
4. **`screenerApi.search` always sends `sort`.** The request now carries `sort=<value>` on every call (including `sort=rvol_desc`), so "sorting is reflected in the API request" is directly observable and each request is self-describing. The frozen API is unchanged (it already accepts all six values). The other non-owned params (`limit`, `universe`) are still never sent.
5. **Default when no sort is set:** `rvol_desc` (the API default and the current behavior of `screener-filters-ui`). No `sort` in the URL and an invalid `sort` both resolve to `rvol_desc`.
6. **Sort is not a filter.** `hasActiveFilters` / `countActiveFilters` ignore `sort`, and "Limpiar filtros" clears only the six criteria, so the selected ranking **persists** across a clear. Justification: filtering and ranking are independent concerns; clearing criteria must not silently discard the user's chosen order.
7. **Persistence mechanism** (verification criterion 3): `patchScreenerFilters` patches only the keys present in the patch, so changing a filter never rewrites `sort`; the select's value is derived from the URL, so it stays selected; and `screenerFiltersKey` includes `sort`, so the refetch triggered by a filter change re-sends the same `sort`. No component-only state is involved.
8. **The response echo is not a second source of truth.** The select is driven by the URL-owned `sort`, not by `response.sort`, so it updates instantly and a deep link restores it. All URL writes stay `replace: true`.

## Acceptance Scenarios

### Scenario 1: Default ranking

Given the seeded `sp500` universe has stored bars/snapshots/signals,
When a Visitor opens `/screener` with no `sort` param,
Then the select shows `Volumen relativo (RVOL mayor)`, the request carries `sort=rvol_desc`, and the table renders the API order unchanged.

### Scenario 2: Changing the sort reorders the visible list

Given the Screener shows results,
When the Visitor selects `RSI (menor)` (or any of the six options),
Then the URL gains `sort=rsi_asc` (non-default only), a new `/api/screener` request carries `sort=rsi_asc`, and the table renders the returned order (server ranking, never a client sort).

### Scenario 3: Sort persists while filters change

Given the Visitor has selected `Confianza (más señales)` (`sort=signal_count_desc`),
When a filter is toggled/changed (signal, RSI, RVOL, SMA200 or MA cross),
Then the URL still contains `sort=signal_count_desc`, the select is unchanged, and the refetch request carries both the new filter and `sort=signal_count_desc`.

### Scenario 4: Invalid or hand-edited sort

Given a URL with `sort=confidence_desc` or `sort=garbage`,
When the page loads,
Then the parsed sort is `rvol_desc`, the select shows the default, the request carries `sort=rvol_desc`, and no `422` is produced.

### Scenario 5: Clear filters keeps the ranking

Given `sort=rsi_desc` plus active filters,
When the Visitor clicks "Limpiar filtros",
Then the six criterion params are removed, `sort=rsi_desc` remains in the URL, and the request carries `sort=rsi_desc`.

### Scenario 6: Shareable / restorable sort URL

Given a URL like `/screener?sort=change_desc&min_rvol=2`,
When it is opened directly,
Then the select shows `Variación diaria (mayor)` and the request carries both `sort=change_desc` and `min_rvol=2`.

### Scenario 7: Empty and error states keep the control usable

Given a valid-but-empty result or a request error,
When the response is `200 candidates: []` or a non-2xx rejection,
Then the existing empty/error panels render as before (empty is not an error) and the sort control is still rendered and can issue a new request.

## Repository Research

### Files Inspected

- `feature_list.json` (`candidate-list-ranking` entry: "Keep sort options limited to those the screener API supports") — requirements.
- `docs/specs/screener-filters-ui.md` — the accepted UI spec this feature extends (non-goal at the time: "No sorting controls… sends no `sort` param and uses the API default (`rvol_desc`)").
- `frontend/src/lib/api.ts` — `ScreenerFilters`, `ScreenerResponse` (already has `sort: string`), `screenerQueryString` (serializes only the six filter params; explicitly never `sort`/`limit`/`universe`), `screenerApi.search`.
- `frontend/src/lib/screenerFilters.ts` — `parseScreenerFilters`, `patchScreenerFilters` (owns six keys, preserves unrelated params), `hasActiveFilters`, `countActiveFilters`, `screenerFiltersKey`, `EMPTY_SCREENER_FILTERS`.
- `frontend/src/pages/ScreenerPage.tsx` — results header row ("Mostrando N de M candidatos" + Universe chip) rendered only when `data !== null && status !== 'error'`; `update`, `clearFilters`, fetch effect keyed on `screenerFiltersKey`.
- `frontend/src/components/screener/ScreenerFilterPanel.tsx` — chip/segmented/number-input token patterns to reuse for the select.
- `frontend/src/components/screener/CandidateResults.tsx`, `CandidateTable.tsx` — status machine and the table that renders the API order (unchanged).
- `ARCHITECTURE.md` ("Screener API" sorts; "Screener UI" URL-is-source-of-truth / server-side-only), `CONSTRAINTS.md` ("Frontend", "Public API") — the frozen contract and MUST rules.
- `DESIGN.md` (lines 88–147) — tokens, native-control treatment, accessibility baseline.
- `alphapulse/src/types.ts` (`sortBy`), `alphapulse/src/App.tsx` (`sortBy: 'rvol_desc'` default + `queryParams.set('sortBy', …)`), `alphapulse/src/components/ScreenerView.tsx` (sorting bar) — intent reference only.
- `init.ps1` / `frontend/package.json` — SPA gate runs `lint` + `build`; no test runner.

### Existing Patterns To Follow

- Pure helper module `frontend/src/lib/screenerFilters.ts` owns parse/patch/key; components never touch `fetch`.
- `screenerApi.search` serializes only API-valid params and forwards an `AbortSignal`.
- Token-only styling; native inputs use the `NUMBER_INPUT_CLASS` treatment (2px border, `surface-bright`, hard shadow, `focus:shadow-[4px_4px_0px_#ffcc00]`).
- URL is the single source of truth; all writes use the functional `setSearchParams(prev => …, { replace: true })` form.
- No new dependency; verification is `npm --prefix frontend run lint` / `run build` + a live dev smoke.

### Current Gaps

- No sort type, no sort option list/labels, no sort parsing/patching/keying, no sort control, and `screenerQueryString` deliberately omits `sort`.
- The results header is only rendered once data exists, so the control needs an always-visible placement.
- No frontend test runner / E2E harness: bytecode behavior is checked via `tsc -b` + oxlint + a throwaway helper script + the live smoke.

## Technical Approach

### API client (`frontend/src/lib/api.ts`)

- Add `export type ScreenerSort = 'rvol_desc' | 'signal_count_desc' | 'change_desc' | 'change_asc' | 'rsi_desc' | 'rsi_asc'`.
- Add `sort: ScreenerSort` to `ScreenerFilters` (required).
- In `screenerQueryString`, always `params.set('sort', filters.sort)` (keep omitting `limit`/`universe`); update the doc comment that currently says sort is never sent.

### Helpers (`frontend/src/lib/screenerFilters.ts`)

- `export const DEFAULT_SCREENER_SORT: ScreenerSort = 'rvol_desc'`.
- `export const SCREENER_SORT_OPTIONS: readonly { value: ScreenerSort; label: string }[]` in the Scenario-2 order, labels exactly as in Decision 2.
- `export function isScreenerSort(value: string | null): value is ScreenerSort` (or an exportable `parseScreenerSort`), returning `DEFAULT_SCREENER_SORT` for absent/unknown.
- `parseScreenerFilters` -> `sort: parseScreenerSort(params.get('sort'))`.
- `patchScreenerFilters` -> when `patch.sort !== undefined`, `setOrDelete(next, 'sort', patch.sort === DEFAULT_SCREENER_SORT ? null : patch.sort)` (default is omitted from the URL).
- `screenerFiltersKey` -> append `filters.sort` as a new deterministic segment (last).
- `hasActiveFilters` / `countActiveFilters` -> unchanged (do **not** count `sort`).
- `EMPTY_SCREENER_FILTERS` -> add `sort: DEFAULT_SCREENER_SORT`. Export a criteria-only `EMPTY_SCREENER_CRITERIA: Partial<ScreenerFilters>` (or an equivalent list of the six criterion fields) for the clear action.

### Page and components

- `frontend/src/components/screener/ScreenerSortControl.tsx` — create; props `{ value: ScreenerSort; onChange: (sort: ScreenerSort) => void }`; a `<label>` "Ordenar por" + native `<select>` with `SCREENER_SORT_OPTIONS`, styled with the token input treatment and `focus:shadow-[4px_4px_0px_#ffcc00]`.
- `frontend/src/pages/ScreenerPage.tsx` — render the results header on every status; put `<ScreenerSortControl value={filters.sort} onChange={(sort) => update({ sort })} />` in it next to the Universe chip; keep the `Mostrando …` count conditional on `data`; `clearFilters` patches `EMPTY_SCREENER_CRITERIA` (not `EMPTY_SCREENER_FILTERS`) so `sort` survives.

No change to `ScreenerFilterPanel.tsx`, `CandidateResults.tsx` or `CandidateTable.tsx` semantics (the table keeps rendering `candidates` as received).

## Expected File Changes

- `frontend/src/lib/api.ts` — modify; add `ScreenerSort`, add `sort` to `ScreenerFilters`, send `sort` in `screenerQueryString`.
- `frontend/src/lib/screenerFilters.ts` — modify; sort constants/labels, parse/patch/key support, criteria-only clear constant; keep `sort` out of active-filter counts.
- `frontend/src/components/screener/ScreenerSortControl.tsx` — create; the labeled select.
- `frontend/src/pages/ScreenerPage.tsx` — modify; always-visible results header with the sort control; criteria-only `clearFilters`.
- `ARCHITECTURE.md`, `CONSTRAINTS.md` — update (see Durable Documentation Impact).
- `docs/specs/candidate-list-ranking.md` (this file), `PROGRESS.md`, `feature_list.json` — update at implementation.
- Not changed: `ScreenerFilterPanel.tsx`, `CandidateResults.tsx`, `CandidateTable.tsx` (beyond no-op), `router.tsx`, `nav.ts`, `AppHeader.tsx`, `frontend/package.json`/lockfile, `routes/api.php`, `ScreenerController.php`, `init.ps1`, Laravel/engine/schema.

## Visual Design Impact

- UI involved: yes. Design source: `DESIGN.md` (visual source of truth) and `alphapulse/src/components/ScreenerView.tsx` (intent reference only).
- Screens/states affected: `/screener` results header in loading / refreshing / ready (populated and empty) / error; responsive widths.
- New design artifact required: no — a select reuses existing tokens/native-control treatment.
- `DESIGN.md` references to honor:
  - Layout (109): filters at the top, ranked table below; the sort bar sits in the results header directly above the table.
  - Components (121–124): native control styled like the other inputs (2px `#1a1a1a` border, `surface-bright`, hard `2px 2px 0 #1a1a1a` shadow, mono label/status text); no pill, no gradient.
  - Typography (97–102): the "Ordenar por" label and the control text use mono; the option labels are Spanish.
  - Accessibility (142–147): a real `<label htmlFor>` + `<select>` (keyboard operable), visible focus (`focus:shadow-[4px_4px_0px_#ffcc00]`), no color-only state.
  - Responsive (136–140): the header row wraps on narrow widths.
- Feature-specific states: the control must remain usable during loading/error (Decision 1); it shows the URL value even before the first response arrives.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — "Screener UI" notes that `sort` is URL-backed with the API values, the request always carries `sort`, and the SPA still never re-sorts client-side.
- `CONSTRAINTS.md`: update — Frontend Screener MUST rules: `sort` is URL-backed using the six API values and defaults to `rvol_desc`; the SPA MUST send `sort` but MUST NOT client-sort; `sort` MUST NOT count as an active filter and MUST survive "Limpiar filtros".
- `AGENTS.md`: not needed — no workflow/startup/operating-rule/verification change; `init.ps1` unchanged.
- `DESIGN.md`: not needed — existing tokens cover the select.
- `docs/specs/screener-filters-ui.md`: not needed — it is an accepted spec; the durable rules move to `ARCHITECTURE.md`/`CONSTRAINTS.md`.
- Other: `PROGRESS.md`, `feature_list.json` — update with evidence at implementation.

## Implementation Plan

1. Add `ScreenerSort` + `sort` to `ScreenerFilters` and make `screenerQueryString` send `sort` (`frontend/src/lib/api.ts`).
2. Add the sort constants, parse/patch/key support and the criteria-only clear constant to `frontend/src/lib/screenerFilters.ts`.
3. Build `ScreenerSortControl.tsx` with token-only styling.
4. Wire it into an always-visible results header in `ScreenerPage.tsx` and switch `clearFilters` to the criteria-only patch.
5. Verify: `npm --prefix frontend run lint`, `run build`, a throwaway helper check, a live dev smoke, a manual browser pass, `.\init.ps1`.
6. Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [x] Add `ScreenerSort`, `ScreenerFilters.sort` and always-send `sort` in `screenerQueryString` (`frontend/src/lib/api.ts`).
- [x] Add `DEFAULT_SCREENER_SORT`, `SCREENER_SORT_OPTIONS` (6 values + Spanish labels), sort parsing, sort patching (delete when default), sort in `screenerFiltersKey`, and `EMPTY_SCREENER_CRITERIA` (`frontend/src/lib/screenerFilters.ts`); keep `sort` out of `hasActiveFilters`/`countActiveFilters`.
- [x] Create `frontend/src/components/screener/ScreenerSortControl.tsx` (label + native select, token styling, focus offset).
- [x] Render the results header on every status and mount the sort control in `ScreenerPage.tsx`; make `clearFilters` preserve `sort`.
- [x] Confirm no client-side sorting and that `CandidateTable` still renders the API order.
- [x] Run `npm --prefix frontend run lint` and `run build` (0 warnings/errors, exit 0); confirm no new dependency.
- [x] Run the temporary helper check (parse absent/invalid/6 values; patch default vs non-default; key differs per sort; counts ignore sort; clear preserves sort) and remove the script.
- [x] Run the live dev smoke: each of the six `sort` values returns the API order through the proxy; `/screener` renders; teardown servers and fixture rows.
- [x] Run the manual browser checklist (Scenarios 1–7) and note the no-browser-automation gap.
- [x] Run `.\init.ps1` (exit 0, no server started).
- [x] Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Verification Plan

No frontend test runner or E2E harness exists, so the closest verification is `tsc -b`/oxlint + `vite build` + a throwaway pure-helper check + an HTTP/URL live dev smoke + a manual browser checklist. There is no persistent E2E command to add/update; record that gap in `PROGRESS.md`.

- `npm --prefix frontend run lint` -> `tsc -b` + oxlint, 0 warnings/0 errors, exit 0.
- `npm --prefix frontend run build` -> production build succeeds, exit 0; no dependency change in `frontend/package.json`.
- Temporary helper check via `node --experimental-strip-types` (removed afterwards): `parseScreenerFilters` maps absent/`garbage`/`confidence_desc` -> `rvol_desc` and all six valid values through; `patchScreenerFilters` deletes `sort` for `rvol_desc` and sets it otherwise while preserving unrelated params; `screenerFiltersKey` differs per sort; `countActiveFilters` is unchanged by `sort`; the criteria-only clear leaves `sort` in the URL.
- **Live dev smoke** (temporary, torn down): seed a small fixture universe (bars + snapshots + signals + varied `rvol`/`rsi14`/`change_percent`/signal counts, temporary script + cleanup), start `php artisan serve` (`:8000`) and `npm --prefix frontend run dev` (`:5173`, via the Vite proxy) and confirm for each of the six values that `GET /api/screener?sort=<value>` returns the expected ticker order (and that `rvol_desc` is the default with no param); confirm `/screener?sort=rsi_asc` and every new/changed module transform 200 and the transformed bundle contains `sort`; interact via the proxy as needed to confirm one request per sort change. Stop all servers, release ports and delete the temporary rows.
- **Manual browser checklist** (Scenarios 1–7): default select/request; each of the six options reorders the table and updates the URL; a filter change keeps the selected sort in the URL, select and request; an invalid `sort` falls back without a `422`; "Limpiar filtros" keeps the sort; a pasted `?sort=…` URL restores the select; DESIGN fidelity (2px border, hard shadow, mono, focus offset, wrapping). Visual fidelity is manual (no browser automation).
- `.\init.ps1` -> exit 0 (Laravel tests + SPA lint/build + engine tests), starts no server; `init.ps1` unchanged and stays non-blocking.

## Evidence To Capture

- `npm --prefix frontend run lint` / `run build` output (0 errors, exit 0); `frontend/package.json` unchanged.
- The six `sort` request URLs observed and their ticker order, the default (`sort=rvol_desc`) behavior, and the invalid-value fallback.
- Manual per-scenario results (pass/fail), including the no-browser-automation note.
- `.\init.ps1` exit status.
- Confirmation that `ScreenerController.php`, `routes/api.php`, `ScreenerFilterPanel.tsx`, `CandidateResults.tsx`, `CandidateTable.tsx`, `nav.ts`/`router.tsx`, Laravel/engine/schema and `init.ps1` were untouched.

## Key Implementation Risks

- **Accidental client sort** — do not add a comparator; the table must render `response.candidates` as received. The only effect of `sort` is the query param.
- **`sort` counted as a filter** — `hasActiveFilters`/`countActiveFilters` and the "Limpiar filtros" button/labels must ignore `sort`, or clearing/empty-state logic drifts.
- **Losing the sort on filter change** — patch only the changed keys and keep `sort` in the URL; a `sort`-less refetch would silently reset the ranking to `rvol_desc`.
- **Stale key / no refetch** — `screenerFiltersKey` must include `sort`, otherwise changing only the sort will not trigger a request (the duplicate-suppression guard compares keys).
- **Invalid URL -> 422** — parse `sort` strictly against the six API values and fall back to `rvol_desc`; never forward an unknown value.
- **Control unavailable during loading/error** — render the results header on every status so the control is reachable (Decision 1).
- **DESIGN without a browser** — reuse the exact token classes and the established input treatment; keep the label/select keyboard-accessible with a visible focus ring.
- **Scope creep** — no new filter, no `limit`/`universe` control, no row navigation, no saved screener/watchlist, no API change.

## Implementation Findings

- Implemented as specified; no deviation from the Technical Approach. `parseScreenerSort` is the exported parser (with a private `isScreenerSort` guard), which the spec allowed.
- **Manual browser checklist / DESIGN fidelity gap:** no frontend test runner or E2E harness exists, so Scenarios 1–7 and the pixel-level DESIGN checks (2px border, hard shadow, mono, focus offset, wrapping header) were verified at the helper/HTTP/URL/transformed-module level rather than in a real browser. The "sort change -> one request" path is covered by `screenerFiltersKey` differing per sort (it drives the fetch effect deps) plus the duplicate-suppression guard.
- `ScreenerResponse.sort` is still typed `string` and intentionally unused for the control (Decision 8); the select is driven by the URL-owned `sort`.
- Environment notes for the smoke: Vite dev binds `localhost`/`::1`, so proxy requests must use `http://localhost:5173` (not `127.0.0.1`); PowerShell 5.1 parses `"$base?sort=..."` as a variable named `$base?sort`, so query strings must be concatenated rather than interpolated.
- The frozen API's pre-existing shared throttle limiter key is unchanged: rapid sort changes count against the same `sha1(domain|ip)` bucket as the login limiter (no scope to change it here).

## Validator Checklist

- [ ] Implementation stays within scope (no API/Laravel/engine/schema change, no new filter/limit/universe control, no client re-sort, no new dependency).
- [ ] Acceptance scenarios 1–7 pass; the control is anonymous and reachable in loading/error states.
- [ ] Exactly the six API sort values are offered with Spanish labels; absent/invalid URL values fall back to `rvol_desc` with no `422`.
- [ ] Every sort change is reflected in the API request (`sort=<value>`), and the visible order equals the API order (no client comparator).
- [ ] The selected sort persists across filter changes and "Limpiar filtros"; a `?sort=…` URL restores it.
- [ ] `sort` is URL-backed and is not counted as an active filter.
- [ ] The control follows `DESIGN.md` (2px border, hard shadow, mono, visible focus, wrapping header).
- [ ] No frontend test runner/E2E harness exists, and the lint/build + helper check + dev smoke + manual checklist is the documented closest verification.
- [ ] `ARCHITECTURE.md`/`CONSTRAINTS.md` updated; `PROGRESS.md`/`feature_list.json` updated with evidence.
- [ ] No unrelated product behavior or extra feature work was added.
