# Feature Implementation Spec: Interactive candidate chart

## Source Feature

- `id`: `chart-interactive`
- `area`: `chart`
- `depends_on`: `instrument-detail-api`, `app-shell-navigation` (both `accepted`)
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

A Visitor opens a Candidate and sees a real, interactive chart of that Instrument: candlesticks from the stored Daily Bars, a volume histogram, moving-average reference levels and the active Signal's pivot level — all rendered from the accepted, frozen `GET /api/instruments/{ticker}` payload. The screen replaces the `/chart` placeholder and introduces the first per-instrument deep link (`/instruments/:ticker`) reachable from the existing Screener Candidate table.

The feature is EOD-only and read-only. It calls one existing public endpoint, adds one self-hosted npm charting library, and invents no new data.

## Non-Goals

- No API, Laravel, engine, migration, model or schema change. `instrument-detail-api` is **frozen** (`CONSTRAINTS.md` → Public API).
- No per-bar indicator series and no client-side indicator math. The engine owns indicator math (`CONSTRAINTS.md` → Indicators); the API returns only the **latest** snapshot.
- No stop/target levels (they are not modeled anywhere — see the reconciliation below).
- No RSI/MACD/Bollinger sub-panes, no EMA overlays, no timeframe (weekly/monthly) selector, no drawing tools, no compare/overlay symbols, no fullscreen mode, no screenshot/export.
- No watchlist action, no saved screener, no new screener filters/sort, no screener redesign. The only Screener change is a minimal ticker link in `CandidateTable`.
- No embedded TradingView widget (decision already made: self-hosted `lightweight-charts`).
- No new frontend test runner / E2E harness (none exists in this repo).
- No `nav.ts` / `AppHeader.tsx` change and no new tab.

## Job Story

When the Screener surfaces a Candidate I want to inspect,
I want to open its chart and see the EOD price action, volume, the moving averages and the breakout pivot it triggered on,
so I can judge the setup on the chart instead of another table row.

## Users And Permissions

- Visitor (no session): full access. `GET /api/instruments/{ticker}` is anonymous (the `api` middleware only) and `docs/user-and-access-model.md` states browsing requires no session. The chart page **must not** call `useAuth`, redirect or prompt for login.
- Registered User / Admin: identical anonymous read access; no ownership is involved.
- No authorization branch anywhere on this surface.

## Decisions (explicit)

1. **Route + entry.** Add a canonical instrument deep link `/instruments/:ticker` (nested under the existing `AppLayout` shell). Primary entry: the ticker in each `CandidateTable` row becomes a `react-router` `<Link to={`/instruments/${encodeURIComponent(candidate.ticker)}`}>` — a **minimal integration link only**, no row redesign, no other `CandidateTable` change. Secondary entry: the existing `/chart` route is re-pointed at the same page in "no ticker selected" mode (a short guidance empty state linking to `/screener`) so the accepted Chart tab stops rendering a placeholder that claims this feature is pending. `/chart` and `/instruments/:ticker` share one page component (`InstrumentChartPage`); `ChartPage.tsx` is deleted. `nav.ts` is unchanged (`/chart` stays the tab path).
2. **Bar window.** Request `?limit=252` explicitly (one trading year; the API default and well inside the `1..2000` clamp). The window is a named constant so a future change is intentional. The API returns the newest N bars ascending; the SPA must not re-order them.
3. **MA overlays — the critical reconciliation.** The detail API returns **only the latest** Indicator Snapshot (`sma20`, `sma50`, `sma200` are single numbers for the as-of bar), **not a per-bar indicator series**. Therefore the chart draws each `sma20`/`sma50`/`sma200` value that is non-null as a **horizontal reference price line** at its latest value (labelled `SMA 20` / `SMA 50` / `SMA 200`), and draws **no line when the value is `null`**. A real per-bar MA curve is impossible from the frozen payload; the SPA **must not** compute SMA/EMA client-side (the engine owns indicator math; a client copy would duplicate and could drift). A per-bar series is recorded as a Future Hook requiring an API extension (`?include=indicators` or extra bar fields) — not this feature.
4. **Volume.** A `HistogramSeries` volume pane below the price pane (v5 `addSeries(HistogramSeries, options, paneIndex)` — confirm the exact signature in the installed typings), one bar per Daily Bar at the bar's date, colored by candle direction (`gain` / `loss`). No volume MA, no RVOL overlay (the latest `rvol` is shown as text, not drawn).
5. **Signal levels — pivot/stop/target reconciliation.** Draw **one price line** for the active `pivot_breakout_rvol` signal, using `metadata.pivot` (a real value the engine computed; the prior 20-session high). Also surface the same signal's `metadata.close` and `metadata.rvol` as text in a small "Señales activas" panel. **Stop and target are NOT drawn and MUST NOT be drawn or derived.** No table, snapshot, signal metadata or command computes a stop or a target; the prototype's numbers are fake fixture data. Deriving them (e.g. an ATR multiple) would be new product behavior, so it is explicitly **out of scope** and recorded as a Future Hook: stop/target must first be modeled by the engine/signals pipeline (and persisted in signal metadata), after which the chart can draw them without invention. The other 8 signal types carry empty/unknown metadata and contribute no line; all active signals are listed textually with the existing `signalLabel()` helper.
6. **Charting library & version verification.** Add **`lightweight-charts`** from npm to `frontend/` (self-hosted bundle; Apache-2.0; no CDN and no embedded TradingView widget). Do **not** assume the version: run the install, read the resolved version and the installed `dist/typings.d.ts`, and pin exactly what was verified. Registry state at planning time is `5.2.1` / `Apache-2.0`, and the v5 API is `createChart(container, options)` + `chart.addSeries(CandlestickSeries | HistogramSeries, options[, paneIndex])`; verify these before coding and record the result.
7. **Teardown & lifecycle.** Lightweight Charts owns a canvas + observers. Create the chart in one `useEffect` that depends on the loaded payload/ticker and always return a cleanup that calls `chart.remove()` (removing the series and observers) and clears refs. The effect must be safe under React 19 StrictMode double-invocation and on ticker change (no leaked canvases, no console errors, no duplicate charts).
8. **States.** `loading` (skeleton panel), `ready` (chart), `not_found` (API `404 {message:"Instrument not found."}` → "Instrumento no encontrado" panel with a link to the Screener), `error` (`role="alert"` + manual Reintentar, no auto-retry), `empty` (`bars.length === 0` → "Sin datos EOD para {TICKER}" panel, chart never created), and a soft "insufficient history" note when `snapshot` is `null` or some SMA values are `null` (chart still renders; missing lines are simply absent).
9. **Accessibility & responsiveness.** The library renders to `<canvas>` and is not screen-reader navigable, so: give the chart container `role="img"` + a descriptive `aria-label`, render a textual OHLC/legend strip above it (mono, last bar + SMA values + pivot), and keep the interactive OHLC/crosshair as a progressive enhancement. The chart must resize with its container (`autoSize: true`) inside a card with an explicit minimum height; the layout is desktop-first and must not force horizontal page scroll at tablet widths.

