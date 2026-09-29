# Feature Implementation Spec: Create the market-data database schema

## Source Feature

- `id`: `db-schema-market-data`
- `area`: `data`
- `depends_on`: `repo-scaffold-laravel`
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Create the persistence layer for market data: the migrations, Eloquent models and factories for Universes, Instruments, Daily Bars, Indicator Snapshots and Signals, matching `docs/domain-model.md`. After this feature, another feature can store scraped bars, compute indicator snapshots and persist signals against a tested schema.

This slice is schema + models + a persistence test only. It does not scrape, compute or expose data.

## Non-Goals

- No scraping/ingestion (that is `ingestion-scraper-eod` / `ingestion-run-orchestration`).
- No indicator math or signal detection (`indicators-compute` / `signals-detect`).
- No S&P 500 seed data (`universe-sp500-seed`).
- No API endpoints or controllers.
- No changes to the engine, SPA or Laravel auth/routing.

## Job Story

When I build ingestion or screening features,
I want a clear, tested database schema and Eloquent models for market data,
so I can store bars, snapshots and signals without re-deciding the data model each time.

## Users And Permissions

- Developer (local): runs migrations and tests. No end-user roles involved.

## Acceptance Scenarios

### Scenario 1: Migrations run and roll back cleanly

Given the Laravel app,
When the developer runs `php artisan migrate:fresh`,
Then the market-data tables are created with no error, and `php artisan migrate:rollback` reverses them cleanly.

### Scenario 2: One instrument round-trips with its data

Given the schema,
When a test creates one `Instrument` with one `DailyBar`, one `IndicatorSnapshot` and one `Signal`,
Then each row is persisted and read back through the Eloquent relationships.

### Scenario 3: Daily bars are unique per instrument and date

Given an existing `DailyBar` for `(instrument, date)`,
When a second bar with the same instrument and date is inserted,
Then the database rejects it (unique constraint).

### Scenario 4: Universe / Instrument many-to-many

Given a `Universe` and an `Instrument`,
When the instrument is attached to the universe,
Then the relationship resolves from both sides.

## Repository Research

### Files Inspected

- `database/migrations/0001_01_01_000000_create_users_table.php` — Laravel 13 migration style (`return new class extends Migration` with `up`/`down`).
- `app/Models/User.php` — Laravel 13 model style: PHP attributes `#[Fillable([...])]` / `#[Hidden([...])]`, `casts()` method, `HasFactory`.
- `database/factories/UserFactory.php` — factory convention.
- `database/seeders/DatabaseSeeder.php` — seeder convention (not used here).
- `phpunit.xml` — tests run on `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`.
- `tests/Feature/ExampleTest.php`, `tests/TestCase.php` — test conventions.
- `docs/domain-model.md` — entities, relationships and states.
- `docs/technical-discovery.md` / `CONSTRAINTS.md` — local DB is SQLite; schema is domain-specific.

### Environment Findings (probed, not assumed)

- Laravel 13.34.0 on PHP 8.4.8; `pdo_sqlite` enabled; tests use in-memory SQLite.
- Only the framework migrations exist today (`users`, `cache`, `jobs`); no domain tables.
- `app/Models` contains only `User`; `database/factories` only `UserFactory`.
- No domain seeders and no API routes.

### Existing Patterns To Follow

- Anonymous-class migrations with `up`/`down`.
- Models use `#[Fillable]` attributes and `casts()`, with `HasFactory`.
- Factories live in `database/factories`; tests use `RefreshDatabase`.
- Table names are plural and snake_case; pivots are alphabetical singular.

### Current Gaps

- No market-data tables, models, factories or tests.
- No signal-type vocabulary (defined later by `signals-detect`; the schema only stores a string `type`).

## Technical Approach

