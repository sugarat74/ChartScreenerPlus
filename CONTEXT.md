# Context

Shared vocabulary for Chartiko (repository directory `ChartScreenPlus`, former working name `AlphaPulse`). This file is a glossary only: no plans, tasks, or implementation details.

## Glossary

### Screener

The core product surface: a set of technical filters applied to a Universe of Instruments that returns a ranked list of Candidates. A "screener" is either the act of filtering or a saved filter definition (see `Saved Screener`).

### EOD (End of Day)

Daily closing data. Chartiko works exclusively with end-of-day values, never intraday. The Market Close reference is the official close of the relevant exchange session. EOD labels appear only in Admin, not in public UI.

### Instrument (Ticker / Symbol)

A listed security identified by a ticker (e.g. `NVDA`). Carries company name, sector, optional GICS sub-industry, optional headquarters country and exchange. An Instrument is a **stock** (a listed company, the default) or an **ETF** (an exchange-traded fund of the Market Context universe: SPY and the sector ETFs). ETFs have a chart but never appear in the Screener, the Watchlist, alerts, the Company Card, Fundamentals or the forecast.

### Listing Market (Mercado de cotización)

The exchange group whose trading calendar, session close, currency and data-source symbol rules apply to an Instrument: `US` (NYSE/Nasdaq, USD), `XMAD` (Bolsa de Madrid, EUR) or `XETR` (Xetra, EUR). European tickers carry the suffix of their market (`SAN.MC`, `SAP.DE`). Not to be confused with Market Context. Planned, `docs/specs/multi-market-universes.md`.

### Universe

A named, curated set of Instruments that a Screener runs against. The first real Universe is the **S&P 500**, the default. Planned since 2026-10-26 (`docs/specs/multi-market-universes.md`): **Nasdaq-100**, **IBEX 35** and **DAX**. A Screener universe has one Listing Market and one currency; an Instrument in two universes (S&P 500 and Nasdaq-100) is a single Instrument with one set of bars. Russell 2000 and the full Nasdaq Composite remain future scope. The `market-context` Universe (SPY and the 11 SPDR sector ETFs) is ingested daily for Market Context; it is not a Screener universe.

### Daily Bar (OHLCV)

One trading day of an Instrument: Open, High, Low, Close, Volume. The atomic unit of stored market data.

### Indicator Snapshot

The computed technical indicators for an Instrument for a given Daily Bar: moving averages (SMA/EMA), RSI, MACD, ADX, Bollinger Bands, RVOL, etc.

### Signal

A deterministic, rule-based condition derived from Indicators (e.g. `Golden Cross`, `RSI oversold`, `RVOL > 2`) on the latest Daily Bar. Geometric formations are Chartist Patterns, a separate concept.

### Golden Cross

A bullish Signal where the short-term moving average (e.g. SMA 50) crosses above the long-term moving average (SMA 200). The opposite is a **Death Cross**.

### Chartist Pattern (Patrón chartista)

A geometric price formation detected by explicit deterministic rules over swing points. In scope since 2026-10-10: Double Top, Double Bottom, Cup with Handle and Bull Flag; since 2026-10-21 (chart-patterns-extended) also Triple Top, Triple Bottom, Inverse Cup with Handle, Bear Flag, Bullish Pennant and Bearish Pennant (triangles, wedges, rectangles and head-and-shoulders are future scope). Each type has a fixed **direction**: bullish types confirm with a close above the Breakout level, bearish ones with a close below it. A pattern is **forming** (geometry complete, no breakout yet) or **confirmed** (a close beyond its **Breakout level** within the last 10 sessions). The Breakout level (neckline or pivot) is the only price line a pattern exposes; Chartiko never derives targets.

### RVOL (Relative Volume)

Current Volume divided by an average Volume baseline (e.g. 50-day). Used to detect unusual participation, especially on breakouts.

### Pivot

A reference price level (resistance or support) that a Breakout must clear to be considered valid.

### Breakout

A close above a Pivot with confirming Volume/RVOL.

### Candidate (Shortlist)

An Instrument that currently satisfies a Screener's filters. Shown as a ranked list.

### Saved Screener

A Registered User's persisted filter definition, which can be re-applied at any time. Owned by that user.

### Alert (Alerta)

A Registered User's rule evaluated after each daily data update: **new Candidates** entering one of their Saved Screeners, chosen **Signal types appearing** on their Watchlist Instruments, or chosen **Chartist Pattern types appearing or being confirmed** (breakout) on their Watchlist Instruments. The first evaluation only records a baseline; later ones notify additions. Never real-time.