## Acceptance Scenarios

### Scenario 1: Open a Candidate from the Screener

Given the Screener shows Candidates and one has stored bars, a snapshot and a signal,
When the Visitor clicks that Candidate's ticker,
Then the browser navigates to `/instruments/{TICKER}` and the page requests `/api/instruments/{TICKER}?limit=252`, rendering candlesticks, volume bars, the SMA reference lines and the pivot line.

### Scenario 2: Deep link and case-insensitivity

Given an Instrument stored as `NVDA`,
When the Visitor opens `/instruments/nvda` directly (no session),
Then the page renders that Instrument's chart and shows the canonical ticker from `instrument.ticker` (`NVDA`), with bars plotted left-to-right ascending by date.

### Scenario 3: MA overlays come from the latest snapshot only

Given the detail payload has `snapshot.sma50 = 165.2` and `snapshot.sma200 = null`,
When the chart renders,
Then an `SMA 50` horizontal reference line is drawn at ~165.2 and **no** `SMA 200` line is drawn; the SPA performs no client-side moving-average computation.

### Scenario 4: Signal reference level from the active signal

Given the Instrument has an active `pivot_breakout_rvol` signal with `metadata = {pivot: 100, close: 110, rvol: 3}`,
When the chart renders,
Then a pivot price line is drawn at 100, labelled as the pivot, the signal is listed in the "Señales activas" panel with its close and RVOL, and **no** stop or target line exists.

### Scenario 5: Insufficient history is handled gracefully

Given an Instrument with no stored Daily Bars (`bars: []`, `meta.bar_count: 0`),
When `/instruments/{TICKER}` is opened,
Then the page shows a "Sin datos EOD" panel and **does not** create a chart (no crash, no empty canvas).
Given instead an Instrument with only a few bars and `snapshot: null`,
Then the chart renders candlesticks and volume, no MA lines, and an informative "historial insuficiente" note — no fabricated values.

### Scenario 6: Unknown ticker

Given no Instrument `ZZZZ`,
When `/instruments/ZZZZ` is opened,
Then the page shows the not-found panel containing the API message and a link back to the Screener, and no chart is created.

### Scenario 7: Network failure and retry

Given the request fails (network error or non-2xx treated as an error),
When the request settles,
Then a `role="alert"` panel shows the message and a "Reintentar" button that reissues exactly one request.

### Scenario 8: The bare Chart tab is not a dead end

Given a Visitor clicks the Chart tab without a ticker,
When `/chart` loads,
Then a short guidance panel renders ("Selecciona un candidato…") with a link to `/screener`, and no API request is issued.

### Scenario 9: Teardown on navigation / ticker change