1. **Tables (one migration per concern, or one grouped migration).**
   - `universes`: `id`, `name` (unique), `slug` (unique), `timestamps`.
   - `instruments`: `id`, `ticker` (unique), `company`, `sector` (nullable), `exchange` (nullable), `active` (boolean default true), `timestamps`.
   - `instrument_universe` (pivot): `id`, `universe_id` (FK cascade), `instrument_id` (FK cascade), `timestamps`, unique(`universe_id`, `instrument_id`).
   - `daily_bars`: `id`, `instrument_id` (FK cascade), `date`, `open`, `high`, `low`, `close` (`decimal(12,4)`), `volume` (`unsignedBigInteger`), `timestamps`, unique(`instrument_id`, `date`).
   - `indicator_snapshots`: `id`, `instrument_id` (FK cascade), `date`, nullable `decimal(12,4)` columns `sma20`, `sma50`, `sma200`, `ema21`, `ema55`, `rsi14`, `adx`, `macd`, `macd_signal`, `macd_hist`, `bb_upper`, `bb_middle`, `bb_lower`, and nullable `decimal(8,4)` `rvol`, `timestamps`, unique(`instrument_id`, `date`).
   - `signals`: `id`, `instrument_id` (FK cascade), `date`, `type` (string, index), `metadata` (json, nullable), `timestamps`, index(`instrument_id`, `date`).
2. **Models.** `Universe`, `Instrument`, `DailyBar`, `IndicatorSnapshot`, `Signal` with `#[Fillable]`, `casts()` (dates -> `date`, decimals -> `decimal:4`, `active` -> `bool`, `metadata` -> `array`) and relationships:
   - `Universe` belongsToMany `Instrument` (pivot `instrument_universe`).
   - `Instrument` belongsToMany `Universe`; hasMany `DailyBar`, `IndicatorSnapshot`, `Signal`.
   - `DailyBar` / `IndicatorSnapshot` / `Signal` belongsTo `Instrument`.
3. **Factories.** `UniverseFactory`, `InstrumentFactory`, `DailyBarFactory`, `IndicatorSnapshotFactory`, `SignalFactory` with coherent defaults (e.g. ticker `NVDA`, plausible prices/indicators).
4. **Test.** `tests/Feature/MarketDataSchemaTest.php` using `RefreshDatabase`: round-trip one instrument with a bar, snapshot and signal; assert the daily-bar unique constraint; assert the universe/instrument pivot.
5. **Portability.** Use `decimal(...)` and `json` types that work on SQLite (tests) and remain portable to MySQL/PostgreSQL later; rely on Laravel's default SQLite foreign-key enforcement.

## Expected File Changes

- `database/migrations/*_create_universes_table.php` — create.
- `database/migrations/*_create_instruments_table.php` — create.
- `database/migrations/*_create_instrument_universe_table.php` — create.
- `database/migrations/*_create_daily_bars_table.php` — create.
- `database/migrations/*_create_indicator_snapshots_table.php` — create.
- `database/migrations/*_create_signals_table.php` — create.
- `app/Models/Universe.php`, `Instrument.php`, `DailyBar.php`, `IndicatorSnapshot.php`, `Signal.php` — create.
- `database/factories/UniverseFactory.php`, `InstrumentFactory.php`, `DailyBarFactory.php`, `IndicatorSnapshotFactory.php`, `SignalFactory.php` — create.
- `tests/Feature/MarketDataSchemaTest.php` — create.
- `CONSTRAINTS.md` — update (schema conventions).
- `PROGRESS.md`, `feature_list.json` — update with evidence.

## Visual Design Impact

- UI involved: no. `DESIGN.md` is not applicable to this slice.

## Durable Documentation Impact

- `ARCHITECTURE.md`: not needed — the entities/boundaries are already in `docs/domain-model.md` and `docs/technical-discovery.md`; a table inventory would duplicate them.
- `CONSTRAINTS.md`: update — MUST rules: prices/indicators stored as `decimal`; `daily_bars` and `indicator_snapshots` unique per `(instrument_id, date)`; all domain tables have `timestamps`; tests use in-memory SQLite.
- `AGENTS.md`: not needed — no workflow/startup change.
- Other docs: `PROGRESS.md` and `feature_list.json` — update with evidence.

## Key Implementation Risks

- **Laravel 13 model idioms** — use `#[Fillable]` attributes and `casts()`; do not use legacy `$fillable` arrays that conflict with the repo style.
- **Unique-constraint enforcement on SQLite** — verify foreign keys/unique indexes actually reject duplicates in tests (SQLite needs the index; Laravel enables FK pragmas by default).
- **Decimal portability** — `decimal(12,4)` values round-trip as strings with the `decimal:4` cast; tests must assert accordingly.
- **Scope creep** — do not add signal-type enums, business rules, seeding, controllers or API resources here.

