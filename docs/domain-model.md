# Domain Model

## Core Concepts

- **Instrument (Ticker):** a listed security (`NVDA`), with company, sector and exchange.
- **Universe:** a named set of Instruments a Screener runs against. First real Universe: S&P 500.
- **Daily Bar (OHLCV):** one trading day for one Instrument. Stored per Instrument and date.
- **Indicator Snapshot:** computed indicators for an Instrument on a given Daily Bar (SMA/EMA, RSI, MACD, ADX, Bollinger, RVOL...).
- **Signal:** deterministic condition derived from Indicators (e.g. Golden Cross, Breakout with RVOL). Belongs to an Instrument and a Daily Bar.
- **Screener (definition):** a named set of technical filters over a Universe, optionally saved by a Registered User.
- **Screener Result (Candidate list):** the ranked Instruments returned when a Screener is applied.
- **Watchlist Entry:** a follow relationship between a Registered User and an Instrument.
- **Ingestion Run (EOD Run):** one execution of the pipeline (scrape -> store Daily Bars -> compute Snapshots -> recompute Signals).
- **User:** Visitor (anonymous), Registered User, or Admin.

## Relationships

- A **Universe** has many **Instruments**; an **Instrument** can belong to several Universes.
- An **Instrument** has many **Daily Bars** (one per trading date) and many **Indicator Snapshots** (one per Daily Bar).
- A **Signal** is derived per Instrument and Daily Bar.
- A **Screener** belongs to a **Universe** and references zero or one owner (Registered User) when saved.
- A **Registered User** owns many **Saved Screeners** and many **Watchlist Entries**.
- An **Ingestion Run** processes a whole **Universe** and produces Daily Bars, Snapshots and Signals.

## States and Lifecycles

**Ingestion Run**
`queued -> running -> completed` or `failed`, plus `partial` when some Instruments failed but the run finished. A `failed` or `partial` run can be re-run by the Admin.

**Saved Screener**
`draft` (being edited, not persisted) -> `saved` (persisted, re-appliable) -> `deleted`. A saved Screener can be applied (`applied`) without leaving `saved`.

**Watchlist Entry**
`active` -> `removed`. No soft-delete requirement in the MVP.

**Signal**
Recomputed on every Ingestion Run. A Signal is not edited by users; it is derived and can appear or disappear between runs.

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
