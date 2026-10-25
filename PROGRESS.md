# Progress Log

Newest first; only the latest 10 sessions are kept here. Older sessions are in `docs/archive/progress-log.md` (read only when you need that history).

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

## Screener company classification implementation (`screener-company-classification`, passing) — 2026-10-24

- Extended the offline 503-member S&P 500 CSV with GICS sub-industry and headquarters country, retaining its existing identity, sector and exchange fields. The dated source snapshot and conversion rules are in `database/data/sp500-classification.md`. Exact ticker join yielded 500 industries and 499 countries; CTVA, PSKY and WBD are unmatched, and XYZ has no known headquarters country. Unknown values remain null.
- Additive migrations store both values on Instruments and Screener rows; the Instrument migration backfills only the new fields on existing rows, preserving Admin edits and universe membership. Seeder, row builder, public API and Candidate table now carry the values. Fixed sector/country labels are localized in es/en; source industry is displayed verbatim. ETF exclusion and existing Screener filters/ranking remain intact.
- Focused Laravel seeder, migration and API tests passed; es/en Candidate table Vitest passed. Standard `.\init.ps1` exited 0: Laravel 853 passed/6 skipped (6567 assertions), frontend lint/typecheck, 351 Vitest in 45 files and build, engine 101 passed. `git diff --check` clean. Independent validation remains pending.

## Password recovery implementation (`auth-password-reset`, passing) — 2026-10-24

- Added public, CSRF-backed forgot/reset endpoints using Laravel's existing 60-minute password broker. A request gets the same response for known and unknown email; only a known address receives a queued, localized account email while outbound mail is ready. Successful reset consumes the token, changes the hashed password, rotates `remember_token`, deletes all sessions and never signs anyone in. The existing email-verification state stays unchanged.
- Added es/en SPA request and reset pages, a login link, shared mail layout content and privacy wording for Brevo's opening pixel. Updated `ARCHITECTURE.md`, `CONSTRAINTS.md` and `docs/user-and-access-model.md`.
- Focused Laravel `PasswordResetTest`: 9 passed / 43 assertions, including an email-link-to-reset-to-new-login integration. Focused Vitest `PasswordResetPage` + `LegalPage`: 21 passed. `npm --prefix frontend run lint` passed. Standard `./init.ps1` exited 0: Laravel suite, Vitest 349 in 44 files, SPA lint/build, engine 101. `git diff --check` clean. No production delivery check yet; independent validation pending.

## Password recovery and Screener classification planning — 2026-10-24

- Prepared `docs/specs/auth-password-reset.md` and `docs/specs/screener-company-classification.md` as separate implementation contracts. Both features remain `not_started` in `feature_list.json`; no application code or production configuration changed.
- Recovery reuses the 60-minute Laravel password broker, the Brevo-ready account mail path and the session directory. Its contract covers a generic request response, one-use token, all-session invalidation, es/en UI and no automatic sign-in. The existing verification state remains unchanged.
- Classification extends the committed offline S&P 500 dataset to GICS sub-industry and headquarters country, then carries the values through Instrument, precomputed Screener row, API and Candidate table. The implementation must review source provenance/licensing and ambiguous locations before committing data; unknowns stay null. Existing filters/ranking and ETF exclusion remain unchanged.
- Planning review: `git diff --check` clean; `feature_list.json` parses. Standard `./init.ps1` baseline exited 0: Laravel 842 passed / 6 skipped, SPA Vitest 346 in 43 files, lint/typecheck/build passed, engine 101 passed. No product implementation was run because this session prepared specs only.
