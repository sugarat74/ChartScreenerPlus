# Context

Shared vocabulary for Chartiko (repository directory `ChartScreenPlus`, former working name `AlphaPulse`). This file is a glossary only: no plans, tasks, or implementation details.

## Glossary

### Screener

The core product surface: a set of technical filters applied to a Universe of Instruments that returns a ranked list of Candidates. A "screener" is either the act of filtering or a saved filter definition (see `Saved Screener`).

### EOD (End of Day)

Daily closing data. Chartiko works exclusively with end-of-day values, never intraday. The Market Close reference is the official close of the relevant exchange session. EOD labels appear only in Admin, not in public UI.

### Instrument (Ticker / Symbol)

A listed security identified by a ticker (e.g. `NVDA`). Carries company name, sector and exchange.

### Universe

A named, curated set of Instruments that a Screener runs against. The first real Universe is the **S&P 500**. Other universes (NASDAQ 100, Russell 2000, IBEX 35) are future scope.

### Daily Bar (OHLCV)

One trading day of an Instrument: Open, High, Low, Close, Volume. The atomic unit of stored market data.

### Indicator Snapshot

The computed technical indicators for an Instrument for a given Daily Bar: moving averages (SMA/EMA), RSI, MACD, ADX, Bollinger Bands, RVOL, etc.

### Signal

A deterministic, rule-based condition derived from Indicators (e.g. `Golden Cross`, `RSI oversold`, `RVOL > 2`) on the latest Daily Bar. Geometric formations are Chartist Patterns, a separate concept.

### Golden Cross

A bullish Signal where the short-term moving average (e.g. SMA 50) crosses above the long-term moving average (SMA 200). The opposite is a **Death Cross**.

### Chartist Pattern (Patrón chartista)

A geometric price formation detected by explicit deterministic rules over swing points. In scope since 2026-10-10: Double Top, Double Bottom, Cup with Handle and Bull Flag (triangles, head-and-shoulders, wedges and bear flags are future scope). A pattern is **forming** (geometry complete, no breakout yet) or **confirmed** (a close beyond its **Breakout level** within the last 10 sessions). The Breakout level (neckline or pivot) is the only price line a pattern exposes; Chartiko never derives targets.

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

A Registered User's rule evaluated after each daily data update: **new Candidates** entering one of their Saved Screeners, or chosen **Signal types appearing** on their Watchlist Instruments. The first evaluation only records a baseline; later ones notify additions. Never real-time.

### Notification

The in-app message an Alert produces when it finds something new (instruments and reasons). Read in Chartiko; kept 90 days. Email delivery is a separate, opt-in digest.

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
