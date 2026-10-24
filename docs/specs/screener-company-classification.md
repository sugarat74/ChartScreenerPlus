# Feature Implementation Spec: Sector, industry and country in the Screener

## Source Feature

- `id`: `screener-company-classification`; `area`: screener; `status`: not_started.
- `depends_on`: `screener-precomputed`, `screener-filters-ui` (accepted).
- Source: `feature_list.json`; owner request of 2026-10-22, prepared 2026-10-24.

## Goal and job story

When I review ranked Candidates, I can see the sector, GICS sub-industry and country of each stock beside its name, in a compact desktop/mobile layout. The information comes from the committed S&P 500 constituent dataset, is available to Visitors and is never inferred from the AI Company Card.

## Non-goals

- No sector/industry/country Screener filters, ranking changes, other universes, Company Card edits or AI-generated classification.
- No runtime lookup or network dependency in the seeder, API or tests; no SEC fundamentals feed reuse.
- No new Market Context entitlement: basic company classification is public, unlike Market Context sector trend/rank filters.

## Users and permissions

- Visitor, Registered User and Admin see the same fields on the public Candidate list. Existing Screener filters and permissions remain unchanged.
- ETFs never appear as Candidates, regardless of classification values.

## Acceptance scenarios

1. Given a committed S&P 500 stock with known classification, when the offline seeder runs twice, then its Instrument stores sector, GICS sub-industry and country without duplicates; its Screener row and public API return the same values.
2. Given a stock with a genuinely unknown industry or country, when it is seeded and shown, then the database and JSON contain `null`, and the UI displays a localized dash/unknown label; it never guesses from the company name or exchange.
3. Given a Candidate list on desktop or narrow mobile, when a Visitor opens the Screener, then sector, industry and country are readable beside the ticker/company, while price, change, RVOL, RSI, Signals and chart link remain available.
4. Given a market-context ETF, when the screener rows refresh, then no ETF appears in the public Candidate list.
5. Given es/en locale, when a fixed GICS sector is shown, then its label is localized; an industry name from the source is displayed verbatim because no verified bilingual industry taxonomy exists.

## Repository research

- `database/data/sp500.csv`, `database/seeders/Sp500UniverseSeeder.php`: committed offline 503-member dataset currently has `ticker,company,sector,exchange`; idempotent seeding updates Instruments and refreshes Screener rows. Industry and country are absent.
- `database/migrations/2026_09_29_100002_create_instruments_table.php`, `app/Models/Instrument.php`, `database/migrations/2026_10_12_120000_create_screener_rows_table.php`, `app/Models/ScreenerRow.php`: Instrument and precomputed row fields; additive nullable columns are needed on both tables.
- `app/Services/Screener/ScreenerRowBuilder.php`: copies Instrument identity fields into `screener_rows`; its refresh excludes ETFs. `app/Services/Screener/CandidateQuery.php` controls the public payload and must add `industry` and `country` without altering filter/order logic. `app/Http/Controllers/ScreenerController.php` pages that query.
- `frontend/src/lib/api.ts`, `frontend/src/components/screener/{CandidateResults,CandidateTable}.tsx`, `frontend/src/pages/ScreenerPage.tsx`, `frontend/src/i18n/messages/{es,en}.ts`: Candidate type, table and i18n. Existing sector labels are in the Market Context catalog; share a pure public mapping rather than tying classification to the gated Market Context panel.
- `DESIGN.md`: dense, data-first table and mobile overflow convention. `frontend/package.json` has Vitest but no persistent browser E2E command; `init.ps1` is the full non-blocking gate.

## Data contract and technical approach

