# Progress Log

Newest first; only the latest 10 sessions are kept here. Older sessions are in `docs/archive/progress-log.md` (read only when you need that history).

## Multi-market universes planning (Nasdaq-100, IBEX 35, DAX; not started) — 2026-10-26

- At the user's explicit request ("prepara la feature para nasdaq, ibex 35 y dax 40") wrote the umbrella spec `docs/specs/multi-market-universes.md` and appended six `not_started` features: `universe-registry`, `ingestion-multi-market`, `universe-selector-ui`, `universe-nasdaq100`, `universe-ibex35`, `universe-dax`. "Nasdaq" is read as the Nasdaq-100; the Composite is deferred.
- Key decisions: Listing Market (`US`/`XMAD`/`XETR`) and currency per Instrument and Universe; globally unique tickers with `.MC`/`.DE` suffixes for European stocks (`MRK.DE` vs `MRK`); one Instrument per company across universes (Nasdaq-100 overlap fetched once); dated offline snapshots with exact membership sync; per-market calendars, schedules (Madrid/Xetra 18:15 local) and a waiting global lock; alerts once a day in the US run; per-universe public flag (`SCREENER_UNIVERSES_PUBLIC`, default `sp500`); Market Context, Breadth, sector context filters, Fundamentals, Company Card and forecast stay US-only.
- Same day, two more future features at explicit request: `market-hours-status` (`docs/specs/market-hours-status.md`, home-page open/closed markets from the static schedule only) and `market-events-calendar` (`docs/specs/market-events-calendar.md`, reuses the FOMC/ECB calendar; blocked on owner decisions about earnings and release sources).
- Updated the `AGENTS.md` scope guard (narrowed the non-US exclusion), `CONTEXT.md`, `docs/domain-model.md` and `docs/risks-and-open-questions.md`. Planning only: no application code changed and `init.ps1` was not run.
- Owner answers applied the same day:
  - Nasdaq-100 is confirmed; the Composite stays deferred.
  - New universes are public to everyone, Visitors included.
  - IBEX 35 and DAX are published via Yahoo as an owner-accepted licensing risk. The kill-switch is `SCREENER_UNIVERSES_PUBLIC`.
  - The label is "DAX": `universe-dax40` was renamed to `universe-dax`, slug `dax`.
  - The events calendar will use an external earnings-date source, but the provider and its display licence are still pending. EODHD, FMP and Finnhub are recorded as candidates.
- Still open: the earnings provider and budget, and the events calendar coverage (US only or also Europe).

## Independent validation: Screener table column controls (`screener-table-column-controls`, accepted) — 2026-10-25

- Verdict **accept**. Validator reran `.\init.ps1` (exit 0: Laravel 867 passed / 6 skipped, 6846 assertions; SPA lint/typecheck with the existing RequireAuth.test warning; 375 Vitest in 47 files; build; engine 101) and focused screener/saved/AI/alerts suites (80 passed), reviewed the full diff and confirmed the modified tests were not weakened. All seven declared deviations accepted (RSI/RVOL bounds and inverted ranges now `422`; sector/country sort by GICS name/ISO code; only sector/country are selects; AI apply clears column filters; PostgreSQL checked only by CI; SQLite ASCII-only case folding; updated tests).
- Non-blocking follow-ups: CI `test-pgsql` must pass before merge (LIKE escape, `collate "C"` sorts); table headings are hidden on empty results (chips and the sort select remain); an AI answer with an inverted RSI range is now `invalid_provider_response`; a legacy Saved Screener with `rsi_min > rsi_max` still matches nothing in alerts. Full record archived to `docs/archive/feature-evidence-accepted.json`.

## Screener table column controls implementation (`screener-table-column-controls`, passing) — 2026-10-25

- Every Candidate table heading now has a sort button (both directions, `aria-sort`, nulls last, ticker tie-break) and a filter toggle opening one editor above the table: ticker/company and industry fragments, localized GICS sector and HQ country selects, inclusive Close/Change/RVOL/RSI bounds and the existing Signal/Pattern selection. Active column criteria show as removable chips (also in the empty state); «Limpiar filtros» clears them and keeps the sort.
- API: new public params `search`, `sector`, `industry`, `country`, `rvol_max`, `close_min`/`close_max`, `change_min`/`change_max` and 12 per-column sorts, validated in `ScreenerFilterParser` and applied in SQL before count/paging (bound, wildcard-escaped LIKE; fixed sort map). Contract tightening: numeric bounds (`rsi_*` 0–100, `min_rvol` 0–1000) and inverted ranges, including `rsi_min > rsi_max`, are now `422`. Saved Screeners store the twenty-key definition and re-check it with the parser; old rows read the new keys as null. The AI schema, prompt and response stay at eleven keys (`ScreenerQuerySchema` projects the parser output).
- Verified: new `ScreenerColumnControlsTest` (14 tests) plus focused screener/saved/alerts/AI/localization suites 191 passed; Vitest `screenerColumns.test.ts` + `ScreenerColumnControls.test.tsx`; standard `.\init.ps1` exit 0: Laravel 867 passed / 6 skipped (6846 assertions), SPA lint/typecheck (existing RequireAuth warning), 375 Vitest in 47 files, build, engine 101. Manual Playwright on a scratch SQLite copy (503 seeded rows): 1440 px and 390 px, Energy filter 21/21 across two 10-row pages, RSI-ascending nulls last, keyboard open/Escape focus return, no page-level horizontal overflow. PostgreSQL behavior (LIKE escape, `collate "C"` text sorts) is only covered by CI. Independent validation pending.

## Independent validation: Screener company classification (`screener-company-classification`, accepted) — 2026-10-25

