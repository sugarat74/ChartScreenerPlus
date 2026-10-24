# Feature Implementation Spec: Compare stocks

## Source Feature

- `id`: `stock-comparison`; `area`: trader UI; `status`: `not_started`.
- `depends_on`: `screener-api`, `chart-interactive` (accepted).
- Source: `feature_list.json`; owner request 2026-10-24. The owner selected **technical indicators and price history** for the first version.

## Goal and job story

When a Visitor has found several stocks, they can compare two to four active stock Instruments in one shareable view: a common-base price chart and a compact table of latest price and technical values. They can open any compared Instrument's existing chart for detail. The view uses Chartiko's stored daily data; it makes no prediction or recommendation.

## Non-goals

- No fundamentals, SEC figures, AI Company Card, forecast, ranking score, winner label, recommendation, portfolio return or backtesting.
- No intraday data, new market feed, ingestion/indicator calculation, server-side comparison snapshot or database table.
- No ETF/Market Context comparison, new Universe onboarding, saved comparisons or user-specific ownership. Market Context access rules remain unchanged.
- No new Screener filter, sort or Candidate ranking rule.

## Users and permissions

- Visitor, Registered User and Admin: the same read-only comparison of active `kind=stock` Instruments. No account is required, just as for the public Instrument chart.
- A public search endpoint must apply the stock/active constraints server-side. Comparison uses only the existing public Instrument detail endpoint, and excludes `kind=etf` even if a URL is edited by hand.

## Acceptance scenarios

1. **Select and share.** Given two to four active stocks, when a Visitor selects them via the Compare page or enters from a Candidate/Instrument chart, then the URL contains their canonical ordered tickers and range; reloading or sharing the URL restores the same selection without a session. Duplicate tickers collapse; removal and reorder update the URL. A fifth stock is rejected with a localized limit message.
2. **Search.** Given a ticker or company fragment, when the Visitor searches, then only active stock Instruments are suggested, each with ticker and company, up to 20 in a stable order. An ETF, inactive Instrument and unknown query never appear. Empty, malformed and overlong queries are handled within a fixed request budget.
3. **Comparable history.** Given stocks with partly different bar dates, when range `1m`, `3m` or `1y` is chosen, then the chart uses only exact dates shared by all selected stocks, ending at their latest common date and taking at most 21, 63 or 252 common sessions respectively. Each line starts at 100 on the first shared date: `100 × close(date) / close(first shared date)`. Do not forward-fill, interpolate or compare raw currency prices as returns. A nonpositive base, fewer than two shared dates or no bars yields a localized insufficient-history state while usable metadata remains visible.
4. **Latest technical values.** Given an Instrument detail response, when the table renders, then it shows company, sector, latest close and its date, one-session price change from the last two bars if possible, RSI(14), RVOL, SMA50 and SMA200 with the **snapshot date**. Null indicators show a dash; a snapshot older/newer than the latest bar remains visibly dated, never silently presented as same-session data. The chart labels its first shared date as the 100-point base. Sector follows the existing es/en mapping; numeric formatting follows the active locale.
5. **Errors and exclusion.** Given a manually edited URL containing an invalid ticker, an ETF or a request failure, when the page loads, then it shows a per-symbol error/removal action without stale values from a previous selection; valid symbols remain usable. Browser navigation, range changes and rapid symbol changes cannot render an out-of-date response.
6. **Responsive/accessibility.** At 390 px and desktop widths, ticker selection, common-base chart, as-of dates, technical table and links to existing Instrument charts remain usable by keyboard. Each series is identified by a visible ticker label and accessible text, not by color alone. Spanish and English labels/states are localized; no public EOD wording appears.

## Repository research