Given a chart is rendered,
When the Visitor navigates to another Candidate or leaves the page,
Then the previous chart instance is removed (no orphaned canvas, no console errors) and a fresh chart renders for the new ticker.

## Repository Research

### Files Inspected

- `feature_list.json` — `chart-interactive` entry (verification criteria + the now-resolved charting-library note); `depends_on` = `instrument-detail-api`, `app-shell-navigation` (both `accepted`).
- `docs/specs/instrument-detail-api.md` — the frozen contract consumed here; its Future Hooks already name the per-bar indicator series as a `chart-interactive` decision.
- `docs/specs/screener-filters-ui.md` — the established SPA page/state/loading/error pattern and the "no test runner/E2E" verification convention.
- `frontend/src/lib/api.ts` — `request<T>` helper (`credentials: 'include'`, CSRF echo, `ApiError`), and the `screenerApi` module pattern to mirror.
- `frontend/src/router.tsx`, `frontend/src/nav.ts` — child routes are relative strings; `/chart` exists as a placeholder; `NAV_ITEMS` is the single source of tab paths.
- `frontend/src/pages/ChartPage.tsx`, `frontend/src/components/PagePlaceholder.tsx` — the placeholder to replace.
- `frontend/src/pages/ScreenerPage.tsx`, `frontend/src/components/screener/{CandidateResults,CandidateTable}.tsx` — the request/state machine and the table whose ticker cell becomes the entry link (`signalLabel` lives in `frontend/src/lib/screenerFilters.ts`).
- `frontend/src/layouts/AppLayout.tsx`, `frontend/src/components/AppHeader.tsx` — the shell (`max-w-7xl` column) and the hardcoded `end` NavLink behavior.
- `frontend/src/index.css` — the `@theme` tokens (hex values) the canvas colors must mirror.
- `frontend/package.json`, `frontend/vite.config.ts` — scripts (`dev`/`build`/`lint`/`typecheck`), deps, and the `/api`+`/sanctum` dev proxy; **no test runner**.
- `DESIGN.md` — colors/typography/shapes/components/accessibility and the "Chart:" component line; the "Open Design Questions" charting-library bullet.
- `ARCHITECTURE.md` — SPA routing list, Instrument Detail API section, dependency direction; `CONSTRAINTS.md` — Frontend + Public API + Indicators MUST rules.
- `docs/risks-and-open-questions.md` — the "Charting approach" blocking question.
- `alphapulse/src/components/{InteractiveChartView,InteractiveCandleChart}.tsx`, `alphapulse/src/types.ts` — intent reference only: candlesticks, volume, SMA/EMA toggles, pivot/target/stop overlay, legend. Its data, RSI/EMA/Bollinger toggles, Copilot button, "Analizar con IA", pattern checklist and track record are out of scope.
- `npm view lightweight-charts` and the published README (read-only, at planning time): latest `5.2.1`, `license = Apache-2.0`, v5 API, and an explicit requirement to keep the TradingView attribution link (the `layout.attributionLogo` chart option satisfies it).

### Existing Patterns To Follow

- API clients live in `frontend/src/lib/api.ts` as typed module objects; components never call `fetch` directly. `request` already forwards `RequestInit` (including `signal`).
- Pages own the request + `loading`/`refreshing`/`ready`/`error` machine; presentational components are dumb. Errors use `role="alert"` + a manual "Reintentar" (`messageFor` pattern in `ScreenerPage`).
- Token-only styling; cards use `bg-surface-bright` + `border-2 border-outline` + `shadow-[2px_2px_0px_#1a1a1a]`; focus is `focus:shadow-[4px_4px_0px_#ffcc00]`; mono for every number.
- `PascalCase.tsx` components, camelCase `.ts` helpers, explicit `.ts`/`.tsx` import extensions.
- oxlint enforces `react-hooks(exhaustive-deps)` and `react(set-state-in-effect)` beyond its configured rules: keep the chart effect self-contained and read changing values through refs where the effect deps are intentionally keyed.

### Current Gaps

- No `instrumentApi` client, no chart page, no `/instruments/:ticker` route, no charting dependency.
- No per-bar indicator series in the API (structural; see Decision 3).
- No stop/target anywhere in the data model (structural; see Decision 5).
- No frontend test runner or E2E harness; behavior is verified by `lint`/`build` + a live dev smoke + a manual browser checklist.

## Technical Approach

### Data contract consumed (frozen)

`GET /api/instruments/{ticker}?limit=252` → `{instrument, bars[], snapshot|null, signals[], meta}`. `bars` = `{date:'Y-m-d', open, high, low, close, volume:int}` ascending; `snapshot` = the latest row with the 15 indicator keys (`sma20`/`sma50`/`sma200`/`ema21`/`ema55`/`rsi14`/`adx`/`macd`/`macd_signal`/`macd_hist`/`bb_upper`/`bb_middle`/`bb_lower`/`rvol`, each `number|null`) plus `date`; `signals` = `{type, date, metadata}` ordered by type; `meta = {limit, bar_count, latest_bar_date}`. Unknown ticker → `404 {message}`.

