# Build Brief

## Problem

Retail traders who use technical analysis must manually inspect hundreds of stocks to find actionable setups (moving-average crosses, volume spikes, breakouts). This is slow, inconsistent and impossible to scale by hand, and it happens after market close when decisions for the next session are prepared.

## Current Workaround / Existing System

Manual browsing of quote websites and charting tools, one ticker at a time, combined with personal watchlists and spreadsheets. No reliable way to run the same technical criteria across an entire index and get a ranked shortlist.

## Target Users

- **Primary:** Semi-technical retail trader. Understands moving averages, RSI, MACD and volume; wants to narrow a large universe to a shortlist of Candidates and inspect them on a chart.
- **Secondary:** Admin/Operator. Controls and supervises EOD data Ingestion Runs and the Instrument universe.

## Goals

- Let a trader run a multi-criteria technical Screener over the S&P 500 and get a ranked shortlist.
- Detect deterministic Signals (moving-average crosses, trend/MA alignment, RVOL/volume, RSI, MACD, breakout of a Pivot) per Instrument, computed from EOD data.
- Show an interactive chart with price, volume, moving averages, the detected Signal and its reference levels (pivot, stop, target).
- Persist per-user Saved Screeners and Watchlists for Registered Users.
- Give an Admin a panel to run, monitor and re-run the EOD Ingestion and inspect its logs.

## Non-Goals

- Intraday or real-time quotes.
- Order execution or broker integration.
- Advanced backtesting / historical strategy performance analytics.
- Fundamental data or news.
- Automatic email / Telegram / push alerts and notifications.
- Non-US markets (IBEX and others).
- Native mobile/desktop apps (responsive web only).
- AI Copilot (deferred to a later phase).
- Paid plans, subscriptions or licensing.

## MVP Slice

A vertical slice that proves the riskiest useful behavior end to end:

1. An Ingestion Run scrapes EOD data for the S&P 500 universe, stores Daily Bars and computes Indicator Snapshots + Signals.
2. A Visitor opens the Screener, applies technical filters, and gets a ranked Candidate list.
3. The Visitor opens a Candidate and sees an interactive chart with indicators and the Signal's reference levels.
4. A Registered User saves a Screener and maintains a Watchlist.
5. An Admin triggers and monitors an Ingestion Run from the Admin panel.

## Validation Plan

- Ingestion: a run over the S&P 500 completes and stores Daily Bars + Indicator Snapshots with a report of successes/failures per ticker.
- Screener: a known filter (e.g. Golden Cross + RVOL > 2) returns the expected Instruments when tested against a fixed fixture dataset, independent of live network data.
- Signal correctness: indicator and Signal computations verified against hand-calculated fixtures for a handful of tickers.
- Charts: a Candidate chart renders price, volume, MAs and the Signal's levels.
- Access: anonymous browse works; saving a Screener/Watchlist requires registration.

## Success Criteria

- A trader can go from "no idea" to a ranked shortlist of charts in minutes, using the same criteria that would otherwise be checked manually ticker by ticker.
- The pipeline is reproducible from stored Daily Bars, so results can be verified without hitting the network.
- The product is honest about being an analytical tool, not financial advice.

## Notes

- Visual direction is defined by the existing `alphapulse/` prototype and captured in `DESIGN.md`.
- Working product name in the mockup is `AlphaPulse`; repository is `ChartScreenPlus`.
- Scraping a public source is an accepted dependency and a known risk (see `docs/risks-and-open-questions.md`).