## Implementation Plan

1. Create the six migrations following the Laravel 13 anonymous-class style.
2. Create the five models with `#[Fillable]`, `casts()` and relationships.
3. Create the five factories with coherent defaults.
4. Add `tests/Feature/MarketDataSchemaTest.php` (`RefreshDatabase`).
5. Verify: `php artisan migrate:fresh`, `php artisan migrate:rollback`, `php artisan test`.
6. Update `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [x] Create the `universes`, `instruments`, `instrument_universe`, `daily_bars`, `indicator_snapshots`, `signals` migrations.
- [x] Create the `Universe`, `Instrument`, `DailyBar`, `IndicatorSnapshot`, `Signal` models with attributes, casts and relationships.
- [x] Create the five factories.
- [x] Create `tests/Feature/MarketDataSchemaTest.php` covering round-trip, uniqueness and the pivot.
- [x] Run `php artisan migrate:fresh` and `php artisan migrate:rollback` cleanly.
- [x] Run `php artisan test` (all green) and `.\init.ps1` (exit 0).
- [x] Update `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Findings

- Migrations are timestamped `2026_09_29_10000{1..6}_create_*_table.php` so the domain tables sort after Laravel's `0001_01_01_*` framework migrations.
- The `decimal:4` cast renders fixed 4-decimal strings on read (`61.5` -> `"61.5000"`, `2.5` -> `"2.5000"`), so the round-trip test uses values with four decimal places and asserts the string form. This nuance is recorded in `CONSTRAINTS.md`.
- `signals.type` is stored as a plain indexed string with a nullable `json` `metadata` column; no enum/vocabulary is introduced (that belongs to `signals-detect`).
- A second test method also proves the `indicator_snapshots` unique constraint, since the spec's `CONSTRAINTS.md` update requires both `(instrument_id, date)` uniques.
- Verified on the working SQLite DB with `php artisan db:show`: `universes`, `instruments`, `instrument_universe`, `daily_bars`, `indicator_snapshots`, `signals` all present after `migrate:fresh`.


## Verification Plan

- `php artisan migrate:fresh` → all market-data tables created, exit 0.
- `php artisan migrate:rollback` → market-data tables dropped cleanly, exit 0 (then `migrate:fresh` again to leave a working DB).
- `php artisan test` → the new schema test plus existing tests pass, exit 0.
- The schema test asserts: round-trip of instrument + bar + snapshot + signal; `daily_bars` duplicate `(instrument_id, date)` is rejected; universe/instrument pivot resolves both ways.
- `.\init.ps1` → Laravel + SPA + engine checks pass, exit 0, no server started.
- Persistent E2E: none exists and there is no user flow; feature tests are the right level for a schema. Record that no E2E harness exists.
- Startup script rule: `init.ps1` stays a non-blocking gate and starts no server.

## Evidence To Capture

- `php artisan migrate:fresh` and `migrate:rollback` output.
- `php artisan test` output (all tests pass, exit 0).
- Confirmation the schema test proves the daily-bar uniqueness and the pivot.
- `.\init.ps1` output (exit 0).
- Confirmation `frontend/`, `engine/` and `alphapulse/` were not modified.

## Validator Checklist

- [ ] Implementation stays within this feature's scope (no ingestion, indicators, signals logic, seeding or API).
- [ ] Acceptance scenarios pass.
- [ ] Verification evidence is present.
- [ ] Persistent E2E coverage was added/updated when the feature has an observable user/API flow and an E2E harness exists, or the spec explains why it is not needed.
- [ ] `feature_list.json` and `PROGRESS.md` were updated correctly.
- [ ] No unrelated product behavior or extra feature work was added.
- [ ] Migrations follow the Laravel 13 style and roll back cleanly.
- [ ] Models use `#[Fillable]`/`casts()` and expose the documented relationships.
- [ ] `daily_bars`/`indicator_snapshots` are unique per `(instrument_id, date)` and the test proves it.