### API client (`frontend/src/lib/api.ts`)

Add types `InstrumentBar`, `InstrumentSnapshot`, `InstrumentSignal`, `InstrumentDetailResponse` and:

```ts
export const instrumentApi = {
  // Anonymous; `request` forwards the AbortSignal. Ticker is path-encoded.
  async detail(
    ticker: string,
    options: { limit?: number; signal?: AbortSignal } = {},
  ): Promise<InstrumentDetailResponse>
}
```

It builds `/api/instruments/${encodeURIComponent(ticker)}` plus `?limit=` (default `CHART_BAR_LIMIT`) and calls `request`. A GET needs no CSRF cookie. `metadata` is typed as `Record<string, number> | null` (signals-detect guarantees numeric metadata) with defensive runtime guards.

### Pure helpers (`frontend/src/lib/chartData.ts`, new; no React)

- `CHART_BAR_LIMIT = 252`.
- `CHART_COLORS` — the canvas needs literal colors; each entry is exactly the `frontend/src/index.css` token hex with the token name in a comment (`gain #059669`, `loss #e63b2e`, `accent #ffcc00`, `ink #1a1a1a`, `surfaceBright #faf7f2`, `grid #d0cbc3`, `muted #4a4a4a`, `tertiary #0055ff`). No new hues.
- `toCandlestickData(bars)` → `{ time: 'YYYY-MM-DD', open, high, low, close }[]` (Lightweight Charts accepts the `YYYY-MM-DD` business-day string).
- `toVolumeData(bars)` → `{ time, value, color }[]` colored by `close >= open ? gain : loss`.
- `smaLevels(snapshot)` → `[{ price, title: 'SMA 20' | 'SMA 50' | 'SMA 200', color }]` for non-null `sma20`/`sma50`/`sma200` only (`[]` when `snapshot` is `null`).
- `signalLevels(signals)` → `[{ price, title: 'PIVOTE', color: accent }]` where only `type === 'pivot_breakout_rvol'` and a finite numeric `metadata.pivot` produce an entry; never produces stop/target.
- `hasChartData(bars)` → `bars.length > 0`.
- `insufficientHistory(bars, snapshot)` → `boolean` (true when `snapshot` is `null` or none of the three SMAs is non-null) to drive the note.

These are the deterministic surface exercised by the throwaway helper check (the repo has no test runner).

### Chart component (`frontend/src/components/chart/InteractiveChart.tsx`)

- Props: `{ ticker, bars, snapshot, signals }` (already-fetched payload; no fetching inside).
- If `!hasChartData(bars)` the parent renders the empty state instead, so this component assumes ≥1 bar.
- Effect: `createChart(container, { autoSize: true, layout: { background: { type: ColorType.Solid, color: surfaceBright }, textColor: ink, fontFamily: mono, attributionLogo: true }, grid: { vertLines/horzLines: grid }, rightPriceScale: { borderColor: ink }, timeScale: { borderColor: ink }, crosshair: { mode: CrosshairMode.Normal } })`.
- `candleSeries = chart.addSeries(CandlestickSeries, { upColor: gain, downColor: loss, borderUpColor/borderDownColor: ink, wickUpColor: gain, wickDownColor: loss })` → `setData(toCandlestickData(bars))`.
- Volume: `chart.addSeries(HistogramSeries, { priceFormat: { type: 'volume' } }, 1)` → `setData(toVolumeData(bars))`; verify the pane argument in the installed typings (fallback: overlay the histogram with `priceScaleId: ''` + `scaleMargins`).
- For each entry of `smaLevels(snapshot)` and `signalLevels(signals)`: `candleSeries.createPriceLine({ price, color, lineWidth: sma ? 1 : 2, lineStyle: LineStyle.Dashed, axisLabelVisible: true, title })`.
- `chart.timeScale().fitContent()`; cleanup `chart.remove()`.
- **Keep `attributionLogo` enabled** (default) — Apache-2.0 NOTICE requires a TradingView attribution link on the page that shows the chart.
- After teardown the component must be re-mountable (ticker change / StrictMode) without console errors.

### Page (`frontend/src/pages/InstrumentChartPage.tsx`, new) + `SignalLevelsPanel`

- Reads `const { ticker } = useParams<{ ticker: string }>()`; `null/undefined` ticker → the `/chart` guidance empty state (no request). `decodeURIComponent` if needed; the API normalizes case.
- Fetch effect keyed on the ticker with an `AbortController`, ignoring `AbortError`, plus a `retryToken`; statuses exactly as in Decision 8. Mirrors `ScreenerPage`'s ref pattern so oxlint's `exhaustive-deps` is satisfied.
- Renders: page header (ticker, company/sector/exchange, latest bar date, `EOD` chip + link back to the Screener), a mono legend/metrics strip (last bar OHLC, latest `rsi14`/`rvol`, SMA values, pivot), the "Señales activas" panel (labels via `signalLabel`, plus pivot/close/rvol for `pivot_breakout_rvol`), and the chart card.
- No `useAuth`, no redirect, no login prompt.

