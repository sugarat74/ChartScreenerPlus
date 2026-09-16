# Risks and Open Questions

## Blocking Next Phase

- **Data source and legality:** which public source is scraped, its terms of use, and whether its structure is stable enough. This gates any real ingestion work.
- **Charting approach:** TradingView Lightweight Charts (free library) vs embedded TradingView widget (licensing/ToS/attribution). Decides what the chart screen can show.
- **Hosting target:** concrete deployment target and how the Laravel app, Python engine, database and scheduler are co-located.

## Implementation-Time Questions

- Exact indicator parameter defaults (SMA 20/50/200, EMA 21/55, RSI 14, MACD 12/26/9, ADX, Bollinger 20/2, RVOL baseline window).
- Precise Signal rule definitions and thresholds (e.g. what counts as a recent Golden Cross, RVOL cutoffs, Pivot detection method).
- How the S&P 500 constituent list is obtained and refreshed.
- Where the Python engine boundary sits: internal HTTP API vs queue/CLI invoked by Laravel.
- Auth method details (session vs token), registration/email verification requirements for the MVP.
- Scheduler time relative to Market Close across DST and US market holidays.

## Later / Not MVP

- AI Copilot (natural-language refinement) and any LLM dependency.
- Chartist pattern detection (Cup & Handle, Double Top/Bottom, flags).
- Automated alerts/notifications (email, Telegram, push).
- Multi-market and additional universes (NASDAQ 100, Russell 2000, IBEX 35).
- Paid plans/subscriptions and licensing.
- Backtesting and historical strategy performance.
- Backfill/history depth policy beyond what is needed for 200-day indicators.

## Assumptions

- EOD-only data is sufficient for the target user's decision process.
- A public scraping source can supply S&P 500 daily OHLCV at acceptable reliability for a proof of concept.
- ~500 tickers/day is a workload a single modest host can handle.
- Deterministic indicator Signals are the right first slice; geometric patterns are genuinely later work.
- The `alphapulse/` prototype's visual language is acceptable as the product direction.

## Risks

- **Scraping fragility / blocking:** source markup changes, IP bans or throttling can break ingestion; mitigated by throttling, retries, run logs and re-run.
- **Incorrect math presented as signals:** indicator and Signal bugs would mislead users; mitigated by fixture-based tests.
- **Scope creep back to the mockup:** the prototype shows AI, alerts, plans and geometric patterns that are explicitly out of MVP scope.
- **Licensing of charts/data:** embedding third-party widgets or redistributing scraped data may carry legal constraints.
- **Trust/positioning:** users may treat output as financial advice; the product must state it is an analytical tool.

## Research Tasks

- Evaluate 1-2 candidate public EOD sources for structure stability, coverage and rate limits.
- Decide the charting library by testing Lightweight Charts against the chart requirements.
- Prototype the Python indicator/Signal pipeline on a fixture dataset and compare with hand calculations.
- Spike the Laravel <-> Python contract (HTTP vs queue) with a trivial end-to-end job.
- Confirm the S&P 500 constituent list source and refresh cadence.
