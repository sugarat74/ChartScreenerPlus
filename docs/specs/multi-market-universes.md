# Feature Implementation Spec: Nasdaq-100, IBEX 35 and DAX Screener universes (umbrella)

## Source Features

This umbrella spec covers six features, implemented in this order. Each one ships, is verified and is accepted on its own. Before implementing a feature, its session may write a detailed contract in `docs/specs/<id>.md`. That file wins where it is more precise, and this umbrella must then be corrected to match it, as was done for `docs/specs/market-context.md`.

| Order | `id` | `area` | `depends_on` | Summary |
| --- | --- | --- | --- | --- |
| 1 | `universe-registry` | backend | `screener-table-column-controls`, `saved-screeners`, `alerts-engine`, `market-context-patterns` | Listing Market and currency on Instruments and Universes, Screener universe registry and visibility, universe-aware Screener API, Saved Screeners and alerts, US-only gates |
| 2 | `ingestion-multi-market` | ingestion | `universe-registry`, `ingestion-scheduler` | Engine symbol mapping per Listing Market, per-market calendars and schedules, overlap-free pipeline, per-universe freshness in Admin |
| 3 | `universe-selector-ui` | frontend | `universe-registry`, `ingestion-multi-market` | Screener universe selector backed by the URL, universe on Saved Screeners, currency labels, Admin freshness table |
| 4 | `universe-nasdaq100` | data | `universe-selector-ui` | Nasdaq-100 dated snapshot and seeder, ingested by the US pipeline without re-fetching S&P 500 members |
| 5 | `universe-ibex35` | data | `universe-selector-ui` | IBEX 35 dated snapshot and seeder, Bolsa de Madrid calendar, EUR, `.MC` symbols |
| 6 | `universe-dax` | data | `universe-selector-ui` | DAX dated snapshot and seeder, Xetra calendar, EUR, `.DE` symbols |

Features 4, 5 and 6 are independent of each other once Feature 3 is accepted.

Related future features requested the same day and kept out of this delivery:
- `market-hours-status` (`docs/specs/market-hours-status.md`) shows open/closed markets on the home page. It reuses Feature 2's calendars.
- `market-events-calendar` (`docs/specs/market-events-calendar.md`) is a calendar of central bank meetings, macro releases and earnings dates. It is blocked on owner source decisions.

- `status`: planned 2026-10-26 at the user's explicit request: "prepara la feature para nasdaq, ibex 35 y dax 40". "Nasdaq" is read as the curated **Nasdaq-100** index, not the full Nasdaq Composite (about 3,000 issues; see Open Questions).
- `source`: `feature_list.json`. Scope change recorded in `AGENTS.md` (MVP Scope Guard, 2026-10-26).
- **Owner answers (2026-10-26):**
  1. Publish IBEX 35 and DAX **publicly** with the current unofficial Yahoo source. This is an owner-accepted risk (R1). The per-universe public flag is the kill-switch.
  2. New universes are visible to **everyone**, Visitors included, like the S&P 500.
  3. "Nasdaq" means the **Nasdaq-100**; the full Composite stays deferred.
  4. The label is **"DAX"** (official name), not "DAX 40". Feature id `universe-dax`, slug `dax`.

## Goal

Let a Screener user choose which index to screen: **S&P 500** (the default, unchanged), **Nasdaq-100**, **IBEX 35** or **DAX**. Every Universe gets the same daily data, Indicator Snapshots, Signals, Chartist Patterns, Candidate table, chart, Watchlist and alerts. Spanish and German stocks are priced in EUR, follow their own exchange calendar and are updated after their own session close.

US-only features stay US-only: Market Context, Breadth, Market Regime, the sector context filters, SEC Fundamentals, the AI Company Card and the forecast. This spec does not extend them to Europe.

## Non-Goals

- No full Nasdaq Composite, Russell 2000, other indices or other exchanges. No European index levels (`^IBEX`, `^GDAXI`, `^NDX`) and no index charts.
- No intraday data. No real-time data. No new paid data vendor. The source path stays the existing unofficial Yahoo (primary) / Stooq (fallback) path.
- No currency conversion. No mixed-currency Candidate tables. No FX rates.
- No European Market Context, Breadth, sector ETFs, Market Regime or macro series.
- No European fundamentals. SEC EDGAR is US-only, and no other fundamentals feed is in scope.
- No AI Company Card or forecast for non-US Listing Markets.
- No change to the natural-language Screener schema. It applies to the universe that is already selected.
- No change to the S&P 500 dataset or to its add-only seeder.
- No paid plans. Access follows the existing Visitor / Registered User split, plus a per-universe visibility flag (decision D9).

## Job Story

When I look for technical setups,
I want to run the same Screener on the Nasdaq-100, the IBEX 35 or the DAX,
so I can find Candidates in the markets I trade without leaving Chartiko.

## Users And Permissions

| Actor | Can |
| --- | --- |
| Visitor | Choose any **public** Screener universe in the Screener (all four once enabled, owner decision 2026-10-26). Open charts of its Instruments. |
| Registered User | The same, plus Saved Screeners per universe, alerts on them, and Watchlist items from any universe. |
| Admin | Everything above, including universes that are seeded but not public yet (preview, D9). Sees per-universe freshness and runs ingestion per universe from the Admin panel. |
| Developer/Operator | `ingestion:pipeline --market=...` and `db:seed --class=<Index>UniverseSeeder`. Follows the rebalance procedure (D6). |