- `routes/api.php`, `app/Http/Controllers/InstrumentController.php`: public `GET /api/instruments/{ticker}` returns up to 2,000 ordered Daily Bars, latest Indicator Snapshot, Signals and metadata; default 252 bars. It does not return a historical indicator series or a stock-search catalog.
- `app/Models/Instrument.php`, `app/Models/Universe.php`: `kind=stock|etf`, `active`, and Universe membership already exist. Search can query active stocks without a new table.
- `frontend/src/lib/api.ts`: `instrumentApi.detail(ticker, {limit,signal})` already sends public requests and abort signals. Reuse its frozen response; add a small search client/type only.
- `frontend/src/pages/InstrumentChartPage.tsx`, `frontend/src/components/chart/InteractiveChart.tsx`, `frontend/src/lib/chartData.ts`: chart loading/error conventions; `lightweight-charts` is locally installed and must retain its attribution logo. The current canvas is for one Instrument and should not be modified to masquerade as a multi-series comparison.
- `frontend/src/pages/ScreenerPage.tsx`, `frontend/src/components/screener/CandidateTable.tsx`, `frontend/src/nav.ts`, `frontend/src/router.tsx`: anonymous Screener, Candidate links and shared navigation/router. Add a discoverable Compare entry and links with one preselected ticker.
- `frontend/src/i18n/messages/{es,en}.ts`, `frontend/src/lib/companyClassification.ts`, `DESIGN.md`: typed bilingual copy, sector labels, neo-brutalist tokens and mobile overflow convention.
- `frontend/package.json`, `init.ps1`: Vitest component/unit tests and the non-blocking full gate exist; no persistent browser E2E command exists.

## Data and technical approach

1. Add public, throttled `GET /api/instruments/search?q=` **before** `GET /api/instruments/{ticker}`. Query only active `stock` Instruments; normalize/validate a maximum 80-character `q`, escape SQL LIKE wildcards, limit to 20, order exact ticker then ticker prefix then company match and ticker. Return only `{instruments:[{ticker,company,sector}]}`. A blank query returns an empty array. Never fetch bars or private account data for suggestions. Use the same stable es/en validation/error conventions as other public APIs.
2. Add `/comparar` inside `AppLayout`, publicly reachable via `NAV_ITEMS`. Use `?tickers=AAPL,MSFT&range=3m` as canonical URL state (uppercase, unique, 2–4 for chart, allow one while choosing a second; allowed ranges `1m|3m|1y`, default `3m`). Reject/ignore malformed entries safely without issuing requests for them; preserve a visible notice. Selection comes from the search endpoint. The Screener Candidate and stock Instrument chart offer a Compare link that opens with that ticker preselected. Do not display the stock-only action on an ETF chart.
3. Fetch each selected stock via `instrumentApi.detail` with `limit=252`, concurrently with `AbortController` cleanup and a selection/range request key so late responses cannot replace current content. Bound concurrency at four. Verify returned ticker, `active` and `kind=stock`; treat missing `kind` as stock for the existing backward-compatible detail contract. Keep per-symbol loading/failure/insufficient states and a manual retry; do not fail the whole view because one request fails.
4. Put date intersection and normalized-point calculation in a pure `frontend/src/lib/stockComparison.ts` helper. Use ascending, unique `YYYY-MM-DD` dates and finite positive closes; base and endpoint are the same shared dates for every series. For a range, use the last N common dates up to the latest common date (not a rolling count per stock). Values are percentages indexed to 100, not a simulated investable return. If fewer than two common dates, do not draw misleading lines; display the reason. Preserve raw close and date separately for the technical table.
5. Add a presentational line-chart component, loaded only on the Compare page. Reuse `lightweight-charts`, theme tokens and `attributionLogo: true`; use four distinct token colors and explicit ticker labels, plus screen-reader text/table for values. Release chart instances and resize observers on unmount, theme/locale change and StrictMode remount. Keep the latest snapshot values separate from historical price lines; never invent an indicator series from one snapshot.
6. Show a mobile-friendly technical comparison (rows per metric and columns per ticker with horizontal scrolling if needed, or stacked cards at narrow widths). Dates appear by each field group: latest bar date and latest snapshot date. Display null values and stale/mismatched dates honestly. Link each stock to `/instruments/{ticker}`. No EOD label outside Admin.

## Expected file changes

