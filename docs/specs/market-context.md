# Feature Implementation Spec: Market Context panel (umbrella)

## Source Features

This umbrella spec covers six features, implemented in this order. Each one ships, is verified and is accepted on its own.

| Order | `id` | `area` | `depends_on` | Summary |
| --- | --- | --- | --- | --- |
| 1 | `market-context-sectors` | backend | `ingestion-scheduler`, `indicators-compute`, `screener-precomputed` | Sector ETF universe, daily sector and breadth snapshots |
| 2 | `macro-series-fred` | backend | `ingestion-scheduler` | Daily FRED macro series (rates, curve, liquidity, leading data, policy rates) and a central bank calendar |
| 3 | `market-regime` | backend | `market-context-sectors`, `macro-series-fred` | Rule-based daily Market Regime with visible reasons |
| 4 | `market-context-ui` | frontend | `market-regime` | Public `/mercado` page, API and Admin status |
| 5 | `market-context-patterns` | frontend | `market-context-ui`, `chart-patterns-ui` | Sector and regime context on the chart and two Screener filters |
| 6 | `options-put-call` | backend | `market-context-ui` | Opt-in Cboe equity put/call ratio: sentiment card on `/mercado` and a low-weight regime component |

- `status`: planned 2026-10-22 at the user's explicit request: "creame en docs una documentacion ampliada de esta funcionalidad, solo los de viabilidad facil y creame una features o varias si es necesario pra ir implementando". Feature 6 added the same day at the user's request: "En la parte macro estudia incluir ratio put call de las opciones", then "si, apúntalo en la spec y añade la feature".
- `source`: `feature_list.json`. Product rationale: `docs/marketing/2026-10-22-funcionalidades-diferenciales.md` (idea 15).

## Goal

Give traders one glance at **the state of the market around a setup**: which sectors lead or lag, how broad the market is, where interest rates and the yield curve stand, whether central bank liquidity is growing or shrinking, what the leading data say, and what the central banks did last. A rule-based **Market Regime** (favourable / neutral / adverse) summarizes it with the reasons visible.

The value for Chartiko is the link with Chartist Patterns and Signals: a pattern shown with its sector's trend and the market regime around it. Standalone macro dashboards are a crowded market. Inside Chartiko the context is a differentiator.

Everything is **descriptive**. Nothing predicts, recommends or rates.

## Non-Goals

Excluded by the feasibility review (2026-10-22):

- **No social sentiment** (X/Twitter or any other network): the X API is expensive and restrictive, and the signal is noisy and easy to manipulate.
- **No proprietary indicators**: no Conference Board LEI, no ISM PMI, no CNN Fear & Greed, no AAII survey, no CME FedWatch probabilities. They are licensed and cannot be redistributed freely.
- **No fiscal deficits or debt figures**: annual or quarterly data that do not change the daily reading.

Also excluded:

- No non-US equity data, no intraday data, no new market data vendor beyond FRED. The only exception is the opt-in Cboe put/call ratio of Feature 6, which stays disabled in production until Cboe's terms are cleared.
- No forecasts, no "probability of recession", no targets and no buy/sell wording.
- No regime-change alerts or emails (listed under Later).
- No AI-written commentary (listed under Later).
- No paid plans: access uses the existing Visitor / Registered User split.

## Job Story

When I look at a stock or a pattern,
I want to see in seconds whether its sector and the whole market are with it or against it,
so I can judge the setup in its context with rules I can read.

## Users And Permissions

| Actor | Can |
| --- | --- |
| Visitor | Open `/mercado`: regime with reasons, sector table, breadth, rates, liquidity, central banks and leading data, all with their current values. See the context strip on the chart. Use the two new Screener filters. |
| Registered User | Everything above, plus the history charts on `/mercado` (enforced server-side, as in `premium-panels-auth-only`), plus saving the new filters in Saved Screeners and the natural-language Screener. |
| Admin | Sees the Market Context status in the Admin panel and can rerun the commands. |
| Developer/Operator | `market-context:compute`, `macro:refresh`, `market-regime:compute`. |