### Router (`frontend/src/router.tsx`)

- Add `{ path: 'instruments/:ticker', element: <InstrumentChartPage /> }`.
- Change `{ path: 'chart', element: <ChartPage /> }` to `<InstrumentChartPage />` and delete `frontend/src/pages/ChartPage.tsx`.
- `nav.ts`/`AppHeader.tsx` unchanged. Known cosmetic nit: with the hardcoded `end`, the Chart tab is not highlighted while on `/instruments/:ticker`; accepted (fixing it would require tab-activeness logic and is out of scope).

## Expected File Changes

- `frontend/package.json`, `frontend/package-lock.json` — modify; add the verified `lightweight-charts` version.
- `frontend/src/lib/chartData.ts` — create; pure mappers/constants.
- `frontend/src/lib/api.ts` — modify; instrument detail types + `instrumentApi.detail`.
- `frontend/src/pages/InstrumentChartPage.tsx` — create; the page (fetch + states + composition).
- `frontend/src/pages/ChartPage.tsx` — delete; replaced by `InstrumentChartPage` (no-ticker mode).
- `frontend/src/components/chart/InteractiveChart.tsx` — create; Lightweight Charts lifecycle.
- `frontend/src/components/chart/SignalLevelsPanel.tsx` — create; active signals / pivot text.
- `frontend/src/router.tsx` — modify; add `/instruments/:ticker`, re-point `/chart`.
- `frontend/src/components/screener/CandidateTable.tsx` — modify; wrap the ticker cell in a `Link` only.
- `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/risks-and-open-questions.md`, `DESIGN.md` (small), `PROGRESS.md`, `feature_list.json` — update (see below).
- Not changed: `frontend/src/nav.ts`, `frontend/src/components/AppHeader.tsx`, other screener components, Laravel/`routes/api.php`/controllers/models/migrations, `engine/`, `init.ps1`.

## Visual Design Impact

- UI involved: yes. Design source: `DESIGN.md` (source of truth); `alphapulse/` is intent-only.
- Screens/states affected: `/instruments/:ticker` (loading, ready, insufficient-history note, empty, not-found, error), `/chart` (no-ticker guidance), the Candidate table ticker link.
- New design artifact required: no.
- DESIGN references to honor: 2px `#1a1a1a` borders + hard `2px 2px 0 #1a1a1a` shadow (never blurred) on the chart card and panels; mono for every figure; accent `#ffcc00` for the pivot/primary affordances; `gain #059669` / `loss #e63b2e` for up/down (paired with the textual legend, never color alone); visible focus `focus:shadow-[4px_4px_0px_#ffcc00]`; content inside the existing `max-w-7xl` shell; chart canvas colors are the exact token hexes (Decision/`CHART_COLORS`).
- Feature-specific states: SMA line present vs absent, pivot line, volume pane, crosshair hover, insufficient-history note, empty vs error disambiguation, `/chart` guidance.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — add `/instruments/:ticker` (+ `/chart` no-ticker mode) to the SPA route list and add a "Chart UI" section (library choice/version, anonymous data source, latest-snapshot SMA reference lines, pivot-only signal levels, teardown).
- `CONSTRAINTS.md`: update — add a Frontend Chart MUST block: self-hosted `lightweight-charts` (Apache-2.0, keep attribution) and never the embedded widget; data only from `GET /api/instruments/{ticker}`; no client-side indicator math (SMA lines are the latest snapshot values); no stop/target drawing; remove the chart instance on unmount/ticker change.
- `docs/risks-and-open-questions.md`: update — move "Charting approach" from blocking to decided (self-hosted Lightweight Charts) and record the residual Apache-2.0 attribution requirement.
- `DESIGN.md`: update (small) — resolve the "Charting library" open question so the visual source of truth is not stale. The component line mentioning "(pivot/stop/target)" stays as ambition; the MVP pivot-only behavior is recorded here.
- `AGENTS.md`: not needed — no workflow/startup/operating-rule change; `init.ps1` is untouched.
- `docs/user-and-access-model.md`, `docs/domain-model.md`: not needed — browsing is already anonymous and the domain is unchanged.

## Implementation Plan