Authorization is server-side. A non-public universe returns 404 to non-Admins on every endpoint that accepts a universe. Hiding the option in the UI is not the control.

## Domain Decisions (shared)

**D1. Listing Market.** New term **Listing Market (Mercado de cotización)**: the exchange group whose trading calendar, session close, currency and data-source symbol rules apply to an Instrument. It is deliberately not called "market", which would clash with Market Context. Closed list:

| Code | Exchanges | Timezone | Close (local) | Currency | Public label es / en |
| --- | --- | --- | --- | --- | --- |
| `US` | NYSE, Nasdaq, Cboe | America/New_York | 16:00 | USD | EE. UU. / United States |
| `XMAD` | Bolsa de Madrid (BME, SIBE) | Europe/Madrid | 17:30 | EUR | Bolsa de Madrid / Madrid Stock Exchange |
| `XETR` | Xetra (Deutsche Börse) | Europe/Berlin | 17:30 | EUR | Xetra / Xetra |

`instruments.market` (string 8, default `US`) and `instruments.currency` (char 3, default `USD`). Existing rows, including the Market Context ETFs, stay `US`/`USD`. `instruments.exchange` keeps its free-text meaning (NYSE, NASDAQ, BME, XETRA).

**D2. Ticker identity.** `instruments.ticker` stays globally unique and stays the public key in every URL and payload (`/instruments/{ticker}`, Watchlist, alerts, Saved Screeners).
- US tickers are unchanged (`NVDA`, `BRK.B`).
- European tickers carry the market suffix used by Yahoo: `SAN.MC`, `ITX.MC`, `SAP.DE`, `MRK.DE`. Without the suffix, Merck KGaA (`MRK`) would collide with the S&P 500's Merck & Co. (`MRK`).
- The suffix must match the market: `.MC` ⇔ `XMAD`, `.DE` ⇔ `XETR`. US tickers never end in `.MC` or `.DE`. Seeders reject violations.
- The UI shows the full ticker (`SAN.MC`) everywhere, with the exchange beside it.

**D3. One Instrument, many universes.** A company in both the S&P 500 and the Nasdaq-100 is **one** Instrument row with two `instrument_universe` rows. It therefore has one set of Daily Bars, Indicator Snapshots, Signals, patterns and one Screener row (`screener_rows.instrument_id` is unique today and stays so). The Candidate query is already universe-scoped through the pivot, so the Instrument appears in both universes' Candidate lists.
- All members of a Screener universe share its Listing Market and currency. A seeder throws if an existing Instrument's market or currency differs from the universe's.
- For an overlapping ticker, the S&P 500 dataset is the source of truth for company and classification. The Nasdaq-100 seeder only attaches membership to an Instrument that is already in `sp500`. It never overwrites that Instrument's fields.

**D4. Screener universe registry.** `universes` gains:
- `market` (string 8, default `US`);
- `currency` (char 3, default `USD`);
- `is_screener` (boolean, default `false`);
- `position` (small int, default 0, the selector order).

`sp500` is backfilled to `is_screener = true, position = 0`. `market-context` stays `is_screener = false`. The display name is `universes.name`: "S&P 500", "Nasdaq-100", "IBEX 35", "DAX". These are proper nouns and are not translated.

**D5. Default and selection.**
- The default universe stays `config('ingestion.universe')` (`sp500`).
- The Screener URL carries `universe=<slug>` only when it is not the default.
- The API accepts `universe` on `GET /api/screener`. A slug that is unknown, is not a Screener universe, or is not visible to the caller returns the existing JSON `404 universe_not_found`. This keeps the current contract. Validation is server-side.

**D6. Constituent snapshots and rebalances.** Each new index has an offline, committed snapshot:
- `database/data/<slug>.csv`, same header as `sp500.csv`: `ticker,company,sector,industry,country,exchange`;
- `database/data/<slug>.md`: source URL, retrieval date (`as_of`), SHA-256 of the raw source, transform, classification rules and exceptions, and the rebalance procedure.

Index seeders are offline and idempotent. **Unlike the add-only S&P 500 seeder**, they sync membership to the snapshot exactly:
- they attach new members;
- they detach members that are no longer listed;
- they never delete an Instrument or its history;
- an Instrument left in no Screener universe becomes `active = false`, keeps its chart and Watchlist entries, and is no longer ingested;
- a re-added Instrument becomes `active = true` again.

Rebalance procedure, written in each `.md`:
1. Replace the CSV with a new dated snapshot.
2. Update `as_of` and the hash.
3. Run the seeder (deploy runs it).
4. Record the change in `PROGRESS.md`.

Review cadence to note in each `.md`:
- Nasdaq-100: annual December reconstitution plus ad-hoc replacements;
- IBEX 35: quarterly reviews by BME's Technical Advisory Committee;
- DAX: quarterly reviews (March, June, September, December).

A stale snapshot is a known, documented risk. It is not detected automatically.

