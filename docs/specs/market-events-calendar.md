# Feature Implementation Spec: Market events calendar (short spec)

## Source Feature

- `id`: `market-events-calendar`
- `area`: `backend`
- `depends_on`: `market-context-ui`, `macro-series-fred`. If the owner chooses European coverage, also `universe-ibex35` and `universe-dax`.
- `status`: `not_started`. This is a future feature and not part of the multi-universe delivery. It is **blocked on the earnings provider and display-licence choice (Q1)** and on coverage (Q3) below; the owner chose an external earnings source on 2026-10-26. Refine this file into a full contract once they are answered.
- `source`: `feature_list.json`. Requested 2026-10-26: "añadir un calendario de eventos económicos, presentación de resultados, reuniones de la fed... cosas que afecten al mercado".

## Goal

A dated, descriptive list of upcoming and recent events that move markets:

- central bank policy meetings;
- key macro releases (CPI, payrolls/employment situation, GDP, PCE, retail sales);
- company earnings dates for Screener Instruments.

The list is shown on `/mercado`. Each Instrument's chart page also shows the date of its next or last earnings.

## Build On What Exists (do not duplicate)

- **Central banks.** `config/central_banks.php` and `App\Services\Macro\CentralBankCalendar` already keep the FOMC and ECB meeting dates by hand, with `source_url`, `checked_at` and a staleness test (`CentralBankCalendarTest`). `/mercado` already shows the last decision and next meeting (`docs/specs/macro-series-fred.md`, Domain Rule 7; `docs/specs/market-context.md`). The events calendar **reads this source**. It does not create a second one.
- **Macro releases.** FRED is already the only allowed macro feed (`macro-series-fred`, opt-in `MACRO_ENABLED`, same key). FRED's release endpoints (`fred/releases/dates`, `fred/release/dates`) publish the scheduled dates of public US releases (BLS CPI and Employment Situation, BEA GDP and PCE, Census Retail Sales). This is the preferred source because it adds no new vendor. It needs a terms check per release, as `macro-series-fred` did: the FRED attribution and the no-AI rule apply.
  - Only a closed list of release ids is used.
  - The calendar shows dates and, after publication, the published value already stored as a Macro Observation where one exists.
- **Access.** This is a Market Context surface, so it is behind the existing `view-market-context` ability: Registered-only, with Admin preview while `MARKET_CONTEXT_PUBLIC` is off. That is one gate for a future paid plan.

## Hard Rules

- **No forecasts, consensus estimates, "expected" values, surprises or advice.** Only dates, event names, the source, and (after the fact) the published value. This is consistent with the Market Context rules (`docs/specs/market-context.md`, decision 4).
- Every event shows its source and its `checked_at` or last-refresh date. Unknown or unconfirmed dates are labelled "tentativa" / "tentative". A date is never guessed.
- No EOD labels in public UI. Dates and times are shown in the viewer's timezone, or as dates only when the source gives no time.
- No news, no text from third parties, no AI-written summaries. Calendar data is never sent to the AI provider (this is a FRED rule anyway).
- Failures are isolated, as in Market Context decision 5: a failed refresh is logged and shown in Admin, and never fails the pipeline.

## Open Decisions (owner) — blocking

1. **Earnings dates provider and display licence (still blocking).** The owner decided on 2026-10-26 to use an **external source** for earnings dates, not a hand-kept list and not SEC-only. SEC EDGAR only shows past results (10-Q/10-K, 8-K item 2.02). The **provider is still pending** the owner's choice. The blocking question is budget and a licence that allows **public display on chartiko.com**. No feed may be wired before that choice, and AGENTS.md records this exception to "other fundamentals data feeds".
2. **Macro releases source.** Recommended: FRED release dates for the US, at no cost from an already approved provider (see candidates). FOMC and ECB dates keep coming from the existing hand-kept calendar.
3. **Coverage (still open).**
   - US only (FOMC, US releases, US earnings).
   - Or Europe too: ECB (already in config), Eurostat HICP/GDP release calendar (a new public source to clear), Spanish and German national releases, and IBEX 35 / DAX earnings. The European earnings dates need the same feed decision as Q1, because SEC does not cover them.

   If Europe is chosen, this feature depends on `universe-ibex35` and `universe-dax`, and on Listing Market data from `universe-registry`.

## Candidate Sources (orchestrator research 2026-10-26 — verify before contracting)

- **Macro release dates: FRED API `fred/releases/dates`** with `include_release_dates_with_no_data=true`. It returns scheduled future release dates. FRED is already an approved provider (`macro-series-fred`), so it is recommended for US macro releases at no cost. The FRED attribution and no-AI rules apply.
- **Central bank meetings:** reuse `config/central_banks.php` / `CentralBankCalendar` (FOMC, ECB), as described above.
- **Earnings dates: EODHD `/api/calendar/earnings`** (past and upcoming, global coverage claimed).
  - It is included in the personal Fundamentals (€59.99/month) and All-In-One (€99.99/month) plans.
  - The commercial "Internal use" plan (€399/month) explicitly forbids showing data to people outside the company.
  - Public display needs Enterprise (€2,499/month) or a custom agreement. Confirm with EODHD sales.
  - Coverage of Madrid (`.MC`) and Xetra earnings is not confirmed on its documentation page.
- **Alternatives to evaluate:**
  - **Financial Modeling Prep**: earnings and economic calendar. Its pricing page could not be retrieved, so check its display terms.
  - **Finnhub**: earnings and economic calendar. Its free tier is reportedly personal use only.
- **Key risk:** personal-tier licences do not allow public display on chartiko.com. Budget and display licence are the blocking decision. Whatever provider is chosen sits behind a replaceable source interface, like the Cboe put/call source, and its terms are recorded in `docs/risks-and-open-questions.md` before any production use.

## Scope Sketch (to refine after the decisions)

- Table `market_events`, one row per event:
  - `kind` (`central_bank`, `macro_release`, `earnings`);
  - `region` (`US`, `EU`, `ES`, `DE`);
  - `date`;
  - `time_utc` (nullable);
  - `title_key` or `series_code`;
  - `instrument_id` (nullable, earnings only);
  - `status` (`scheduled`, `tentative`, `published`);
  - `source`;
  - `source_url`;
  - `refreshed_at`.

  Central bank rows can be read straight from `CentralBankCalendar` instead of being stored.
- `events:refresh`: opt-in, idempotent, run by the US pipeline next to `macro:refresh` and isolated like it.
- `GET /api/market-context/events?from=&to=` behind `view-market-context`. The `/mercado` section has "Próximos eventos" / "Upcoming events" (14 days) and "Recientes" / "Recent" (7 days), with filters by kind and region. A chart-page chip shows "Resultados: {date}" / "Earnings: {date}" when known.
- es/en i18n, DESIGN.md, and a short educational "¿Qué significa?" text per kind, with no advice wording.

## Acceptance (summary, after refinement)

- Recorded FRED release-date fixtures parse to the closed release list. An unknown or changed response stores nothing and records the error.
- The central bank events equal `CentralBankCalendar` output, with no second copy of the dates.
- The access matrix matches Market Context: Visitor 401, Registered User allowed only when the flag is on, Admin preview.
- No forecast, consensus or advice wording (Vitest scan of the messages). es/en complete. No EOD wording.
- `.\init.ps1` passes.
