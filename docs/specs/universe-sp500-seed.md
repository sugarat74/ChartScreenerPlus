# Feature Implementation Spec: Seed the S&P 500 universe

## Source Feature

- `id`: `universe-sp500-seed`
- `area`: `data`
- `depends_on`: `db-schema-market-data`
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Populate the S&P 500 universe from a committed dataset so the rest of the product always has a known Instrument set to work against. After this feature, running the seeder creates/updates the `S&P 500` universe and its Instruments (ticker, company, sector, exchange) idempotently, with no network access.

This slice only seeds Instruments/Universes. It does not fetch quotes, compute indicators or expose data.

## Non-Goals

- No quote/daily-bar ingestion (that is `ingestion-scraper-eod`).
- No indicator or signal logic.
- No live scraping or network calls at seed time.
- No API endpoints or UI.
- No other universes (NASDAQ 100, Russell 2000, IBEX) and no scheduled refresh.

## Job Story

When I ingest quotes or run a screener,
I want a reliable, offline list of S&P 500 Instruments in the database,
so I can process a known universe without depending on an external list at runtime.

## Users And Permissions

- Developer/Operator (local or CLI): runs the seeder. No end-user roles involved.

## Acceptance Scenarios

### Scenario 1: Seeding creates the universe and its instruments

Given an empty database,
When the S&P 500 seeder runs,
Then one `S&P 500` universe exists with one Instrument per dataset row.

### Scenario 2: Instruments carry the expected fields

Given the seed has run,
When a known ticker (e.g. `NVDA`) is read,
Then its `company` and `sector` match the dataset and `ticker` is unique.

### Scenario 3: Re-seeding is idempotent

Given the seed has already run,
When it runs again,
Then instrument and pivot counts are unchanged (no duplicates).

### Scenario 4: Instruments are attached to the universe

Given the seed has run,
When the universe's instruments are read,
Then every seeded Instrument belongs to the `S&P 500` universe.

## Repository Research

### Files Inspected

- `app/Models/Universe.php`, `Instrument.php` — `belongsToMany` via `instrument_universe`; `#[Fillable]`.
- `database/migrations/*_create_universes_table.php`, `*_create_instruments_table.php`, `*_create_instrument_universe_table.php` — columns and uniqueness (`ticker` unique; pivot unique pair).
- `database/seeders/DatabaseSeeder.php` — seeder entry point.
- `database/factories/InstrumentFactory.php`, `UniverseFactory.php` — factory defaults for tests.
- `tests/Feature/MarketDataSchemaTest.php` — `RefreshDatabase` patterns.
- `docs/domain-model.md` / `CONTEXT.md` — Universe/Instrument definitions.
- `CONSTRAINTS.md` — DB conventions (SQLite local/tests).

### Environment Findings (probed, not assumed)

- Tables already exist (accepted `db-schema-market-data`); `instruments.ticker` is unique and the pivot is unique per pair.
- `sector`/`exchange` are nullable; `active` defaults true.
- No dataset or seeder for universes exists yet.
- Tests run on in-memory SQLite (no network).

### Existing Patterns To Follow

- Seeders live in `database/seeders`; runnable via `php artisan db:seed --class=...`.
- Models use `#[Fillable]`; idempotent writes via `updateOrCreate` / `syncWithoutDetaching`.
- Tests use `RefreshDatabase`.

### Current Gaps

- No committed constituent dataset.
- No universe seeder or test.

## Technical Approach

