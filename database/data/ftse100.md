# FTSE 100 constituent snapshot (as_of 2026-11-13)

`ftse100.csv` is the committed, offline constituent list read by `Ftse100UniverseSeeder` (slug `ftse100`, name "FTSE 100", Listing Market `XLON`, currency `GBX`, selector position 5). Header: `ticker,company,sector,industry,country,exchange`. Tickers carry the Yahoo `.L` suffix (decision C2 of `docs/specs/universes-cac40-ftse100.md`); BT Group's class-A share `BT.A` is written as Yahoo writes it, `BT-A.L`. `exchange` is the Yahoo exchange code, `LSE`, for every row.

- `as_of`: 2026-11-13 (retrieved the same day; composition in force since the deletion of Beazley on 1 October 2026).
- Count: **97 members** out of the **100 index constituents**: the 97 quoted in pence. The three constituents quoted in another currency are left out (decision P2, owner decision O2; see "Excluded constituents").
- `ftse100.csv` SHA-256: `b6b418896fef2f6c0b3d2b5a0058f2f31f7ff5c83d2df1b4f855ab2a34680e8f`.

## Sources and verification

FTSE Russell publishes the composition only as a dynamic factsheet download, so the snapshot is a base list plus FTSE Russell / LSEG announcements:

1. Base list: Wikipedia, "FTSE 100 Index", constituents table "after the changes on 1 October 2026" (<https://en.wikipedia.org/wiki/FTSE_100_Index>). Raw wikitext of revision `1384229503` (2026-11-07T06:36:24Z, the revision live on 2026-11-08), fetched 2026-11-13 (`index.php?oldid=1384229503&action=raw`), SHA-256 `9acd8ddd9746050749cb76111955aebb7d463fc6c912fc662c4d67476004d7d9`, identical to the hash recorded in the spec on 2026-11-08. The current revision on 2026-11-13 (`1385074262`, raw SHA-256 `2dfa42bd927dad1bb85297a0d4423c2c3cb6f6b243c952c5c25f9dfcf539f65c`) changes only the History and Weighting sections: its constituents table parses to the same 100 rows (company, ticker, ISIN, ICB sector), byte for byte. Source of the tickers, company names and the `industry` text (the table's "FTSE industry classification benchmark sector" column, the ICB sector shown by the London Stock Exchange, verbatim). 100 rows.
2. FTSE Russell / LSEG: the September 2026 quarterly review (announced 2 September 2026, effective 21 September 2026) added easyJet and Ithaca Energy and removed Entain and Persimmon (<https://www.lseg.com/en/media-centre/press-releases/2026/ftse-uk-index-series-review-september-2026>); Beazley was deleted on 1 October 2026 (FTSE Russell notice of 28 September 2026, cited by the table header). Both additions are in the table; Entain, Persimmon and Beazley are not. Taken from the spec, which fetched them on 2026-11-08; not re-fetched by the implementer.
3. Yahoo (live, read-only, 2026-11-13, through the project engine `GET /eod/{symbol}?market=XLON` on a local port): all 100 symbols resolve. The 97 members return currency `GBX` (Yahoo `GBp`), 502 to 507 daily sessions each (2024-11-13 to 2026-11-13; 86 with 507, 9 with 506, 1 with 505, 1 with 502), no weekend date, no bar on the England and Wales bank holidays of the window (2024-12-25/26, 2025-01-01, 2025-04-18/21, 2025-05-05/26, 2025-08-25, 2025-12-25/26, 2026-01-01, 2026-04-03/06, 2026-05-04/25, 2026-08-31), a largest day-over-day close ratio of 1.344 (well under the 40x scale guard) and last closes from 94.08 to 16,860 pence. The excluded three return `USD` (`CPG.L`, `IHG.L`) and `EUR` (`MTLN.L`), as on 2026-11-08.

**Not verified:**
- The full ordinary changes of the March and June 2026 reviews (Wikipedia only), any deletion between 1 October 2026 and the as-of date (none found on the Wikipedia page, whose table is unchanged since 26 October 2026), and the composition as published by FTSE Russell's own factsheet (a dynamic download).
- The sector, country and company labels are editorial (below).

Wikipedia's text is CC BY-SA 4.0; only the ticker, company name and ICB sector label of each row were taken. FTSE Russell owns the "FTSE" mark and the index data; the name is used only to describe the list. Review source terms together with the legal notice (risk R2 of the spec).

## Excluded constituents

Decision P2 (owner decision O2, default accepted): a universe has one currency and Chartiko does no currency conversion, so the constituents whose London line is not quoted in pence are not members. Checked live on 2026-11-08 (spec) and again on 2026-11-13 (engine, `market=XLON`):

| Ticker | Company | Yahoo currency | ICB sector (Wikipedia) | Last close 2026-11-13 |
| --- | --- | --- | --- | --- |
| CPG.L | Compass Group | USD | Consumer Services | 33.95 |
| IHG.L | IHG Hotels & Resorts | USD | Travel and Leisure | 179.70 |
| MTLN.L | Metlen Energy & Metals | EUR | Gas, Water and Multi-utilities | 45.66 |

If Yahoo later quotes one of them in pence, add it in a rebalance; if a member changes its quote currency, the `currency_mismatch` guard fails that item (nothing stored) and the Admin run reports it, so it must then be moved here.

## Transform

- Rows are sorted by ticker (byte order). Tickers are Wikipedia's ticker plus `.L`; the one dotted class ticker `BT.A` becomes `BT-A.L` (Yahoo's form; the engine does not rewrite European symbols).
- `company` is the common name: Wikipedia's link text with a trailing " plc" removed (Anglo American, Antofagasta, Fresnillo, GSK, Halma, Kingfisher, National Grid, Next, Pearson, Prudential, SSE, Shell), "EasyJet" written "easyJet" and "F & C Investment Trust" written "F&C Investment Trust".
- `industry` is the ICB sector label of the table, verbatim (decision D7).
- `sector` is an **editorial GICS mapping** (one of the 11 GICS names of `ScreenerFilterParser::SECTORS`) by the ICB-to-GICS rule of decision C4, not taken from a dataset: Banks, Life/Non-life Insurance, Investment Banking and Brokerage Services, Finance and Credit Services and Closed End Investments to Financials; Real Estate Investment Trusts to Real Estate; Non-Renewable Energy to Energy; Industrial Metals and Mining, Precious Metals and Mining and Chemicals to Materials; Pharmaceuticals/Biotechnology and Medical Equipment and Services to Health Care; Software and Computer Services to Information Technology; Telecommunications Service Providers and Media to Communication Services; Electricity and Gas, Water and Multi-utilities to Utilities; Food Producers, Beverages, Tobacco and Personal Care, Drug and Grocery Stores to Consumer Staples; Travel and Leisure, Retailers, Household Goods and Home Construction, Personal Goods, Leisure Goods and Consumer Services to Consumer Discretionary; Aerospace and Defense, Industrial Support Services, Industrial Engineering, General Industrials, Construction and Materials and Electronic and Electrical Equipment to Industrials.
- **One override of the rule:** the two airlines (`IAG.L`, `EZJ.L`, ICB Travel and Leisure) are **Industrials**, the GICS sector of airlines, so `IAG.L` has the same sector as the IBEX 35 `IAG.MC`.
- `country` is the ISO 3166-1 alpha-2 **headquarters** country, not the listing or registration country. It is GB unless noted. Where the registration differs (Jersey for Experian, Glencore and WPP; Bermuda for Hiscox; Guernsey for Pershing Square; Ireland for DCC; Spain for IAG), the headquarters decides, and the row notes it.

| Ticker | Company | `sector` (GICS, editorial) | `industry` (ICB) | `country` | `exchange` | Note |
| --- | --- | --- | --- | --- | --- | --- |
| AAF.L | Airtel Africa | Communication Services | Telecommunications Service Providers | GB | LSE | London headquarters, operations in Africa |
| AAL.L | Anglo American | Materials | Industrial Metals and Mining | GB | LSE |  |
| ABDN.L | Aberdeen Group | Financials | Investment Banking and Brokerage Services | GB | LSE |  |
| ABF.L | Associated British Foods | Consumer Staples | Food Producers | GB | LSE |  |
| ADM.L | Admiral Group | Financials | Non-life Insurance | GB | LSE |  |
| ALW.L | Alliance Witan | Financials | Closed End Investments | GB | LSE | Investment trust; Financials **uncertain** (no GICS operating sector) |
| ANTO.L | Antofagasta | Materials | Industrial Metals and Mining | GB | LSE | London headquarters, mines in Chile |
| AUTO.L | Autotrader Group | Information Technology | Software and Computer Services | GB | LSE | Online marketplace; Information Technology by the rule, **uncertain** (GICS would likely say Communication Services) |
| AV.L | Aviva | Financials | Life Insurance | GB | LSE |  |
| AZN.L | AstraZeneca | Health Care | Pharmaceuticals, Biotechnology and Marijuana Producers | GB | LSE |  |
| BA.L | BAE Systems | Industrials | Aerospace and Defense | GB | LSE |  |
| BAB.L | Babcock International | Industrials | Aerospace and Defense | GB | LSE |  |
| BARC.L | Barclays | Financials | Banks | GB | LSE |  |
| BATS.L | British American Tobacco | Consumer Staples | Tobacco | GB | LSE |  |
| BBOX.L | Tritax Big Box REIT | Real Estate | Real Estate Investment Trusts | GB | LSE |  |
| BBY.L | Balfour Beatty | Industrials | Construction and Materials | GB | LSE |  |
| BGEO.L | Lion Finance Group | Financials | Banks | GB | LSE | London-registered holding, banks in Georgia and Armenia; **uncertain country** (GB vs GE) |
| BLND.L | British Land | Real Estate | Real Estate Investment Trusts | GB | LSE |  |
| BNZL.L | Bunzl | Industrials | General Industrials | GB | LSE |  |
| BP.L | BP | Energy | Non-Renewable Energy | GB | LSE |  |
| BRBY.L | Burberry Group | Consumer Discretionary | Personal Goods | GB | LSE |  |
| BT-A.L | BT Group | Communication Services | Telecommunications Service Providers | GB | LSE | LSE ticker `BT.A`; Yahoo `BT-A.L` |
| BTRW.L | Barratt Redrow | Consumer Discretionary | Household Goods and Home Construction | GB | LSE |  |
| CCC.L | Computacenter | Information Technology | Software and Computer Services | GB | LSE | IT services and reselling; **uncertain** |
| CCEP.L | Coca-Cola Europacific Partners | Consumer Staples | Beverages | GB | LSE | Uxbridge headquarters |
| CCH.L | Coca-Cola HBC | Consumer Staples | Beverages | CH | LSE | Swiss-registered, Zug headquarters |
| CNA.L | Centrica | Utilities | Gas, Water and Multi-utilities | GB | LSE |  |
| CRDA.L | Croda International | Materials | Chemicals | GB | LSE |  |
| CTEC.L | Convatec | Health Care | Medical Equipment and Services | GB | LSE |  |
| DCC.L | DCC Energy | Industrials | Industrial Support Services | IE | LSE | Dublin headquarters; Industrials by the ICB sector, **uncertain** (mostly energy distribution) |
| DGE.L | Diageo | Consumer Staples | Beverages | GB | LSE |  |
| DPLM.L | Diploma | Industrials | Industrial Support Services | GB | LSE |  |
| EDV.L | Endeavour Mining | Materials | Precious Metals and Mining | GB | LSE | London headquarters, mines in West Africa |
| EXPN.L | Experian | Industrials | Industrial Support Services | IE | LSE | Jersey-registered, Dublin headquarters; **uncertain country** (large UK operations) |
| EZJ.L | easyJet | Industrials | Travel and Leisure | GB | LSE | **Override**: airline, GICS Industrials (the rule says Consumer Discretionary) |
| FCIT.L | F&C Investment Trust | Financials | Closed End Investments | GB | LSE | Investment trust; Financials **uncertain** |
| FRES.L | Fresnillo | Materials | Precious Metals and Mining | MX | LSE | UK-registered, Mexico City headquarters; **uncertain country** |
| GAW.L | Games Workshop | Consumer Discretionary | Leisure Goods | GB | LSE | **Uncertain** sector (miniatures maker and retailer) |
| GLEN.L | Glencore | Materials | Industrial Metals and Mining | CH | LSE | Jersey-registered, Baar headquarters (the spec's example listed the registration, JE; the headquarters rule gives CH) |
| GSK.L | GSK | Health Care | Pharmaceuticals, Biotechnology and Marijuana Producers | GB | LSE |  |
| HLMA.L | Halma | Industrials | Electronic and Electrical Equipment | GB | LSE |  |
| HLN.L | Haleon | Health Care | Pharmaceuticals, Biotechnology and Marijuana Producers | GB | LSE | Consumer health; Health Care by the ICB sector, **uncertain** (GICS could say Consumer Staples) |
| HSBA.L | HSBC | Financials | Banks | GB | LSE |  |
| HSX.L | Hiscox | Financials | Non-life Insurance | BM | LSE | Bermuda-registered group headquarters |
| HWDN.L | Howdens Joinery | Consumer Discretionary | Retailers | GB | LSE |  |
| IAG.L | International Airlines Group | Industrials | Travel and Leisure | ES | LSE | **Override**: airline, Industrials as the IBEX 35 `IAG.MC`. Registered in Madrid, main operations (British Airways) in the UK: **uncertain country**, ES as in `ibex35.csv`. A separate Instrument from `IAG.MC` (C2) |
| ICG.L | ICG | Financials | Investment Banking and Brokerage Services | GB | LSE |  |
| IGG.L | IG Group | Financials | Investment Banking and Brokerage Services | GB | LSE |  |
| III.L | 3i | Financials | Investment Banking and Brokerage Services | GB | LSE |  |
| IMB.L | Imperial Brands | Consumer Staples | Tobacco | GB | LSE |  |
| IMI.L | IMI | Industrials | Electronic and Electrical Equipment | GB | LSE |  |
| INF.L | Informa | Communication Services | Media | GB | LSE |  |
| INVP.L | Investec | Financials | Banks | GB | LSE | Dual-listed group (Investec plc in London, Investec Ltd in Johannesburg); the London line |
| ITH.L | Ithaca Energy | Energy | Non-Renewable Energy | GB | LSE | Joined 21 September 2026 |
| ITRK.L | Intertek | Industrials | Industrial Support Services | GB | LSE |  |
| JD.L | JD Sports | Consumer Discretionary | Retailers | GB | LSE |  |
| KGF.L | Kingfisher | Consumer Discretionary | Retailers | GB | LSE |  |
| LAND.L | Land Securities | Real Estate | Real Estate Investment Trusts | GB | LSE |  |
| LGEN.L | Legal & General | Financials | Life Insurance | GB | LSE |  |
| LLOY.L | Lloyds Banking Group | Financials | Banks | GB | LSE |  |
| LMP.L | LondonMetric Property | Real Estate | Real Estate Investment Trusts | GB | LSE |  |
| LSEG.L | London Stock Exchange Group | Financials | Finance and Credit Services | GB | LSE |  |
| MKS.L | Marks & Spencer | Consumer Staples | Personal Care, Drug and Grocery Stores | GB | LSE | Food and clothing retailer; Consumer Staples by the ICB sector, **uncertain** |
| MNG.L | M&G | Financials | Investment Banking and Brokerage Services | GB | LSE |  |
| MRO.L | Melrose Industries | Industrials | Aerospace and Defense | GB | LSE |  |
| NG.L | National Grid | Utilities | Gas, Water and Multi-utilities | GB | LSE |  |
| NWG.L | NatWest Group | Financials | Banks | GB | LSE |  |
| NXT.L | Next | Consumer Discretionary | Retailers | GB | LSE |  |
| PCT.L | Polar Capital Technology Trust | Financials | Closed End Investments | GB | LSE | Investment trust; Financials **uncertain** |
| PRU.L | Prudential | Financials | Life Insurance | GB | LSE | London and Hong Kong head offices, business in Asia and Africa; **uncertain country** |
| PSH.L | Pershing Square Holdings | Financials | Closed End Investments | GG | LSE | Guernsey-registered closed-end fund (manager in New York); Financials **uncertain** |
| PSON.L | Pearson | Communication Services | Media | GB | LSE | Education publisher; Communication Services by the ICB sector, **uncertain** |
| REL.L | RELX | Information Technology | Software and Computer Services | GB | LSE | Information and analytics; Information Technology by the rule (C4), **uncertain** |
| RIO.L | Rio Tinto | Materials | Industrial Metals and Mining | GB | LSE | Dual-listed with Rio Tinto Ltd (Australia); the London line |
| RKT.L | Reckitt | Consumer Staples | Personal Care, Drug and Grocery Stores | GB | LSE |  |
| RR.L | Rolls-Royce Holdings | Industrials | Aerospace and Defense | GB | LSE |  |
| RTO.L | Rentokil Initial | Industrials | Industrial Support Services | GB | LSE |  |
| SBRY.L | Sainsbury's | Consumer Staples | Personal Care, Drug and Grocery Stores | GB | LSE |  |
| SDLF.L | Standard Life | Financials | Life Insurance | GB | LSE |  |
| SGE.L | Sage Group | Information Technology | Software and Computer Services | GB | LSE |  |
| SGRO.L | Segro | Real Estate | Real Estate Investment Trusts | GB | LSE |  |
| SHEL.L | Shell | Energy | Non-Renewable Energy | GB | LSE |  |
| SMIN.L | Smiths Group | Industrials | General Industrials | GB | LSE |  |
| SMT.L | Scottish Mortgage Investment Trust | Financials | Closed End Investments | GB | LSE | Investment trust; Financials **uncertain** |
| SN.L | Smith & Nephew | Health Care | Medical Equipment and Services | GB | LSE |  |
| SPX.L | Spirax Group | Industrials | Industrial Engineering | GB | LSE |  |
| SSE.L | SSE | Utilities | Electricity | GB | LSE | Perth (Scotland) headquarters |
| STAN.L | Standard Chartered | Financials | Banks | GB | LSE |  |
| STJ.L | St. James's Place | Financials | Investment Banking and Brokerage Services | GB | LSE |  |
| SVT.L | Severn Trent | Utilities | Gas, Water and Multi-utilities | GB | LSE |  |
| TSCO.L | Tesco | Consumer Staples | Personal Care, Drug and Grocery Stores | GB | LSE |  |
| ULVR.L | Unilever | Consumer Staples | Personal Care, Drug and Grocery Stores | GB | LSE |  |
| UU.L | United Utilities | Utilities | Gas, Water and Multi-utilities | GB | LSE |  |
| VOD.L | Vodafone Group | Communication Services | Telecommunications Service Providers | GB | LSE |  |
| WEIR.L | Weir | Industrials | Industrial Engineering | GB | LSE |  |
| WPP.L | WPP | Communication Services | Media | GB | LSE | Jersey-registered, London headquarters |
| WTB.L | Whitbread | Consumer Discretionary | Travel and Leisure | GB | LSE |  |

Sector affects only the Sector filter of these Instruments (the sector context filters are US-only, decision D10). Worth a second look on the next rebalance: the sectors of `AUTO`, `CCC`, `DCC`, `GAW`, `HLN`, `MKS`, `PSON`, `REL` and the five investment trusts, and the country of `BGEO`, `EXPN`, `FRES`, `IAG` and `PRU`.

## Duplicates and share classes

- No company has two lines in the index; BT Group is the only class-share ticker (`BT-A.L`).
- Cross-listed companies are separate Instruments in other universes (C2): `IAG.L` here and `IAG.MC` in the IBEX 35. No other member is in another committed universe (no S&P 500, Nasdaq-100, IBEX 35, DAX or CAC 40 ticker ends in `.L`).
- The five investment trusts (Alliance Witan, F&C Investment Trust, Scottish Mortgage, Polar Capital Technology, Pershing Square) are valid stocks for technical screening; they have no operating-company fundamentals (none are used outside the US).

## Calendar

The London holidays and half days live in `config/ingestion.php` (`markets.XLON.holidays`, `early_closes`), added and sourced by `ingestion-xlon-gbx` (LSE business-days page and gov.uk England and Wales bank holidays): 2026 closures 1 Jan, 3 Apr, 6 Apr, 4 May, 25 May, 31 Aug, 25 Dec, 28 Dec; 2027 closures 1 Jan, 26 Mar, 29 Mar, 3 May, 31 May, 30 Aug, 27 Dec, 28 Dec; 12:30 early closes on 24 and 31 December. The pipeline runs at 17:15 Europe/London (16:30 close + 45 minutes). Cross-check: the 97 members' Yahoo bars contain no session on the bank holidays of the two-year window (above). Refresh every year.

## Behavior

- The seeder is offline and idempotent. It syncs membership to this file exactly (attaches new members, detaches removed ones, never deletes an Instrument or its history). An Instrument left in no Screener universe becomes `active = false` and is no longer ingested.
- All members are created as `XLON`/`GBX` Instruments. Prices are stored and shown in pence as quoted (decision P1), never divided by 100; a response in `GBP`, `USD` or `EUR` fails the item with `currency_mismatch`, and the London scale guards (40x jump, 1,000,000 cap, `price_scale_mismatch` against stored bars) apply.
- `ingestion:pipeline --market=XLON` ingests it (the scheduled event fires at 17:15 Europe/London once the universe is seeded). Yahoo is the only source (no Stooq fallback). Volume is the Yahoo LSE line only, so absolute volume understates trading; relative volume stays consistent.

## Review cadence

FTSE Russell reviews the FTSE 100 quarterly (March, June, September, December: announced on the first Wednesday of the month, effective after the close of the third Friday), plus ad hoc deletions for takeovers or delistings, filled from the reserve list (for example Beazley on 1 October 2026). A stale snapshot is a known risk that is not detected automatically. Next scheduled review after the as-of date: December 2026 (announced 2 December, effective after the close of 18 December 2026).

## Rebalance procedure

1. Replace `ftse100.csv` with a new dated snapshot (same header, sorted by ticker, `.L` suffix, class shares in Yahoo's hyphen form; classify new members by the rule above).
2. Check every new member live with `GET /eod/{symbol}?market=XLON`: it must return `GBX`. A member quoted in another currency goes to "Excluded constituents" instead.
3. Update `as_of`, the counts and the SHA-256 values in this file, and the expected rows in `tests/Feature/Ftse100UniverseSeederTest.php`.
4. Run `php artisan db:seed --class=Ftse100UniverseSeeder --force` (deploy runs it).
5. Record the change in `PROGRESS.md`.
