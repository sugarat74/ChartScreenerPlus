# Feature Implementation Spec: Compact Screener filter panel

## Source Feature

- `id`: `screener-filter-panel-compact`
- `area`: `screener`
- `depends_on`: `screener-technical-filters-ui` (accepted), `market-context-patterns` (accepted), `screener-table-column-controls` (accepted)
- `status`: `not_started` at planning time
- `source`: `feature_list.json`; explicit user request 2026-11-05 ("repiensa el apartado de los filtros 'Criterios técnicos' ... más recogido/limpio estilo Finviz con las mismas opciones pero ocupando menos espacio (desplegables o pestañas) evalúa lo que sea mejor"). The choice of pattern was delegated to the planner.

## Goal

Replace the tall «Criterios técnicos» block of the home Screener (15 Signal chips, RSI inputs, RVOL chips, SMA200 chip, MA-cross segments, 12+ Pattern chips in two groups plus status, Sector context) with a compact, Finviz-style toolbar: one row of small controls, each showing its current value, with the multi-value groups opening one inline editor card on demand. At rest the panel must take at most ~2 rows (target <= 120 px tall at 1440 px; today roughly 350-450 px) and at 390 px at most ~4 rows (<= 220 px).

This is a layout/interaction refactor only. Every filter keeps its options, semantics, URL key, API request, Saved Screener definition and natural-language mapping (the same 30 criteria keys). Nothing is added or removed.

## Non-Goals

- No new, removed or renamed filter, preset or URL parameter; no API/backend/engine change; no change to `lib/screenerFilters.ts` parsing, `countActiveFilters` or Saved Screener serialization.
- Not the Excel/Access-style sort+filter menu in column headers (separate pending idea). Overlap is acknowledged: both write through the same `update(patch)`; this feature must not change `ScreenerColumnFilters`/`CandidateTable`, so that idea can later reuse the editors.
- No Apply button, no draft store, no new dependency (no popover/headless-UI lib), no floating positioned popovers, no whole-panel collapse, no tabs.
- No change to `UniverseSelector`, `ScreenerAiQuery`, `SavedScreenersPanel`, `ScreenerSortControl`, `IndicatorsToggle`, `MarketHoursStrip`.

## Evaluation And Decision

Constraints weighed: 15 multi-select Signals and many Patterns are the bulk of the height and cannot be summarised without being opened; mobile 390 px; a11y; no new deps; active filters must stay visible; the table-header funnels already use an inline editor card.

| Option | Verdict | Why |
|---|---|---|
| (a) Row of dropdown/popover triggers showing their value | Adopted as the base, but with an **inline** card instead of a floating popover | Finviz look, state visible at rest, one row. Floating popovers need anchoring, collision/clipping handling, a bottom sheet at 390 px and outside-click/focus logic (or a dependency): high risk for no gain. |
| (b) Tabs | Rejected | Finviz needs tabs for ~130 filters; we have 7 groups. Tabs hide which filters are active in other tabs, add a second navigation level, and the panel keeps the height of the tallest tab. |
| (c) Collapsible whole panel | Rejected as the main device | A closed panel hides current values (needs a separate summary anyway) and an open one is as tall as today. The toolbar is already one row. |
| (d) Toolbar of value-showing controls + one inline editor card + count/clear | **Chosen** | Same interaction model the user already has in the table-header funnel editor (one bordered card, `aria-expanded`, Esc returns focus); identical at 390 and 1440 px; zero dependencies; no clipping; no focus trap. |

Per group (the most compact control that is also accessible):

| Group | Control in the toolbar | Shows when active |
|---|---|---|
| Signals (15, multi, OR) | trigger -> inline card with the 15 chips | `Señales · 2` |
| RSI (14) min/max | trigger -> inline card with the two inputs | `RSI 30-70`, `RSI >= 30`, `RSI <= 70` |
| Min RVOL (1, 1.5, 2, 3) | native `<select>` (`Todos`, `>= 1×` ...) | the chosen preset |
| Price > SMA200 | single toggle chip (`aria-pressed`), no card | pressed style |
| MA cross (any/bullish/bearish) | native `<select>` | the chosen value |
| Patterns (multi, grouped) + status | trigger -> inline card with the two direction groups, status segments and hint | `Patrones · 3` (+ status when not `any`) |
| Sector context (Market Context access) | trigger -> inline card (trend segments, rank select, hint, `Vista previa` chip for Admin preview; dashed sign-in box for `signIn`); not drawn for `hidden`/`loading`/non-US | `Sector: ▲ · Top 3` |

Native selects for single-choice groups keep them one tap, fully accessible and mobile-native. Active triggers/selects use the existing «active» look (`bg-primary text-on-primary`, hard shadow) so activity reads without opening anything. No persisted open/closed state is needed (see open question 2).

## Interaction And A11y Design

