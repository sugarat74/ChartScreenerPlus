# DAX constituent snapshot (as_of 2026-10-31)

`dax.csv` is the committed, offline constituent list read by `DaxUniverseSeeder` (slug `dax`, name "DAX", Listing Market `XETR`, EUR, selector position 3). Header: `ticker,company,sector,industry,country,exchange`. Tickers carry the Yahoo `.DE` suffix (decision D2); `exchange` is `XETRA`. The label is "DAX" (the official index name; it has 40 constituents), never "DAX 40".

- `as_of`: 2026-10-31 (retrieved the same day; composition in force since 2026-06-22).
- Count: **40 companies**, one security each (ordinary or preference share on the Xetra line).
- `dax.csv` SHA-256: `b36fe267b70bb5d44722c8ce40e6aec148a02c58f5ef97cb514f70a55c9ac1a8`.

## Sources and verification

Deutsche Börse / STOXX publish the DAX composition only as dynamic pages and spreadsheets, so the snapshot is a base list plus the official STOXX change announcements since:

1. Base list: Wikipedia, "DAX", components table "as of 22 September 2025" (<https://en.wikipedia.org/wiki/DAX>). Raw wikitext fetched 2026-10-31, SHA-256 `2a114d9c8b7e9965154ea7a7e00921dee02eec247e83d8cb3ad4f1a8b896d359`. Source of the tickers, company names and the `industry` text (the table's "Prime Standard Sector" column, verbatim). 40 rows; `AIR.PA` was changed to the Xetra line `AIR.DE`.
2. STOXX press releases "STOXX announces scheduled adjustments to DAX Blue-Chip Indices", fetched 2026-10-31 (hash = SHA-256 of the fetched page):
   - 3 Dec 2025, effective 22 Dec 2025: no adjustments in DAX (<https://stoxx.com/stoxx-announces-scheduled-adjustments-to-dax-blue-chip-indices-dec-3-2025/>, `45dd6c2e9af436c5e197bf98d526c25eaba3ed9b7a1f0b9e4411d039518e27dd`).
   - 4 Mar 2026, effective 23 Mar 2026: no adjustments in DAX (<https://stoxx.com/stoxx-announces-scheduled-adjustments-to-dax-blue-chip-indices-mar-4-2026/>, `7408a0d4bc5d82ea88748c89a8a1047e5ee63ccbb6c7b856dec3d6ce53cb9965`).
   - 3 Jun 2026, effective 22 Jun 2026: **HOCHTIEF in, Porsche Automobil Holding SE out** (<https://stoxx.com/stoxx-announces-scheduled-adjustments-to-dax-blue-chip-indices-jun-3-2026/>, `2ec25553483ee7bd8cd75e502f7b7f50e15e5e8d5551a5f2ffac79916e882ee0`). Applied: `PAH3.DE` removed, `HOT.DE` added.
   - 3 Sep 2026, effective 21 Sep 2026: no adjustments in DAX; only MDAX/SDAX/TecDAX changed (<https://stoxx.com/stoxx-announces-scheduled-adjustments-to-dax-blue-chip-indices-sep-3-2026/>, `12e0247c3a5bf78a5c21c1822b99f48610193c3f8d05d2bea88d143f747a65c7`). The next scheduled review is announced on 3 December 2026.
3. Yahoo (live, read-only, 2026-10-31): all 40 `.DE` symbols return EUR daily bars (507 sessions each over 2 years, exchange `GER`, timezone `Europe/Berlin`). `SAP.DE` has no weekend dates and no session on the Xetra closures; a trimmed real response is the engine fixture `yahoo_sap_de_easter.json`.

**Not verified:**
- The ordinary reviews between the 27 Dec 2024 change (the last row of Wikipedia's "former components" table) and the 22 Sep 2025 table date (March, June, September 2025) rely on Wikipedia alone; the STOXX releases were checked only from December 2025 on.
- Off-cycle DAX changes: no other 2026 change besides Hochtief for Porsche SE was found. The Deutsche Börse Xetra circular 4993208 (6 March 2026, "extraordinary changes to the composition of the DAX Blue Chip Indices" effective 9 March 2026) only reshuffles Xetra Product Assignment Groups (Deutsche Wohnen, Schaeffler, Einhell); no DAX member is involved (checked by the independent validator, 2026-10-31).
- The sector, country and `industry` labels are editorial (below).

Wikipedia's text is CC BY-SA 4.0; only the ticker, company name and sector label of each row were taken. Review source terms together with the legal notice (risk R2 of `docs/specs/multi-market-universes.md`).

## Transform

- Rows are sorted by ticker. Tickers are Xetra codes with `.DE` appended.
- `company` is the common name (legal suffixes removed).
- `industry` is the Wikipedia sector label for the ticker, verbatim (decision D7). It is not a GICS value.
- `sector` is an **editorial GICS mapping** (one of the 11 GICS names of `ScreenerFilterParser::SECTORS`), **classified manually** from the company's business; it is not taken from a dataset. Uncertain ones are flagged below.
- `country` is the ISO 3166-1 alpha-2 **headquarters** country, not the listing country. It is DE unless noted.
- `MRK.DE` (Merck KGaA, Darmstadt) is a different company from the US `MRK` (Merck & Co.) and a different Instrument with its own bars (decision D2).

| Ticker | Company | `sector` (GICS, editorial) | `industry` | `country` | Note |
| --- | --- | --- | --- | --- | --- |
| ADS.DE | adidas | Consumer Discretionary | Apparel | DE |  |
| AIR.DE | Airbus | Industrials | Aerospace & Defence | NL | Airbus SE is registered in Leiden (NL) with main operations in Toulouse (FR); **uncertain country**. Wikipedia lists the Paris code `AIR.PA`; the Xetra line `AIR.DE` is used and resolves on Yahoo |
| ALV.DE | Allianz | Financials | Financial Services | DE |  |
| BAS.DE | BASF | Materials | Chemicals | DE |  |
| BAYN.DE | Bayer | Health Care | Pharmaceuticals | DE |  |
| BEI.DE | Beiersdorf | Consumer Staples | Consumer goods | DE |  |
| BMW.DE | BMW | Consumer Discretionary | Automotive | DE |  |
| BNR.DE | Brenntag | Industrials | Distribution | DE | Chemical distribution; GICS Trading Companies |
| CBK.DE | Commerzbank | Financials | Financial Services | DE |  |
| CON.DE | Continental | Consumer Discretionary | Automotive | DE | Tires and automotive parts |
| DB1.DE | Deutsche Börse | Financials | Financial Services | DE |  |
| DBK.DE | Deutsche Bank | Financials | Financial Services | DE |  |
| DHL.DE | Deutsche Post (DHL Group) | Industrials | Logistics | DE | Deutsche Post DHL Group; Yahoo long name "DHL AG" |
| DTE.DE | Deutsche Telekom | Communication Services | Telecommunication | DE |  |
| DTG.DE | Daimler Truck | Industrials | Automotive | DE | Trucks; GICS Machinery (Industrials) |
| ENR.DE | Siemens Energy | Industrials | Energy technology | DE | Power and grid equipment |
| EOAN.DE | E.ON | Utilities | Utilities | DE |  |
| FME.DE | Fresenius Medical Care | Health Care | Healthcare | DE |  |
| FRE.DE | Fresenius | Health Care | Healthcare | DE | Fresenius SE (hospitals, pharma) |
| G1A.DE | GEA Group | Industrials | Mechanical Engineering | DE |  |
| G24.DE | Scout24 | Communication Services | E-Commerce | DE | **Uncertain**: online property marketplaces; Communication Services (interactive media) |
| HEI.DE | Heidelberg Materials | Materials | Construction Materials | DE |  |
| HEN3.DE | Henkel | Consumer Staples | Consumer Goods | DE | Preference share |
| HNR1.DE | Hannover Re | Financials | Insurance | DE |  |
| HOT.DE | Hochtief | Industrials | Construction | DE | Added 2026-06-22 (replaced Porsche SE). `industry` is not in the Wikipedia table (Sept 2025): editorial label |
| IFX.DE | Infineon Technologies | Information Technology | Technology | DE |  |
| MBG.DE | Mercedes-Benz Group | Consumer Discretionary | Automotive | DE |  |
| MRK.DE | Merck KGaA | Health Care | Pharmaceuticals | DE | Merck KGaA (Darmstadt); not the US `MRK` (Merck & Co.) |
| MTX.DE | MTU Aero Engines | Industrials | Aerospace & Defence | DE |  |
| MUV2.DE | Munich Re | Financials | Financial Services | DE |  |
| QIA.DE | Qiagen | Health Care | Biotech | NL | Qiagen N.V. is Dutch-registered (Venlo), main operations in Hilden (DE); **uncertain country** |
| RHM.DE | Rheinmetall | Industrials | Aerospace & Defence | DE |  |
| RWE.DE | RWE | Utilities | Utilities | DE |  |
| SAP.DE | SAP | Information Technology | Technology | DE |  |
| SHL.DE | Siemens Healthineers | Health Care | Medical Equipment | DE |  |
| SIE.DE | Siemens | Industrials | Industrials | DE |  |
| SY1.DE | Symrise | Materials | Chemicals | DE | **Uncertain**: flavours and fragrances; Materials (specialty chemicals) vs Consumer Staples |
| VNA.DE | Vonovia | Real Estate | Real Estate | DE |  |
| VOW3.DE | Volkswagen Group | Consumer Discretionary | Automotive | DE | Preference share |
| ZAL.DE | Zalando | Consumer Discretionary | E-Commerce | DE |  |

Sector affects only the Sector filter of these Instruments (the sector context filters are US-only, decision D10). Worth a second look on the next rebalance: the sectors of `SY1` and `G24`, and the country of `AIR` and `QIA`.

## Calendar

The Xetra holidays live in `config/ingestion.php` (`markets.XETR.holidays`). Source: Deutsche Börse's official trading calendar page (<https://www.cashmarket.deutsche-boerse.com/cash-en/trading/trading-calendar-and-trading-hours>, fetched 2026-10-31, SHA-256 `00b83880feabb0b42154f8a8c6a30396f5958a6dae72d0604976e1c666ec1328`).

- 2026, as published: non-trading days 1 January, 3 April (Good Friday), 6 April (Easter Monday), 1 May, 24, 25 and 31 December. Unlike BME, 24 and 31 December are full non-trading days (settlement stays open), so they are listed. Ascension, Whit Monday and Corpus Christi (14 May, 25 May, 4 June 2026) are trading days.
- 2027 is in the same overview (1 January, 26 March, 29 March, 24 and 31 December; 1 May and 25 December fall on a Saturday) but the page says changes for future years are not excluded: treat it as **unofficial until confirmed** in 2027.
- Cross-check: the Yahoo daily bars of `SAP.DE` contain no session on 2026-01-01, 2026-04-03, 2026-04-06, 2026-05-01, nor on 2025-04-18, 2025-04-21, 2025-12-24, 2025-12-25, 2025-12-26 and 2025-12-31.
- Refresh every year (add the next year, drop the oldest).

## Behavior

- The seeder is offline and idempotent. It syncs membership to this file exactly (attaches new members, detaches removed ones, never deletes an Instrument or its history). An Instrument left in no Screener universe becomes `active = false` and is no longer ingested.
- All members are created as `XETR`/`EUR` Instruments. No member overlaps another universe.
- `ingestion:pipeline --market=XETR` ingests it (the scheduled event fires at 18:15 Europe/Berlin once the universe is seeded). Yahoo is the only source for `.DE` (no Stooq fallback). Volume is the Xetra line only, so absolute volume understates trading; relative volume stays consistent.

## Review cadence

STOXX reviews the DAX quarterly (March, June, September and December; announced around the first Wednesday of the month and effective after the third Friday), with extraordinary changes at any time (for example a takeover or a free-float breach). A stale snapshot is a known risk that is not detected automatically. Next scheduled review: announced 3 December 2026.

## Rebalance procedure

1. Replace `dax.csv` with a new dated snapshot (same header, sorted by ticker, `.DE` suffix; classify new members as above).
2. Update `as_of`, the count and the SHA-256 values in this file, and the expected rows in `tests/Feature/DaxUniverseSeederTest.php`.
3. Run `php artisan db:seed --class=DaxUniverseSeeder --force` (deploy runs it).
4. Record the change in `PROGRESS.md`.