## Domain Decisions (shared)

1. **New terms.** Add to `CONTEXT.md` when the first feature ships:
   - **Market Context (Contexto de mercado):** the daily set of sector, breadth and macro readings shown on `/mercado`.
   - **Market Regime (Entorno de mercado):** a rule-based label (favourable / neutral / adverse) computed from Market Context components. It is descriptive, never a forecast or advice.
   - **Breadth (Amplitud):** the share of the S&P 500 Universe that meets a condition (for example, close above SMA200).
   - **Macro Series:** a public time series fetched from FRED and stored with its own dates.
   - **Put/Call Ratio (Ratio put/call):** put option volume divided by call option volume on a session. Added with Feature 6.
2. **Dates.** Every reading carries its own date. Market readings use the last session. Macro series keep the date of their last observation, which can be weeks old for monthly data, and the UI shows it. Public labels never say EOD or "end of day" (AGENTS.md rule); they use "Datos del {date}" / "Data as of {date}".
3. **Staleness.** A reading is *stale* when it is older than its frequency allows: daily over 7 calendar days, weekly over 16 days, monthly over 75 days. Stale readings are shown with a "desactualizado" / "stale" badge and **do not count** in the regime.
4. **No advice.** No Market Context text contains buy, sell, target, probability or recommendation wording. Each card has a short educational "¿Qué significa?" / "What does it mean?" text in `frontend/src/i18n/messages/*.ts`.
5. **Isolation.** A failure in any Market Context step is logged and shown in Admin, but it never fails the ingestion pipeline, the Screener refresh or the alert evaluation.
6. **Sources and attribution.** Every card names its source. Each FRED series is checked before it is enabled: public-domain government data (Treasury, Federal Reserve, BLS, Census, DOL) is fine. Series with third-party copyright stay disabled until their terms are cleared. The FRED API terms notice is shown on the page; verify its current wording when implementing.

---

## Feature 1 — `market-context-sectors`

Implementation contract, exact edge-case rules and resolved ambiguities: `docs/specs/market-context-sectors.md`.

### Scope

1. **Instrument kind.** Migration adding `instruments.kind` (`string 16`, default `stock`, values `stock`|`etf`). Existing rows stay `stock`.
2. **Market context universe.** New Universe `market-context` seeded by `MarketContextUniverseSeeder` with 12 ETFs (`kind = etf`, exchange `NYSE Arca`). The `sector` column holds the exact GICS sector name used in `database/data/sp500.csv`, so each S&P 500 stock maps to its sector ETF:

   | Ticker | Fund | `sector` (GICS, as in sp500.csv) |
   | --- | --- | --- |
   | SPY | SPDR S&P 500 ETF Trust | `null` (benchmark) |
   | XLB | Materials Select Sector SPDR Fund | Materials |
   | XLC | Communication Services Select Sector SPDR Fund | Communication Services |
   | XLE | Energy Select Sector SPDR Fund | Energy |
   | XLF | Financial Select Sector SPDR Fund | Financials |
   | XLI | Industrial Select Sector SPDR Fund | Industrials |
   | XLK | Technology Select Sector SPDR Fund | Information Technology |
   | XLP | Consumer Staples Select Sector SPDR Fund | Consumer Staples |
   | XLRE | Real Estate Select Sector SPDR Fund | Real Estate |
   | XLU | Utilities Select Sector SPDR Fund | Utilities |
   | XLV | Health Care Select Sector SPDR Fund | Health Care |
   | XLY | Consumer Discretionary Select Sector SPDR Fund | Consumer Discretionary |

3. **Ingestion.** `ingestion:pipeline` runs, after the main universe's indicators and before `alerts:evaluate`:
   - `ingestion:run --universe=market-context` (a separate Ingestion Run, visible in Admin as today);
   - `indicators:compute --universe=market-context`;
   - `market-context:compute`.

   Signals and patterns are **not** computed for this universe. Failures follow decision 5.
