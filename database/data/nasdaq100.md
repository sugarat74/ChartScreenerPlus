# Nasdaq-100 constituent snapshot (as_of 2026-10-26)

`nasdaq100.csv` is the committed, offline constituent list read by `Nasdaq100UniverseSeeder` (slug `nasdaq100`, name "Nasdaq-100", Listing Market `US`, USD, selector position 1). Header: `ticker,company,sector,industry,country,exchange`.

- `as_of`: 2026-10-26 (retrieved the same day). The index composition is the one published for 2026-10-22/23.
- Count: **101 securities** issued by 100 companies (Alphabet is included with both share classes, `GOOGL` and `GOOG`; both are separate Instruments and both are S&P 500 Instruments too).
- Overlap with `sp500.csv` (503 symbols): **86 members** are S&P 500 constituents; **15 members** are not.
- `nasdaq100.csv` SHA-256: `005ce16016e80a9b2a41f2c03673ad44dd3d18d274514e8298277da27015d644`.

## Sources and verification

Primary (the membership list was cross-checked between two independent sources; the two ticker sets are identical, 101 of 101):

1. Nasdaq's own quote-list endpoint `https://api.nasdaq.com/api/quote/list-type/nasdaq100` (the data behind nasdaq.com's Nasdaq-100 page), fetched 2026-10-26, page date "Oct 23, 2026", `totalrecords` 101. Raw response SHA-256 `0f34411864b04f95b28c5b9294d14070ee1359c70ec5308c1e60b74b9f0f0408`. Used for membership only (symbols and legal names).
2. Wikipedia, "List of Nasdaq-100 companies" (<https://en.wikipedia.org/wiki/List_of_Nasdaq-100_companies>), wikitext fetched 2026-10-26, "As of October 22, 2026, the index comprised 101 securities issued by 100 companies". Raw wikitext SHA-256 `039f06917261572fb3e7e8158026af7191acb93f8e671ffa93ceae43ff0541af`. Used for the ICB subsector text of the 15 non-S&P members (`industry`).

Wikipedia's text is CC BY-SA 4.0; only ticker and the ICB subsector label of 15 rows were taken, and the Nasdaq list is a public listing of constituent names. Review source terms together with the legal notice (risk R2 of `docs/specs/multi-market-universes.md`).

## Transform

- Rows are sorted by ticker. Tickers are Nasdaq symbols, unchanged (no dots in the current list).
- `exchange` is `NASDAQ` for every member (the index lists only Nasdaq-listed securities).
- For the 86 S&P 500 members, the row copies the `sp500.csv` values (company, GICS sector, GICS sub-industry, country). The seeder never reads them: for an overlapping ticker the S&P 500 Instrument is the source of truth (decision D3) and the seeder only attaches membership. They are kept so the file is self-consistent.
- For the 15 non-S&P members:
  - `company`: the common name (legal suffixes and share-class text removed);
  - `industry`: the ICB subsector from Wikipedia, verbatim (decision D7);
  - `sector`: **editorial GICS mapping** (Nasdaq uses ICB, not GICS), listed below;
  - `country`: ISO 3166-1 alpha-2 **headquarters** country (principal executive office), not the listing country. **Classified manually**, not taken from a dataset.

| Ticker | Company | `sector` (GICS, editorial) | `industry` (ICB, verbatim) | `country` | Note |
| --- | --- | --- | --- | --- | --- |
| ALAB | Astera Labs | Information Technology | Semiconductors | US | |
| ALNY | Alnylam Pharmaceuticals | Health Care | Biotechnology | US | |
| ARM | Arm Holdings | Information Technology | Semiconductors | GB | ADS; Cambridge |
| ASML | ASML Holding | Information Technology | Industrial Machinery | NL | Semiconductor equipment (GICS IT); Veldhoven |
| CCEP | Coca-Cola Europacific Partners | Consumer Staples | Soft Drinks | GB | London |
| CRWV | CoreWeave | Information Technology | Computer Services | US | |
| FER | Ferrovial | Industrials | Military, Government, Technical | NL | Registered in the Netherlands |
| MELI | Mercado Libre | Consumer Discretionary | Catalog/Specialty Distribution | UY | Montevideo, Uruguay |
| MSTR | MicroStrategy | Information Technology | Software | US | |
| NBIS | Nebius Group | Information Technology | Computer Services | NL | Amsterdam |
| PDD | PDD Holdings | Consumer Discretionary | EDP Services | IE | Principal executive office in Dublin since 2023; operations in China |
| RKLB | Rocket Lab | Industrials | Aerospace | US | |
| SHOP | Shopify | Information Technology | Software | CA | Ottawa (HQ country CA either way) |
| SPCX | SpaceX | Industrials | Telecommunications Services | US | Newly listed; GICS not verified, mapped to aerospace and defense (Industrials). Nasdaq/ICB labels it telecommunications (Starlink) |
| TRI | Thomson Reuters | Industrials | Software | CA | GICS Professional Services (Industrials); ICB says Technology |

Values not verifiable offline and worth a second look on the next rebalance: the editorial `sector` of `SPCX`, `TRI`, `CRWV`, `MSTR`, and `PDD`/`MELI` countries (headquarters is a judgement call for multinationals). Sector affects only the Sector filter label of these 15 Instruments (the sector context filters use the GICS sector of the stock's SPDR ETF).

## Behavior

- The seeder is offline and idempotent. It syncs membership to this file exactly (attaches new members, detaches removed ones, never deletes an Instrument or its history). An Instrument left in no Screener universe becomes `active = false` and is no longer ingested.
- Run it after `Sp500UniverseSeeder` (`DatabaseSeeder` and `deploy/deploy.sh` do). Non-S&P members are created as `US`/`USD` Instruments; overlapping members keep their S&P 500 fields and their single set of Daily Bars and Screener row.
- The US pipeline (`ingestion:pipeline`) ingests the universe after `sp500` with `--except-universe=sp500`, so only the 15 non-S&P members are fetched (about two years of bars each on the first run).

## Review cadence

Annual December reconstitution plus ad-hoc replacements. A stale snapshot is a known risk that is not detected automatically.

## Rebalance procedure

1. Replace `nasdaq100.csv` with a new dated snapshot (same header, sorted by ticker; reconcile overlap with `sp500.csv` and classify new non-S&P members as above).
2. Update `as_of`, the counts and the SHA-256 values in this file, and the expected counts in `tests/Feature/Nasdaq100UniverseSeederTest.php`.
3. Run `php artisan db:seed --class=Nasdaq100UniverseSeeder --force` (deploy runs it).
4. Record the change in `PROGRESS.md`.
