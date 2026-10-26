# Feature Implementation Spec: Technical indicators expansion (umbrella)

## Source Features

This umbrella spec covers seven features, implemented in this order. Each ships, is verified and is accepted on its own. Before implementing one, its session may write a detailed contract in `docs/specs/<id>.md`; that file wins where it is more precise, and this umbrella must then be corrected.

| Order | `id` | `area` | `depends_on` | Summary |
| --- | --- | --- | --- | --- |
| 1 | `indicators-expansion-engine` | engine | `indicators-compute` | Engine math for ATR (14), +DI/-DI (14) and Stochastic (14,3,3); extended `Snapshot` payload; hand-calculated tests; ADX/Bollinger untouched |
| 2 | `indicators-expansion-storage` | backend | `indicators-expansion-engine` | Migration and persistence of the new snapshot columns, history series endpoint for the chart, backfill procedure, forecast and Company Card non-regression |
| 3 | `signals-expansion` | engine | `indicators-expansion-engine`, `signals-detect`, `alerts-engine` | Six new deterministic Signals (ADX, Bollinger, Stochastic), vocabulary in Screener, Saved Screeners, Watchlist alerts, labels es/en; AI Screener schema unchanged |
| 4 | `screener-technical-filters-api` | screener | `indicators-expansion-storage`, `signals-expansion` | Precomputed `screener_rows` columns, five new range filters and sorts, Candidate payload fields, Saved Screeners |
| 5 | `screener-technical-filters-ui` | frontend | `screener-technical-filters-api` | URL state, column filters, optional "Indicators" columns, chips, user guide |
| 6 | `chart-indicator-overlays` | chart | `indicators-expansion-storage`, `signals-expansion` | Indicator picker with remembered choice, Bollinger Bands overlay, band price lines for the band Signals |
| 7 | `chart-indicator-panels` | chart | `chart-indicator-overlays` | ADX (+DI/-DI), ATR and Stochastic sub-panes under the price pane |

Features 4-5 and 6-7 are independent branches once Feature 3 is accepted.

- `status`: planned 2026-10-26 at the user's explicit request ("si vete preparandolo"). The user proposed exactly two groups: (1) make the indicators already computed and stored but unused (ADX 14 and Bollinger 20,2) visible and useful in Signals, Screener filters and chart; (2) add ATR (14) and the Stochastic oscillator. Nothing else is in scope.
- `source`: `feature_list.json`. Scope change recorded in `AGENTS.md` (MVP Scope Guard, 2026-10-26).

## Goal

Make four indicators useful to a Chartiko user: ADX and Bollinger Bands (already in `indicator_snapshots`, shown nowhere except the Company Card context and the forecast input) plus the new ATR and Stochastic. After this work a user can (a) see new descriptive Signals, (b) filter and sort the Candidate table by trend strength, band position, band width, volatility and stochastic reading, and (c) toggle the Bollinger Bands, ADX, ATR and Stochastic on the chart. Everything is deterministic, daily, and applies to every Screener universe (S&P 500, Nasdaq-100, IBEX 35, DAX) with no per-market code.

## Non-Goals

- No other indicators. **Deferred, to be asked separately:** OBV, Ichimoku, VWAP, SuperTrend, CCI, Williams %R, Fibonacci levels, Keltner, Donchian, Parabolic SAR. VWAP also needs intraday data and is out for that reason.
- No intraday data, backtesting, advice, targets, stops or "buy/sell" wording. Signals describe a condition; they never say to trade.
- No change to the natural-language Screener schema, sort enum or prompt (decision D9).
- No change to the forecast inputs or results, and no change to the AI Company Card context (D10).
- No SMA/EMA/RSI/MACD series on the chart (see Open Question 1: the chart does not draw them today).
- No new data source, no new dependency in the engine (pure standard library, like `core.py`), no new frontend charting library.
- No per-user indicator parameters. Periods are fixed constants, like every existing indicator.
- No public "EOD/end-of-day" wording outside Admin.

## Job Story