4. **ETFs stay out of stock features.**
   - Not in the Screener. `CandidateQuery` is universe-scoped, but `ScreenerRowBuilder` iterates every Instrument, so it must not build rows for `kind = etf` (and removes any).
   - Not addable to the Watchlist (`POST /api/watchlist` returns 422; there is no Watchlist search) and not in alerts.
   - No Company Card, fundamentals or forecast (the endpoints return 404 for `kind = etf`; the forecast state reads Signals, which ETFs never get).
   - `/instruments/XLK` stays reachable and shows the chart without those panels.
5. **Sector snapshot.** Table `market_sector_snapshots`, one row per **sector ETF** (SPY has none; its trend is `spy_trend` in item 6): `date`, `instrument_id`, `sector`, `ret_5`, `ret_20`, `ret_60`, `rs_20`, `rs_60`, `rs_rank`, `trend`, `breadth_200`, timestamps; unique `(date, instrument_id)`. Definitions for date `d`:
   - `ret_n`: % change of the close over `n` sessions.
   - `rs_n`: `ret_n(ETF) - ret_n(SPY)`, in percentage points.
   - `rs_rank`: 1 to 11 by `rs_60` descending, ties broken by ticker.
   - `trend`:
     - `uptrend` when `close > sma50 > sma200`;
     - `downtrend` when `close < sma50 < sma200`;
     - otherwise `mixed`;
     - `null` when `sma200` is null.
   - `breadth_200`: % of the sector's S&P 500 members with data on `d` whose close is above their `sma200`.
6. **Market breadth.** Table `market_breadth_snapshots`: `date`, `universe_id` (unique together), `members`, `pct_above_sma50`, `pct_above_sma200`, `new_highs`, `new_lows`, `advancers`, `decliners`, `spy_trend` (same rule as `trend`), timestamps. Definitions:
   - A new high is `high[d] >=` the max high of the previous 251 sessions; members with less history are excluded from both counts. New lows mirror it.
   - Advancers and decliners compare the close to the previous session's close; unchanged closes count in neither.
7. **Command.** `market-context:compute {--date=} {--from=}`:
   - Idempotent (upsert).
   - `--from` backfills every session from that date, so the history charts have at least one year of data on first deploy.
   - Computed in Laravel over `daily_bars` and `indicator_snapshots`, without the engine, like `screener:refresh`.

### Acceptance

- A fixture with SPY, two sector ETFs and a handful of stocks produces the exact hand-computed `ret_*`, `rs_*`, `rs_rank`, `trend`, `breadth_200` and breadth figures.
- A missing `sma200` gives a `null` trend, not a false one.
- Rerunning the command for the same date changes nothing (idempotent).
- ETFs never appear in Screener results, screener rows, the Watchlist, alerts, Company Card, fundamentals or forecast. Tests cover each.
- A pipeline run where the market-context ingestion fails still completes the main universe, the Screener refresh and the alerts, and the failure is recorded.

---

## Feature 2 — `macro-series-fred`

### Scope