- Verdict **accept**. Independently re-derived GICS sub-industry and headquarters country for all 503 tickers from the committed source snapshot with the documented mapping: 0 mismatches, original columns untouched, nulls exactly CTVA/PSKY/WBD (both) and XYZ (country). Migrations are additive and backfill only the new fields; row builder and public payload add fields without filter/order changes.
- Production `/api/screener` paged 503/503 rows matching the dataset, no ETFs; Playwright at 390 px and 1440 px confirmed the three separate columns with in-table horizontal scroll. `.\init.ps1` exit 0: Laravel 853 passed / 6 skipped (6567 assertions), SPA lint (existing RequireAuth.test warning), 351 Vitest in 45 files, build, engine 101 passed.
- Non-blocking follow-ups: migration file names are dated 2026-10-27 (later migrations created before that date would sort earlier); unknown values render a bare dash without screen-reader text; the ticker column is not sticky when scrolling the table on mobile. `screener-table-column-controls` is now unblocked.

## Harness token diet: archived history — 2026-10-25

- Moved the full records of the 64 `accepted` features to `docs/archive/feature-evidence-accepted.json` (entries in `feature_list.json` keep `id`, `area`, `title`, `status`, `depends_on`) and the 88 oldest `PROGRESS.md` sessions to `docs/archive/progress-log.md`, verbatim. Session-start reading dropped from ~580 KB to ~48 KB.
- `AGENTS.md` startup now reads only the selected feature's entry, spec and related progress; end of session keeps at most 10 sessions and archives a feature's record on acceptance. Recommendations for future projects in `docs/eficiencia-de-tokens-con-agentes.md`.
- Verified: a script rebuilt the original 72 feature records exactly from the slim list plus the archive, and every non-empty original `PROGRESS.md` line is present in the two new files; `scripts/harness-context.ps1` output is unchanged in substance. No application code changed; `init.ps1` not run.

## Screener column filters and sorting specification — 2026-10-25

- Added the future feature `screener-table-column-controls` to `feature_list.json` and wrote `docs/specs/screener-table-column-controls.md`. It covers filters and sort directions for all nine current Candidate columns, server-side behavior before pagination, URL/Saved Screener persistence, accessible controls and API safety. It depends on `screener-company-classification`, which is still `passing` pending independent validation. No application code or product behavior changed.

## Screener classification columns — 2026-10-25

- Moved Sector, GICS sub-industry and headquarters country from the ticker/company cell into three adjacent Candidate table columns, before price and technical values. The public API, ranking, filters, null handling and mobile horizontal scrolling are unchanged.
- Focused Spanish/English CandidateClassification Vitest: 2 passed, including header order and per-row column values. Full post-change `.\init.ps1` exited 0: Laravel 853 passed / 6 skipped, SPA lint/typecheck (existing RequireAuth.test warning only), 351 Vitest in 45 files, production build, engine 101 passed. `git diff --check` passed. Production not deployed in this session.
- Prior Mercado tab changes from this session remain uncommitted; the legal publication gate remains off by the owner's explicit choice.

## Mercado tab visibility follow-up — 2026-10-25

- The Mercado tab is now always visible in the header. It is a link only when the signed-in session has `capabilities.market_context`; guests and denied accounts see a muted, noninteractive tab with a localized reason. The server's `view-market-context` ability remains the access gate.
- The owner reconfirmed that `MARKET_CONTEXT_PUBLIC` stays off until the legal notice with the FRED terms clause is published. Registered Users therefore see a disabled tab for now; Admins retain preview access. Once the flag is enabled, Registered Users get an active tab and Visitors continue to see a disabled one.
- Focused AppHeader Vitest: 5 passed. Full post-change `.\init.ps1` exited 0: Laravel 853 passed / 6 skipped, SPA lint/typecheck (existing RequireAuth.test warning only), 351 Vitest in 45 files, production build, engine 101 passed. `git diff --check` and feature-list JSON parsing passed. Production not deployed in this session.

## Stock comparison specification (`stock-comparison`, not started) — 2026-10-24

- Added `docs/specs/stock-comparison.md` and a backlog entry for a public, shareable comparison of two to four active stocks. Owner chose daily price history and technical indicators for the first version; fundamentals SEC are outside scope. The spec defines a bounded stock search, exact common-date alignment with each price line indexed to 100, dated latest indicators, URL state, es/en responsive UI and verification. No application code or production behavior changed.
- Planning baseline `./init.ps1` exited 0: Laravel 853 passed/6 skipped, SPA lint/typecheck, 351 Vitest in 45 files and build, engine 101 passed. Next step is implementation followed by independent validation; `stock-comparison` remains `not_started`.

## Password recovery and Screener classification deployment — 2026-10-24

- PR 70 passed SQLite/frontend/engine CI and the full PostgreSQL/PgBouncer job, then merged as `a558b54`. The `main` CI/deploy run 40119598548 completed successfully, including the production release activation and Screener refresh.
- Production smoke: `/`, `/recuperar-contrasena` and `/restablecer-contrasena` return 200. Public `/api/screener` returns `sector`, `industry` and `country` for ranked Candidates (e.g. CCI, FIX, GL), with nulls retained for unmatched source values. Headless Edge screenshots at 390 px and 1440 px confirmed readable labels, compact wrapping and that price, RVOL, RSI, Signals and chart links remain present. A live reset email to a disposable production account was not sent; focused end-to-end mail-link integration and the previously verified Brevo delivery path are the available evidence.
- Both features remain `passing` pending independent validation. The data-source licensing/staleness risk remains documented in `docs/risks-and-open-questions.md` and `database/data/sp500-classification.md`.