1. Install and verify the dependency (`npm --prefix frontend install lightweight-charts`), read the resolved version + license + `dist/typings.d.ts`, and pin it.
2. Add the instrument detail types + `instrumentApi.detail` to `frontend/src/lib/api.ts`.
3. Add `frontend/src/lib/chartData.ts` (mappers, `CHART_COLORS`, `CHART_BAR_LIMIT`).
4. Build `InteractiveChart.tsx` (create/setData/price lines/fitContent/`remove()` cleanup) and `SignalLevelsPanel.tsx`.
5. Replace `ChartPage.tsx` with `InstrumentChartPage.tsx` (fetch + state machine + composition) and update `router.tsx`.
6. Add the minimal ticker `Link` in `CandidateTable.tsx`.
7. Verify: helper check, `lint`, `build`, live dev smoke, manual browser checklist, `.\init.ps1`.
8. Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/risks-and-open-questions.md`, `DESIGN.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [x] `npm --prefix frontend install lightweight-charts`; record the resolved version + license; confirm the v5 `createChart`/`addSeries`/`createPriceLine`/`setData`/`remove` signatures in `frontend/node_modules/lightweight-charts/dist/typings.d.ts`; confirm `frontend/package.json` + lockfile record the version.
- [x] Add `InstrumentBar`/`InstrumentSnapshot`/`InstrumentSignal`/`InstrumentDetailResponse` and `instrumentApi.detail(ticker, {limit, signal})` to `frontend/src/lib/api.ts` (path-encoded ticker, `AbortSignal` forwarded).
- [x] Create `frontend/src/lib/chartData.ts` (`CHART_BAR_LIMIT`, `CHART_COLORS`, `toCandlestickData`, `toVolumeData`, `smaLevels`, `signalLevels`, `hasChartData`, `insufficientHistory`).
- [x] Create `frontend/src/components/chart/InteractiveChart.tsx` with candlesticks, volume pane, SMA/pivot price lines, `autoSize`, `attributionLogo` kept enabled, and a `chart.remove()` cleanup.
- [x] Create `frontend/src/components/chart/SignalLevelsPanel.tsx` (active signals via `signalLabel`; pivot/close/rvol text).
- [x] Create `frontend/src/pages/InstrumentChartPage.tsx` (params, fetch + abort + retry, loading/ready/insufficient/empty/not-found/error, header/legend, guidance state for the no-ticker case).
- [x] Update `frontend/src/router.tsx`; delete `frontend/src/pages/ChartPage.tsx`.
- [x] Add the ticker `Link` to `/instruments/{ticker}` in `frontend/src/components/screener/CandidateTable.tsx` (ticker cell only).
- [x] Run the throwaway pure-helper check on `chartData.ts`; then `npm --prefix frontend run lint` and `run build`.
- [x] Run the live dev smoke (seeded temp instrument + no-bar/sparse/unknown cases), then tear down and restore the DB.
- [x] Run `.\init.ps1` (exit 0, no server started, script unchanged).
- [x] Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/risks-and-open-questions.md`, `DESIGN.md`, `PROGRESS.md`, `feature_list.json`.

## Verification Plan

No persistent E2E command and no frontend test runner exist (no Vitest/Playwright/Cypress; `init.ps1` runs only `tsc -b`/oxlint/`vite build`). The closest available verification is lint/typecheck/build + a throwaway pure-helper check + a live dev smoke + a manual browser checklist; record that gap explicitly in `PROGRESS.md`.

1. **Dependency verification (before coding).**
   - `npm --prefix frontend ls lightweight-charts` → the resolved version; `node -p "require('./frontend/node_modules/lightweight-charts/package.json').version"` and `.../license` → `Apache-2.0`; confirm no unexpected transitive runtime deps. Read `dist/typings.d.ts` to confirm `addSeries(definition, options?, paneIndex?)` and `createPriceLine`.
2. **Static gate.**
   - `npm --prefix frontend run lint` → `tsc -b` + oxlint: **0 warnings, 0 errors**, exit 0.
   - `npm --prefix frontend run build` → production build succeeds, module count rises vs the current 118, `dist/` produced, exit 0. Confirm the built JS contains a `lightweight-charts` chunk and the new route string `/instruments/`.
3. **Pure-helper check (throwaway, removed after).** A temporary `node --experimental-strip-types` script importing `frontend/src/lib/chartData.ts` asserts: candle/volume mapping (date strings, integer volume, up/down color); `smaLevels` includes only non-null SMAs and is `[]` for a `null` snapshot; `signalLevels` yields exactly one pivot entry for `pivot_breakout_rvol` and **never** a stop/target, and ignores a non-numeric/absent pivot and the other 8 types; `hasChartData([])` false and `insufficientHistory` true for `{bars: [], snapshot: null}`.
4. **Live dev smoke** (temporary; torn down). Seed a temporary instrument (e.g. `ZZCHART`) with ~250 Daily Bars + a snapshot + a `pivot_breakout_rvol` signal, plus a sparse instrument (bars but `snapshot: null`) and a no-bars instrument; start `php artisan serve` (:8000) and `npm --prefix frontend run dev` (:5173), and verify through the Vite proxy:
   - `GET /api/instruments/ZZCHART?limit=252` → 200 with ascending bars, a snapshot and the signal; `GET /api/instruments/ZZNOPE` → 404 JSON; the sparse/no-bars payloads match Scenario 5.
   - `GET /chart` → 200, `GET /instruments/ZZCHART` → 200 (SPA fallback), and `/src/pages/InstrumentChartPage.tsx` + `/src/components/chart/InteractiveChart.tsx` + `/src/lib/chartData.ts` transform 200.
   - Probe `http://localhost:5173` (Vite binds `::1`), concatenate query strings (PowerShell 5.1 parses `"$base?limit"` as a variable). Kill the whole process trees, release 8000/5173, delete temp rows, and confirm the DB is back to `users=1/universes=1/instruments=503/bars=0/snapshots=0/signals=0`; remove every temporary script/log.