- `app/Http/Controllers/InstrumentSearchController.php`, `routes/api.php`: public bounded stock lookup.
- `tests/Feature/InstrumentSearchTest.php`: search, auth independence, stock/active exclusion, validation and limit/order.
- `frontend/src/lib/api.ts`, new `frontend/src/lib/stockComparison.ts`: search type/client, URL/date/normalization helpers.
- `frontend/src/pages/StockComparisonPage.tsx`, new chart component under `frontend/src/components/chart/`: state, technical table and price lines.
- `frontend/src/nav.ts`, `frontend/src/router.tsx`, `frontend/src/components/screener/CandidateTable.tsx`, `frontend/src/pages/InstrumentChartPage.tsx`: route and entry points.
- `frontend/src/i18n/messages/{es,en}.ts` and focused Vitest files: translations and regression coverage.
- `feature_list.json`, `PROGRESS.md`, and durable docs below: implementation evidence and contract changes.

## Visual design impact

- UI involved: yes. `DESIGN.md` and the current Screener/Instrument chart are the sources of truth; no new image asset is needed.
- Use compact data-first cards/table, tabular right-aligned numeric values, high-contrast series identifiers and a horizontally usable mobile layout. Cover empty/one selected/loading/ready/partial error/no shared history states, both themes and both languages.

## Durable documentation impact

- `ARCHITECTURE.md`: update when implemented, to document the public search boundary and client-only comparison calculation/chart.
- `CONSTRAINTS.md`: update only if implementation establishes a durable new rule; existing EOD/no client indicator-computation/chart attribution rules already apply.
- `AGENTS.md`: no change; startup, scope and verification workflow do not change.
- `CONTEXT.md`, `docs/domain-model.md`: no new persisted entity; keep Instrument/Daily Bar/Indicator Snapshot definitions unchanged.
- `docs/user-and-access-model.md`: update public Compare/search access; no owned records.
- `docs/risks-and-open-questions.md`: update only if implementation discovers a new material data or rendering risk; existing market-data licensing risk applies.

## Implementation plan and tasks

1. Add and test the bounded public Instrument search API.
2. Add pure URL normalization and shared-date/price-index helpers with adversarial fixtures (missing dates, zero close, duplicates, staleness).
3. Build the Compare page, request lifecycle and price chart, then link it from navigation, Candidates and stock charts.
4. Localize all copy, verify responsive/accessibility states and update durable evidence.

- [ ] Public stock search with deterministic results, throttle and Laravel feature tests.
- [ ] Canonical URL state, 2–4 selection and pure comparison math with Vitest coverage.
- [ ] Lazy chart, latest technical table, per-stock errors and both entry points.
- [ ] es/en, mobile/desktop, keyboard and theme checks; docs/evidence.

## Verification plan and evidence

- `php artisan test --filter=InstrumentSearchTest`: guest access, matching/order/limit, blank/invalid input, escaped wildcard, inactive/ETF exclusion.
- `npm --prefix frontend run test -- StockComparison`: URL normalization, date intersection, indexed prices, missing values and async race/error states; component behavior in es/en, keyboard labels and stock-only links.
- `npm --prefix frontend run lint`, `npm --prefix frontend run build`, then `.\init.ps1` (non-blocking Laravel, SPA and engine gate; it must not start dev servers). No persistent browser E2E command exists, so add focused API/component tests and manually smoke a fixture with 390 px/desktop, light/dark, keyboard, deep-link reload and rapid selection changes.
- Record exact test counts/exit codes, a sample pair's common first/last dates and indexed values, the unknown/ETF cases, and visual/accessibility observations in `PROGRESS.md`/`feature_list.json`. On deployment, smoke public search, a shared URL and the compare page without a session; no external feed is needed.

## Validator checklist

- [ ] Public read-only search excludes inactive stocks and ETFs; no auth or new data feed was introduced.
- [ ] Every price series uses the same exact dates and 100-point base; missing/stale data and null indicators are visible without guesses.
- [ ] Latest indicator dates are not conflated with price chart dates; no historical indicators are synthesized in the SPA.
- [ ] Two to four stocks, canonical URL, entry links, rapid changes and per-symbol failures work in es/en and at 390 px/desktop.
- [ ] Chart attribution, keyboard access, theme colors and all existing Screener/Instrument behavior remain intact.
- [ ] Required tests, `init.ps1`, manual smoke and durable evidence passed; `feature_list.json` is `passing` until an independent validator accepts it.