When I look for setups beyond moving averages and RSI,
I want to filter for trending, squeezing, volatile or stochastic-extreme stocks and see the same indicators on the chart,
so I can confirm a Candidate visually and compare it with others in the same table.

## Users And Permissions

| Actor | Can |
| --- | --- |
| Visitor | Use every new Screener filter, sort and column, see the new Signals and use the chart toggles, exactly like the existing technical filters (public Screener). |
| Registered User | The same, plus the new filters inside Saved Screeners (and their alerts) and the new Signal types in Watchlist alerts. |
| Admin | The same. No admin screen changes; the existing pipeline runs the new computation. |

No new ability or role. Authorization is unchanged and server-side.

## Domain Decisions (shared)

**D1. Fixed parameters and names.** ATR 14 (Wilder), +DI/-DI 14 (Wilder, same smoothing as ADX), Stochastic 14,3,3 (slow: raw %K over 14 sessions, %K = SMA(3) of raw %K, %D = SMA(3) of %K). ADX 14 and Bollinger 20/2 keep their existing formulas and stored values byte for byte. New snapshot fields: `atr14`, `plus_di`, `minus_di`, `stoch_k`, `stoch_d` (`decimal(12,4)` nullable, like the others). `plus_di`/`minus_di` are stored because the chart panel and the Signal metadata need them and they cost nothing (ADX already computes them).

**D2. Formulas (engine `core.py`, `None` for missing history, never a partial window).**
- True range `TR[i] = max(H[i]-L[i], |H[i]-C[i-1]|, |L[i]-C[i-1]|)` for `i >= 1` (bar 0 has no TR, exactly as `adx` does today).
- `atr(highs, lows, closes, 14)`: first value at index 14 = mean of `TR[1..14]`; then `ATR[i] = (ATR[i-1]*13 + TR[i]) / 14`. `None` before index 14 (needs 15 bars). A flat series gives 0, not an error.
- `plus_di`/`minus_di`: with the Wilder sums already built in `adx` (smoothed TR, +DM, -DM), `+DI = 100*smoothed(+DM)/smoothed(TR)`, `-DI` likewise; first value at index 14; a zero smoothed TR gives 0. Refactor `adx` to expose them through a shared helper; the ADX output must not change (regression test over the existing fixtures).
- `stochastic(highs, lows, closes, 14, 3, 3)` returns `(k, d)`: `rawK[i] = 100*(C[i]-LL)/(HH-LL)` with `HH/LL` the highest high / lowest low of the 14 sessions ending at `i`, first at index 13. If `HH == LL` the raw value is **50.0** (neutral; a gap-free `None` is required because the next SMA needs contiguous input; documented and tested). `%K = SMA(rawK, 3)` first at index 15; `%D = SMA(%K, 3)` first at index 17. Values stay inside 0..100.
- Derived values (never stored in `indicator_snapshots`): `%B = (close - bb_lower)/(bb_upper - bb_lower)` (`None` when the band width is 0, so a flat window has no %B); `bandwidth = (bb_upper - bb_lower)/bb_middle * 100` (percent; `None` if `bb_middle` is 0 or any input is `None`); `atr_percent = atr14/close * 100`.

**D3. New Signals (six; vocabulary extends the fixed list, rules in `engine/app/signals/rules.py`; boundary rules as today: strict crosses, inclusive thresholds, `None` never fires).**

| Type | Condition on the as-of bar | Metadata (reference levels) |
| --- | --- | --- |
| `adx_trend_strength` | `adx` crosses above 25: previous `adx <= 25` and current `adx > 25` | `adx`, `plus_di`, `minus_di` (direction is read from the DI pair, not a separate type) |
| `bollinger_squeeze` | `bandwidth` today is `<=` the minimum bandwidth of the previous 119 sessions (a 120-session low); needs 139 bars | `bandwidth`, `lookback_min` |
| `bollinger_close_above_upper` | close crosses above the upper band: previous `close <= bb_upper` and current `close > bb_upper` | `close`, `bb_upper` |
| `bollinger_close_below_lower` | close crosses below the lower band: previous `close >= bb_lower` and current `close < bb_lower` | `close`, `bb_lower` |
| `stoch_bullish_cross` | `%K` crosses above `%D` (previous `%K <= %D`, current `%K > %D`) while the previous `%K <= 20` | `stoch_k`, `stoch_d` |
| `stoch_bearish_cross` | `%K` crosses below `%D` (previous `%K >= %D`, current `%K < %D`) while the previous `%K >= 80` | `stoch_k`, `stoch_d` |

