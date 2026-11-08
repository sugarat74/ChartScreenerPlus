# CAC 40 constituent snapshot (as_of 2026-11-08)

`cac40.csv` is the committed, offline constituent list read by `CacUniverseSeeder` (slug `cac40`, name "CAC 40", Listing Market `XPAR`, EUR, selector position 4). Header: `ticker,company,sector,industry,country,exchange`. Tickers carry the Yahoo `.PA` suffix (decision C2), with one documented exception, ArcelorMittal `MT.AS` (decision C3). `exchange` is the Yahoo exchange code: `PAR` for the 39 Paris lines and `AMS` for `MT.AS`.

- `as_of`: 2026-11-08 (retrieved the same day; composition in force since the December 2025 quarterly update, unchanged by the March, June and September 2026 reviews).
- Count: **40 companies**, one security each (39 `.PA` + `MT.AS`).
- `cac40.csv` SHA-256: `f978c62a4c47bdc5ad1f0542e8e976066a12cb907eb3e0f52b53f452d1c75a6d`.

## Sources and verification

Euronext publishes the CAC 40 composition only as dynamic pages, so the snapshot is a base list plus Euronext's own review announcements:

1. Base list: Wikipedia, "CAC 40", components table "as of the quarterly update effective December 2025" (<https://en.wikipedia.org/wiki/CAC_40>). Raw wikitext fetched 2026-11-08 (`action=raw`), SHA-256 `b422996cdaa72131e1334f61f401a05a1b0548d95a65b2571e4211f1a8ff698f` (identical to the hash recorded in the spec). Source of the tickers, company names and the `industry` text (the table's "GICS Sub-Industry" column, verbatim). 40 rows.
2. Euronext press releases: no change in the composition of the CAC 40 in the March 2026, June 2026 (effective 22 June) and September 2026 annual reviews (effective 21 September; only other CAC-family indices changed) (<https://www.euronext.com/en/about/media/euronext-press-releases/euronext-announces-2026-annual-review-results-cacr-family>). The December 2025 composition therefore holds at the as-of date. Taken from the spec `docs/specs/universes-cac40-ftse100.md`, which fetched them on 2026-11-08; not re-fetched by the implementer.
3. Yahoo (live, read-only, 2026-11-08, through the project engine `GET /eod/{symbol}?market=XPAR`): all 40 symbols return EUR daily bars, 511 sessions each (2024-11-06 to 2026-11-06), no weekend date, no bar on the Euronext closures of 2026 (1 Jan, 3 Apr, 6 Apr, 1 May, 25 Dec) and a bar on the UK-only bank holidays (4 May, 25 May, 31 Aug 2026). `STLAP.PA` and `STMPA.PA` resolve; `MT.PA` returns no data, `MT.AS` works. A trimmed real `SAN.PA` response is the engine fixture `yahoo_san_pa_easter.json`.

**Not verified:**
- Off-cycle replacements between reviews (none found) and the composition as published by Euronext itself (its index page is dynamic; Wikipedia is the ticker source).
- The sector, country and `industry` labels are editorial (below).

Wikipedia's text is CC BY-SA 4.0; only the ticker, company name and sub-industry label of each row were taken. Review source terms together with the legal notice (risk R2 of the spec).

## Transform

- Rows are sorted by ticker (byte order). Tickers are the Yahoo symbols of Wikipedia's table (`CS.PA` is AXA, `EN.PA` Bouygues, `MC.PA` LVMH, `ML.PA` Michelin, `OR.PA` L'Oreal).
- `company` is the common name (legal suffixes removed).
- `industry` is the Wikipedia GICS sub-industry label for the ticker, verbatim (decision D7).
- `sector` is an **editorial GICS mapping** (one of the 11 GICS names of `ScreenerFilterParser::SECTORS`) by the rule in C4 of the spec, from the sub-industry and the company's business; not taken from a dataset. Uncertain ones are flagged below.
- `country` is the ISO 3166-1 alpha-2 **headquarters** country, not the listing country. It is FR unless noted.
- **ArcelorMittal exception (C3, owner decision O1):** `MT.AS` is the only non-`.PA` member. `ListingMarket` declares `.AS` as a second suffix of `XPAR` (`extra_suffixes`), and the engine does the same (`MARKET_EXTRA_SUFFIXES`); `.AS` is rejected for every other market and no other `.AS` ticker may be added to this universe. Euronext runs one calendar for Amsterdam and Paris, so the holidays are the same.

| Ticker | Company | `sector` (GICS, editorial) | `industry` | `country` | `exchange` | Note |
| --- | --- | --- | --- | --- | --- | --- |
| AC.PA | Accor | Consumer Discretionary | Hotels, Restaurants & Leisure | FR | PAR |  |
| ACA.PA | Crédit Agricole | Financials | Regional Banks | FR | PAR |  |
| AI.PA | Air Liquide | Materials | Industrial Gases | FR | PAR |  |
| AIR.PA | Airbus | Industrials | Aerospace & Defense | NL | PAR | Airbus SE is registered in Leiden (NL) with main operations in Toulouse (FR); **uncertain country**. Same company as the DAX `AIR.DE` but a separate Instrument (C2) |
| BN.PA | Danone | Consumer Staples | Packaged Foods & Meats | FR | PAR |  |
| BNP.PA | BNP Paribas | Financials | Diversified Banks | FR | PAR |  |
| BVI.PA | Bureau Veritas | Industrials | Research & Consulting Services | FR | PAR | Testing and certification; Industrials |
| CA.PA | Carrefour | Consumer Staples | Hypermarkets & Super Centers | FR | PAR |  |
| CAP.PA | Capgemini | Information Technology | IT Consulting & Other Services | FR | PAR |  |
| CS.PA | AXA | Financials | Life & Health Insurance | FR | PAR | AXA (Yahoo symbol `CS.PA`) |
| DG.PA | Vinci | Industrials | Construction & Engineering | FR | PAR |  |
| DSY.PA | Dassault Systèmes | Information Technology | Application Software | FR | PAR |  |
| EL.PA | EssilorLuxottica | Health Care | Apparel, Accessories & Luxury Goods | FR | PAR | Wikipedia sub-industry is "Apparel, Accessories & Luxury Goods"; mapped to Health Care (eyewear and lenses): **uncertain** |
| EN.PA | Bouygues | Industrials | Construction & Engineering | FR | PAR | Bouygues (construction, telecoms, media); Industrials by sub-industry |
| ENGI.PA | Engie | Utilities | Gas Utilities | FR | PAR | Wikipedia sub-industry "Gas Utilities"; Utilities |
| ENX.PA | Euronext | Financials | Financial Exchanges & Data | FR | PAR | Exchange operator; Financials |
| ERF.PA | Eurofins Scientific | Health Care | Biotechnologies | LU | PAR | Luxembourg-registered, main operations in Nantes (FR); **uncertain country** |
| FGR.PA | Eiffage | Industrials | Construction & Engineering | FR | PAR |  |
| GLE.PA | Société Générale | Financials | Diversified Banks | FR | PAR |  |
| HO.PA | Thales | Industrials | Aerospace & Defense | FR | PAR |  |
| KER.PA | Kering | Consumer Discretionary | Apparel, Accessories & Luxury Goods | FR | PAR |  |
| LR.PA | Legrand | Industrials | Electrical Components & Equipment | FR | PAR |  |
| MC.PA | LVMH | Consumer Discretionary | Apparel, Accessories & Luxury Goods | FR | PAR |  |
| ML.PA | Michelin | Consumer Discretionary | Tires & Rubber | FR | PAR | Tyres; GICS Consumer Discretionary (auto components), Wikipedia sector Industrials |
| MT.AS | ArcelorMittal | Materials | Steel | LU | AMS | **Exception C3**: Yahoo has no Paris line (`MT.PA` returns no data); the Euronext Amsterdam line (EUR, `Europe/Amsterdam`, Yahoo exchange `AMS`) is used. Same company as the IBEX 35 `MTS.MC`, a separate Instrument |
| OR.PA | L'Oréal | Consumer Staples | Personal Products | FR | PAR |  |
| ORA.PA | Orange | Communication Services | Integrated Telecommunication Services | FR | PAR |  |
| PUB.PA | Publicis | Communication Services | Advertising | FR | PAR | Advertising; Communication Services |
| RI.PA | Pernod Ricard | Consumer Staples | Distillers & Vintners | FR | PAR |  |
| RMS.PA | Hermès | Consumer Discretionary | Apparel, Accessories & Luxury Goods | FR | PAR |  |
| RNO.PA | Renault | Consumer Discretionary | Automobile Manufacturers | FR | PAR |  |
| SAF.PA | Safran | Industrials | Aerospace & Defense | FR | PAR |  |
| SAN.PA | Sanofi | Health Care | Pharmaceuticals | FR | PAR |  |
| SGO.PA | Saint-Gobain | Industrials | Building Products | FR | PAR |  |
| STLAP.PA | Stellantis | Consumer Discretionary | Automobile Manufacturers | NL | PAR | Dutch-registered (Amsterdam); **uncertain country**. Wikipedia ticker `STLAP.PA` resolves on Yahoo |
| STMPA.PA | STMicroelectronics | Information Technology | Semiconductors | CH | PAR | Geneva headquarters, Dutch registration; **uncertain country**. Wikipedia ticker `STMPA.PA` resolves on Yahoo |
| SU.PA | Schneider Electric | Industrials | Electrical Components & Equipment | FR | PAR |  |
| TTE.PA | TotalEnergies | Energy | Integrated Oil & Gas | FR | PAR |  |
| URW.PA | Unibail-Rodamco-Westfield | Real Estate | Retail REITs | FR | PAR |  |
| VIE.PA | Veolia | Utilities | Multi-Utilities | FR | PAR | Water and waste; Utilities (Multi-Utilities) |

Sector affects only the Sector filter of these Instruments (the sector context filters are US-only, decision D10). Worth a second look on the next rebalance: the sector of `EL`, and the country of `AIR`, `ERF`, `STLAP` and `STMPA`.

## Calendar

The Euronext holidays live in `config/ingestion.php` (`markets.XPAR.holidays`, `early_closes`). Source: Euronext, trading hours and holidays (<https://live.euronext.com/en/resources/trading-hours-holidays>, fetched 2026-11-08, SHA-256 `720c5f685ce1b503368e8939ead045e2d7cc62ea3dd51868d3ac9de32934a176`).

- 2026 cash-market closures: 1 January, 3 April (Good Friday), 6 April (Easter Monday), 1 May, 25 December. 2027: 1 January, 26 March, 29 March (1 May and 25 December 2027 fall on weekends). 2027 is on the same page but subject to change: **confirm during 2027**.
- 24 and 31 December are **half days**, not closures: trading days in `early_closes` with `14:05`. The 14:05 CET close for the Amsterdam, Brussels, Lisbon and Paris cash markets is stated on the same Euronext page (notices for 2019 and 2020); the page's 2026 and 2027 rows only say "Half Trading Day", so the time is carried over from those earlier notices. The closing auction (to 17:35) is not modelled. The ingestion schedule ignores `early_closes`: the pipeline still runs at 18:15 on half days, which is harmless.
- Cross-check: the Yahoo daily bars of all 40 members contain no session on 2026-01-01, 2026-04-03, 2026-04-06, 2026-05-01 or 2026-12-25 and do contain 2026-05-04, 2026-05-25 and 2026-08-31.
- Refresh every year (add the next year, drop the oldest).

## Behavior

- The seeder is offline and idempotent. It syncs membership to this file exactly (attaches new members, detaches removed ones, never deletes an Instrument or its history). An Instrument left in no Screener universe becomes `active = false` and is no longer ingested.
- All members are created as `XPAR`/`EUR` Instruments. No member overlaps another universe: `AIR.PA` is a different Instrument from the DAX `AIR.DE`, and `MT.AS` from the IBEX 35 `MTS.MC`.
- `ingestion:pipeline --market=XPAR` ingests it (the scheduled event fires at 18:15 Europe/Paris once the universe is seeded). Yahoo is the only source (no Stooq fallback). Volume is the Paris line only, so absolute volume understates trading; relative volume stays consistent.

## Review cadence

Euronext reviews the CAC 40 quarterly (March, June, September annual review, December), with extraordinary changes at any time. A stale snapshot is a known risk that is not detected automatically. Next scheduled review after the as-of date: December 2026.

## Rebalance procedure

1. Replace `cac40.csv` with a new dated snapshot (same header, sorted by ticker, `.PA` suffix; `MT.AS` stays the only `.AS` unless the owner decides otherwise; classify new members as above).
2. Update `as_of`, the count and the SHA-256 values in this file, and the expected rows in `tests/Feature/CacUniverseSeederTest.php`.
3. Run `php artisan db:seed --class=CacUniverseSeeder --force` (deploy runs it).
4. Record the change in `PROGRESS.md`.
