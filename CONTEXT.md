# Context

Shared vocabulary for ChartScreenPlus (working product name: `AlphaPulse`). This file is a glossary only: no plans, tasks, or implementation details.

## Glossary

### Screener

The core product surface: a set of technical filters applied to a Universe of Instruments that returns a ranked list of Candidates. A "screener" is either the act of filtering or a saved filter definition (see `Saved Screener`).

### EOD (End of Day)

Daily closing data. ChartScreenPlus works exclusively with end-of-day values, never intraday. The Market Close reference is the official close of the relevant exchange session.

### Instrument (Ticker / Symbol)

A listed security identified by a ticker (e.g. `NVDA`). Carries company name, sector and exchange.

### Universe

A named, curated set of Instruments that a Screener runs against. The first real Universe is the **S&P 500**. Other universes (NASDAQ 100, Russell 2000, IBEX 35) are future scope.

### Daily Bar (OHLCV)

One trading day of an Instrument: Open, High, Low, Close, Volume. The atomic unit of stored market data.

### Indicator Snapshot

The computed technical indicators for an Instrument for a given Daily Bar: moving averages (SMA/EMA), RSI, MACD, ADX, Bollinger Bands, RVOL, etc.

### Signal

A deterministic, rule-based condition derived from Indicators (e.g. `Golden Cross`, `RSI oversold`, `RVOL > 2`). The MVP produces Signals, not geometric pattern detections.

### Golden Cross

A bullish Signal where the short-term moving average (e.g. SMA 50) crosses above the long-term moving average (SMA 200). The opposite is a **Death Cross**.

### Chartist Pattern (Patrón chartista)

A geometric price formation (Cup & Handle, Double Top, Flag, Triangle...). These are the product's long-term ambition but are **not** part of the MVP, which uses deterministic Signals first.

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

### Watchlist (Seguimiento / Radar)

A Registered User's personal list of Instruments they follow. Owned by that user.

### Ingestion Run (EOD Run)

A single execution of the data pipeline: scrape EOD quotes, store Daily Bars, compute Indicator Snapshots, recompute Signals. Can be **scheduled** (daily after Close) or **manual** (triggered from the Admin panel).

### Engine

The Python service responsible for scraping, indicator computation and signal detection.

### Copiloto IA (AI Copilot)

A natural-language refinement surface backed by an LLM. Shown in the mockup; **out of MVP scope**.

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