1. **Committed dataset.** Add `database/data/sp500.csv` with header `ticker,company,sector,exchange` and one row per constituent. Generate it once at implementation time from a public source (e.g. the Wikipedia "List of S&P 500 companies" table); `exchange` may be empty (the schema allows null) when the source does not provide it. Normalize: trim, uppercase tickers, dedupe, strip blank lines/BOM. Note the source and generation date in a header comment or the seeder docblock.
2. **Seeder.** `database/seeders/Sp500UniverseSeeder.php`:
   - `updateOrCreate(['slug' => 'sp500'], ['name' => 'S&P 500'])` for the universe.
   - For each CSV row, `Instrument::updateOrCreate(['ticker' => ...], ['company' => ..., 'sector' => ..., 'exchange' => ..., 'active' => true])`.
   - Attach idempotently with `$universe->instruments()->syncWithoutDetaching($ids)` (or `sync($ids)` to also prune removed members; choose `syncWithoutDetaching` for add-only, document the choice).
   - Use a transaction for the whole seed.
3. **Entry point.** Register the seeder in `DatabaseSeeder` (or document the explicit `--class` invocation); keep it safe to run repeatedly.
4. **Parsing.** Read the CSV with a small, testable helper (e.g. a private method or `League\Csv` only if already available — do not add a dependency; use `fgetcsv`/`str_getcsv`). Handle quoted fields.
5. **Test.** `tests/Feature/Sp500UniverseSeederTest.php` (`RefreshDatabase`): run the seeder twice; assert the universe exists; instrument count equals the dataset row count (read the CSV dynamically, not a hardcoded 503); pivot count equals instrument count; a spot-checked ticker has the expected company/sector; counts are unchanged after the second run.

## Expected File Changes

- `database/data/sp500.csv` — create; committed constituent dataset.
- `database/seeders/Sp500UniverseSeeder.php` — create; idempotent seeder.
- `database/seeders/DatabaseSeeder.php` — modify; call the universe seeder (or document `--class`).
- `tests/Feature/Sp500UniverseSeederTest.php` — create.
- `CONSTRAINTS.md` — update (dataset location + idempotent-seeding rule).
- `PROGRESS.md`, `feature_list.json` — update with evidence.

## Visual Design Impact

- UI involved: no. `DESIGN.md` is not applicable to this slice.

## Durable Documentation Impact

- `ARCHITECTURE.md`: not needed — seeding data does not change boundaries; the universe source/format is captured here and in `CONSTRAINTS.md`.
- `CONSTRAINTS.md`: update — MUST rules: the S&P 500 dataset lives at `database/data/sp500.csv`; seeding is offline and idempotent (upsert by ticker, no duplicates); tests never use the network.
- `AGENTS.md`: not needed — no workflow/startup change.
- Other docs: `PROGRESS.md` and `feature_list.json` — update with evidence.

## Key Implementation Risks

- **Dataset accuracy** — a bad/wrong source or a broken parse yields wrong Instruments. Validate row count, uniqueness and a spot-check; record the source and date.
- **Idempotency** — re-running must not duplicate; rely on `ticker` uniqueness and `updateOrCreate` + `syncWithoutDetaching`; prove it with a re-run test.
- **Network-free tests** — the seeder must read the committed file, never fetch; tests must pass offline.
- **Scope creep** — do not add quotes, indicators, other universes or a refresh scheduler.
- **CSV hygiene** — handle BOM, quotes, blank lines and trailing whitespace.

## Implementation Plan

1. Generate and commit `database/data/sp500.csv` (normalized), noting the source/date.
2. Implement `Sp500UniverseSeeder` (parse + idempotent universe/instruments/pivot).
3. Wire it into `DatabaseSeeder` and confirm it can be run via `--class`.
4. Add `Sp500UniverseSeederTest`.
5. Verify: seed twice, `php artisan test`, `.\init.ps1`.
6. Update `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [x] Create `database/data/sp500.csv` with `ticker,company,sector,exchange` (normalized; source/date noted).
- [x] Implement `database/seeders/Sp500UniverseSeeder.php` (idempotent universe + instruments + pivot).
- [x] Wire it into `database/seeders/DatabaseSeeder.php`.
- [x] Add `tests/Feature/Sp500UniverseSeederTest.php` (creates, spot-check, idempotent re-run).
- [x] Run `php artisan db:seed --class=Sp500UniverseSeeder` twice and confirm stable counts.
- [x] Run `php artisan test` and `.\init.ps1` (exit 0, no server).
- [x] Update `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Findings