### Notification

The in-app message an Alert produces when it finds something new (instruments and reasons). Read in Chartiko; kept 90 days. Email delivery is a separate, opt-in digest.

### Watchlist (Seguimiento / Radar)

A Registered User's personal list of Instruments they follow. Owned by that user.

### Ingestion Run (EOD Run)

A single execution of the data pipeline: scrape EOD quotes, store Daily Bars, compute Indicator Snapshots, recompute Signals. Can be **scheduled** (daily after Close) or **manual** (triggered from the Admin panel).

### Engine

The Python service responsible for scraping, indicator computation and signal detection.

### Copiloto IA (AI Copilot)

A natural-language refinement surface backed by an LLM. Shown in the mockup; **out of MVP scope**. Distinct from the Company Card.

### Company Card (Ficha IA)

A sheet for one Instrument with two clearly separated parts: the key financial metrics, verified figures computed by Chartiko from the company's SEC filings (see Fundamentals), and an AI-written part generated on demand by an external LLM: business description, sector and industry, and a qualitative three-month outlook ("Previsión a 3 meses") grounded on those figures and on macro and sector context. The AI part is shared per Instrument and language, refreshed after a freshness window or a new filing, labelled as AI-generated and unverified, and never contains price targets or buy/sell advice. It is not the Copilot.

### Fundamentals (Fundamentales SEC)

Company Fundamentals: per-Instrument financial inputs (revenue, operating income, D&A, EPS, debt, cash, equity, shares, dividends) computed by Chartiko from the company's own 10-K/10-Q filings in SEC EDGAR, with the period they refer to (trailing twelve months, fiscal year or balance-sheet date). Ratios that use a price (P/E, market cap, dividend yield) combine them with our latest close at read time. Refreshed by a daily command separate from the EOD pipeline; a newer filing not yet in SEC data is shown as pending. Not used by the Screener or alerts.

### Market Context (Contexto de mercado)

The daily set of sector, Breadth and macro readings around the market, each with its own date, shown on `/mercado` (public once the owner turns it on; Admins preview it before). Descriptive only: never a forecast, a target or advice.

### Market Regime (Entorno de mercado)

A rule-based daily label (favourable / neutral / adverse) computed from up to seven Market Context components (S&P 500 Breadth, SPY trend, sector participation, yield curve, financial conditions, net liquidity, volatility), each stored with its score, value, date and staleness so the reasons stay visible. With fewer than four usable components the label is **insufficient** ("Sin datos suficientes" / "Not enough data"), never a guess. The thresholds are fixed, uncalibrated initial values; a past date uses only data published by then. It is descriptive, never a forecast or advice.

### Breadth (Amplitud)

The share of the S&P 500 Universe that meets a condition on a session (for example, close above its SMA200), plus counts such as new highs/lows and advancers/decliners. A **sector Breadth** applies the same condition to the stocks of one sector.

### Macro Series

A public time series from the closed list in `config/macro.php`, fetched from FRED and stored as published with its own dates (Treasury yields, policy rates, liquidity, leading data). Curve spreads (10y−2y, 10y−3m) and net liquidity are computed by Chartiko from those series, not fetched. Cboe series (put/call ratio) will join the same store later with `source = cboe`.

### Central Bank Calendar

The Fed (FOMC) and ECB monetary policy meeting dates, copied by hand from the official calendars into `config/central_banks.php`. It gives each bank's last and next meeting; Chartiko never infers a "hold" or a probability from it.

### Admin (Operator)

The role that controls and supervises Ingestion Runs and the Instrument universe through the Admin panel.

### Registered User

An authenticated person who can save Screeners and maintain a Watchlist. Distinct from a Visitor.

## Rejected / Ambiguous Terms

### Scraping

Prefer `Ingestion Run` or `EOD Run` when talking about the overall process. "Scraping" refers only to the data-acquisition step, not the whole pipeline.

### Plan / Licencia (PRO, etc.)

The mockup shows a "PRO TRADER" license. There are no paid plans in the MVP; the plan UI is decorative and should not drive requirements. Use `Registered User` for access modeling.

### Análisis en paralelo

Ambiguous. Use `Screener` running against a whole `Universe`, not "parallel analysis of symbols".

### Portfolio / Cartera

The mockup shows observed capital and P&L. These are not product concepts in the MVP and must not be treated as domain entities.
