# Feature Implementation Spec: Market hours and open/closed status on the home page

## Source Feature

- `id`: `market-hours-status`
- `area`: `frontend`
- `depends_on`: `ingestion-multi-market`, `universe-ibex35`, `universe-dax`
- `status`: `not_started` (future feature; not part of the first multi-universe delivery)
- `source`: `feature_list.json`. Requested 2026-10-26: "añadir los horarios en los distintos mercados y su calendario, para ver en portada cuales están abiertos en cada momento". Related umbrella: `docs/specs/multi-market-universes.md`. This is a short spec; refine it into a full contract before implementation.

## Goal

The home page (`/`, the Screener) shows a compact strip for the supported Listing Markets:

- **NYSE/Nasdaq** (`US`);
- **Bolsa de Madrid** (`XMAD`);
- **Xetra** (`XETR`).

Each market shows whether it is **open** or **closed** right now, plus the next open or close time in the viewer's timezone. A details popover or page lists each market's regular hours and its upcoming holidays and early closes.

## Hard Rules

- **Static schedule and clock only.** The status comes from the committed calendar data and the current time. It never comes from intraday or real-time market data, and nothing is fetched from an exchange or a data source. A surprise halt or an unscheduled closure is not reflected. A short note says the status follows the published schedule.
- **No EOD labels** in this public UI (AGENTS.md). The wording is "Abierto / Cerrado", "Abre {time}", "Cierra {time}" (en: "Open / Closed", "Opens {time}", "Closes {time}").
- No advice and no index levels or prices in the strip.

## Reuse And Data

- The calendars come from Feature 2 of the umbrella (`ingestion-multi-market`): `config/ingestion.php` `markets.<code>`, which holds timezone, close and holidays, and `App\Services\Market\MarketCalendar`.
- The real `XMAD`/`XETR` holiday lists come from `universe-ibex35` and `universe-dax`. That is why this feature depends on them.
- This feature adds two keys per market: `open` (US 09:30, XMAD 09:00, XETR 09:00 local) and `early_closes` (`date => HH:MM`, for example US day after Thanksgiving 13:00, and BME/Xetra 24 and 31 December when those are trading days). The values are taken from the official calendars and refreshed annually together with the holiday lists.
- Auctions and pre/post-market are out of scope: only the regular continuous session is shown.
- One read-only public endpoint, for example `GET /api/markets/status`. For each market it returns the code, the timezone, today's session in UTC ISO (`opens_at`/`closes_at`, or null on a closed day), `is_open`, `next_open_at`, `next_close_at`, and the next N holidays and early closes. The SPA formats times in the viewer's timezone and refreshes the status from the clock every minute without refetching. It refetches when the next boundary passes.
- Markets shown: those with a public Screener universe (`SCREENER_UNIVERSES_PUBLIC`). All three are planned public (owner decision 2026-10-26), and a market disappears from the strip if its universes are hidden with the kill-switch.

## Acceptance (summary)

- Laravel tests with a frozen clock, in UTC instants, check:
  - each market open and closed just before, at and after its open and close;
  - weekends;
  - each market's own holiday (for example, a Xetra holiday on a US trading day);
  - an early close;
  - both DST transitions in Europe and the US (different weeks);
  - `next_open_at` across a weekend plus a holiday.
- Vitest checks:
  - the strip in es/en with times in a mocked viewer timezone;
  - the status flips when the mocked clock crosses a boundary;
  - accessible text (status not by color alone);
  - no hardcoded strings;
  - no EOD wording.
- Visual smoke at 390 px and 1440 px, light and dark, following DESIGN.md: there is no horizontal page overflow.
- `.\init.ps1` passes.

## Non-Goals

- No live or intraday data, no exchange status feeds, no notifications of opens and closes, no markets beyond the supported Listing Markets, no auction or extended-hours phases.
