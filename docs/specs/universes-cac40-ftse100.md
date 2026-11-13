# Feature Implementation Spec: CAC 40 and FTSE 100 Screener universes (umbrella)

## Source Features

This umbrella spec covers three features, implemented in this order. Each one ships, is verified and is accepted on its own. It extends `docs/specs/multi-market-universes.md` (decisions D1-D11 there apply unchanged unless this spec says otherwise) with two more Listing Markets.

| Order | `id` | `area` | `depends_on` | Summary |
| --- | --- | --- | --- | --- |
| 1 | `universe-cac40` | data | `universe-selector-ui`, `market-hours-status` | CAC 40 dated snapshot and seeder, Euronext Paris (`XPAR`) calendar, EUR, `.PA` symbols, market-hours strip entry |
| 2 | `ingestion-xlon-gbx` | ingestion | `universe-cac40` | London (`XLON`) Listing Market, calendar and schedule, and the pence (GBX) currency handling in the engine and Laravel, with no universe yet |
| 3 | `universe-ftse100` | data | `ingestion-xlon-gbx` | FTSE 100 dated snapshot and seeder, `.L` symbols, GBX, strip entry |

- `status`: `universe-cac40` accepted (2026-11-12, #101); `ingestion-xlon-gbx` accepted (2026-11-13, independent validation); `universe-ftse100` accepted (2026-11-13, independent validation; 97 members, non-public). Planned 2026-11-08 at the user's explicit request (Spanish owner): add the **CAC 40** (the request said "cac 70", read as a typo for CAC 40, the only French blue-chip index of that kind) and the **FTSE 100**.
- `source`: `feature_list.json`. Scope change recorded in `AGENTS.md` (MVP Scope Guard, 2026-11-08).
- Same model as the Nasdaq-100 / IBEX 35 / DAX work: dated offline constituent snapshot (`database/data/<slug>.csv` + `<slug>.md`), idempotent seeder on `IndexUniverseSeeder`, per-market calendar and schedule, EOD through the existing Yahoo path, non-public by default (`SCREENER_UNIVERSES_PUBLIC` unchanged), Admin preview, US-only features stay US-only.
- Evidence in this spec was gathered read-only on 2026-11-08: Wikipedia raw wikitext, Euronext / LSEG / FTSE Russell pages and a live Yahoo chart request per constituent (details per feature).

## Goal

Let a Screener user choose **CAC 40** (Euronext Paris, EUR) and **FTSE 100** (London Stock Exchange, quoted in pence) in the universe selector, once the owner makes them public. Both get the same daily data, Indicator Snapshots, Signals, Chartist Patterns, Candidate table, chart, Watchlist and alerts as the existing European universes, updated after their own session close and following their own calendar.

## Non-Goals

- Everything in the Non-Goals of `multi-market-universes.md` still holds: no intraday data, no new paid vendor, no index levels (`^FCHI`, `^FTSE`) or index charts, no European Market Context, no European fundamentals, no AI Company Card or forecast outside the US, no change to the natural-language Screener schema.
- **No currency conversion and no FX rates.** In particular, FTSE 100 lines quoted in USD or EUR are not converted (see decision P2).
- No other Euronext markets (AEX, BEL 20, PSI) and no other UK index (FTSE 250). ArcelorMittal's Amsterdam line is allowed in the CAC 40 only as a documented exception (C3), not as a new market.
- No auction or pre/post-market modelling in the market-hours strip.
- No change to the S&P 500, Nasdaq-100, IBEX 35 or DAX datasets.

## Job Story

When I look for technical setups in French or British blue chips,
I want to run the same Screener on the CAC 40 or the FTSE 100,
so I can find Candidates there without leaving Chartiko.

## Users And Permissions

Unchanged from `multi-market-universes.md`. Both universes are **non-public by default**: only Admins see them (preview, D9) until the owner adds `cac40` / `ftse100` to `SCREENER_UNIVERSES_PUBLIC` after a verified production run. Authorization stays server-side (404 for hidden universes). The kill-switch procedure in `deploy/README.md` applies to them.

## Domain Decisions

**C1. Listing Markets (extends D1).** The closed list grows from three to five codes:

| Code | Exchange | Timezone | Open / close (local) | Currency | Ticker suffix | Public label es / en |
| --- | --- | --- | --- | --- | --- | --- |
| `XPAR` | Euronext Paris | Europe/Paris | 09:00 / 17:30 | EUR | `.PA` | Bolsa de París (Euronext) / Euronext Paris |
| `XLON` | London Stock Exchange | Europe/London | 08:00 / 16:30 | **GBX** | `.L` | Bolsa de Londres / London Stock Exchange |

Existing rows stay as they are. The label uses the exchange name and avoids a country-only label, like `Xetra`.

**C2. Ticker identity and collision rules (extends D2).**
- Tickers carry the Yahoo suffix and stay globally unique: `AIR.PA`, `SAN.PA`, `SHEL.L`, `BT-A.L`. `.PA` is exactly `XPAR`, `.L` is exactly `XLON`, and a US ticker, `.MC`, `.DE` or any other market's ticker never ends in them. `ListingMarket::tickerMatches`, the engine `symbol_matches_market` and the seeders all enforce it.
- Yahoo writes a class-share dot as a hyphen in London symbols (`BT.A` on the LSE is `BT-A.L`). The ticker is stored verbatim as Yahoo accepts it, and the engine does not rewrite European symbols (`yahoo_symbol` already returns non-US tickers unchanged).
- Same-name tickers in different markets are different Instruments (`SAN.PA` Sanofi vs `SAN.MC` Banco Santander; `MC.PA` LVMH). Same company on two exchanges is also two Instruments with their own bars, never merged (D3 only merges inside one market): Airbus `AIR.PA` (CAC 40) vs `AIR.DE` (DAX), ArcelorMittal `MT.AS`/`MTS.MC`, International Airlines Group `IAG.L` vs `IAG.MC`. A cross-universe test asserts these stay separate.
- The suffix test needs a US-collision check: the committed `sp500.csv` and `nasdaq100.csv` must contain no ticker ending in `.L`, `.PA` or `.AS` (verified in the seeder test, so a future S&P addition fails loudly).

**C3. ArcelorMittal exception (CAC 40).** Yahoo has no Paris line for ArcelorMittal: `MT.PA` returns no data; `MT.AS` (Euronext Amsterdam, EUR, Europe/Amsterdam, 511 sessions) works, and `MTS.MC` is the IBEX 35 line. Euronext runs one calendar for Amsterdam and Paris, so the schedule and holidays are the same. Recommended default (owner decision O1): keep the CAC 40 at 40 members by allowing `.AS` as a second suffix of `XPAR`, declared in `ListingMarket` (`extra_suffixes`) and listed as a documented exception in `cac40.md`; no other `.AS` ticker is allowed in this universe. Alternative: seed 39 members and say so.

**C4. Sector mapping and country (extends D7).** `sector` is one of the 11 GICS names in `ScreenerFilterParser::SECTORS`, an editorial mapping listed per ticker in the `.md`. `industry` keeps the source's text verbatim. `country` is the ISO-3166 alpha-2 **headquarters** country, not the listing country.
- CAC 40: Wikipedia's table gives a "GICS Sub-Industry" column and a Yahoo-style sector. The mapping uses the sub-industry: e.g. Accor, Kering, Hermes, LVMH, Michelin, Renault, Stellantis to Consumer Discretionary; Carrefour, Danone, L'Oreal, Pernod Ricard to Consumer Staples; Capgemini, Dassault Systemes, STMicroelectronics to Information Technology; EssilorLuxottica, Eurofins, Sanofi to Health Care; Orange, Publicis to Communication Services; Engie, Veolia to Utilities; Unibail-Rodamco-Westfield to Real Estate; TotalEnergies to Energy; Air Liquide, ArcelorMittal to Materials; banks, insurers and Euronext to Financials; the rest to Industrials. Country is FR except ArcelorMittal LU, Eurofins LU, Airbus NL (as in `dax.md`), Stellantis NL and STMicroelectronics CH (uncertain: Geneva headquarters, Dutch registration), flagged in `cac40.md`.
- FTSE 100: the source's classification is **ICB** industry text (Wikipedia column, FTSE Russell). It maps to GICS by rule, not by dataset: Banks, Life/Non-life Insurance, Investment Banking and Brokerage, Finance and Credit Services, Closed End Investments (Alliance Witan, F&C, Scottish Mortgage, Polar Capital Technology, Pershing Square) to Financials; Real Estate Investment Trusts to Real Estate; Non-Renewable Energy to Energy; Industrial Metals/Precious Metals and Mining, Chemicals to Materials; Pharmaceuticals/Biotechnology and Medical Equipment to Health Care; Software and Computer Services to Information Technology (RELX, Sage, Autotrader, Computacenter: the last two uncertain); Telecommunications Service Providers and Media to Communication Services; Electricity and Gas, Water and Multi-utilities to Utilities; Food Producers, Beverages, Tobacco, Personal Care/Drug/Grocery Stores to Consumer Staples; Travel and Leisure, Retailers, Household Goods, Personal Goods, Leisure Goods, Consumer Services to Consumer Discretionary; Aerospace and Defense, Industrial Support/Engineering, General Industrials, Construction and Materials, Electronic and Electrical Equipment to Industrials. Genuinely uncertain ones (Compass, Games Workshop, Metlen, Computacenter, Lion Finance, the investment trusts) are flagged in `ftse100.md`.
- Country is GB except where the headquarters or registration differs and is recorded in the `.md` (examples: Coca-Cola HBC CH, DCC IE, Glencore JE, Hiscox BM, Pershing Square GG, Lion Finance GE-vs-GB uncertain, Metlen GR-vs-GB uncertain, International Airlines Group ES-vs-GB uncertain). Unknown stays null.

**C5. Calendars and schedule (extends Feature 2 of the umbrella).**
- `config/ingestion.php` gains `markets.XPAR` and `markets.XLON` with `timezone`, `open`, `close`, `buffer_minutes` (default 45, the EU convention that covers the closing auction and source publication), `holidays` and `early_closes`, each with a source URL and a "refresh annually" note. Env overrides follow the `INGESTION_<CODE>_*` pattern.
- **XPAR (Euronext Paris).** Close 17:30; the closing auction runs to 17:35 and is not modelled. Pipeline: 17:30 + 45 = **18:15 Europe/Paris**. Euronext's holiday page (`live.euronext.com/en/resources/trading-hours-holidays`, fetched 2026-11-08) lists the cash-market closures: 2026 = 1 Jan, 3 Apr (Good Friday), 6 Apr (Easter Monday), 1 May, 25 Dec; 2027 = 1 Jan, 26 Mar, 29 Mar. 25 Dec 2027 and 1 May 2027 fall on weekends and are not listed. **24 and 31 December are half days, not closures** (2026: Thu 24 and Thu 31 Dec; 2027: Fri 24 and Fri 31 Dec), so they are not in `holidays` and go in `early_closes` with `14:05`. The page does not state the half-day close time; 14:05 comes from secondary sources and Euronext's older calendars, so it is **unverified** and must be confirmed against Euronext's "Cash markets trading hours" document before it is committed (if it cannot be confirmed, use 14:05 and flag it in the `.md`). The ingestion schedule ignores `early_closes`: the pipeline still runs at 18:15 on half days, well after a 14:05 close, which is harmless.
- **XLON (London).** Open 08:00, close 16:30 (closing auction to 16:35, not modelled). Pipeline: 16:30 + 45 = **17:15 Europe/London**. LSEG's business-days page (`londonstockexchange.com/equities-trading/business-days`, fetched 2026-11-08) lists closures from 31 Aug 2026: 31 Aug 2026, 25 Dec 2026, 28 Dec 2026 (substitute Boxing Day), and for 2027 1 Jan, 26 Mar, 29 Mar, 3 May, 31 May, 30 Aug, 27 Dec (substitute), 28 Dec (substitute). The earlier 2026 closures (1 Jan, 3 Apr, 6 Apr, 4 May, 25 May) were checked against gov.uk England/Wales bank holidays (`https://www.gov.uk/bank-holidays.json`) on 2026-11-10 and again on 2026-11-13; the listed 2027 dates and the 12:30 half days were confirmed on the LSE business-days page on both dates, and all 16 closures match gov.uk exactly. 1 May is a London trading day (the UK early-May holiday is the first Monday). Early closes at **12:30 London time** on 24 and 31 December (official, LSEG): 2026-12-24, 2026-12-31, 2027-12-24, 2027-12-31. London is **not** the same as Paris on bank holidays: UK-only closures (4 May, 25 May and 31 Aug 2026, 3 May, 31 May and 30 Aug 2027, 28 Dec 2026, 27/28 Dec 2027) are Paris trading days, and 26 Dec is a weekend in both years.
- **DST.** The UK and the euro-area markets (Paris, Madrid, Berlin) change clocks on the same dates (last Sunday of March and October), so London 17:15 and Paris/Madrid/Berlin 18:15 always fall at the same UTC instant. The US changes on different dates (second Sunday of March, first Sunday of November), so for about two to three weeks a year the New York/Paris offset is 5 hours instead of 6. The schedule is evaluated in each market's timezone (as today), so no fixed UTC offset is stored.
- **Lock contention.** Four European pipelines (`XMAD`, `XETR`, `XPAR`, `XLON`) now fire at the same UTC instant on common trading days, and the global lock makes them run one after another. The default wait budget is `ingestion.lock_wait_seconds` = 3600 since `universe-cac40`. Record the measured duration of one complete European pipeline before FTSE production enablement; if four runs do not fit in the wait budget, stagger with `INGESTION_<CODE>_BUFFER_MINUTES` (e.g. Paris 55, London 65). The US run (22:30 Paris time, or 21:30 in the DST gap weeks) is not affected.
- **Pre-merge dates note.** Calendar lists are committed snapshots; 2027 dates are derived or secondary-sourced and must be flagged to confirm, exactly like `XMAD` and `XETR`.

**C6. Feature applicability (extends D10).** `XPAR` and `XLON` behave like `XMAD`/`XETR` in the D10 table: Daily Bars, Indicators, Signals, Chartist Patterns, Candidate table, filters, chart, Saved Screeners, alerts, Watchlist and the natural-language Screener work; sector context filters return 422 `filter_not_available_for_universe`; Market Context, Breadth, Market Regime, SEC Fundamentals, AI Company Card and forecast stay US-only. The existing gates test `market === ListingMarket::US` / `isUsStock()`, so no new gate code is needed; the work is tests with an `XPAR` and an `XLON` fixture.

## The pence (GBX) decision

**Facts (live Yahoo requests, 2026-11-08, 2-year daily range, all 100 FTSE 100 `.L` symbols):**
- 97 symbols report `currency: "GBp"` (pence, the LSE quoting unit): e.g. AstraZeneca `AZN.L` last 13,930, Lloyds `LLOY.L` 95.88, Vodafone `VOD.L` 116.5.
- 2 report `USD` (`CPG.L` Compass Group 33.23, `IHG.L` IHG Hotels & Resorts 178.0) and 1 reports `EUR` (`MTLN.L` Metlen Energy & Metals 44.5, 321 sessions).
- No GBP (pounds) line among the 100. All 97 pence series have 502-507 sessions, `exchangeName` LSE, `Europe/London`, and a maximum day-over-day close ratio under 1.4 (no 100x scale flip in the 2-year window).
- **Existing bug the spec must fix:** `engine/app/sources/stooq.py` upper-cases the reported currency (`raw_currency.strip().upper()`), so Yahoo's `GBp` becomes `GBP`. Pence would be read as pounds if a universe were declared `GBP`.
- The Candidate table, chart and Watchlist already show the currency code beside prices (D8), so no per-currency formatting exists or is needed. There is no minimum-price, dollar-volume or turnover filter: the only price filters are `close_min`/`close_max` (range 0..1,000,000, which covers pence: the highest FTSE 100 close is about 16,000p). No UI string or filter is USD-hardcoded: `formatPrice` is plain two-decimal numbers (only its comment says USD), the `?? 'USD'` fallbacks are defaults for old rows, and the only USD-labelled strings are the US-only Market Context liquidity card.

**P1. Decision: store and show pence as quoted, with the currency code `GBX`. No arithmetic on prices.**
- `ListingMarket::XLON` currency is `GBX` (3 characters, fits `instruments.currency` char(3) and `universes.currency`; it is the market code for pence, not an ISO 4217 code, and the app never passes it to `Intl.NumberFormat` currency style). Close heading: "Cierre (GBX)" / "Close (GBX)". Close filter bounds are pence (a user types 500 for GBP 5), which is how UK platforms quote.
- The engine maps Yahoo's `GBp` and `GBX` to `GBX` (case-sensitive match **before** upper-casing, so `GBp` is never turned into `GBP`). Every other code is upper-cased as today. `GBP` (pounds) is a different code, so a pounds-quoted `.L` line fails the existing `currency_mismatch` check instead of being stored with a 100x scale error.
- **Why not normalise to pounds:** (a) dividing OHLC by 100 adds an irreversible arithmetic step with a per-symbol failure mode (Yahoo is known to return a symbol in pence while labelling it pounds, or vice versa; a wrong guess corrupts the stored series silently), whereas storing the source's own unit needs no guess; (b) precision: the display is two decimals, so Lloyds at 95.88p would show GBP 0.96 and lose 4% of resolution on prices that low (Vodafone 1.17, Centrica 1.52); (c) UK users, the LSE and every UK data site quote these stocks in pence, so 1,264.00 GBX matches what they see elsewhere; (d) it needs no new display logic, since the currency code is already shown.
- **Effects, checked one by one:** dimensionless indicators (RSI, ADX, DI, Stochastic, Bollinger %B and width, ATR %, RVOL) keep their values across pence and pounds; absolute price fields (SMA/EMA, MACD, Bollinger bands, ATR, price levels and Supertrend) scale with the quoted unit; ATR in absolute units, Pivot Breakout levels, chart axis, signal levels, pattern levels and the candidate Close are pence and are labelled GBX where the currency is shown (chart header, Candidate heading, Watchlist). Notification and e-mail texts show plain numbers as they do for EUR, which is consistent but unlabelled (not in scope to add currency there; noted as a risk). `stock-comparison` indexes prices to 100, so GBX vs EUR/USD comparisons stay valid. Close filters and the natural-language Screener use the universe's own unit; the NL prompt does not mention currency and is unchanged.
- **Scale-jump guard (part of `ingestion-xlon-gbx`):** after parsing, the engine rejects an XLON series (controlled upstream error, nothing stored) when any two consecutive closes differ by a ratio of 40 or more (in either direction), which only a pence/pound unit flip or a corrupt series produces; the existing `currency_mismatch` check catches the labelled case. The live 2-year check above found none, so the threshold has wide margin. Also reject an XLON series normalized to `GBX` (source `GBp` or `GBX`) when a bar close exceeds 1,000,000, and one with a non-positive or non-finite close. **Against stored data (Laravel):** the engine only sees one response, so a source that relabels a whole history (internally consistent, 100x off) would pass it; `InstrumentIngestor` therefore compares the incoming close on the latest stored date (or the first later date) with the stored close and fails the item with `price_scale_mismatch` (nothing stored, run `partial`) at a ratio of 40 or more. Both guards are London-only, so other markets retain their existing handling of split-adjusted history; a genuine London consolidation of 40:1 or more would also fail loudly and need an Admin decision.

**P2. Non-pence primary lines (decision, owner decision O2).** Compass Group (`CPG.L`, USD), IHG (`IHG.L`, USD) and Metlen (`MTLN.L`, EUR) are quoted on the LSE in a different currency, and a universe has a single currency (D8, no FX). Recommended default: **seed 97 members**, leave the three out, and list them in `ftse100.md` under "Excluded constituents" with the Yahoo currency and the date checked. The guide text says the FTSE 100 shows the constituents quoted in pence. Alternatives: (a) a per-Instrument currency in a mixed table (rejected: breaks D8 and the Close column); (b) converting with FX (out of scope). The `currency_mismatch` guard remains as a runtime backstop if Yahoo later changes a symbol's currency; the Admin run then reports it as a failed item.

---

## Feature 1 — `universe-cac40`

### Scope

1. **Engine (`engine/app/sources/stooq.py`, `engine/app/main.py`).** Add `XPAR` (Europe/Paris, suffix `.PA`, extra suffix `.AS` for the ArcelorMittal exception, decision C3) to `MARKET_TIMEZONES`, `MARKET_SUFFIXES` and the `market` `Literal`. No Stooq fallback (as for `XMAD`/`XETR`; the source note says so).
2. **Laravel.** `ListingMarket::XPAR` with timezone, open 09:00, close 17:30, EUR, suffix `.PA` and `extra_suffixes` support in `tickerMatches`; `config/ingestion.php` `markets.XPAR` with the real 2026/2027 holidays and the half days (C5); `PipelineSchedule` needs no change (it iterates `ListingMarket::codes()`); raise `lock_wait_seconds` default to 3600 (C5) and cover it in the `.env.example`/config comment.
3. **Snapshot.** `database/data/cac40.csv` (header `ticker,company,sector,industry,country,exchange`; exchange `PAR`, the Yahoo exchange code) and `cac40.md` with source URLs, `as_of`, SHA-256 of the raw sources, transform, per-ticker sector/country table, the exceptions and the rebalance procedure. Review cadence: Euronext quarterly steering committee (March, June, September annual, December); the next scheduled review after the as-of date is December 2026.
4. **Seeder.** `CacUniverseSeeder` extending `IndexUniverseSeeder`: slug `cac40`, name "CAC 40", `XPAR`, EUR, position 4. Registered in `DatabaseSeeder` and `deploy/deploy.sh` (after `DaxUniverseSeeder`), non-public by default.
5. **Frontend.** `ListingMarketCode` gains `XPAR`; `markets` and the market label maps in `es.ts`/`en.ts` (both places: the strip names and the universe/market label block) gain `XPAR`; the user guide (es/en) mentions the CAC 40 (about 40 Euronext Paris stocks, priced in euros, updated from 6:15 pm Paris time on Euronext trading days) where the selector offers it, in the same paragraphs that mention the IBEX 35 and DAX and in the "which stocks" FAQ answer. Home/meta copy unchanged until public. The market-hours strip needs no new component: `GET /api/markets/status` iterates `ListingMarket::codes()` and shows the markets of visible universes, so the CAC 40 appears for Admins now and for everyone once public; the strip is checked with five markets for overflow in the browser pass.
6. **Docs.** `deploy/README.md` rollout item (seeder runs on deploy, the `--market=XPAR` run, verification, public enablement, annual holiday refresh, kill-switch), `ARCHITECTURE.md` market list, `CONTEXT.md`/`docs/domain-model.md` Listing Market table.

### Constituents (verified 2026-11-08)

- **40 companies.** Base list: Wikipedia "CAC 40", components table "as of the quarterly update effective December 2025" (<https://en.wikipedia.org/wiki/CAC_40>), raw wikitext fetched 2026-11-08, SHA-256 `b422996cdaa72131e1334f61f401a05a1b0548d95a65b2571e4211f1a8ff698f`. It gives company, sector, GICS sub-industry and the Yahoo-style ticker for each row (the source of the `industry` text).
- Official check: Euronext's press releases state **no changes in the composition of the CAC 40** in the March 2026, June 2026 (effective 22 June) and September 2026 annual review (changes effective 21 September affect only the other CAC-family indices) (<https://www.euronext.com/en/about/media/euronext-press-releases/euronext-announces-2026-annual-review-results-cacr-family>, the March and June releases at the same path). The December 2025 composition therefore holds at 2026-11-08. Not verified: off-cycle replacements between reviews (none found), and the composition as published by Euronext itself (its index page is dynamic; Wikipedia is the ticker source).
- Live Yahoo check, 2026-11-08: 39 of the 40 Wikipedia tickers return EUR daily bars on `.PA` (511 sessions each, exchange `PAR`, `Europe/Paris`). The exception is ArcelorMittal: Wikipedia lists `MT.AS`, `MT.PA` returns no data, `MT.AS` works (EUR, `Europe/Amsterdam`). Wikipedia lists Stellantis as `STLAP.PA` and STMicroelectronics as `STMPA.PA`; both resolve.
- Cross-list notes: Airbus is `AIR.PA` here and `AIR.DE` in the DAX (two Instruments, C2); ArcelorMittal `MT.AS` here and `MTS.MC` in the IBEX 35.

### Acceptance

- Seeder test on the committed CSV: 40 rows (39 `.PA` + `MT.AS`), market `XPAR`, EUR, slug `cac40`, valid GICS sector names and ISO countries, sorted, hash matches the `.md`, idempotent, S&P 500/Nasdaq-100/IBEX/DAX untouched, non-public by default with Admin preview, no S&P/Nasdaq ticker collides with the suffix rules, `AIR.PA` and `AIR.DE` are separate Instruments.
- Suffix tests (PHP and engine): `SAN.PA` is `XPAR` only, `SAN.PA` with `market=XMAD` returns 422, `MT.AS` is accepted only for `XPAR`, `.AS` on any other market is rejected.
- Calendar tests: `XPAR` holidays are weekdays; the half days 24/31 Dec are trading days with a 14:05 early close; a UK-only holiday is an `XPAR` trading day and a Paris holiday that is a US trading day (and the reverse) is skipped only by its own market; `latestSession` before/after 18:15 across the CET/CEST change; schedule test shows the 18:15 Europe/Paris event and none when no `cac40` universe is seeded.
- Engine fixture: a trimmed real `SAN.PA` Yahoo response (EUR, Europe/Paris, midnight timestamps) yields correct session dates with no weekend dates and none on the Euronext closures.
- Lock test: two European pipelines at the same time both complete within `lock_wait_seconds` with a fake lock.
- Market status API and strip tests list five markets only when their universes are visible; Vitest for the strip and selector with `XPAR`; es/en complete.
- **Live smoke (no browser):** `GET /eod/SAN.PA?market=XPAR` and `/eod/MT.AS?market=XPAR` on the running engine return about 2 years of EUR bars with weekday session dates; a local `ingestion:pipeline --market=XPAR --force` ingests the 40 members (failures, if any, noted) and computes indicators, Signals and patterns. **Holiday-week check:** confirm the stored series for `SAN.PA` has no bar on 2026-04-03, 2026-04-06 and 2026-05-01 and does have bars on the UK-only closures 2026-05-04, 2026-05-25 and 2026-08-31; and, in a unit test with a frozen clock, that the week of 24-28 Dec 2026 yields Paris sessions on 24 (half day) and 28 Dec and none on 25 Dec.
- **Browser pass (needed, but only for layout):** the strip with five markets at 390 px and 1440 px, light and dark, no horizontal overflow; the Admin sees the `cac40` option and EUR Candidates.
- `.\init.ps1` passes. CI `test-pgsql` passes.

### Risks

- Licensing and trademarks: same owner-accepted class as R1/R2 of the umbrella; Euronext licenses its market data and the CAC 40 name. The kill-switch applies.
- Half-day close time 14:05 and the 2027 calendar are not officially confirmed (C5).
- The `.AS` exception adds a second suffix to the rule; keep it explicit and tested.
- Four pipelines at one instant (C5).
- Yahoo single-venue volume for Paris understates absolute volume (RVOL is relative).

### Not in scope

Public enablement (a separate, recorded owner step), index charts, other Euronext markets, Euronext Growth, any fundamentals.

---

## Feature 2 — `ingestion-xlon-gbx`

**Implementation notes (2026-11-13).** Scale behavior, stated precisely: dimensionless indicators (RSI, ADX/DI, Stochastic, Bollinger %B and width, ATR %, RVOL), OBV and the Supertrend direction are identical after dividing prices by 100; price-valued fields (SMA/EMA, MACD, Bollinger bands, ATR, Supertrend level, price levels) scale by 100 (asserted field by field on the real AZN.L bars). The scale guards are scoped to `XLON`. Fixtures: Yahoo chart endpoint (`range=2y&interval=1d`) captured 2026-11-10 and trimmed to symbol/currency/exchange/timezone, timestamps and original OHLCV (no adjusted values, no rescaling): `engine/tests/fixtures/yahoo_azn_l_gbp.json` (505 sessions; raw SHA-256 `5a27cf17b1ab6ade2dbb378c64594a9d006f60b11238ea7d9f326296548b801a`; re-checked 2026-11-13: all 503 overlapping closes identical to a live fetch), `yahoo_bt_a_l_gbp.json` (`8b826cf57bee89da91cc5c4541b79bc6519b672f7df8f43cb3ceee3fdd445e31`) and `yahoo_san_pa_uk_holidays.json` (`0b6a43620ce278290ca02e7e59c60a842b839e43a1a5f2da82d6be31db67c923`), both trimmed to UK bank-holiday windows; the Laravel pipeline fixture `tests/fixtures/universes/london_pipeline.json` holds 210 AZN.L bars and one engine snapshot. The SPA gains the `XLON` market code and labels (`ListingMarketCode`, es/en "Bolsa de Londres" / "London Stock Exchange") now, so the Close heading, filter chips and chart header are tested with a fixture; nothing shows them until a London universe is seeded.

### Scope

1. `ListingMarket::XLON`: Europe/London, open 08:00, close 16:30, currency `GBX`, suffix `.L`; engine `MARKET_TIMEZONES`/`MARKET_SUFFIXES`/`Literal` gain `XLON`.
2. **Currency handling (decision P1).** Engine: map Yahoo `GBp`/`GBX` to `GBX` before upper-casing; keep every other code upper-cased; add the scale-jump guard (ratio 40 or more between consecutive closes, and the 1,000,000 cap). Laravel: `InstrumentIngestor` compares the engine's `GBX` with the Instrument's currency exactly as today (so `GBP`, `USD`, `EUR` on a GBX Instrument fail with `currency_mismatch` and store nothing). `universes.currency`/`instruments.currency` accept `GBX` (char(3), no migration).
3. **Calendar and schedule (C5).** `config/ingestion.php` `markets.XLON` with the committed holidays (2026 and 2027) and the 12:30 early closes; schedule 17:15 Europe/London.
4. **No universe, no public surface.** This feature seeds nothing, so the strip and the schedule do not show London yet (they depend on a seeded Screener universe). It exists so the currency and calendar behavior is reviewed and accepted before 100 symbols depend on it.
5. `lock_wait_seconds` and the four-pipeline evidence from C5 are verified here if `universe-cac40` has not already covered them.

### Acceptance

- Engine tests with recorded fixtures (no network): a real `AZN.L` response (`GBp`, Europe/London) returns currency `GBX` and unchanged prices; a response labelled `GBP` returns `GBP`; a synthetic series with a 100x step is rejected; a `BT-A.L` response keeps its symbol; `AZN.L` with `market=XPAR` returns 422; `AZN.L` without `market` behaves as before for US (422, not a US symbol).
- A `currency_mismatch` item for a `GBP`/`USD`/`EUR` response on a GBX Instrument fails cleanly, stores no bars, and the run is `partial`.
- Calendar tests: every `XLON` holiday is a weekday; UK-only holidays (4 May 2026, 31 Aug 2026, 28 Dec 2026, 3 May 2027) are skipped only by `XLON`; the 12:30 early close on 24/31 Dec; `latestSession` before and after 17:15 London across the GMT/BST changes and in the weeks where the US is on a different offset; schedule test shows the 17:15 Europe/London event only when an `XLON` universe is seeded (fixture).
- A fixture `XLON` universe through a pipeline run stores pence values, computes indicators equal (within tolerance) to the same series divided by 100 (scale-invariance check), and shows GBX in the Candidate payload `currency`.
- Frontend: Close heading "Cierre (GBX)" / "Close (GBX)", Close filter chips and the chart header show GBX with a fixture; no hardcoded strings.
- **Live smoke:** `GET /eod/AZN.L?market=XLON` and `/eod/BT-A.L?market=XLON` return about 2 years of `GBX` bars with weekday session dates and no bar on 2026-05-04, 2026-05-25 or 2026-08-31 (UK bank holidays) but bars on those days for `SAN.PA`. No browser needed.
- `.\init.ps1` passes. CI `test-pgsql` passes.

### Risks

- Yahoo could relabel a symbol's currency (pence to pounds) without notice; the guard and the mismatch check stop it loudly rather than storing wrong data, at the cost of a failed item.
- `GBX` is not an ISO 4217 code; any future code path that feeds it to `Intl.NumberFormat` currency style or to an FX service would break. The current app does neither; the Close column stays a plain number.
- Notification and e-mail texts show unlabelled numbers (as they do for EUR).

### Not in scope

Currency conversion, pounds display toggle, FX rates, per-Instrument currencies in one table.

---

## Feature 3 — `universe-ftse100`

**Implementation notes (2026-11-13).** Owner defaults accepted: O2 (97 members; `CPG.L`, `IHG.L`, `MTLN.L` excluded and listed in `ftse100.md`), O3 (pence as `GBX`), O4 (non-public), O5 (labels). The Wikipedia revision live on 2026-11-08 (`oldid=1384229503`) reproduces the spec hash `9acd8ddd...`; the current revision (2026-11-13) has an identical constituents table. Live check 2026-11-13 through the local engine: 97 `GBX`, 502-507 sessions, no weekend or England/Wales bank-holiday bar, largest day-over-day ratio 1.344; the three excluded still report USD/USD/EUR. Editorial choices beyond the C4 rule: the airlines `IAG.L` and `EZJ.L` are Industrials (GICS airlines, same as `IAG.MC`), and the headquarters rule gives Glencore `CH` (Baar) rather than the registration `JE` named as an example in C4; Experian `IE` (Dublin) and Fresnillo `MX` (Mexico City) follow the same rule. Local pipeline on a scratch SQLite database: first run 124 s (ingestion 54 s, indicators 56 s, Signals 7 s, patterns 6 s), second full run 160 s; 49,163 bars, all `GBX`, none on weekends or UK bank holidays.

### Scope

1. **Snapshot.** `database/data/ftse100.csv` and `ftse100.md`: tickers with the `.L` suffix (`BT-A.L` for BT Group), exchange `LSE`, sector by the ICB-to-GICS rule (C4), `industry` the ICB text verbatim, country by the HQ rule. The `.md` carries the source, `as_of`, hashes, the per-ticker table, the **Excluded constituents** section (decision P2), the duplicates note (below) and the rebalance procedure. Review cadence: FTSE Russell quarterly review (March, June, September, December: announced the first Wednesday of the month, effective after the close of the third Friday), plus ad hoc deletions for takeovers or delistings, filled from the reserve list (for example Beazley left on 1 October 2026).
2. **Seeder.** `Ftse100UniverseSeeder`: slug `ftse100`, name "FTSE 100", `XLON`, GBX, position 5; in `DatabaseSeeder` and `deploy/deploy.sh`; non-public by default.
3. **Frontend/docs.** `ListingMarketCode` gains `XLON`; `markets`/label maps in es/en; user guide (es/en) mentions the FTSE 100 (about 100 London Stock Exchange stocks, those quoted in pence, prices in pence (GBX), updated from 5:15 pm London time on London trading days); `deploy/README.md` rollout item; `ARCHITECTURE.md`, `CONTEXT.md`, `docs/domain-model.md` lists.

### Constituents (verified 2026-11-08)

- **100 companies in the index, 97 seeded.** Base list: Wikipedia "FTSE 100 Index", constituents table "after the changes on 1 October 2026" (<https://en.wikipedia.org/wiki/FTSE_100_Index>), raw wikitext fetched 2026-11-08, SHA-256 `9acd8ddd9746050749cb76111955aebb7d463fc6c912fc662c4d67476004d7d9`: 100 rows with company, ticker, ISIN and ICB industry.
- Official check: FTSE Russell / LSEG announced on 2 September 2026 that **easyJet and Ithaca Energy join and Entain and Persimmon leave** the FTSE 100, effective 21 September 2026 (<https://www.lseg.com/en/media-centre/press-releases/2026/ftse-uk-index-series-review-september-2026>); both are in Wikipedia's table. Beazley was deleted on 1 October 2026 (the table's own header cites the FTSE Russell notice). Not verified: the full ordinary changes of the March and June 2026 reviews (Wikipedia only), any deletion after 1 October 2026, and the composition as published by FTSE Russell's own factsheet (a dynamic download).
- Live Yahoo check, 2026-11-08: all 100 `.L` symbols resolve; the 97 `GBp` ones (decision P1) are seeded; `CPG.L` (USD), `IHG.L` (USD) and `MTLN.L` (EUR) are excluded by P2. `BT.A` is `BT-A.L`; everything else is the Wikipedia ticker plus `.L`.
- **Duplicates and share classes:** none in the 100 today (BT Group is the only dotted class ticker; no company has two lines in the index). Cross-listed companies are separate Instruments elsewhere (C2): International Airlines Group `IAG.L` vs `IAG.MC`. Five investment trusts (Alliance Witan, F&C Investment Trust, Scottish Mortgage, Polar Capital Technology, Pershing Square) are in the index and seeded as Financials with their trust `industry` text; they are valid stocks for technical screening but have no operating-company fundamentals (none are used outside the US).

### Acceptance

- Seeder test on the committed CSV: 97 rows, every ticker ending in `.L`, market `XLON`, currency `GBX`, slug `ftse100`, valid GICS sectors and ISO countries, sorted, hash matches the `.md`, idempotent, the three excluded tickers absent and listed in the `.md`, other universes untouched, non-public by default with Admin preview; public labels say "FTSE 100".
- A pipeline run (`--market=XLON --force` locally) ingests the 97 members (failures noted), computes indicators, Signals and patterns; Candidates appear for an Admin with Close in GBX; the strip shows London for an Admin.
- The production schedule shows the 17:15 Europe/London event (`php artisan schedule:list`).
- **Live smoke (no browser):** the 97 symbols fetched with `market=XLON` all return `GBX`, 500+ sessions, weekday dates; a recorded trimmed fixture for one stock around a UK-only bank holiday. **Holiday-week check** as in `ingestion-xlon-gbx` (UK bank holidays absent, Paris present); and in a frozen-clock test the week of 24-31 Dec 2026: London 24 Dec half day, 25 and 28 Dec closed, 29-30 Dec open, 31 Dec half day.
- **Browser pass (needed):** selector with six universes for an Admin, Candidate table and filter chips in GBX, chart header "LSE · GBX", strip with five markets at 390 px, light and dark; no horizontal overflow.
- Public enablement (`ftse100` in `SCREENER_UNIVERSES_PUBLIC`) is a separate owner step after a verified production run, recorded in `PROGRESS.md`.
- `.\init.ps1` passes. CI `test-pgsql` passes.

### Risks

- Licensing and trademarks: FTSE Russell owns the "FTSE" mark and the index data; Yahoo's LSE data is unofficial. Same owner-accepted class as R1/R2; the kill-switch applies. The index name is used only to describe the constituent list.
- Constituent drift: ad hoc deletions happen between reviews (Beazley); a stale snapshot has no automatic detection.
- Seeding 97 instead of 100 can look like an error; the guide text and the `.md` state it.
- Pence labelling in alert texts (see `ingestion-xlon-gbx` risks).

### Not in scope

FTSE 250 or other UK indices, AIM, London-listed ETFs/trusts outside the index, currency conversion, index levels, UK fundamentals (Companies House), public enablement.

---

## Repository Research (inspected 2026-11-08)

- `app/Support/ListingMarket.php`: closed list `US`, `XMAD`, `XETR` with `timezone/open/close/currency/suffix`; `tickerMatches` rejects a ticker that ends in another market's suffix. Needs `XPAR`, `XLON` and the `.AS` exception.
- `engine/app/sources/stooq.py`: `MARKET_TIMEZONES`, `MARKET_SUFFIXES`, `symbol_matches_market`, `yahoo_symbol` (non-US verbatim), `parse_yahoo_eod` (currency upper-cased: the `GBp` bug), `engine/app/main.py` `market: Literal["US","XMAD","XETR"]`.
- `app/Services/Ingestion/InstrumentIngestor.php`: `currency_mismatch` guard compares the engine currency to `instruments.currency`.
- `config/ingestion.php`: `markets.XMAD`/`markets.XETR` pattern (timezone, open, close, buffer 45, holidays, early_closes); `lock_wait_seconds` 900.
- `app/Services/Market/MarketCalendar.php`, `PipelineSchedule.php` (iterates `ListingMarket::codes()` filtered by seeded universes), `MarketHoursStatus.php`, `app/Http/Controllers/MarketStatusController.php` (markets of visible universes, in `codes()` order): no code change beyond the new codes.
- `database/seeders/IndexUniverseSeeder.php` and `DaxUniverseSeeder.php`: the new seeders only name slug, name, market and position. `deploy/deploy.sh` seeds each index seeder; `deploy/README.md` items 13 (IBEX 35) and 17 (DAX) are the rollout template.
- US/market gates: `Instrument::isUsStock()`, `ScreenerRowBuilder` (sector context only for `US`), `ScreenerController`/`SavedScreenerController`/`ScreenerInterpretController` (422 outside `US`), `InstrumentController` availability: all test `ListingMarket::US`.
- Frontend: `frontend/src/lib/api.ts` `ListingMarketCode = 'US' | 'XMAD' | 'XETR'`; `frontend/src/i18n/messages/{es,en}.ts` market label maps (two places each); `frontend/src/i18n/guide/{es,en}.ts` universe and schedule paragraphs and the "which stocks" FAQ; `frontend/src/lib/screenerColumns.ts` `columnLabel` adds the currency code to the Close heading; `frontend/src/i18n/format.ts` `formatPrice` is plain two decimals (comment mentions USD only).
- Tests that list markets and need new cases: `tests/Feature/MultiMarketIngestionTest.php`, `MarketStatusApiTest.php`, `UniverseRegistryTest.php`, `tests/Unit/MarketCalendarTest.php`, `MarketHoursStatusTest.php`, `engine/tests/test_stooq.py`, and the frontend `MarketHoursStrip`, `UniverseSelector`, `ScreenerUniverse`, `screenerUniverse` tests. The model seeder tests are `tests/Feature/DaxUniverseSeederTest.php` and `Ibex35UniverseSeederTest.php`.
- Not inspected in detail: the alert e-mail templates (no currency usage found by grep), the Saved Screener payload with the new universe slugs beyond the registry test, and the Admin freshness panel strings (they use the universe's own currency).

## Durable Documentation Impact

- `AGENTS.md`: updated now (scope guard and out-of-scope line). `CONTEXT.md`, `docs/domain-model.md`, `docs/risks-and-open-questions.md`, `docs/specs/multi-market-universes.md`: pointer lines now; the market tables are updated by each implementing feature.
- `ARCHITECTURE.md`: per-market list and the `GBX` rule (in `ingestion-xlon-gbx`). `deploy/README.md`: one item per universe plus the lock-wait change. `CONSTRAINTS.md`: not needed. `DESIGN.md`: only if the strip layout changes for five markets.

## Risks

- **R1 Data licensing** (Euronext, LSEG/FTSE Russell, unofficial Yahoo): owner-accepted class from 2026-10-26; the kill-switch applies; both universes start non-public.
- **R2 Index trademarks** ("CAC 40", "FTSE 100"): names used only to describe lists; review with the legal notice.
- **R3 Calendars**: five holiday lists to refresh yearly; 2027 Euronext and LSE entries partly derived; the Euronext half-day close time is unverified.
- **R4 Pence**: relabelling or scale flips by the source; mitigated by the guard and the mismatch check.
- **R5 Constituent drift**: stale snapshots, ad hoc deletions.
- **R6 Volume and time**: about 40 plus 97 extra fetches a day, four European pipelines at one instant (C5).

## Open Questions (owner), with the recommended default

- **O1. ArcelorMittal in the CAC 40** (no Paris line on Yahoo). Default: include it through `MT.AS` with a documented `.AS` exception (40 members). Alternative: 39 members.
- **O2. FTSE 100 lines not quoted in pence** (Compass Group and IHG in USD, Metlen in EUR). Default: leave them out (97 members) and say so. Alternative: none that respects "no FX, one currency per universe".
- **O3. Pence vs pounds.** Default: store and show pence as GBX (P1). Alternative: normalise to GBP (not recommended: precision loss, silent-scale risk).
- **O4. Public enablement.** Default: both stay non-public until the owner decides after a verified production run, as for IBEX 35 and DAX.
- **O5. Market labels.** Default: "Euronext Paris" / "London Stock Exchange" (es: "Bolsa de París (Euronext)", "Bolsa de Londres").

## Validator Checklist (all features)

- Default S&P 500 behavior, payloads (apart from added fields) and existing tests are unchanged; the other four universes are untouched.
- No duplicate Instruments, bars or Screener rows; same-company cross-listings are separate Instruments (`AIR.PA`/`AIR.DE`, `IAG.L`/`IAG.MC`).
- Suffix rules enforced in PHP and in the engine; `GBp` is never read as `GBP`; a `GBP`/`USD`/`EUR` line on a GBX Instrument fails with `currency_mismatch` and stores nothing.
- Universe access is enforced server-side (404 for hidden universes); sector context filters return 422 outside `US`; US-only features unreachable for `XPAR`/`XLON`.
- Bar dates are exchange-timezone session dates; each market's calendar and schedule are independent; UK-only and Paris-only holidays are respected by their own market only.
- No EOD wording in public UI; es/en complete; DESIGN.md respected; no hardcoded strings.
- Evidence recorded in `feature_list.json`, and `.\init.ps1` passes.
