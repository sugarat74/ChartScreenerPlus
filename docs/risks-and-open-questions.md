# Risks and Open Questions

## Blocking Next Phase

- **Data source and legality:** Yahoo Finance chart data is the primary source for `ingestion-scraper-eod`; Stooq daily EOD CSV is the fallback because its anti-bot response can exhaust the request timeout. Both are unofficial integrations whose terms of use and redistribution rights need review. Reliable live ingestion at scale still needs rate-limit and terms validation.
- **Hosting/security verification (2026-10-04):** historical NO APTO findings were remediated and revalidated: code/activation/shared parents and venv no longer allow web-group writes, SQLite has a dedicated private directory, SSH host key is pinned and independently confirmed, APP_URL is canonical HTTPS, and limited sudo commands work. CI/deploy succeeded. See `docs/security/2026-10-04-revalidacion-despliegue.md`: global verdict **VALIDACION INCOMPLETA** for firewall/provider rules, complete advisories, authenticated production behavior and automated backup/retention. A manual isolated SQLite backup/restore integrity check passed.
- **PostgreSQL migration (2026-10-06, `db-postgresql-migration` passing in repo, not cut over):** code, CI and runbook exist; production still runs SQLite until an operator runs `deploy/migrate-to-postgresql.sh` from the root console in a maintenance window. Open: confirm the VPS PostgreSQL major version matches CI's `postgres:18` image; run the `--dry-run` first (SQLite may hold values PostgreSQL rejects, reported by table/id/column); rollback after cut-over loses writes made on PostgreSQL; PgBouncer is a hard runtime dependency (bypass documented); off-VPS backup copy is not configured; the backup restore test compares counts with live data and must run while ingestion is idle.
- **Production data (2026-10-04):** live SQLite has 68,136 Daily Bars but no Indicator Snapshots or Signals. Local backfill evidence is not production evidence; execute the existing computation pipeline as a separately scoped operational task before expecting a populated Candidate list.

- **Privacy notice missing (2026-10-07):** `admin-users-sessions` stores sign-in activity (email, IP, user agent) for 90 days, but the site publishes no privacy notice or legal pages. A draft processing record is in `docs/legal/tratamiento-actividad-de-acceso.md`; the owner's identifying data and a professional review are pending before publishing it (run the `web-legal-compliance-eu` skill for the full set of legal pages).

## Implementation-Time Questions

- Exact indicator parameter defaults (SMA 20/50/200, EMA 21/55, RSI 14, MACD 12/26/9, ADX, Bollinger 20/2, RVOL baseline window).
- Precise Signal rule definitions and thresholds (e.g. what counts as a recent Golden Cross, RVOL cutoffs, Pivot detection method).
- How the S&P 500 constituent list is obtained and refreshed.
- Where the Python engine boundary sits: internal HTTP API vs queue/CLI invoked by Laravel.
- Auth method details (session vs token), registration/email verification requirements for the MVP.
- **Decided by `chart-interactive` (2026-09-30):** charting approach. The chart uses the **self-hosted `lightweight-charts` npm package** (TradingView Lightweight Charts, pinned **5.2.1**, Apache-2.0) — not the embedded TradingView widget and not a CDN. The chart page is anonymous, consumes only `GET /api/instruments/{ticker}`, draws the latest-snapshot SMA values as reference lines and only the `pivot_breakout_rvol` pivot (never a derived stop/target). The library's `attributionLogo` stays enabled to satisfy its NOTICE; the residual requirement is that any future chart surface must also keep the attribution link visible. See `ARCHITECTURE.md` → "Chart UI" and `CONSTRAINTS.md` → "Frontend Chart".
- **Decided by `ingestion-scheduler` (2026-09-30):** scheduler time relative to Market Close across DST and US market holidays. The daily event runs at `16:30 America/New_York` (config-driven `market_close` + `schedule_buffer_minutes`), DST-aware via the event timezone, skipping weekends and a committed NYSE holiday list in `config/ingestion.php`. See `ARCHITECTURE.md` → "Scheduling (Daily EOD Pipeline)" and `CONSTRAINTS.md` → "Operations And Scheduling".

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
- **Holiday-list drift:** the daily EOD schedule skips a committed NYSE holiday snapshot (`config/ingestion.php`), not a computed calendar, so it must be refreshed annually. A missing holiday causes a harmless idempotent run attempt (bars/snapshots unchanged, signals recomputed), not bad data.
- **Production scheduler / overlap lock:** `ingestion-scheduler` only defines the schedule; production still needs a host cron / Windows Task Scheduler entry running `php artisan schedule:run` (deployment concern). Overlap protection needs a lock-capable cache store (`database`/`array`); a crashed run holds the command lock until `INGESTION_LOCK_TTL_SECONDS` (default 7200s).
- **Source readiness at close + buffer:** at `16:30` the EOD bar may not be published yet, so a run with zero new bars is a normal, idempotent outcome rather than a bug.
- **Stooq anti-bot block (observed 2026-10-01):** `GET https://stooq.com/q/d/l/?s=nvda.us&i=d` returns an HTML SHA-256 proof-of-work challenge from this environment, not CSV. The engine now falls back to the reachable Yahoo Finance chart endpoint; live `GET /eod/NVDA` returned 501 daily bars and Laravel stored them. The fallback is not a completed licensing or scale decision: validate Yahoo terms, coverage and rate limits before operating all 503 tickers daily.
- **Incorrect math presented as signals:** indicator and Signal bugs would mislead users; mitigated by fixture-based tests.
- **Scope creep back to the mockup:** the prototype shows AI, alerts, plans and geometric patterns that are explicitly out of MVP scope.
- **Licensing of charts/data:** embedding third-party widgets or redistributing scraped data may carry legal constraints. Concrete residual for the chart: `lightweight-charts` is Apache-2.0 and its NOTICE requires the TradingView attribution link on the page showing the chart, so the chart's `layout.attributionLogo` must stay enabled and future chart surfaces must keep it visible.
- **Trust/positioning:** users may treat output as financial advice; the product must state it is an analytical tool.

## Research Tasks

- Evaluate 1-2 candidate public EOD sources for structure stability, coverage and rate limits.
- ~~Decide the charting library by testing Lightweight Charts against the chart requirements.~~ Done: `chart-interactive` (2026-09-30) chose the self-hosted `lightweight-charts` 5.2.1 package and verified the v5 API against the installed typings.
- Prototype the Python indicator/Signal pipeline on a fixture dataset and compare with hand calculations.
- Spike the Laravel <-> Python contract (HTTP vs queue) with a trivial end-to-end job.
- Confirm the S&P 500 constituent list source and refresh cadence.