Reasons: crosses give a once-per-event reading and avoid permanent states (an ADX above 25 stays above for weeks); the oversold/overbought zone is already covered for RSI, so Stochastic only signals the turn out of the zone; squeeze uses a percentile-like lookback of about six months. Thresholds (25, 20/80, 120 sessions) are named constants, uncalibrated initial values, recorded in Risks. Deferred: band-walk, squeeze breakout direction, ADX falling, ATR-based signals. ATR has **no** Signal: it is a magnitude, shown as a filter, a column and a panel.

**D4. Storage.** Additive nullable columns only. `indicator_snapshots`: `atr14`, `plus_di`, `minus_di`, `stoch_k`, `stoch_d`. `screener_rows`: `adx` (`decimal(12,4)`), `bb_pctb` and `bb_width` and `atr_pct` (`double`, derived, bound with the 17-significant-digit `exact()` helper used for `change_percent`), `stoch_k` (`decimal(12,4)`), each with a single-column index like `rsi14`. `down()` drops them.

**D5. Backfill and runtime.** `ComputeIndicators` already recomputes and upserts every bar of every instrument on each pipeline run, so the new columns fill for the full history by simply running the normal commands after the migration; there is no special migration job. Procedure: deploy, `php artisan migrate`, then `indicators:compute --universe=<slug>` for each universe, `signals:detect --universe=<slug>`, `screener:refresh`. The implementer measures local time for the 518-Instrument set (about 2 years, 260k bars) before and after and records it in `PROGRESS.md`; if the per-row `updateOrCreate` makes the one-off run unreasonable, replace it by chunked `upsert` keyed on `(instrument_id, date)` (behavior-identical, idempotent). Engine cost grows by a few O(n) passes per instrument (negligible). Until the backfill finishes, new fields are `null`, filters match nothing on them and the UI shows "-" (no error state).

**D6. Chart history source.** The chart gets series from a new public endpoint `GET /api/instruments/{ticker}/indicators?limit=252` (limit clamped 1..2000 silently, like `show`), returning `{ticker, series: [{date, bb_upper, bb_middle, bb_lower, adx, plus_di, minus_di, atr14, stoch_k, stoch_d}], meta}` ascending by date, taken from the stored snapshots of the newest `limit` dates; warm-up dates carry `null`. The SPA must not recompute indicators (the chart component states this contract today). `show` is unchanged except that its latest `snapshot` object gains the five new fields (additive). The endpoint is fetched lazily, only when at least one indicator is switched on.

**D7. Screener filters, columns and sorts.** Five inclusive range filters, all `min`/`max`, null never matches, inverted range is `422` like the existing pairs: `adx_min`/`adx_max` (0..100), `bb_pctb_min`/`bb_pctb_max` (-1..2; %B is 0 on the lower band, 1 on the upper, so it can be outside), `bb_width_min`/`bb_width_max` (0..1000, bandwidth in percent), `atr_pct_min`/`atr_pct_max` (0..100, ATR as percent of the close), `stoch_k_min`/`stoch_k_max` (0..100). Sorts (added to `COLUMN_SORTS`, not to `SORTS`): `adx_asc|desc`, `bb_pctb_asc|desc`, `bb_width_asc|desc`, `atr_pct_asc|desc`, `stoch_k_asc|desc`; nulls last both directions, ticker ascending tie-break, as today. The Candidate payload gains `adx`, `bb_pctb`, `bb_width`, `atr_pct`, `stoch_k` (floats or `null`, additive). Saved Screeners accept the ten keys, optional on create and always present on read (the `COLUMN_KEYS` pattern); older definitions load unchanged.