1. Extend the committed dataset to `ticker,company,sector,industry,country,exchange`. `industry` means **GICS sub-industry**, not broad GICS industry and not AI prose. `country` means headquarters country from the source, not incorporation or trading venue; store it as an ISO 3166-1 alpha-2 code (e.g. `US`) or null and label it accordingly in the UI. At implementation time, capture a dated, reviewable source snapshot aligned by ticker with the existing CSV. Record source URL, retrieval date, column mapping and licensing review in the dataset documentation. Do not silently turn a headquarters city into a country: use an explicit, tested US-state/DC mapping for US locations and explicit country names for non-US locations; ambiguous locations become null. Review all unmatched tickers and exceptions before committing. Keep the source snapshot or a reproducible generation script only if its redistribution terms allow it; the product consumes the normalized committed CSV offline.
2. Add nullable `industry` and `country` columns to `instruments` and `screener_rows`. Use additive, reversible migrations compatible with SQLite and PostgreSQL. Seed by ticker as today; blank source values become null. Re-running the seeder updates known values and does not create duplicate Instruments or remove universe membership. The migration itself must not pretend to know historic values: deploy runs `db:seed --class=Sp500UniverseSeeder` then `screener:refresh` before users expect them.
3. Copy the two new Instrument values during `ScreenerRowBuilder::refresh()` and add them to `CandidateQuery::payload()` alongside the existing `sector`. No new SQL filter, sort, index or entitlement check. Keep nulls as JSON null. Update `ScreenerCandidate` type accordingly.
4. Render all three labels within the existing ticker/company cell, using compact wrapping on narrow screens; keep the current horizontal scroll for numeric columns and avoid hiding Signals. Translate fixed sector codes/names through a shared es/en catalog, map `US` and any other actually present country codes through es/en labels, display GICS sub-industry source text verbatim, and show a dash for nulls. All headings/assistive labels must use i18n messages. Do not put EOD wording on the public UI.

## Expected file changes

- `database/data/sp500.csv`, `database/seeders/Sp500UniverseSeeder.php`, two additive `database/migrations/*` files, `app/Models/Instrument.php`: source data and storage.
- `app/Services/Screener/ScreenerRowBuilder.php`, `app/Services/Screener/CandidateQuery.php`: precomputed row and public payload.
- `frontend/src/lib/api.ts`, `frontend/src/components/screener/CandidateTable.tsx`, `frontend/src/i18n/messages/{es,en}.ts` and a shared sector-label helper if needed: presentation.
- Focused Laravel seeder/row/API tests and Vitest Candidate table tests; `PROGRESS.md`, `feature_list.json`, dataset notes: evidence and provenance.

## Visual design impact

- UI: yes. Follow `DESIGN.md` and the existing Candidate table, with one compact metadata block in its first column. Check 390 px and desktop widths, keyboard navigation, long industry names, null values and es/en. No new visual asset.

## Durable documentation impact

- `ARCHITECTURE.md`: update the dataset-to-Instrument-to-Screener-row-to-API path only if its documented field list needs expansion.
- `CONSTRAINTS.md`: update the offline provenance, no guesses and null handling rules.
- `AGENTS.md`: no change; startup and workflow stay the same.
- `docs/domain-model.md`: define stored classification attributes on Instrument. `docs/risks-and-open-questions.md`: record data source/licensing or stale classification risk if the source review finds one. Document snapshot date with the dataset.

## Implementation tasks

- [ ] Research and record a usable dated source; reconcile all S&P 500 tickers and commit normalized industry/country values with explicit unknowns.
- [ ] Add nullable columns and extend the idempotent offline seeder and models.
- [ ] Extend Screener row rebuild, API payload and typed client without changing result order or filters.
- [ ] Render public Candidate metadata in es/en, desktop and mobile.
- [ ] Add focused data, API and UI tests; run the gate; record evidence and set `passing` only after implementation is verified.

## Verification and evidence

- Run focused Laravel tests for dataset coverage, idempotence, nulls, row refresh, API shape and ETF exclusion; focused Vitest table tests; then `./init.ps1` in PowerShell. `init.ps1` executes non-blocking checks and must not start persistent servers.
- Check the dataset coverage count and list every null/ambiguous source value in evidence. Verify migration plus seeder plus `php artisan screener:refresh` on a scratch database (no `migrate:fresh` on dev/production).
- No persistent browser E2E command exists in `frontend/package.json`; API feature tests plus component tests cover the stable contract, with a manual 390 px/desktop visual check for the dense table.
- Record source/date/licensing conclusion, counts, test results and visual check in `PROGRESS.md`/`feature_list.json`.

## Validator checklist

- [ ] Source provenance and every ambiguous country conversion are auditable; no runtime feed or invented value.
- [ ] Seeder is offline and idempotent; migrations are additive; Screener rows and API match Instruments.
- [ ] Visitors can see the three fields in es/en on desktop/mobile; nulls are honest; ETFs remain excluded.
- [ ] Filters, ranking, pagination and Market Context permission are unchanged.
- [ ] `./init.ps1` passes and durable evidence/status are correct.
