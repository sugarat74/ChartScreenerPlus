# Technical Discovery

## Product Surface

Responsive web application with three surfaces:

- **Trader surface:** Screener (filters + Candidate list) and interactive chart.
- **User surface:** portal with Saved Screeners and Watchlist.
- **Admin surface:** ingestion control, telemetry/logs and universe management.

The AI Copilot surface exists in the mockup but is out of MVP scope.

## Candidate Stack

- **Backend / API / Auth / Admin:** Laravel (PHP).
- **Data engine:** Python service for scraping EOD quotes, computing Indicator Snapshots and detecting Signals. Exposed to Laravel via an internal API or a queue/CLI contract.
- **Frontend:** React SPA (React 19 + TypeScript + Vite), reusing the `alphapulse/` prototype as the visual base.
- **Styling:** Tailwind CSS with the token set captured in `DESIGN.md`.

Relationship to the mockup: `alphapulse/` is a functional prototype (React + Express + simulated data + Gemini calls). It is a UI/UX reference and intent source, **not** the production base. Its Express server and seeded fake data are discarded.

## Data and Storage

- Relational database (PostgreSQL preferred) for Instruments, Universes, Daily Bars, Indicator Snapshots and Signals.
- Daily Bars are append-only per `(instrument, date)`; Snapshots and Signals are derived and can be recomputed.
- Store raw ingestion results/logs so a run can be audited and a failed ticker re-run.
- Caching (Redis) is optional for the MVP; results are already EOD and recomputed once per day.

## Integrations

- **Market data:** scraping a public EOD source. Risk of blocking/rate limits; ingestion must throttle and retry.
- **Charting:** interactive chart in the SPA. Decide between TradingView Lightweight Charts (free, lightweight) and an embedded TradingView widget (licensing/ToS). See open questions.

## Authentication and Authorization

- Laravel session/API auth for Registered Users; role check for Admin.
- Every request that touches owned resources enforces ownership server-side.

## Deployment and Operations

- Single environment target for the MVP (VPS/container host). Laravel app + Python engine + database + scheduler.
- Scheduler runs the daily Ingestion Run after Market Close (timezone/DST aware).
- Admin panel can trigger manual runs and re-runs.
- No paid infrastructure assumed; keep it runnable on modest hardware for ~500 tickers/day.

## Testing and Verification

- Python: unit tests for indicator math and Signal rules against hand-calculated fixtures; a fixture dataset lets Screener results be verified without network.
- PHP/Laravel: feature tests for auth, ownership enforcement and Screener API responses against fixtures.
- E2E smoke: consume a seeded/fixture dataset end to end without live scraping.
- Live scraping is treated as an integration concern, not a unit-test dependency.

## Observability

- Admin panel shows run status (`queued/running/completed/failed/partial`), per-ticker failures, duration and logs.
- Basic health endpoint for the app and the engine.
- Throttle/ban counters surfaced to the Admin as an operational signal.

## Constraints

- EOD only; no real-time or intraday feed.
- Scraping is fragile and subject to source changes and blocks.
- ~500 tickers/day must stay within a modest time/resource budget.
- A new Instrument needs sufficient history before long-window indicators are meaningful.