**D8. Screener UI.** The default table does not change. A "Indicators" columns toggle (remembered in `localStorage`, default off, wrapped in try/catch) appends four optional columns: ADX, %B, ATR %, Stoch %K (bandwidth is filter-only, via the chips and the column filter popover of %B). Any active indicator criterion in the URL forces the four columns visible for that view so a filter is never invisible. Each optional column has the same heading sort and range filter control as the existing numeric columns (`screenerColumns.ts`), chips use the existing summary helpers, and `Clear filters` removes them while keeping the sort. The six new Signals appear in the existing Signals filter with es/en labels.

**D9. AI natural-language Screener.** Unchanged. `ScreenerFilterParser::SIGNAL_TYPES` becomes the full list of 15; the AI schema uses a new `AI_SIGNAL_TYPES` constant holding the original 9, so `ScreenerQuerySchema`, the prompt and the cache keys are untouched and the new signals/filters answer "not supported" as new filters do today. Extending the AI vocabulary is Open Question 3.

**D10. Forecast and Company Card non-regression.** `ForecastStateBuilder::indicators()` and `CompanyCardContextBuilder::INDICATORS` use explicit field lists, so the new columns do not leak in. Feature 2 adds tests asserting the forecast state payload and the Company Card context are identical before and after the migration for a seeded Instrument, and that existing forecast/card tests pass unmodified.

**D11. Alerts.** Watchlist alerts may watch any of the six new Signal types (`AlertController::SIGNAL_TYPES` extended, es/en email labels in `lang/{en,es}/alert_email.php`; none selected by default). Saved-Screener alerts use the new filters through `CandidateQuery::fromSaved` with no extra code. No alert fires at rollout because no existing alert can reference a new type. The evaluator, schedule and digest are unchanged.

**D12. Chart presentation.** A compact "Indicators" control row above the chart (four toggles, keyboard operable, `aria-pressed`) with the choice remembered in `localStorage` (`chartiko.chart.indicators`, default all off so the default chart is unchanged). Bollinger Bands: three line series on the price pane (upper and lower solid thin, middle dashed), theme-aware colors from `CHART_THEME_COLORS`; the two band Signals also draw a price line at the crossed band value like the pivot level. Panels (Feature 7): lightweight-charts panes below the volume pane, ADX pane with ADX, +DI, -DI and a dashed line at 25; ATR pane with one line; Stochastic pane with %K, %D and dashed lines at 20 and 80 (axis fixed 0..100). The canvas stays non-navigable, so the "Active signals" text panel lists the six Signals with their metadata (ADX/DI, bandwidth, band, %K/%D) for screen readers. Chart height grows by 120 px per active pane; the page must not overflow at 390 px.

**D13. Wording and docs.** User-facing strings are es/en (`frontend/src/i18n/messages/*.ts`, `lang/*`), the guide `frontend/src/i18n/guide/{es,en}.ts` gets entries for the four indicators and six Signals (text only; new signal illustrations in `how-it-works` are deferred), no EOD wording, no advice wording ("squeeze" is described as "narrow bands", not as a forecast of a move). `DESIGN.md` documents the picker and the panes.