- Dataset: `database/data/sp500.csv`, 503 constituents, UTF-8 without BOM, source Wikipedia "List of S&P 500 companies" (`https://en.wikipedia.org/wiki/List_of_S%26P_500_companies`), generated once on **2026-09-29** from the `id="constituents"` component table (Symbol / Security / GICS Sector).
- The Wikipedia table does not carry an exchange column, so `exchange` was derived from each symbol's listing link (`nyse.com` -> `NYSE` 344 rows, `nasdaq.com` -> `NASDAQ` 158, `markets.cboe.com` -> `CBOE` 1); it is left empty when the link is unknown. This is derivable from the same source and is documented in the seeder docblock.
- The CSV is written with an exact `ticker,company,sector,exchange` header and minimal RFC 4180 quoting (13 company names such as `"BXP, Inc."` are quoted). Only two company names are non-ASCII: `Brown–Forman` (en dash U+2013) and `Estée Lauder Companies (The)` (é U+00E9), so the file is UTF-8.
- No comment line is embedded in the CSV (it would complicate the header contract); the source/date/generation notes live in the seeder docblock, `CONSTRAINTS.md` and `PROGRESS.md`.
- The seeder parses with `fgetcsv(..., escape: '')` (RFC 4180, no proprietary escape char) and skips a `#`-free dataset as-is; it strips a BOM from the header, uppercases/trims tickers, skips blank lines and dedupes by ticker (first occurrence). The whole seed runs in a single `DB::transaction`.
- `syncWithoutDetaching` was chosen over `sync` so re-seeding is add-only and never detaches an instrument that was manually added to the universe; documented in the seeder.
- The test reads the dataset row count dynamically and asserts the header, so it does not hardcode 503.
- E2E: no frontend/E2E harness exists and this feature has no user/API flow; feature tests are the right level (recorded gap).

## Verification Plan

- `php artisan migrate:fresh` then `php artisan db:seed --class=Sp500UniverseSeeder` → the universe and N instruments (= dataset rows) exist; `Instrument::count()` matches the CSV row count.
- Run the seeder a second time → instrument and pivot counts unchanged.
- `php artisan test` → `Sp500UniverseSeederTest` passes; suite green, exit 0.
- Spot check via `php artisan tinker` or the test: `NVDA` present with its company/sector.
- `.\init.ps1` → Laravel + SPA + engine checks, exit 0, no server started.
- Persistent E2E: none exists and there is no user flow; feature tests are the right level. Record the gap.
- Startup script rule: `init.ps1` stays non-blocking and starts no server.

## Evidence To Capture

- The committed dataset path and row count, with source/date noted.
- Seeder run output (first + second run) and the resulting instrument/pivot counts.
- `php artisan test` output (seeder test + suite green, exit 0).
- Spot-check result for a known ticker.
- `.\init.ps1` output (exit 0).
- Confirmation `frontend/`, `engine/`, `alphapulse/` and the auth/market-data schema were not modified.

## Validator Checklist

- [ ] Implementation stays within this feature's scope (no quotes/indicators/other universes/UI).
- [ ] Acceptance scenarios pass.
- [ ] Verification evidence is present.
- [ ] Persistent E2E coverage was added/updated when the feature has an observable user/API flow and an E2E harness exists, or the spec explains why it is not needed.
- [ ] `feature_list.json` and `PROGRESS.md` were updated correctly.
- [ ] No unrelated product behavior or extra feature work was added.
- [ ] The dataset is committed at `database/data/sp500.csv` with a recorded source/date.
- [ ] Seeding is offline and idempotent (proven by a re-run test); instrument count matches the dataset row count.
- [ ] Instruments are attached to the `S&P 500` universe.