**D7. Classification of non-S&P constituents.**
- `sector` holds one of the 11 GICS sector names used by `ScreenerFilterParser::SECTORS`, so the existing Sector filter and its labels work unchanged. For European and non-S&P Nasdaq-100 members it is an **editorial mapping** from the source's own sector, listed per ticker in the `.md`.
- `industry` holds the source's industry or sector text verbatim (or null), as with the S&P 500.
- `country` is the ISO-3166 alpha-2 headquarters country. It is not the listing country: Airbus is NL, ArcelorMittal is LU, ASML is NL.
- Unknown values stay null.

**D8. Currency display.** Each Screener universe has a single currency, so a Candidate table never mixes currencies.
- The Close heading shows the code ("Cierre (EUR)" / "Close (EUR)").
- Close filter bounds are in that currency.
- The chart header shows the exchange and currency.
- The Watchlist shows the currency code next to each price.
- Prices keep two decimals in the locale format. No currency symbols are needed.
- The API adds `currency` and `market` to Candidate, instrument and Watchlist payloads.

**D9. Visibility flag and kill-switch.** `config/universes.php` holds `public` (env `SCREENER_UNIVERSES_PUBLIC`, comma list, default `sp500`).
- A seeded Screener universe that is not in the list is visible only to Admins (preview), on every surface.
- Ingestion runs for every seeded Screener universe, public or not.
- **Owner decision 2026-10-26:** every new universe becomes public to everyone, Visitors included, as soon as its feature is verified in production. Production target: `SCREENER_UNIVERSES_PUBLIC=sp500,nasdaq100,ibex35,dax`. Each enablement is recorded in `PROGRESS.md`.
- **Kill-switch:** removing a slug from the env var and running `php artisan config:cache` hides that universe immediately from every non-Admin surface (selector, API 404, Saved Screener validation; existing alerts on it are skipped). No data is deleted, and re-adding the slug restores it. Document this procedure in `deploy/README.md` (Features 4–6) for a takedown or terms issue.

**D10. Feature applicability.**