**D14. Rollback.** All schema changes are additive and reversible (`migrate:rollback`). Code revert alone leaves rows of the six new Signal types in `signals`; after reverting, run `signals:detect --universe=<slug>` for every universe (it replaces each instrument's set) and `screener:refresh`. Saved Screeners that stored the new keys keep them in JSON; `fromSaved` ignores unknown keys. No data outside the new columns is touched, so a revert needs no restore.

## Feature 1 — `indicators-expansion-engine`

### Scope
`atr`, a DI helper shared with `adx`, and `stochastic` in `engine/app/indicators/core.py`; constants and wiring in `snapshots.py`; five optional fields on `Snapshot` in `engine/app/models.py`; update the module docstring periods line. No Laravel change.

### Acceptance
- Hand-calculated fixtures (the convention in `engine/tests/test_indicators.py`): a constant-range rising series (`high = close+1`, `low = close-1`, `close = 10+i`) gives `TR = 2` so `atr14 = 2.0` from index 14 and `None` for indices 0-13; a flat series gives ATR 0 and DI 0; a strict uptrend gives `plus_di > minus_di` and `adx` unchanged vs. today; a second small series is worked out by hand in the test comment for the Wilder recursion (one step after the seed).
- Stochastic: rising series with `high = low = close` gives raw 100, `%K = 100` from index 15 and `%D = 100` from index 17; falling gives 0; flat gives 50.0 and no division error; first-defined indices are exactly 13/15/17 (the first two are `None` for `%K`/`%D`); output always within 0..100.
- A short series nulls only unavailable fields (extend the existing short-series and constant-fixture tests); the real NVDA fixture fills every new field on the last bar.
- `POST /indicators/compute` returns the five new keys per snapshot (`null` when unavailable); every previously returned field is unchanged for all existing fixtures (assert equality against stored expectations).
- `ruff` clean, no dependency added, engine tests green via `.\init.ps1`.

## Feature 2 — `indicators-expansion-storage`

### Scope
Migration adding the five `indicator_snapshots` columns (the `screener_rows` columns of D4 belong to Feature 4). `IndicatorSnapshot` `Fillable` + casts, `IndicatorSnapshotFactory`, `ComputeIndicators::SNAPSHOT_COLUMNS`, `InstrumentController::snapshotPayload` (additive) and the new `indicators` action/route with its payload (D6), `frontend/src/lib/api.ts` types for both. Backfill procedure documented in `deploy/README.md` (a numbered item) and measured (D5).

### Acceptance
- Migration up/down on SQLite and PostgreSQL (CI `test-pgsql`); re-running `indicators:compute` is idempotent and fills the new columns with the engine values (use `Http::fake`); a missing/invalid value stores `null`.
- `GET /api/instruments/{ticker}/indicators`: ascending dates, at most `limit` rows, warm-up `null`, 404 for an unknown ticker with the existing `instrument_not_found` message, clamped limit, works for an instrument of any universe and for an ETF, no auth required (same as `show`).
- `show` returns the old snapshot keys unchanged plus the five new ones; existing instrument tests only gain assertions.
- Forecast and Company Card non-regression tests of D10 pass.
- The measured recompute time for the full local set is recorded in `PROGRESS.md`.

## Feature 3 — `signals-expansion`

### Scope
Six rules appended to `RULES` in `engine/app/signals/rules.py` (squeeze may recompute the bandwidth series from bars through `core.bollinger`, or `detect.py` may pass the snapshot list; keep every existing rule's behavior and signature working), named constants, engine tests. Laravel vocabulary: `ScreenerFilterParser::SIGNAL_TYPES` (15) and `AI_SIGNAL_TYPES` (9, used by `ScreenerQuerySchema`), `SavedScreenerController`, `AlertController`, `SignalFactory`, `lang/{en,es}/alert_email.php`. SPA: `api.ts` union, `screenerFilters.ts` known types, `signals.*` messages es/en, `SignalLevelsPanel` metrics. Update `CONSTRAINTS.md` (vocabulary line) and `ARCHITECTURE.md` (rule list).

### Acceptance
- For each rule: fires on a hand-built series, does not fire at the boundary (strict crosses, inclusive `>= 80`/`<= 20` previous-bar zone, `adx` exactly 25 does not cross), does not fire and returns `None` when any needed value is `None` (including fewer than 139 bars for squeeze).
- `detect_signals` over the existing fixtures still emits the same nine types it emitted before, with identical metadata; each type appears at most once.
- Laravel: filter `?signal=` accepts the six new types, rejects unknown ones with `422`; Saved Screeners and Watchlist alerts accept them; `ScreenerQuerySchema` still lists exactly the original nine (test) and the prompt text is byte-identical.
- Labels exist in es and en; the Signals filter shows them; the signals panel shows each new signal's metadata.

## Feature 4 — `screener-technical-filters-api`

### Scope
Migration for the `screener_rows` columns (D4); `ScreenerRow` model; `ScreenerRowBuilder::row()` fills them from the latest snapshot and bar (derived values per D2, `null` propagation); `ScreenerFilterParser` (NUMERIC_BOUNDS, RANGE_PAIRS, COLUMN_SORTS), `CandidateQuery` (filters, SORTS map, `fromSaved`, payload), `ScreenerController`/`SavedScreenerController` (column keys), docs (`CONSTRAINTS.md` filter contract, `docs/specs/saved-screeners.md` note).

### Acceptance
- Each filter: inclusive bounds, null excluded, inverted range `422` on the min key, bounds out of range `422`; combined with AND and with the existing filters, universe and pagination.
- Each sort orders the full result, nulls last both ways, ticker tie-break; the six original sorts and the default `rvol_desc` are unchanged; the AI sort enum is unchanged (test).
- `screener:refresh` fills the columns: %B `null` for a zero-width band, `atr_pct` `null` for a zero close, values equal the formulas of D2 on a seeded Instrument; no row appears or disappears versus before (row count identical).
- Candidate payload gains the five fields; the old keys and types are unchanged; Saved Screeners round-trip the new keys and old stored definitions still load; Market Context and universe authorization behavior unchanged.
- Query plan check on the 518-row set is not required (table is small); the single-column indexes exist.

## Feature 5 — `screener-technical-filters-ui`

### Scope
`frontend/src/lib/screenerFilters.ts` (URL parse/patch/key, summaries, sort labels), `screenerColumns.ts` (four optional columns), `CandidateTable.tsx`, `ScreenerColumnFilters.tsx`, `ScreenerPage.tsx` (Indicators toggle with `localStorage`), `api.ts`, es/en messages, user guide entries for ADX, Bollinger %B and bandwidth, ATR and Stochastic and for the new Signals (`frontend/src/i18n/guide`), `DESIGN.md` (toggle and optional columns).

### Acceptance
- Default Screener identical to today (nine columns, no new requests parameters); the toggle adds exactly four columns; a URL with an indicator criterion shows them; reload and share restore criteria, sort and toggle where applicable.
- Each column's sort toggles and exposes direction to assistive technology; range filters validate and show chips; `Clear filters` clears the ten keys and keeps the sort.
- `null` values render as "-"; numbers use the locale formatters; no horizontal page overflow at 390 px (the table scrolls inside its wrapper as today).
- Vitest for URL helpers, summaries, optional columns and the toggle (including a throwing `localStorage`); lint, typecheck, build; es/en complete; guide updated.

## Feature 6 — `chart-indicator-overlays`

### Scope
`InstrumentChartPage.tsx` fetches the D6 series lazily (hook next to `useInstrumentMarketContext`), a new indicator picker component, persistence, Bollinger Bands series in `InteractiveChart.tsx`, `chartData.ts` helpers (`toBandData`, band-signal levels from the Signal metadata) and `CHART_THEME_COLORS` entries, `DESIGN.md`, es/en messages, guide chart section.

### Acceptance
- Default chart (no choice stored) is pixel-equivalent to today and makes no `indicators` request; enabling Bollinger requests once, draws three bands aligned to the candles, hides them on toggle off, and survives theme switch, language switch and ticker change without leaking canvases (`chart.remove()` still in cleanup).
- Warm-up `null` points are omitted, not drawn as zeros; an Instrument with fewer than 20 bars shows an inline "not enough history" note via the existing `insufficientHistory` helper pattern and no error.
- `bollinger_close_above_upper`/`below_lower` draw one dashed price line at the crossed band; no other new price lines.
- Failed series request shows a localized inline error with retry; the chart keeps working without the overlay. Keyboard toggles, 390 px and 1440 px checked in light and dark.

## Feature 7 — `chart-indicator-panels`

### Scope
ADX, ATR and Stochastic panes in `InteractiveChart.tsx` (v5 pane index after the volume pane), reference lines (25; 20 and 80), height growth per active pane, picker entries, tests and docs as in Feature 6.

### Acceptance
- Each toggle adds/removes its pane with correct series (ADX/+DI/-DI; ATR; %K/%D), stochastic axis fixed 0..100, ADX reference at 25; panes share the time scale and crosshair with the price pane.
- Values at the last bar match the `snapshot` of `show` (Vitest with a fixed payload, plus a manual check against the Candidate row).
- Chart height is `420 + 120 * panes` px and the page does not overflow at 390 px; all panes off returns to the single-pane layout; no regression of volume, pattern markers or price lines.

## Repository Research (inspected 2026-10-26)

### Files Inspected
- `engine/app/indicators/{core,snapshots}.py`, `engine/tests/test_indicators.py` — pure-stdlib functions returning aligned lists with `None` for missing history; ADX/Bollinger already computed and stored; hand-calculated fixtures and a closed-form monotonic fixture.
- `engine/app/signals/{detect,rules}.py`, `engine/app/models.py` — `RULES` registry, rule signature `(previous, current, bars, index)`, strict-cross convention; `Snapshot` model.
- `app/Models/IndicatorSnapshot.php`, `app/Console/Commands/ComputeIndicators.php`, migration `2026_09_29_100005_create_indicator_snapshots_table.php` — explicit column lists in three places (migration, `Fillable`/casts, `SNAPSHOT_COLUMNS`).
- `app/Services/Screener/{ScreenerRowBuilder,CandidateQuery,ScreenerFilterParser}.php`, migrations of `screener_rows` — precomputed row, `NUMERIC_BOUNDS`, `RANGE_PAIRS`, `COLUMN_SORTS` vs `SORTS`, fixed sort map, `exact()` for doubles; `docs/specs/screener-table-column-controls.md` is the template.
- `app/Services/Screener/Ai/ScreenerQuerySchema.php`, `ScreenerQueryPrompt.php` — the schema derives the signal enum from `ScreenerFilterParser::SIGNAL_TYPES` (hence D9) and the sort enum from `SORTS`.
- `app/Http/Controllers/{InstrumentController,AlertController,SavedScreenerController}.php`, `app/Services/Alerts/AlertEvaluator.php`, `lang/*/alert_email.php`, `database/factories/SignalFactory.php` — the signal vocabulary is duplicated in five PHP places plus the SPA.
- `app/Services/Forecast/ForecastStateBuilder.php`, `app/Services/CompanyCard/CompanyCardContextBuilder.php` — explicit indicator lists, so new columns do not reach the forecast or the card.
- `frontend/src/components/chart/{InteractiveChart,SignalLevelsPanel}.tsx`, `frontend/src/lib/{chartData,screenerColumns,screenerFilters,api}.ts`, `frontend/src/pages/InstrumentChartPage.tsx`, `frontend/src/i18n/guide/en.ts` — the chart draws candles, a volume pane, latest-value SMA/pivot/pattern price lines and markers only; the page payload carries only the latest snapshot.
- `docs/specs/multi-market-universes.md`, `market-context.md` (format), `specs/screener-filter-expansion/` (overlap, see Open Questions), `AGENTS.md`, `CONTEXT.md`, `docs/domain-model.md`, `docs/risks-and-open-questions.md`.

### Existing Patterns To Follow
- Additive migrations, nullable columns, `Fillable`/casts, `Http::fake` for engine calls, `exact()` for derived doubles, universe-agnostic code.
- Closed vocabularies are shared contracts: engine `RULES`, parser constants, controllers, factory, SPA unions, `lang` files; add to all of them in Feature 3.
- Chart components are presentational and never compute indicators; `lightweight-charts` 5.2.1 (panes by index) is already used for the volume pane.

### Current Gaps
- There is no historical indicator series API and the chart draws no indicator series; SMA/EMA/RSI/MACD are not drawn either (only latest SMA lines). This is why Feature 2 adds the series endpoint.
- No persistent browser E2E runner; use focused Laravel/Vitest/pytest coverage and the manual checks listed above.
- Not inspected: PostgreSQL behavior of the migration (CI), real production recompute time.

## Durable Documentation Impact

- `AGENTS.md`: updated now (scope guard).
- `CONTEXT.md`: Indicator Snapshot and Signal updated now.
- `docs/domain-model.md`: updated now.
- `docs/risks-and-open-questions.md`: updated now.
- `ARCHITECTURE.md`: Feature 2 (series endpoint, columns), Feature 3 (rule list), Feature 4 (row columns).
- `CONSTRAINTS.md`: Feature 3 (signal vocabulary line, now 15 with AI keeping 9) and Feature 4 (filter and sort contract).
- `DESIGN.md`: Features 5-7 (optional columns toggle, indicator picker, panes, band colors).
- `deploy/README.md`: Feature 2 (migrate and backfill item, rollback).
- `docs/specs/saved-screeners.md`: Feature 4 note; AI screener spec unchanged.

## Risks

- **R1 Uncalibrated thresholds.** 25, 20/80 and the 120-session squeeze lookback are initial textbook values. Record the production count per Signal after the first run; calibration is a later task, like Market Regime.
- **R2 Backfill window.** Until the post-migration recompute finishes, new fields are `null`; run it right after deploy outside the daily window and verify counts (instruments with a non-null `adx`, `atr14`, `stoch_k`).
- **R3 Vocabulary drift.** Six places must agree on the 15 types; Feature 3 adds a test that compares the engine registry with the Laravel constants.
- **R4 European volume/gaps.** ATR/ADX use gap-inclusive true range; European data has single-venue volume but price indicators are unaffected.
- **R5 Table width.** Four optional columns make the table wider; mitigated by default-off and the existing scroll wrapper.
- **R6 Performance.** Five more indexed columns on a 518-row table are negligible; the recompute cost is measured in Feature 2.
- **R7 Interpretation.** Descriptive wording only; a squeeze or a stochastic cross is not a prediction.

## Open Questions (owner, priority order)

1. **Chart scope.** The chart does not draw SMA/EMA/RSI/MACD series today (only latest SMA lines). Do you also want those as overlays/panels? The Feature 2 endpoint can serve them at low cost, but it is outside the two groups you named; default here: no.
2. **Defaults.** Confirm Stochastic 14,3,3 (slow), ATR 14 and the thresholds ADX 25, stochastic 20/80, squeeze at a 120-session low. Default: as written.
3. **AI Screener.** Should the natural-language Screener learn the new Signals and filters? Default: no (schema, prompt and cache unchanged); doing it is a separate feature touching the strict schema.
4. **Default visibility.** Indicator picker and the Indicators columns start off. Do you prefer Bollinger on by default? Default: off, chart unchanged.
5. **Deferred indicators.** OBV, Ichimoku, VWAP (needs intraday, out), SuperTrend, CCI, Williams %R, Fibonacci: confirm they stay deferred.
6. **Overlap with `specs/screener-filter-expansion/`.** Its R3 lists ATR and volatility as future technical filters; this umbrella supersedes the ATR part (filter on ATR % of price). The other R3 items (SMA20/SMA50 comparisons, distance to highs/lows, beta, candlesticks) remain there. Decide whether that roadmap should reference this spec.

## Validator Checklist (all features)

- Existing indicator values, nine original Signals, original filters, sorts, default ranking, AI schema, forecast results and Company Card context are unchanged (tests, not assertion edits).
- Formulas match D2 on independent recomputation; warm-up indices match; no partial values.
- The vocabulary is identical across engine, Laravel and SPA; the AI schema still lists nine.
- New filters/sorts/payload fields are additive; Saved Screeners old and new load; authorization unchanged.
- Default chart and default Screener table are unchanged for a user who touches nothing; storage failures never break a page.
- es/en complete, no EOD wording, no advice wording, DESIGN.md followed, no overflow at 390 px.
- Backfill procedure run and recorded, rollback documented, evidence in `feature_list.json`, `.\init.ps1` passes.
