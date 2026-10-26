# IBEX 35 constituent snapshot (as_of 2026-10-26)

`ibex35.csv` is the committed, offline constituent list read by `Ibex35UniverseSeeder` (slug `ibex35`, name "IBEX 35", Listing Market `XMAD`, EUR, selector position 2). Header: `ticker,company,sector,industry,country,exchange`. Tickers carry the Yahoo `.MC` suffix (decision D2); `exchange` is `BME`.

- `as_of`: 2026-10-26 (retrieved the same day).
- Count: **35 companies**, one security each.
- `ibex35.csv` SHA-256: `dda0e24bcd35956547a1af85b7d043268d98274a44b4764c8b693456065286c7`.

## Sources and verification

BME publishes the IBEX 35 composition only through dynamic pages and PDF notices, so the snapshot is the last official composition notice, cross-checked against the reviews since:

1. BME Index Manager notice "Composition of IBEX 35 as from September 23rd, 2024" (<https://www.bolsasymercados.es/bme-exchange/docs/Indices/Avisos/ing/gestorindice/Notice_Index_Manager_13-24.pdf>), as cited by Wikipedia, "IBEX 35" (<https://en.wikipedia.org/wiki/IBEX_35>), whose components table lists the 35 tickers. Raw wikitext fetched 2026-10-26, SHA-256 `18bb1a43adf016d3bf27ac4e12c2d271d1e1683f09d4fce0e676f911459e43d2`. Source of the tickers, company names and the `industry` text (the table's "Sector" column, verbatim). The Spanish Wikipedia table is older (October 2023) and was not used.
2. BME, "Historical Constituents - IBEX 35" (<https://www.bolsasymercados.es/dam/descargas/indices/composicion-ibex-35-es-en.pdf>), fetched 2026-10-26, SHA-256 `5c028420d39c9d6e2205fa88d15627865dc217de867bc86987ca8f9fddba3695`. It lists the reviews up to 21/09/2026. Its text layer is garbled, so it was used only to see that the review list continues and that no inclusion or exclusion appears after the 2024 reviews.
3. Press report of BME's Technical Advisory Committee: the 11 June 2026 meeting left the composition unchanged and the next change would be the first since 22 July 2024 (Merca2, 2026-06-12, <https://www.merca2.es/2026/06/12/comite-ibex-sin-cambios-ajusta-coeficientes-indra-naturgy-solaria-2395406/>, SHA-256 of the fetched page `a3213b1a7dbc0e67030beb4ecdd130c94ee0ec833f03cb8b9d3068f078abb474`). **Not verified:** the September 2026 review (21/09/2026 in the BME list) was not found in any source; a change on that date would be missed until the next rebalance.
4. Yahoo (live, read-only, 2026-10-26): `SAN.MC` returns EUR bars, 509 sessions, no weekend dates, timezone `Europe/Madrid`. The other 34 symbols were not individually fetched: verify them on the first production run (`deploy/README.md`).

Wikipedia's text is CC BY-SA 4.0; only the ticker, company name and sector label of each row were taken. Review source terms together with the legal notice (risk R2 of `docs/specs/multi-market-universes.md`).

## Transform

- Rows are sorted by ticker. Tickers are BME codes with `.MC` appended.
- `company` is the common name (legal suffixes removed).
- `industry` is the Wikipedia sector label for the ticker, verbatim (decision D7). It is not a GICS value.
- `sector` is an **editorial GICS mapping** (one of the 11 GICS names of `ScreenerFilterParser::SECTORS`), **classified manually** from the company's business; it is not taken from a dataset. Uncertain ones are flagged below.
- `country` is the ISO 3166-1 alpha-2 **headquarters** country, not the listing country. It is ES unless noted.

| Ticker | Company | `sector` (GICS, editorial) | `industry` (verbatim) | `country` | Note |
| --- | --- | --- | --- | --- | --- |
| ACS.MC | ACS | Industrials | Construction | ES | Construction and engineering |
| ACX.MC | Acerinox | Materials | Steel | ES | |
| AENA.MC | Aena | Industrials | Aviation | ES | Airport operator |
| AMS.MC | Amadeus IT Group | Information Technology | Tourism | ES | Travel technology; Madrid |
| ANA.MC | Acciona | Industrials | Construction | ES | |
| ANE.MC | Acciona Energía | Utilities | Energy | ES | Renewable electricity |
| BBVA.MC | BBVA | Financials | Financial Services | ES | |
| BKT.MC | Bankinter | Financials | Financial Services | ES | |
| CABK.MC | CaixaBank | Financials | Financial Services | ES | |
| CLNX.MC | Cellnex Telecom | Communication Services | Telecommunications | ES | **Uncertain**: tower operator; some vendors class it Real Estate |
| COL.MC | Inmobiliaria Colonial | Real Estate | Real Estate | ES | |
| ELE.MC | Endesa | Utilities | Energy | ES | |
| ENG.MC | Enagás | Utilities | Energy | ES | Gas transmission |
| FDR.MC | Fluidra | Industrials | Manufacturing | ES | **Uncertain**: pool equipment; Industrials (building products) vs Consumer Discretionary |
| FER.MC | Ferrovial | Industrials | Infrastructure | NL | Registered and headquartered in Amsterdam (Ferrovial SE); same as `FER` in the Nasdaq-100 snapshot |
| GRF.MC | Grifols | Health Care | Pharmaceuticals | ES | |
| IAG.MC | International Airlines Group | Industrials | Aviation | ES | **Uncertain** country: registered office in Madrid, main operations (British Airways) in the UK |
| IBE.MC | Iberdrola | Utilities | Energy | ES | |
| IDR.MC | Indra Sistemas | Information Technology | Information Technology | ES | **Uncertain**: IT services and defense; could be Industrials |
| ITX.MC | Inditex | Consumer Discretionary | Textile | ES | |
| LOG.MC | Logista | Consumer Staples | Logistics | ES | **Uncertain**: tobacco and convenience distribution |
| MAP.MC | Mapfre | Financials | Insurance | ES | |
| MRL.MC | Merlin Properties | Real Estate | Real Estate | ES | |
| MTS.MC | ArcelorMittal | Materials | Steel | LU | Headquartered in Luxembourg City |
| NTGY.MC | Naturgy | Utilities | Energy | ES | |
| PUIG.MC | Puig | Consumer Staples | Clothes and Cosmetics | ES | Beauty and fragrance (personal care products) |
| RED.MC | Redeia | Utilities | Energy | ES | Electricity transmission |
| REP.MC | Repsol | Energy | Oil and Gas | ES | |
| ROVI.MC | Laboratorios Rovi | Health Care | Pharmaceuticals | ES | |
| SAB.MC | Banco Sabadell | Financials | Financial Services | ES | |
| SAN.MC | Banco Santander | Financials | Financial Services | ES | |
| SCYR.MC | Sacyr | Industrials | Construction | ES | |
| SLR.MC | Solaria | Utilities | Solar Energy | ES | Renewable electricity |
| TEF.MC | Telefónica | Communication Services | Telecommunications | ES | |
| UNI.MC | Unicaja | Financials | Financial Services | ES | |

Sector affects only the Sector filter of these Instruments (the sector context filters are US-only, decision D10). Worth a second look on the next rebalance: the sectors of `CLNX`, `FDR`, `IDR`, `LOG` and the country of `IAG`.

## Calendar

The Bolsa de Madrid holidays live in `config/ingestion.php` (`markets.XMAD.holidays`). Source: BME's official trading calendar (<https://www.bolsasymercados.es/en/bme-exchange/trading/trading-calendar.html>, fetched 2026-10-26, SHA-256 `e1d0d3ae90f6c6f3c42f01e9746981ee5b5f564260c77653e8a29d69c6e62c8c`).

- 2026, as published by BME: non-trading days 1 January, 3 April (Good Friday), 6 April (Easter Monday), 1 May, 25 December. 24 and 31 December trade until 14:00 CET: half days, which are trading days and are not listed (the schedule fires at 18:15, after any close).
- 2027 is **derived** from the same pattern (1 January, Good Friday 26 March, Easter Monday 29 March; 1 May and 25 and 26 December fall on weekends), because BME had not published 2027. Confirm it when BME does.
- Cross-check: the Yahoo daily bars of `SAN.MC` contain no session on 2026-01-01, 2026-04-03, 2026-04-06, 2026-05-01, nor on 2025-04-18, 2025-04-21 and 2025-12-25/26.
- Refresh every year (add the next year, drop the oldest).

## Behavior

- The seeder is offline and idempotent. It syncs membership to this file exactly (attaches new members, detaches removed ones, never deletes an Instrument or its history). An Instrument left in no Screener universe becomes `active = false` and is no longer ingested.
- All members are created as `XMAD`/`EUR` Instruments. No member overlaps another universe.
- `ingestion:pipeline --market=XMAD` ingests it (the scheduled event fires at 18:15 Europe/Madrid once the universe is seeded). Yahoo is the only source for `.MC` (no Stooq fallback). Volume is Bolsa de Madrid only, so absolute volume understates trading; relative volume stays consistent.

## Review cadence

BME's Technical Advisory Committee reviews the IBEX 35 (ordinary meetings in March, June, September and December, with changes effective after the third Friday). A stale snapshot is a known risk that is not detected automatically.

## Rebalance procedure

1. Replace `ibex35.csv` with a new dated snapshot (same header, sorted by ticker, `.MC` suffix; classify new members as above).
2. Update `as_of`, the count and the SHA-256 values in this file, and the expected rows in `tests/Feature/Ibex35UniverseSeederTest.php`.
3. Run `php artisan db:seed --class=Ibex35UniverseSeeder --force` (deploy runs it).
4. Record the change in `PROGRESS.md`.