- `ScreenerFilterPanel` keeps its export and props (`filters`, `activeCount`, `access`, `onChange`, `onClear`); `ScreenerPage` is untouched.
- Header line: `Criterios técnicos` (smaller than today's `text-lg`), the combine hint (`Y` between filters, `O` between Signals) in one mono line, the existing `N filtro(s) activo(s)` count and `Limpiar filtros` (disabled at 0, same `onClear`). The footer line (`filtersInUrl`/`noFiltersHint`) is dropped; the `filtersInUrl` text becomes the Clear button `title`.
- Immediate apply exactly as today: chips, selects and toggles call `onChange` on click/change; RSI inputs keep uncontrolled drafts and commit on blur/Enter (`parseRsiInput`). Closing the RSI card (trigger click, Esc, opening another group) must first commit the draft (blur the input before unmount) so a typed value is never lost; test it.
- One card open at a time (`openGroup: 'signals' | 'rsi' | 'patterns' | 'sector' | null`, local state, not in the URL). Trigger = `<button type="button" aria-expanded aria-controls="screener-criteria-card">`; the card is a bordered `role="group"` labelled by its trigger, rendered right under the toolbar row (not a `dialog`, no focus trap, no outside-click close, Tab flows naturally). Esc inside the card closes it and returns focus to its trigger; clicking the trigger toggles it. The accessible names of every existing group (signals legend with count, patterns legend, `maCrossAria`, `patterns.statusAria`, `sectorContext.trendAria`, `screener-rsi-min/max`, `screener-sector-rank`) are preserved so existing `getByRole('group', { name })` / label queries keep working once the card is open.
- Trigger text = visible name + value; count/value is part of the accessible name (no colour-only state). No `aria-live` needed.
- Pattern status segments stay disabled with no pattern selected; unselecting the last pattern still resets status to `any` (unchanged `togglePattern`).
- Deep link / Saved Screener / AI query that sets criteria: toolbar values follow `filters`; cards stay closed unless the user opens one.
- Table-header funnels stay as they are. Delineation: the toolbar is the «Criterios técnicos» entry point; the «Filtros de columna» chips row keeps showing column criteria. Signals/Patterns/RSI/RVOL remain also reachable from the column funnels as today (existing intentional duplication, same keys). `activeCount` stays the all-criteria count (unchanged).

## Responsive

- >= `lg`: header line + one wrapping row of controls (about 7 controls fit one row at 1440 px).
- < `sm` (390 px): controls wrap in a 2-column grid (selects and triggers `w-full`), the open card is full width under the grid, chips wrap; no horizontal page scroll; touch targets >= 36 px high.
- Light/dark via existing tokens only (`bg-surface-bright`, `border-outline`, `bg-primary`, `shadow-[2px_2px_0px_var(--color-shadow)]`, yellow focus shadow); no new colours.

## Acceptance Scenarios

1. Given no filters, when the page loads at 1440 px, then the panel shows the header and one control row, cards closed, and is <= 120 px tall.
2. Given I open «Señales» and click two chips, then the URL/API request carry both Signals exactly as before, the trigger reads `Señales · 2`, and Esc closes the card and focuses the trigger.
3. Given a URL with `rsi_min`, `rsi_max`, `min_rvol`, patterns and `ma_cross`, when the page loads, then every control shows its value without opening a card and the API request is identical to the one before the refactor.
4. Given I type an RSI min and press Esc or open another trigger, then the value is committed once (no request per keystroke) and not lost.
5. Given an Admin preview or a Registered User on the S&P 500, the Sector trigger appears (with `Vista previa` for Admin); for a Visitor once public it opens a dashed sign-in box; on non-US universes or `hidden`/`loading` it is not drawn.
6. Given active filters, «Limpiar filtros» runs the same `onClear` and every control returns to its empty state; Saved Screeners, AI query and column funnels behave as before.
7. At 390 px the panel at rest takes <= ~4 short rows, the open card fits the width, nothing scrolls horizontally, in light and dark, es and en.

## Repository Research

Inspected: `frontend/src/components/screener/ScreenerFilterPanel.tsx` (single 419-line component, 7 fieldsets, immediate commit except RSI draft), `ScreenerFilterPanel.test.tsx` (only the sector-context fieldset, via group-name/label queries), `ScreenerColumnFilters.tsx` (inline editor card pattern, `focusToggle`, Esc), `IndicatorsToggle.tsx`, `SavedScreenersPanel.tsx` (uses `<details>`), `pages/ScreenerPage.tsx` (panel at ~line 375, `update`, `clearFilters`, `activeCount`), `lib/screenerFilters.ts` (`countActiveFilters`, `EMPTY_SCREENER_FILTERS`, criteria keys), `DESIGN.md` Screener paragraph, `messages/es.ts` keys `screener.filtersTitle` .. `clearFilters`. Not inspected: the live site was not browsed and Finviz was not fetched (its layout is known: Descriptive/Fundamental/Technical tabs of compact selects). No persistent E2E command exists (none in `package.json`), so coverage is Vitest plus a manual browser smoke.

Patterns to follow: token-only neo-brutal classes already in the panel (`chipClass`, `segmentClass`, `SELECT_CLASS`), `aria-pressed` chips, `aria-expanded` funnel toggles, `useI18n` and `messages/{es,en}.ts`.

## Technical Approach

1. Extract the per-group editors from `ScreenerFilterPanel.tsx` unchanged (markup, classes, handlers) into small components in `frontend/src/components/screener/ScreenerCriteriaEditors.tsx` (`SignalsEditor`, `RsiEditor`, `PatternsEditor`, `SectorEditor`).
2. Add the toolbar: triggers, the two native selects (RVOL via `MIN_RVOL_PRESETS` + `formatMultiple`; MA cross via the existing labels; `Todos`/`Cualquiera` -> `null`), the SMA200 toggle, `openGroup` state, Esc/focus handling; summary formatters in a pure helper `frontend/src/lib/screenerToolbar.ts`.
3. `ScreenerFilterPanel` stays the thin public wrapper (header + toolbar + card).
4. i18n: add only short trigger/summary keys (e.g. `screener.toolbar.*`) in es and en; remove keys that become unused (`filtersInUrl` as a footer line, `noFiltersHint`, legends only if unreferenced); keep legends used as accessible names. No hardcoded text, no EOD wording, no advice wording (the existing ADVICE regexp test must still pass).
5. `DESIGN.md`: rewrite the Screener sentence about the filter panel. Update the user guide entry that describes the panel layout only if one exists (search first).

## Expected File Changes

- `frontend/src/components/screener/ScreenerFilterPanel.tsx` — modify.
- `frontend/src/components/screener/ScreenerCriteriaEditors.tsx`, `frontend/src/lib/screenerToolbar.ts` — create.
- `frontend/src/components/screener/ScreenerFilterPanel.test.tsx`, `frontend/src/lib/screenerToolbar.test.ts` — modify/create.
- `frontend/src/i18n/messages/es.ts`, `en.ts` — modify.
- `DESIGN.md`, `PROGRESS.md`, `feature_list.json` — update. `ARCHITECTURE.md`, `CONSTRAINTS.md`, `AGENTS.md`, backend: not needed (no data, API, rule or scope change).

## Implementation Tasks

1. Extract editors with identical behaviour; existing tests stay green.
2. Build toolbar, summaries, selects, SMA200 toggle, single-open-card state, Esc/focus.
3. RSI draft commit on close; new header and Clear; remove the old footer.
4. es/en messages; DESIGN.md; user guide if applicable.
5. Tests, manual smoke, `.\init.ps1`.

## Verification Plan

- Vitest, unchanged behaviour: for every control, `onChange` receives exactly the patch it did before (signal toggle add/remove, RSI blur/Enter -> `rsiMin/rsiMax` via `parseRsiInput`, RVOL preset and `Todos` -> `null`, SMA200 toggle, each MA cross value, pattern toggle, last pattern removed -> `patternStatus: 'any'`, status disabled with no patterns, sector trend/rank). Existing sector tests keep passing with the card open; `countActiveFilters`/serialization tests untouched.
- Vitest, new: summaries per group (empty, min only, max only, both); `aria-expanded`/`aria-controls`; only one card open; Esc closes and focuses the trigger; RSI draft committed on close; header count and Clear disabled at 0; sector states (allowed, preview, signIn, hidden/loading/undefined); es and en; no ADVICE wording.
- Page level (existing `ScreenerPage` tests or one new): a multi-filter deep link shows values without opening cards and issues the same request.
- Manual browser smoke, recorded by the implementer: 390 and 1440 px, light and dark, es and en, keyboard-only pass (Tab order, Enter/Space, Esc), measured heights vs targets, network request of one multi-filter URL before/after.
- `npm --prefix frontend run test`, `npm --prefix frontend run lint`, `npm --prefix frontend run build`, `.\init.ps1`.

## Evidence To Capture

Before/after screenshots at 390/1440 (light/dark), measured panel heights, the test list above, the identical request URL for one fixed filter combination, `.\init.ps1` result.

## Validator Checklist

- Same options, URL params and requests as before; nothing added or removed.
- Panel at rest within the height targets; active state visible without opening anything.
- Keyboard and screen-reader behaviour as specified; no focus trap; no horizontal scroll at 390 px.
- Light/dark and es/en complete; DESIGN.md updated; `ScreenerColumnFilters` and `CandidateTable` untouched.

## Open Questions (defaults)

1. Keep Signals/Patterns/RSI/RVOL also in the table-header funnels? Default yes (unchanged, out of scope).
2. Remember the open card or a collapsed state in `localStorage`? Default no (not needed at one row; revisit if a collapse is wanted).
3. RVOL and MA cross as native selects instead of chips? Default yes (more compact); easy to switch back to segmented controls.
4. Header count: keep the all-criteria count (includes column criteria) as today? Default yes.