| Feature | `US` (S&P 500, Nasdaq-100) | `XMAD`, `XETR` |
| --- | --- | --- |
| Daily Bars, Indicator Snapshots, Signals, Chartist Patterns, Candidate table, column filters/sorts, chart | yes | yes |
| Saved Screeners, Screener alerts (in the saved universe), Watchlist, Watchlist signal/pattern alerts | yes | yes |
| Natural-language Screener (applies to the selected universe) | yes | yes |
| Sector context filters (`sector_trend`, `sector_rs_rank_max`) and chart sector strip | yes (SPDR sector of the stock's GICS sector) | **no**: the API returns 422 `filter_not_available_for_universe`; the UI hides them; screener rows store null |
| Market Context page, Breadth, Market Regime | S&P 500 only, unchanged | no |
| SEC Fundamentals, AI Company Card, forecast | yes. Non-S&P Nasdaq-100 ADR filers may show the existing `no_cik`/`no_data` states | **no**: endpoints return 404 with a localized "not available for this market" message; the chart page hides those panels |

**D11. Public labels.** Public UI never says EOD or "end of day" (AGENTS.md). Dates use the existing "Datos del {date}" / "Data as of {date}" wording, per universe. Admin may say EOD.

---

## Feature 1 — `universe-registry`

### Scope

1. **Migrations (additive).** Add D1 columns to `instruments` and D4 columns to `universes`, with defaults and the `sp500` backfill. Add `market` and `currency` to `screener_rows`, copied by `ScreenerRowBuilder`.
2. **Models.** Constants for Listing Markets in one place, for example `App\Support\ListingMarket` with code, timezone, close, currency and suffix. `Universe::scopeScreener()` and `Universe::scopeVisibleTo(?User)` apply `is_screener` and D9.
3. **Generic seeder.** Abstract `IndexUniverseSeeder`, configured by slug, name, market, currency, position and dataset path. It implements D2, D3, D6 and D7 rules, reusing the CSV reader of `Sp500UniverseSeeder` (extract a shared reader; do not change S&P behavior). It calls `ScreenerRowBuilder::refresh()` at the end, as today. Tests use a fixture dataset. No real index lands in this feature.
4. **`GET /api/universes`** (public). Returns the Screener universes visible to the caller, ordered by `position`: `slug`, `name`, `market`, `currency`, `members` (active stock members), `latest_date` (max `screener_rows.date` of its members, or null) and `is_default`.
5. **Screener API.** `ScreenerController::resolveUniverse` applies D5 and D9. Sector context filters with a non-`US` universe return 422 (D10). Candidate payload gains `market` and `currency`.
6. **Saved Screeners.** The canonical definition gains a `universe` key (slug). Validation: `Rule::in` the caller's visible Screener universes.
   - A row saved without the key reads as `sp500`.
   - `assertScreenerAccepts` re-checks D10 for the stored universe.
   - The list payload returns `universe` (slug and name).
7. **Alerts.** `AlertEvaluator` evaluates a `screener` alert in its Saved Screener's universe, not in `config('ingestion.universe')`. If that universe is no longer a visible Screener universe for the owner, the alert is skipped (no notification) and logged once.
8. **Sector context.** `ScreenerRowBuilder::sectorContext` returns nulls for non-`US` Instruments.
9. **US-only gates (D10).** Company Card (GET/POST), fundamentals and forecast endpoints return 404 with `messages.not_available_for_market` (es/en in `lang/*`) for non-`US` Instruments, like the existing ETF rule. `fundamentals:refresh` skips non-`US` Instruments. The instrument payload gains `market`, `currency` and an `availability` object (`company_card`, `fundamentals`, `forecast` booleans) so the SPA does not hardcode market rules.

### Implementation notes (`universe-registry`, 2026-10-26)

- Saved Screener universe: the `universe` key is stored in `filters` only when it is not the default universe, so S&P 500 definitions keep their twenty keys and existing payloads/tests are unchanged. The list/create payload always returns a top-level `universe: {slug, name}` (legacy rows read as the default). Validation is `filters.universe` `Rule::in` the caller's visible Screener universes.
- The sector-context 422 is `{message, code: "filter_not_available_for_universe", errors: {<key>: [...]}}` and runs before the 401/403 Market Context check.
- `Universe::visibleTo` / `isVisibleTo` is the single visibility seam; the factory default for `is_screener` is `true` (tests), the column default is `false`.
- The alert skip for a no-longer-visible universe logs once per evaluation (`Log::info`) and leaves `last_state` untouched.
- Fundamentals: the only fundamentals surfaces are the Company Card responses and `fundamentals:refresh`; both are gated.

### Acceptance

- With migrations applied, existing Instruments are `US`/`USD`, `sp500` is the only Screener universe, and every existing Screener, Saved Screener and alert test passes unchanged. The default response is identical except for the added `market` and `currency` fields.
- A fixture universe `fixture-eu` (`XMAD`, EUR, two members ending in `.MC`) seeded by a test subclass of `IndexUniverseSeeder`:
  - is listed by `GET /api/universes` for an Admin;
  - is absent for a Visitor while it is not public, and present once it is in `SCREENER_UNIVERSES_PUBLIC`;
  - `GET /api/screener?universe=fixture-eu` returns only its members, with `currency: "EUR"`;
  - `?universe=market-context`, an unknown slug, or a non-public slug as a Visitor returns 404 JSON.
- Seeder rules, each covered by a test:
  - a `.MC` ticker in a `XETR` dataset throws;
  - a US-dataset ticker ending in `.DE` throws;
  - an existing Instrument with another market throws;
  - re-running the seeder is a no-op;
  - removing a member from the CSV detaches it, keeps its bars, and sets `active = false` only when it is in no other Screener universe;
  - an overlapping `sp500` Instrument keeps its S&P fields.
- An Instrument in two universes has exactly one `screener_rows` row and appears in both universes' Candidate lists.
- `sector_trend=uptrend&universe=fixture-eu` returns 422 for an entitled user. Screener rows of `XMAD` Instruments have null sector context.
- A Saved Screener with `universe: fixture-eu` round-trips. Legacy rows read as `sp500`. A screener alert on it evaluates against `fixture-eu` members only.
- Company Card, fundamentals and forecast return 404 for a `.MC` Instrument, and the instrument payload reports `availability` false for all three.
- `.\init.ps1` passes. CI `test-pgsql` passes.

---

## Feature 2 — `ingestion-multi-market`

### Scope

1. **Engine (`engine/app/sources/stooq.py`, `engine/app/main.py`).**
   - `GET /eod/{symbol}?market=US|XMAD|XETR`. Default `US`, so today's calls are unchanged.
   - Yahoo symbol: for `US`, the current dot-to-hyphen rule (`BRK.B` → `BRK-B`); for `XMAD`/`XETR`, the ticker verbatim (`SAN.MC`, `SAP.DE`).
   - The symbol must end with the market suffix, otherwise 422.
   - Stooq fallback: kept for `US` (`.us`). For `XETR`/`XMAD`, only if the implementer verifies a working Stooq mapping with a recorded fixture; otherwise there is no fallback for that market, and the source note says so.
   - **Session dates.** Yahoo daily timestamps can be midnight local time, which is the previous day in UTC for European exchanges. Bar dates must be taken in the exchange timezone: `chart.meta.exchangeTimezoneName`, or the market's timezone when that is absent. They must not be taken in UTC.
   - The response gains `currency` from `chart.meta.currency` (null for Stooq).
2. **Laravel ingestion.**
   - `EngineClient::eodBars($ticker, $market)` passes the market.
   - `InstrumentIngestor` fails the item with a `currency_mismatch` error, storing nothing, when the engine reports a currency other than the Instrument's. This guards against a wrong symbol mapping.
3. **Calendars.**
   - `config/ingestion.php` gains `markets.<code>` with `timezone`, `close`, `buffer_minutes` and `holidays`.
   - `US` reuses the existing keys and NYSE list (backward compatible env vars).
   - `MarketCalendar` takes a market code: `isTradingDay($market, $date)` and `latestSession($market, $now)`. `latestSession` is the most recent trading day whose close plus buffer has passed, in that market's timezone.
   - Existing callers (`RunIngestionPipeline`, `ForecastStateBuilder`) pass `US`.
   - Real `XMAD`/`XETR` holiday lists arrive with Features 5 and 6. This feature tests with fixture lists.
4. **Pipeline.** `ingestion:pipeline {--market=US} {--universe=} {--force}`:
   - It processes the market's Screener universes in `position` order. `--universe` limits it to one universe, keeping today's `--universe=sp500` usage working.
   - For each universe: `ingestion:run`, `indicators:compute`, `signals:detect` and `patterns:detect`, with a new `--except-universe=` (repeatable) option on those four commands. Members already handled by an earlier universe of the same run are skipped. Nasdaq-100 therefore fetches only its non-S&P members.
   - The first universe keeps today's failure policy. A later universe's failure is isolated: it is logged, the remaining stages still run, and the exit code is 1.
   - `US` only: the Market Context block, macro, Market Regime, `alerts:evaluate` and `forecast:resolve`, in today's order.
   - Every market ends with `screener:refresh`.
   - **Alerts run once a day, in the US pipeline**, over all universes. European data of that day is already stored by then. On a US holiday, alerts are evaluated at the next US run, which still notifies the additions.
5. **Schedule (`routes/console.php`).**
   - One event per Listing Market that has at least one seeded Screener universe: weekdays at `close + buffer` in that market's timezone, skipping its holidays.
   - Defaults: US 16:30 New York (unchanged); `XMAD` 18:15 Madrid; `XETR` 18:15 Berlin. The 45-minute EU buffer covers the closing auction and source publication.
   - The `ingestion:pipeline` lock stays global, so stages never run concurrently. A pipeline that finds the lock held now **waits** up to `ingestion.lock_wait_seconds` (default 900) before skipping with a warning. The two EU pipelines can therefore share the same time.
6. **Admin freshness.**
   - `GET /api/admin/universes/freshness` (Admin only) returns, per Screener universe: `latest_date` (most common latest bar date among active members), `expected_date` (`latestSession`), members with and without a bar on `expected_date`, and the status: `fresh` (all on expected), `partial`, or `stale` (latest older than expected).
   - The Admin manual run already accepts any slug and is unchanged.

### Acceptance

- Engine tests (recorded fixtures, no network):
  - a Yahoo `.DE` response with midnight-Europe/Berlin timestamps yields the correct session dates, with no weekend dates and no one-day shift;
  - a `US` fixture still maps `BRK.B` to `BRK-B`;
  - `SAN.MC` with `market=XETR` returns 422;
  - `currency` is returned;
  - a call without `market` behaves exactly as today.
- A `currency_mismatch` item fails cleanly and stores no bars. The run is `partial`.
- `MarketCalendar` tests with fixture holidays: a `XETR` holiday that is a US trading day (and the reverse) is skipped only by its own market. `latestSession` before and after close plus buffer, across the CET/CEST and EST/EDT changes (late March and late October, when European and US DST changes fall on different dates).
- Pipeline tests with fixture universes:
  - the US run processes `sp500` then a second US universe, and the overlapping members are fetched once (engine fake call count);
  - a failure in the second universe does not skip `screener:refresh` or alerts;
  - an `XMAD` run never calls Market Context, macro, regime, alerts or forecast stages;
  - a held lock is waited on and then taken (or skipped after the wait), shown with a fake lock.
- Schedule test: three events with the right timezones and times, and no event for a market without a seeded Screener universe.
- Freshness endpoint: 403 for non-Admins. Correct `fresh`/`partial`/`stale` on fixtures.
- `.\init.ps1` passes, including the engine tests.

---

## Feature 3 — `universe-selector-ui`

### Scope

1. **Screener selector.**
   - A universe control at the top of the Screener (`frontend/src/pages/ScreenerPage.tsx`), fed by `GET /api/universes`. It shows name, Listing Market label (i18n) and "Datos del {date}". Use a segmented control on desktop and a select at 390 px, following DESIGN.md (tokens, focus ring, light/dark).
   - The URL param `universe` is omitted for the default (`frontend/src/lib/screenerFilters.ts`).
   - An unknown or hidden slug in the URL falls back to the default, removes the param, and shows no error.
2. **Switching universe.**
   - Resets the offset.
   - Keeps technical, Signal, pattern, search, sector, industry and country filters, and the sort.
   - Clears `close_min`/`close_max` when the currency changes.
   - Clears and hides the sector context filters for non-`US` universes (D10).
3. **Currency and market labels (D8).** Close heading with the code. Chart header "{exchange} · {currency}". Watchlist price with the code. Candidate rows of a non-default universe link to the same `/instruments/{ticker}` chart.
4. **Chart page.** The Company Card, fundamentals and forecast panels render only when the instrument payload's `availability` flags allow it. Otherwise they show nothing. There is no error state.
5. **Saved Screeners.** Saving stores the current universe. The list shows the universe name as a chip. Applying restores universe plus filters.
6. **Natural-language Screener.** It sends the current universe with the request context, and applying a result keeps the universe.
7. **Admin.** A "Universos" freshness table on the Admin ingestion page, using Feature 2's endpoint. Admin text may say EOD.
8. **i18n.** All new strings in `frontend/src/i18n/messages/{es,en}.ts`. Universe names come from the API and are not translated. No EOD wording in public UI.

### Implementation notes (`universe-selector-ui`, 2026-10-26)

- Currency in the Close heading, its sort label and its column filter is shown only while the selector is visible (more than one universe), so the single-universe Screener (production today) is byte-identical. The chart header (`exchange · currency`) and the Watchlist badge show it whenever the payload carries `currency`.
- The Watchlist has no price column, so «the currency next to each price» is the `exchange · currency` badge on each row.
- `POST /api/screener/interpret` gained an optional `universe` (the one small backend change): 404 for an unknown or hidden slug, and for a non-`US` universe the sector context keys are nulled and named once in `unsupported`.
- The user guide (`frontend/src/i18n/guide`) is unchanged: it still says the universe is the S&P 500, which stays true until `universe-nasdaq100`, `universe-ibex35` or `universe-dax` is made public; those features must update it.

### Acceptance

- Vitest:
  - selector renders the API list in order, with Market label and date in es/en;
  - choosing a universe updates the URL and refetches with `universe=`; default omits it;
  - an invalid URL slug falls back;
  - the switch rules of item 2;
  - sector context filters are hidden for a `XMAD` universe;
  - Close heading shows `(EUR)`;
  - Saved Screener chip and restore;
  - chart panels hidden when `availability` is false;
  - no hardcoded text.
- Visual smoke (Playwright, local fixtures) at 390 px and 1440 px, light and dark: the selector has no horizontal page overflow and keyboard focus is visible.
- `.\init.ps1` passes.

---

## Feature 4 — `universe-nasdaq100`

### Scope

- `database/data/nasdaq100.csv` and `nasdaq100.md`: the snapshot is taken at implementation time from a documented public source (Nasdaq's published constituent list or Wikipedia "Nasdaq-100"). Record `as_of`, hash and the expected count (about 100–101 securities, because of dual share classes such as GOOGL/GOOG).
- `Nasdaq100UniverseSeeder extends IndexUniverseSeeder`: slug `nasdaq100`, name "Nasdaq-100", `US`, USD, position 1.
  - Overlapping S&P 500 tickers only gain membership (D3).
  - Non-S&P members (for example, foreign ADRs) get classification under D7, with ISO HQ country (ASML is NL, AZN is GB).
- `DatabaseSeeder` and `deploy/deploy.sh` (plus `deploy/README.md`) run it after `Sp500UniverseSeeder`.
- The US pipeline picks it up automatically (Feature 2). The first run backfills about 2 years from the source for the new members.

### Implementation notes (`universe-nasdaq100`, 2026-10-26)

- Snapshot as_of 2026-10-26: 101 securities (100 companies; `GOOGL` and `GOOG`), membership cross-checked between Nasdaq's quote-list endpoint and Wikipedia (identical ticker sets). 86 are S&P 500 members, 15 are not (`ALAB`, `ALNY`, `ARM`, `ASML`, `CCEP`, `CRWV`, `FER`, `MELI`, `MSTR`, `NBIS`, `PDD`, `RKLB`, `SHOP`, `SPCX`, `TRI`). Sources, hashes and the per-ticker classification are in `database/data/nasdaq100.md`.
- The editorial GICS sector and the headquarters country of the 15 non-S&P members are manual classifications (the `.md` lists them and flags the uncertain ones).
- The user guide (es/en) now says the default universe is the S&P 500 and that the Nasdaq-100 is available where the selector appears (true whether or not it is public). The home/meta copy ("Filter S&P 500 stocks") is unchanged until the universe is made public.
- `DatabaseSeeder` and `deploy/deploy.sh` run the seeder after the S&P 500; the production rollout is in `deploy/README.md` item 12.

### Acceptance

- The seeder test on the committed CSV checks:
  - expected row count;
  - every overlapping ticker resolves to the existing S&P Instrument, and its fields are unchanged;
  - no duplicate Instruments;
  - every `sector` is one of the 11 GICS names;
  - every `country` is ISO-2 or null.
- Hand check recorded in evidence: the CSV matches the source on `as_of` (count, a sample of 10 tickers, and the list of non-S&P members).
- A local or production run fetches only the non-S&P members for `nasdaq100` (Ingestion Run `total` equals that count), and the Screener shows Nasdaq-100 Candidates for an Admin before the universe is public.
- Production enablement: add `nasdaq100` to `SCREENER_UNIVERSES_PUBLIC` once the production run is verified (owner approved 2026-10-26). Record it in `PROGRESS.md`.

---

## Feature 5 — `universe-ibex35`

### Scope

- `database/data/ibex35.csv` and `ibex35.md`: the snapshot comes from BME's published IBEX 35 composition or Wikipedia "IBEX 35". Tickers use the `.MC` suffix. Exchange `BME`. Classification follows D7, with the editorial GICS-sector mapping per ticker listed in the `.md`.
- `Ibex35UniverseSeeder`: slug `ibex35`, name "IBEX 35", `XMAD`, EUR, position 2. Run by `DatabaseSeeder` and deploy.
- `config/ingestion.php` `markets.XMAD.holidays`: the official BME trading calendar for the current and next year, with source URL and a "refresh annually" note. Weekday closures only. Half days are trading days.
- Live smoke: `GET /eod/SAN.MC?market=XMAD` on the running engine returns about 2 years of EUR bars with weekday session dates. Record a trimmed Yahoo response as a fixture.

### Implementation notes (`universe-ibex35`, 2026-10-26)

- Snapshot as_of 2026-10-26: 35 companies, the BME composition in force since 22 July 2024 (Puig in, Meliá out); the 11 June 2026 committee left it unchanged. BME publishes it only as dynamic pages and PDFs, so tickers, names and the `industry` text come from Wikipedia's table citing BME's 23 September 2024 notice. The September 2026 review was not found in any source and is unverified. Sector (editorial GICS) and headquarters country are manual (`ArcelorMittal` LU, `Ferrovial` NL, the rest ES; uncertain: `CLNX`, `FDR`, `IDR`, `LOG`, `IAG` country). Details and hashes in `database/data/ibex35.md`.
- Holidays: 2026 as published by BME (1 Jan, 3 Apr Good Friday, 6 Apr Easter Monday, 1 May, 25 Dec). 24 and 31 December trade until 14:00 and stay trading days. 2027 (1 Jan, 26 Mar, 29 Mar) is derived from the pattern because BME had not published it; confirm it when it does.
- Live read-only smoke on `SAN.MC` (engine `fetch_eod`, market XMAD): EUR, 509 sessions, `Europe/Madrid`, no weekend dates, no session on the BME closures. A trimmed real response is the engine fixture `yahoo_san_mc.json`.
- The user guide (es/en) mentions the IBEX 35 as available where the selector offers it. The home/meta copy is unchanged until the universe is made public.

### Acceptance

- The seeder test on the committed CSV checks:
  - 35 rows, every ticker ending in `.MC`;
  - market `XMAD`, currency EUR;
  - GICS sector names and ISO countries valid;
  - idempotent.
- The calendar test lists the committed `XMAD` holidays and checks that each is a weekday.
- An `XMAD` pipeline run (`--force` locally) ingests the 35 members (fewer failures noted in evidence) and computes indicators, Signals and patterns. Candidates appear in the Screener with EUR for an Admin.
- The production schedule shows the 18:15 Europe/Madrid event (`php artisan schedule:list`).
- Public enablement: add `ibex35` to `SCREENER_UNIVERSES_PUBLIC` once the production run is verified (owner accepted R1 on 2026-10-26). Record it in `PROGRESS.md`. The kill-switch procedure is in `deploy/README.md`.

---

## Feature 6 — `universe-dax`

### Scope

The same as Feature 5, with these differences:
- slug `dax`, name "DAX" (the official index name, owner decision 2026-10-26; it has 40 constituents), `XETR`, EUR, position 3;
- source: Deutsche Börse / STOXX published composition or Wikipedia "DAX";
- `.DE` suffix, exchange `XETRA`;
- `markets.XETR.holidays` from the official Xetra trading calendar;
- live smoke on `SAP.DE`. `MRK.DE` must stay distinct from the S&P 500 `MRK` (D2).

### Implementation notes (`universe-dax`, 2026-10-31)

- Snapshot as_of 2026-10-31: 40 companies. Base list is Wikipedia's DAX table (22 Sep 2025) updated with the official STOXX announcements: no DAX change on 3 Dec 2025, 4 Mar 2026 and 3 Sep 2026; 3 Jun 2026 (effective 22 Jun) Hochtief in, Porsche SE out. Wikipedia's Paris line `AIR.PA` is `AIR.DE`. All 40 `.DE` symbols answered on Yahoo (EUR, 507 sessions). Not verified: the March/June/September 2025 reviews (Wikipedia only), off-cycle DAX changes and Deutsche Börse's own factsheet; sector, country (`AIR`, `QIA` uncertain) and industry are editorial. Sources, hashes and the per-ticker classification are in `database/data/dax.md`.
- Holidays: Deutsche Börse's page lists 2026 (1 Jan, 3 Apr, 6 Apr, 1 May, 24, 25 and 31 Dec) and 2027 (1 Jan, 26 Mar, 29 Mar, 24 and 31 Dec) but warns that future years may change: 2027 stays flagged to confirm. Unlike BME, 24 and 31 December are full non-trading days and are listed.
- Live smoke on `SAP.DE`: EUR, 507 sessions 2024-10-30..2026-10-30, `Europe/Berlin`, no weekend dates, no session on the Xetra closures. A trimmed real response around Easter 2026 is the engine fixture `yahoo_sap_de_easter.json` (`yahoo_sap_de.json` from `ingestion-multi-market` is unchanged).
- The user guide (es/en) mentions the DAX as available where the selector offers it. The home/meta copy is unchanged until the universe is made public. Rollout: `deploy/README.md` item 17.

### Acceptance

The same as Feature 5, with 40 rows, `.DE` and slug `dax` in the public list. Also: a test that `MRK.DE` and `MRK` are separate Instruments with separate bars, and that public labels say "DAX" (never "DAX 40").

---

## Repository Research (inspected 2026-10-26)

- `database/seeders/Sp500UniverseSeeder.php`:
  - offline CSV;
  - `updateOrCreate` by unique `ticker`;
  - add-only `syncWithoutDetaching`;
  - refreshes Screener rows.
- `database/seeders/MarketContextUniverseSeeder.php`, `DatabaseSeeder.php`. `deploy/deploy.sh` seeds `MarketContextUniverseSeeder` on every deploy.
- Migrations:
  - `universes` (name, slug);
  - `instruments` (unique `ticker`, exchange, `kind`, classification);
  - pivot `instrument_universe` (unique pair);
  - `screener_rows` (unique `instrument_id`, not universe-scoped).
- `engine/app/sources/stooq.py`:
  - Yahoo first, then Stooq;
  - Stooq hardcodes `{symbol}.us`;
  - Yahoo converts `.` to `-`;
  - Yahoo dates are taken from UTC timestamps.
- `engine/app/main.py`: `GET /eod/{symbol}`. `app/Services/Engine/EngineClient.php::eodBars($ticker)`.
- `config/ingestion.php`: single timezone, close, buffer, NYSE holidays and default `universe`.
- `app/Services/Market/MarketCalendar.php`: NYSE-only. Used by the pipeline and `ForecastStateBuilder`.
- `app/Console/Commands/RunIngestionPipeline.php`:
  - global lock that skips when held;
  - US stages plus the isolated Market Context, macro and regime blocks;
  - global `screener:refresh`;
  - alerts;
  - forecast.
- `app/Http/Controllers/ScreenerController.php`: already accepts `?universe=`; unknown slug returns 404.
- `app/Services/Screener/CandidateQuery.php`: filters by pivot. `ScreenerRowBuilder.php`: builds every stock Instrument; sector context comes from the US sector ETF snapshot by GICS name.
- `app/Http/Controllers/SavedScreenerController.php`: twenty-key canonical definition, with no universe.
- `app/Services/Alerts/AlertEvaluator.php`: screener alerts use `config('ingestion.universe')`.
- `frontend/src/i18n/format.ts`: `formatPrice` assumes USD. `frontend/src/lib/screenerFilters.ts` URL state. `frontend/src/pages/ScreenerPage.tsx` shows the universe name. `AdminPage.tsx` takes a free-text universe slug.
- Not inspected in detail: the Watchlist page component, the chart header component, the Company Card/forecast controllers' ETF gates (to be mirrored), and `docs/specs/stock-comparison.md` beyond its entry.

## Durable Documentation Impact

- `AGENTS.md`: updated now (scope guard).
- `CONTEXT.md`: Universe and Listing Market updated now.
- `docs/domain-model.md`: updated now.
- `docs/risks-and-open-questions.md`: updated now.
- `ARCHITECTURE.md`: update in Feature 2 (per-market schedule, lock wait, engine `market` parameter).
- `DESIGN.md`: update in Feature 3 (selector component).
- `deploy/README.md`: update in Features 4–6 (seeders, enablement flag).
- `docs/specs/stock-comparison.md`: when implemented, its search should include all active stocks. Comparing EUR and USD lines is fine because prices are indexed to 100 on exact common dates. Note this in that spec when it is picked up.
- `CONSTRAINTS.md`: not needed.

## Risks

- **R1 Data licensing (owner-accepted 2026-10-26).** European prices come through the same unofficial Yahoo path. BME and Deutsche Börse license their market data, and redistribution terms for displaying EOD prices to the public are not cleared. This is the same pending question as the US data, with a stronger exchange-licensing angle. The owner accepted the risk and chose public display. Mitigation: the D9 kill-switch hides a universe at once if a takedown or terms issue arises.
- **R2 Index trademarks.** "Nasdaq-100", "IBEX 35" and "DAX" are trademarks of Nasdaq, BME and Deutsche Börse/STOXX. Chartiko uses the names only to describe constituent lists. It shows no index values and no endorsement. Review together with the legal notice.
- **R3 Source reliability for non-US symbols.** There may be no Stooq fallback for `XMAD`/`XETR`. Yahoo `.MC`/`.DE` volume is single-venue (BME / Xetra only, excluding MTFs). RVOL stays consistent because it is relative, but absolute volume understates trading.
- **R4 Dates and calendars.** The midnight-local timestamp issue (Feature 2). Three holiday lists to refresh every year. DST changes in Europe and the US fall on different weeks.
- **R5 Constituent drift.** Snapshots go stale after each rebalance until the procedure is run.
- **R6 Volume and time.** About 16 extra US fetches plus 35 and 40 EU fetches a day. EU runs finish well before the US run. The lock wait covers the two EU events running at the same time.

## Open Questions (owner)

All answered by the owner on 2026-10-26 (see Source Features): Nasdaq-100 (Composite deferred), public to everyone, IBEX 35/DAX published with the risk accepted, label "DAX". No open owner questions remain for Features 1–6.

## Validator Checklist (all features)

- Default S&P 500 behavior, payloads (apart from added fields) and existing tests are unchanged.
- No duplicate Instruments, bars or Screener rows for overlapping tickers. `MRK` and `MRK.DE` are distinct.
- Universe access is enforced server-side (404 for hidden, non-Screener and unknown slugs). Sector context filters return 422 outside `US`.
- US-only features are unreachable for `XMAD`/`XETR` Instruments.
- European bar dates are correct session dates. Each market's calendar and schedule is independent.
- No EOD wording in public UI. es/en complete. DESIGN.md respected.
- Evidence recorded in `feature_list.json`, and `.\init.ps1` passes.
