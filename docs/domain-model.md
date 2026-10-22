# Domain Model

## Core Concepts

- **Instrument (Ticker):** a listed security (`NVDA`), with company, sector, exchange and a **kind**: `stock` (default) or `etf` (Market Context ETFs; chart only, never in stock features).
- **Universe:** a named set of Instruments a Screener runs against. First real Universe: S&P 500. The `market-context` Universe holds SPY and the 11 SPDR sector ETFs and is not a Screener universe.
- **Daily Bar (OHLCV):** one trading day for one Instrument. Stored per Instrument and date.
- **Indicator Snapshot:** computed indicators for an Instrument on a given Daily Bar (SMA/EMA, RSI, MACD, ADX, Bollinger, RVOL...).
- **Signal:** deterministic condition derived from Indicators (e.g. Golden Cross, Breakout with RVOL). Belongs to an Instrument and a Daily Bar.
- **Chart Pattern:** a geometric formation (double top/bottom, cup with handle, bull flag) active on an Instrument's latest Daily Bar, with key points, a status and one breakout level.
- **Screener (definition):** a named set of technical filters over a Universe, optionally saved by a Registered User.
- **Screener Result (Candidate list):** the ranked Instruments returned when a Screener is applied.
- **Watchlist Entry:** a follow relationship between a Registered User and an Instrument.
- **Ingestion Run (EOD Run):** one execution of the pipeline (scrape -> store Daily Bars -> compute Snapshots -> recompute Signals).
- **Alert:** a Registered User's rule (new Candidates in a Saved Screener, chosen Signal types appearing on Watchlist Instruments, or chosen Chartist Pattern types appearing or being confirmed on Watchlist Instruments), evaluated once per daily data update.
- **Notification:** what an Alert produces when it finds additions; owned by the user, kept 90 days.
- **Company Card (Ficha IA):** the AI-generated, unverified part of the company sheet for one Instrument (sector/industry, description, qualitative 3-month outlook grounded on Company Fundamentals; no figures of its own since `company-card-v2`). Shared system data per Instrument, language and schema version; no owner; remembers the SEC filing it was grounded on.
- **Company Fundamentals:** verified financial inputs for one Instrument computed from its SEC 10-K/10-Q filings (EDGAR companyfacts), with provenance and the latest/pending filing; one row per Instrument, rebuilt by `fundamentals:refresh`. Metrics that need a price are derived at read time from our latest close. Each attempted generation leaves a Company Card Generation log row (requesting account, status, token counts; no text), kept 90 days.
- **Sector Snapshot:** one sector ETF's readings on one session: returns over 5/20/60 sessions, relative strength vs SPY (20/60), rank by 60-session relative strength, trend (uptrend / downtrend / mixed) and sector Breadth (% of the sector's S&P 500 stocks above their SMA200). Derived, rebuilt by `market-context:compute`.
- **Breadth Snapshot:** the S&P 500 Universe's Breadth on one session: members with data, % above SMA50/SMA200, new 52-week highs/lows, advancers/decliners and SPY's trend. Derived, rebuilt by `market-context:compute`.
- **User:** Visitor (anonymous), Registered User, or Admin.

## Relationships

- A **Universe** has many **Instruments**; an **Instrument** can belong to several Universes.
- An **Instrument** has many **Daily Bars** (one per trading date) and many **Indicator Snapshots** (one per Daily Bar).
- A **Signal** is derived per Instrument and Daily Bar.
- A **Screener** belongs to a **Universe** and references zero or one owner (Registered User) when saved.
- A **Registered User** owns many **Saved Screeners**, many **Watchlist Entries**, up to 10 **Alerts** and their **Notifications**; deleting a Saved Screener deletes its Alert.
- An **Ingestion Run** processes a whole **Universe** and produces Daily Bars, Snapshots and Signals.
- An **Instrument** has many **Company Cards**, at most one per language and schema version.
- An **Instrument** has at most one **Company Fundamentals** row; Instruments of one company (GOOG/GOOGL) share the same CIK and figures.
- A sector **ETF** has one **Sector Snapshot** per session; it maps to the S&P 500 stocks of the same GICS sector. A **Universe** (the main one) has one **Breadth Snapshot** per session.

## States and Lifecycles

**Ingestion Run**
`queued -> running -> completed` or `failed`, plus `partial` when some Instruments failed but the run finished. A `failed` or `partial` run can be re-run by the Admin.

**Saved Screener**
`draft` (being edited, not persisted) -> `saved` (persisted, re-appliable) -> `deleted`. A saved Screener can be applied (`applied`) without leaving `saved`.

**Watchlist Entry**
`active` -> `removed`. No soft-delete requirement in the MVP.

**Signal**
Recomputed on every Ingestion Run. A Signal is not edited by users; it is derived and can appear or disappear between runs.

**Chart Pattern**
`forming -> confirmed`, recomputed on every run like Signals; it disappears when invalidated or 10 sessions after its breakout.

**Company Card**
`absent -> fresh -> stale -> fresh`. Generated on a Registered User's request; *fresh* while younger than the freshness window (7 days) and made with the current model and prompt version; a *stale* card is still shown until a successful regeneration replaces it (a failed one keeps it). A newer SEC filing in Company Fundamentals also makes it stale.

**Company Fundamentals**
`absent -> ok | partial | no_cik | no_data | error`, refreshed daily; companyfacts is re-read only when a new 10-K/10-Q is listed, while a filing is *pending* (listed but not yet in companyfacts) or on `--force`; a failure keeps the last good figures and records the error.

## Important Scenarios

- New EOD day: an Ingestion Run updates Daily Bars and recomputes Snapshots and Signals; Candidates therefore change daily.
- Trader applies filters, gets a shortlist, opens one Candidate and inspects its chart and Signal levels.
- Registered User saves the current filter set as a Saved Screener and re-applies it later.
- Admin sees a run in `partial` state and triggers a re-run for the failed Instruments.

## Edge Cases

- A new Instrument added to a Universe has too little history to compute a 200-day indicator: it must be flagged, not silently misreported.
- A trading day with no new bar for an Instrument (halt, holiday): the latest Snapshot stays valid and the date is shown.
- A Signal that was valid on the previous run may no longer be valid; results are date-stamped.
- An Instrument removed from a Universe should stop producing new Candidates but its stored history remains.