5. **Manual browser checklist** (no automation; acceptance scenarios 1–9): Candidate ticker link opens the chart; `/instruments/nvda` renders `NVDA`; candles + volume + the present SMA lines + the pivot line render; hover crosshair works; a ticker with no bars shows the empty panel and no canvas; a sparse ticker shows the note and no MA lines; an unknown ticker shows the not-found panel; a forced network failure shows the alert + Reintentar; navigating away/back and changing tickers leaves no orphaned canvas and no console errors; resize follows the container; DESIGN fidelity (2px borders, hard shadows, mono, focus, contrast); attribution logo/link visible.
6. **Harness.** `.\init.ps1` → exit 0 (Laravel tests + SPA lint/build + engine tests), starts no server, and the script is **not modified**. The startup script stays a non-blocking gate and must not start the Vite/Laravel dev servers; it may print manual follow-up commands after the checks pass.

## Evidence To Capture

- Resolved `lightweight-charts` version + license + the confirmed API names from `typings.d.ts`, and the `frontend/package.json`/lockfile entry.
- `npm --prefix frontend run lint` and `run build` output (0 warnings/errors; module count; `dist/`), and confirmation the only dependency change is `lightweight-charts`.
- Throwaway helper-check assertion count and result (script removed afterwards).
- Dev-smoke request results (detail 200, unknown 404, sparse/no-bars payloads, SPA/module transforms 200), teardown/port release, and the DB baseline restoration.
- Manual per-scenario pass/fail results for scenarios 1–9, stating that rendering was checked by hand (no browser automation).
- `.\init.ps1` exit status and confirmation it was unmodified and started no server.
- Scope confirmation: no Laravel/`routes/api.php`/controller/model/migration change, no API contract change, no engine change, no screener redesign, `nav.ts`/`AppHeader.tsx` untouched.

## Implementation Findings

- **Library resolved to `5.2.1` / Apache-2.0, pinned exactly.** `npm install lightweight-charts@5.2.1 --save-exact` added `lightweight-charts: "5.2.1"` plus its single runtime dependency `fancy-canvas@2.1.0` (MIT). The installed `dist/typings.d.ts` confirms `createChart(container, options?)`, `addSeries<T>(definition, options?, paneIndex?)`, `CandlestickSeries`/`HistogramSeries` exports, `createPriceLine({price, color, lineWidth, lineStyle, axisLabelVisible, title})`, `setData(data[])`, `chart.remove()`, `timeScale().fitContent()`, `ColorType.Solid`, `CrosshairMode.Normal`, `LineStyle.Dashed` and `attributionLogo: boolean` (default `true`).
- **Snapshot key count corrected: 13 indicator keys, not 15.** The spec prose said "the 15 indicator keys" while listing 14 names; the frozen `InstrumentController::snapshotPayload` actually returns **13** indicator keys plus `date` (`sma20`…`rvol`, no `ema`/`bb` extras). `InstrumentSnapshot` mirrors the real payload; the SPA reads only `sma20`/`sma50`/`sma200` (plus `rsi14`/`rvol` for the legend).
- **`react(set-state-in-effect)` forced a result-keyed state machine.** Calling `setStatus('loading')` synchronously at the top of the fetch effect produced an oxlint warning (0-warning gate). The page instead stores one settled `{key, status, payload, error}` result keyed by `ticker|retryToken`; a result only renders while its key matches the current URL ticker/retry, which also guarantees a ticker change never flashes the previous instrument. No synchronous setState remains in the effect.
- **Deviation: the chart component is `React.lazy`-loaded.** A static `import` pushed the Vite build over its 500 kB chunk warning, which is written to **stderr**; `init.ps1` runs with `$ErrorActionPreference = "Stop"` and PowerShell 5.1 turns native stderr into a terminating error, so the standard gate failed even though the build exited 0. The chart is now its own `InteractiveChart-*.js` chunk (165.29 kB) loaded via `React.lazy` + `Suspense` (a "Cargando gráfico…" fallback), keeping the main bundle at 375.24 kB and the build warning-free. Behavior is unchanged and the change stays inside `InstrumentChartPage.tsx`.
- **`insufficientHistory(bars, snapshot)` keeps the documented signature**; the `bars` argument is intentionally unused (`_bars`) because the condition is exactly "snapshot is null or none of the three SMAs is non-null" and the empty-bar case is handled before it.
- **`smaLevels` colors all three SMA lines with the `tertiary` token** (`#0055ff`) and distinguishes them by the price-line `title`/axis label plus the textual legend, so nothing depends on color alone. The pivot uses `accent` (`#ffcc00`).
- **Manual browser checklist / DESIGN fidelity gap:** no frontend test runner or E2E harness exists, so acceptance scenarios 1–9 and the pixel-level DESIGN checks were verified at the payload/HTTP/URL/transformed-module level plus the pure-helper check, not by browser automation. Canvas colors are the exact `index.css` token hexes and every returned payload was asserted; the teardown/StrictMode behavior is guaranteed by the effect cleanup (`chart.remove()`).
- **Environment notes:** Vite dev binds `localhost`/`::1` (probe `http://localhost:5173`), PowerShell 5.1 mis-parses `"$base?limit=..."` (concatenate URLs), and the Laravel serve/Vite process trees were killed and ports 8000/5173 released after the smoke.