1. **Opt-in configuration.** `config/macro.php` with `enabled` (`MACRO_ENABLED`, default `false`) and `fred_api_key` (`FRED_API_KEY`). The key is free, set only on the server and never committed. When disabled, every macro command is a no-op that logs once.
2. **Closed series list.** Defined in `config/macro.php`. The implementer verifies each ID, its units and its copyright note on FRED before enabling it:

   | Group | FRED id | Meaning | Frequency |
   | --- | --- | --- | --- |
   | rates | `DGS3MO`, `DGS2`, `DGS10` | US Treasury yields 3m / 2y / 10y (%) | daily |
   | curve | `T10Y2Y`, `T10Y3M` | 10y−2y and 10y−3m spreads (pp) | daily |
   | policy | `DFEDTARL`, `DFEDTARU` | Fed funds target range (%) | daily |
   | policy | `ECBDFR` | ECB deposit facility rate (%) | daily |
   | liquidity | `WALCL` | Fed total assets | weekly |
   | liquidity | `WTREGEN` | Treasury General Account | weekly |
   | liquidity | `RRPONTSYD` | Overnight reverse repo | daily |
   | leading | `ICSA` | Initial jobless claims | weekly |
   | leading | `PERMIT` | Building permits (SAAR) | monthly |
   | leading | `NFCI` | Chicago Fed National Financial Conditions Index | weekly |
   | optional | `VIXCLS` | VIX close | daily — **disabled until Cboe terms are cleared** |
   | optional | OECD composite leading indicator (US) | — | monthly — **only if a currently updated free source is found** (FRED's OECD series or the OECD API); otherwise omitted |

   Units are read from the FRED series metadata and normalized to billions of USD for the liquidity group.
3. **Storage.**
   - `macro_series`: `code` unique, `group`, `units`, `frequency`, `source`, `last_observation_date`, `last_refreshed_at`, `last_error`.
   - `macro_observations`: `macro_series_id`, `date`, `value decimal(20,6)`; unique `(macro_series_id, date)`.
   - FRED's missing values (`"."`) are skipped, never stored as 0.
4. **Command.** `macro:refresh {--series=} {--backfill}`:
   - Fetches observations since the last stored date, or the last 5 years with `--backfill`.
   - Upserts them, records per-series success or error, and throttles and retries like the existing HTTP sources.
   - Run by the pipeline before `market-regime:compute` (Feature 3), and also scheduled daily so weekly releases are picked up on days without a market session.
5. **Derived readings** (`MacroSnapshotService`, pure and testable):
   - Curve: `inverted` when `T10Y3M < 0`.
   - Change in the 10-year yield over 20 observations.
   - **Net liquidity** = `WALCL − WTREGEN − RRPONTSYD`, aligned on the weekly `WALCL` date (using the latest RRP value on or before it), plus its 4-week and 13-week % change.
   - Claims: 4-week average and its distance from the 52-week minimum.
   - Permits: 3-month % change.
   - NFCI: last value (positive means tighter than average).
6. **Central banks.**
   - Current Fed range and ECB rate come from the series.
   - The **last move** (raise / cut / hold, with its date) is the last date the value changed.
   - The **next meeting** comes from `config/central_banks.php`: FOMC and ECB monetary policy meeting dates copied by hand from the official calendars, with a test that fails when no future date is left, as a reminder to update it.
   - No market-implied probabilities.

### Acceptance

- Recorded FRED JSON fixtures (no network in tests) produce the expected stored observations and derived readings, including `"."` gaps and the weekly/daily alignment for net liquidity.
- With `MACRO_ENABLED=false` nothing calls FRED and the pipeline stays green.
- An HTTP error on one series records `last_error` for it and does not stop the other series or the pipeline.
- No API key appears in logs, exceptions or the repository.

---

## Feature 3 — `market-regime`

### Scope

1. **Components.** Each component scores `+1`, `0` or `−1` from the latest non-stale reading. The initial thresholds are named constants in one class, to be calibrated later with history:

   | Component | +1 | −1 | Otherwise |
   | --- | --- | --- | --- |
   | `breadth` | `pct_above_sma200 >= 60` | `<= 40` | 0 |
   | `index_trend` | `spy_trend = uptrend` | `downtrend` | 0 |
   | `sector_participation` | sectors in `uptrend >= 7` | `<= 3` | 0 |
   | `yield_curve` | `T10Y3M >= 0.5` | `< 0` (inverted) | 0 |
   | `financial_conditions` | `NFCI < −0.25` | `> 0` | 0 |
   | `liquidity` | net liquidity 13-week change `>= +2 %` | `<= −2 %` | 0 |
   | `volatility` (only if `VIXCLS` is enabled) | `< 18` | `> 28` | 0 |
   | `options_sentiment` (added by Feature 6, only if enabled) | percentile `>= 90` | percentile `<= 10` | 0 |

2. **Label.** Score = sum of the available components.
   - `favourable` when the score is `>= +3`; `adverse` when `<= −3`; otherwise `neutral`.
   - Fewer than 4 available components → `insufficient` (shown as "Sin datos suficientes" / "Not enough data"), never a guessed label.
3. **Storage.** Table `market_regime_snapshots`: `date` unique, `regime`, `score`, `available`, `components` (JSON: per component `score`, `value`, `as_of`, `stale`), timestamps. The `--from` backfill uses only the data available on each date (no look-ahead).
4. **Command.** `market-regime:compute {--date=} {--from=}`, run by the pipeline after `macro:refresh` and `market-context:compute`.
5. **Reasons.** The API returns the components and the SPA builds the reason sentences from i18n keys (for example "38 % de valores sobre su media de 200 sesiones", "Curva 10a–3m invertida"). The backend stores no free text.

### Acceptance

- Unit tests per component at, below and above each threshold, and for stale and missing inputs.
- The label boundaries `+3` / `−3` and the `insufficient` rule are covered.
- The backfill for a past date ignores observations published after it (fixture with a later observation).

---

## Feature 4 — `market-context-ui`

### Scope

1. **API.**
   - `GET /api/market-context`, public: the latest regime with its components; the 11 sectors (ticker, i18n sector key, `ret_5`, `ret_20`, `ret_60`, `rs_60`, `rs_rank`, `trend`, `breadth_200`); breadth; rates and curve; liquidity; central banks (current rate, last move, next meeting); leading data; and the sources with the `as_of` date per block. Cached until the next pipeline run.
   - `GET /api/market-context/history?metric=…&range=1y|3y`, **Registered Users only**, enforced server-side: a closed list of metrics (`regime_score`, `pct_above_sma200`, `dgs10`, `t10y3m`, `net_liquidity`, `icsa_4w`, `nfci`, `sector_rs_60:{ticker}`).
2. **Page `/mercado`.** A SPA route with a "Mercado" / "Market" header link. It follows `DESIGN.md`, dark mode and es/en i18n. Layout:
   1. **Regime light:** large label with icon and text (never colour alone), the score, the reasons list and "Datos del {date}".
   2. **Sector table/heatmap:** the 11 sectors sorted by `rs_rank`, a toggle for 1 week / 1 month / 3 months, a trend chip and sector breadth. Each row links to `/instruments/{ticker}`.
   3. **Cards:**
      - Breadth: % above SMA50/SMA200, new highs and lows, advancers and decliners.
      - Rates and curve: 3m / 2y / 10y yields, spread, "invertida" badge.
      - Liquidity: net liquidity with its 4-week and 13-week changes.
      - Central banks: Fed range, ECB rate, last move, next meeting.
      - Leading data: claims, permits, NFCI.

      Each card has its source, its `as_of` date, a stale badge when stale, and a "¿Qué significa?" disclosure.
   4. **History charts** (sparkline or small line chart per card) for Registered Users. Visitors see a sign-in prompt in their place, as with the other auth-only panels.
   5. Footer: "Información descriptiva, no es asesoramiento de inversión" / "Descriptive information, not investment advice" and the FRED notice.
3. **Admin.** A "Contexto de mercado" section in the existing Admin ingestion area showing:
   - the last `market-context:compute` and `market-regime:compute` runs;
   - per series: last observation date, last refresh and last error;
   - buttons to rerun each command, as for ingestion.
4. **SEO.** The route keeps the current app-route policy (`noindex`). Making a public "Estado del mercado hoy" landing indexable is a separate marketing decision, because it needs server-side or prerendered HTML.

### Acceptance

- API feature tests: public shape, history returns 401 for guests and 200 for Registered Users, unknown metric gives 422.
- SPA tests (Vitest): regime label and reasons render from fixtures in es and en; stale badge; Visitor sees the sign-in prompt instead of the history; no EOD wording; no hardcoded text.
- `.\init.ps1` green. Manual check on desktop and mobile in light and dark mode.

---

## Feature 5 — `market-context-patterns`

### Scope

1. **Chart context strip.** On `/instruments/{ticker}` for `kind = stock`:
   - Shows "Sector: {sector} ({ETF}) · {trend} · fuerza relativa {rs_rank}/11 · Entorno: {regime}", linking to `/mercado`.
   - The Chartist Pattern panel shows the same chips next to each pattern, using the latest snapshot. Pattern-date context is listed under Later.
   - Data comes from the instrument detail API (new `market_context` field), public.
2. **Screener filters.**
   - `sector_trend` (`uptrend`|`mixed`|`downtrend`) and `sector_rs_rank_max` (1–11, for example 3 = only the three strongest sectors).
   - `screener_rows` gets `sector_trend` and `sector_rs_rank` columns, filled by `screener:refresh` from the latest sector snapshot.
   - Both filters are accepted by Saved Screeners and alerts that store filters, and by the natural-language Screener's strict schema (`ai-screener-natural-language`).
   - The filters appear in the filter UI with i18n labels and the "Cómo funciona" manual entry.

### Acceptance

- Feature tests: the filters return the expected Candidates on a fixture. Unknown values are rejected. A Saved Screener round-trips the new keys. The NL schema accepts them and rejects anything else.
- SPA tests: the context strip renders for a stock, is absent for an ETF, and has no advice wording.
- The manual and both locales are updated.

---

## Feature 6 — `options-put-call`

### Why

The put/call ratio measures sentiment from what traders actually pay for, not from posts or surveys. It fills the gap left by excluding social sentiment. It is read **contrarian**: extreme fear has tended to coincide with lows and extreme complacency with highs. The **equity** ratio (stock options, mostly retail) is the useful one. The index ratio is dominated by institutional hedging and is stored for reference only.

Fixed thresholds no longer work: since 2020, same-day-expiry options and heavy retail call buying have shifted the "normal" levels. The ratio is therefore read as a **10-session average** compared with its **own percentile over the last year**.

### Scope

1. **Opt-in, off by default.** `put_call.enabled` in `config/macro.php` (`PUT_CALL_ENABLED`, default `false`), separate from `MACRO_ENABLED`. When disabled:
   - nothing is fetched;
   - the `/mercado` card is not shown;
   - the regime component is not available.

   It is enabled in production **only after written permission** from Cboe, or after switching to a licensed source that includes it. Until then it may run in development only.
2. **Source.** Cboe's public daily market statistics (`https://www.cboe.com/us/options/market_statistics/daily/`). The implementer:
   - finds the stable machine-readable response behind that page, or parses the page if there is none;
   - records the exact URL and format in this spec;
   - covers the parser with recorded fixtures (no network in tests).

   Fetched in the Python engine like the other HTTP sources, throttled and retried, behind a source interface so OCC or a licensed provider can replace it without touching storage or the UI.
3. **Storage.** Reuses `macro_series` and `macro_observations` from Feature 2, with `source = cboe` and `frequency = daily`. Three closed series codes:
   - `CBOE_PC_EQUITY`: equity put/call, the one that is used;
   - `CBOE_PC_INDEX`: index put/call, for reference;
   - `CBOE_PC_TOTAL`: total put/call, for reference.

   A missing value is skipped, never stored as 0.
4. **Command.** `put-call:refresh {--date=} {--from=}`:
   - Idempotent (upsert).
   - `--from` backfills one request per session, throttled. The free historical CSV ends in 2019, so the backfill reads the daily page for each past date. On first deploy it loads at least 13 months, enough for the one-year percentile.
   - Run by the pipeline before `market-regime:compute` and isolated like the rest (decision 5).
5. **Derived readings** (pure and testable, next to `MacroSnapshotService`):
   - `pc_equity_10`: average of the last 10 equity values.
   - `pc_equity_pct_1y`: percentile rank of today's `pc_equity_10` among the `pc_equity_10` values of the last 252 sessions, from 0 to 100. It is `null` with fewer than 200 values, never a guessed number.
   - Reading band: `fear` when the percentile is `>= 80`, `complacency` when it is `<= 20`, otherwise `normal`.
6. **Regime component.** `options_sentiment` in the Feature 3 table:
   - It only scores at the extremes: `+1` at percentile `>= 90` (extreme fear, contrarian) and `−1` at `<= 10` (extreme complacency). It is `0` the rest of the time, so its weight is low by design.
   - Stale (decision 3) or `null` means not available.
   - Thresholds are named constants, uncalibrated, like the other components.
7. **UI.** A "Sentimiento en opciones" / "Options sentiment" card on `/mercado`:
   - the 10-session equity ratio, its 1-year percentile and the band chip (fear / normal / complacency), with source "Cboe" and the date;
   - index and total ratios as secondary values;
   - a "¿Qué significa?" text that explains the contrarian reading without advice wording;
   - for Registered Users, history in the existing history endpoint with metric `pc_equity_10`.
8. **Admin.** The series appear in the Feature 4 Admin section with their last date, last refresh and last error, and a button to rerun the command.

### Acceptance

- Recorded Cboe fixtures (no network) parse to the expected equity, index and total values. A changed or empty page records `last_error` and stores nothing.
- `pc_equity_10`, the percentile (including the fewer-than-200-values `null`) and the bands match hand-computed values on a fixture.
- `options_sentiment` scores at, below and above 90 and 10, and is unavailable when stale or disabled.
- With `PUT_CALL_ENABLED=false` nothing calls Cboe, the card is absent, the regime ignores the component and the pipeline stays green.
- Vitest: card in es and en, band chip, no advice wording, no hardcoded text.
- `.\init.ps1` green.

---

## Later (not planned as features)

- In-app and email alerts when the Market Regime changes.
- Context at the pattern's detection date (historical join) and pattern statistics by regime (ideas 3 and 4 of the differentiators doc).
- A public, indexable "Estado del mercado hoy" landing and a weekly email or Telegram post generated from it.
- AI-written weekly summary of the Market Context (facts only).
- More markets (Europe/IBEX sector proxies) once a licensed data source exists.

## Risks And Open Questions

- **Market data licence:** the ETFs come through the same unofficial source as the stocks (see `docs/risks-and-open-questions.md`). Publishing them carries the same pending licence issue.
- **FRED terms:** per-series copyright must be checked, VIX is disabled by default, and the API terms notice must be shown.
- **OECD CLI availability** through a free, current source is unverified.
- **Central bank calendar** is maintained by hand once a year; the test in Feature 2 makes a missing update fail loudly.
- **Cboe terms (Feature 6):** the daily statistics page is governed by Cboe's website terms, and Cboe's data agreements limit use to personal or internal purposes. Public display needs written permission or a licensed source; ask Cboe and OCC, and ask the market data providers being quoted whether they include it.
- **Put/call source coverage:** Cboe's figures cover only volume traded on Cboe exchanges. OCC publishes a put/call report on cleared volume across all US options exchanges and is the fallback if its terms are clearer.
- **Put/call scraping fragility:** the page format can change without notice; the fixtures and `last_error` make a break visible in Admin.
- **Thresholds** in Feature 3 are initial values without calibration. A later calibration, similar to `chart-patterns-calibration`, should check them against history before they are used in marketing.

## Documentation Updates

- `CONTEXT.md`: the four terms in decision 1.
- `AGENTS.md`: MVP Scope Guard paragraph (added with this plan).
- `docs/risks-and-open-questions.md`: FRED terms, VIX, OECD availability.
- "Cómo funciona" manual: a short Market Context section with Feature 4 and the filters with Feature 5.
- Legal/privacy: no personal data is involved. Check that the legal notice lists FRED as a data source, and Cboe once Feature 6 is enabled.
