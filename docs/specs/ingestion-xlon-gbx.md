# London Listing Market and pence handling

Feature `ingestion-xlon-gbx`; dependency `universe-cac40` is accepted. Implementation requested 2026-11-10. This is Feature 2 of `universes-cac40-ftse100.md`; its C1/C2/C5/C6/P1 decisions apply. Status: in_progress.

## Contract

1. Add XLON to ListingMarket, engine suffix/timezone maps and EOD market enum: Europe/London, 08:00-16:30, GBX, .L. Preserve hyphenated class tickers (BT-A.L). Reject cross-market symbols (422) and keep US defaults. No seeder, FTSE universe, public flag, new vendor or migration.
2. Normalize case-sensitive Yahoo GBp and GBX to GBX before uppercasing other currency codes; GBP remains GBP. Never multiply/divide stored OHLC. For XLON only, reject nonfinite/nonpositive closes, any consecutive-close max/min ratio >=40, and any GBX close >1,000,000. The London-only scope preserves other markets' existing split behavior. Failure is a controlled engine 502 with no Stooq fallback. Laravel's existing GBP/USD/EUR-versus-GBX currency mismatch stops storage for that item and permits a partial run.
3. London holidays 2026/27: England/Wales bank holidays. Early closes 24/31 Dec at12:30 London time. Pipeline17:15 Europe/London, including half days; no event or strip until a London Screener universe exists. Existing lock wait3600 remains. Test GMT/BST, US/EU transition gaps, UK-only holidays and year-end substitution days. No live production pipeline-duration claim without a seeded London universe.
4. Existing Candidate/table/filter/chart currency labels must support GBX; add London labels es/en and ListingMarketCode. US-only panels remain gated off. No actual London universe exposed by this foundation feature.
5. Scale behavior: dimensionless indicators (RSI/ADX/DI/Stochastic/RVOL), volume-based OBV and Supertrend direction match after dividing prices by100; price-valued SMA/EMA/MACD/Bollinger/ATR/Supertrend levels scale by100. Corrects the umbrella's loose wording about indicators being identical. Test real AZN bars with every snapshot field classified; no formula changes.

## Verification

- Offline real Yahoo AZN.L GBp fixture: unchanged price values + GBX, GBP kept distinct, both directions of scale jumps, threshold boundaries, invalid closes, cap, no cross-market fallback, BT-A.L identity and cross-market422.
- Laravel fixture XLON pipeline stores pence, receives/stores indicator snapshots and exposes Candidate GBX. Currency mismatches store zero bars for failed items. Calendar/schedule/default-no-seeder cases; public-market access follows existing registry.
- SPA fixture GBX Close heading and filter chips in es/en, LSE/GBX chart header and US-only panel absence. No browser required by Feature2.
- Live engine GET smoke for AZN.L and BT-A.L; no UK May4/May25/Aug31 bars; Paris has bars on those dates. No data persistence. Full init.ps1 and PostgreSQL CI; evidence records unavailable checks without claiming acceptance.

## Source provenance (2026-11-10)

LSE official business-days page: https://www.londonstockexchange.com/en/resources/markets-products/business-days (bank holidays from Aug2026 through2027; explicitly12:30 closing process on24/31 Dec). Earlier2026 closures checked against England/Wales in https://www.gov.uk/bank-holidays.json; 8 dates in2026 and8 in2027. Refresh annually.

Yahoo chart endpoint https://query1.finance.yahoo.com/v8/finance/chart/{symbol}?range=2y&interval=1d&events=history captured2026-11-10. Raw SHA-256: AZN.L `5a27cf17b1ab6ade2dbb378c64594a9d006f60b11238ea7d9f326296548b801a`; BT-A.L `8b826cf57bee89da91cc5c4541b79bc6519b672f7df8f43cb3ceee3fdd445e31`; SAN.PA `0b6a43620ce278290ca02e7e59c60a842b839e43a1a5f2da82d6be31db67c923`. Committed fixtures retain only symbol/currency/exchange/timezone, timestamps and original OHLCV values; AZN excludes the current incomplete session, BT and Paris trim to UK bank-holiday windows. No adjusted values or rescaling. Current capture reports506 London timestamps and510 Paris timestamps. This is source/contract research, never a production ingestion run.

## Tasks

- [x] XLON definitions, source normalization and scale guard.
- [x] London calendar/env overrides and schedule coverage.
- [x] Fixture-backed pipeline, pence storage, currency mismatch and UI compatibility coverage.
- [ ] Live smoke, full verification, durable docs and handoff evidence.


Local evidence: full init.ps1 exit0 (Laravel1030 passed/6 skipped, SPA491 passed, engine185 passed, lint/typecheck/build); final added BST fallback test included in London engine26 passed. London Laravel7 passed/737 assertions; targeted frontend38 passed. Live API evidence in PROGRESS.md. PostgreSQL CI pending; no production rollout or capacity timing.