## Validator Checklist

- [ ] Scope is respected: no API/Laravel/engine/schema change, no per-bar indicator series, no client-side indicator math, no stop/target, no watchlist/saved-screeners/filters, no embedded TradingView widget.
- [ ] Acceptance scenarios 1–9 pass; the Candidate ticker link and the `/instruments/:ticker` deep link both work anonymously.
- [ ] The chart renders price + volume + the latest-snapshot SMA reference lines, and the pivot line only from `pivot_breakout_rvol.metadata.pivot`; absent/null values draw nothing.
- [ ] `bars: []` and sparse-history payloads are handled gracefully with no chart instance and no fabricated values; unknown ticker shows the not-found state.
- [ ] The chart instance is removed on unmount/ticker change (no leaked canvas, StrictMode-safe); the library is self-hosted, pinned/recorded, and keeps its attribution.
- [ ] Styling uses only `DESIGN.md` tokens; loading/error/empty states are distinct; the canvas has an accessible name and a textual legend.
- [ ] Verification evidence is present (dependency version/license, lint/build, helper check, dev smoke, manual checklist, `init.ps1`), and the no-test-runner gap is recorded.
- [ ] `ARCHITECTURE.md`/`CONSTRAINTS.md`/`docs/risks-and-open-questions.md`/`DESIGN.md` updated; `feature_list.json`/`PROGRESS.md` updated with evidence.
- [ ] No unrelated product behavior or extra feature work was added.

## Known Implementation Risks

- **Library API drift (v4 vs v5).** `addCandlestickSeries`/`setMarkers` are v4; the installed v5 uses `addSeries(CandlestickSeries, …)` + `createSeriesMarkers`/`createPriceLine`. Verify against the installed typings before coding; do not copy v4 snippets.
- **Attribution/licensing.** Apache-2.0 plus a NOTICE requiring a TradingView link on the page: keep `layout.attributionLogo` enabled; do not hide it.
- **Canvas + React StrictMode / ticker changes.** Always `chart.remove()` in the effect cleanup and null the refs; otherwise double canvases and "object already disposed" errors appear in dev.
- **Zero-size container.** `createChart` needs a laid-out container; use `autoSize: true` plus an explicit `min-height` on the card.
- **Over-reading the SMA lines.** A horizontal line is a reference level, not a curve. Label it (`SMA 50`, …) and document that a per-bar series needs an API change.
- **Non-numeric/absent signal metadata.** Guard `metadata.pivot` with `Number.isFinite`; never emit `NaN`/`undefined` to the chart.
- **oxlint React rules.** `react-hooks(exhaustive-deps)` and `react(set-state-in-effect)` are enforced; key the fetch effect on the ticker/retry token and read changing values through refs.
- **Smoke environment gotchas (observed in prior sessions).** Vite dev binds `::1` (probe `http://localhost:5173`); PowerShell 5.1 mis-parses `"$base?limit=..."` (concatenate URLs); always kill the whole process tree and free ports 8000/5173.
- **Scope creep.** The prototype's RSI/EMA/Bollinger panes, timeframe selector, pattern/R:R card, track record, Copilot and watchlist actions must not be pulled in.

## Future Hooks (not this feature)

- **Per-bar indicator series** (SMA/EMA curves, Bollinger bands, RSI sub-pane): requires extending `instrument-detail-api` (e.g. `?include=indicators` or per-bar indicator fields) since the engine already computes a snapshot per bar — an API contract change, not an SPA decision.
- **Stop/target levels:** must first be modeled by the engine/signals pipeline and persisted in signal metadata; only then can the chart draw them without inventing values.
- **Signal date marker / event annotations:** `signals[].date` is available and could become a chart marker once marker UX is specified.
- **Watchlist integration ("Seguir"):** belongs to the `watchlist` feature and adds its own action to this page.
