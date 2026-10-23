# Feature Implementation Spec: Rule-based daily Market Regime with visible components

## Source Feature

- `id`: `market-regime`
- `area`: `backend`
- `depends_on`: `market-context-sectors`, `macro-series-fred` (both `accepted`, in production)
- `status`: `not_started` at planning time (2026-10-22)
- `source`: `feature_list.json`. Product decisions: `docs/specs/market-context.md` → "Domain Decisions (shared)" and "Feature 3". This spec is the implementation contract; where it is more precise than the umbrella, this spec wins (see "Resolved Ambiguities And Deviations"). The umbrella's Feature 3 section was narrowly corrected on 2026-10-22 to match it.

## Goal

After every daily run, Chartiko stores one **Market Regime** row per market session: a rule-based label (`favourable` / `neutral` / `adverse`, or `insufficient`) computed from up to seven components (breadth, index trend, sector participation, yield curve, financial conditions, net liquidity, volatility). Each component is stored with its score, the value it was scored on, that value's own date, whether it is stale and its status, so `market-context-ui` can show the reasons. A past date is computed only from data that was available on that date. The pipeline creates about one year of history by itself the first time it runs. Everything is descriptive: no forecast, no advice and no free text in the backend.

## Non-Goals

- No API endpoint, page, Admin section, i18n text or reason sentences (Feature 4 `market-context-ui`).
- No `options_sentiment` component (Feature 6 `options-put-call` adds it through the extension point below).
- No threshold calibration, no regime-change alerts or emails, no AI text (umbrella "Later").
- No change to `market-context:compute`, `macro:refresh`, `MacroSnapshotService`, the `macro-refresh` schedule, the engine or any stock feature.
- No new data source, no ALFRED vintages.

## Job Story

When the daily data update finishes,
I want the market labelled by fixed, visible rules, each with its value and date,
so the Market page can show the label and why, and its history can be audited without look-ahead.

## Users And Permissions

- Visitor / Registered User / Admin: no new surface (Feature 4 exposes it).
- Operator: `php artisan market-regime:compute {--date=} {--from=}`. No new env variable or secret.

## Domain Rules (contract)

1. **Components and thresholds.** `App\Services\MarketRegime\MarketRegimeRules` (pure, static, no DB) holds every constant. `COMPONENTS` is the ordered list below and fixes the key order in `components`. The thresholds are the umbrella's initial, **uncalibrated** values:

   | Key | Input (value) | `+1` | `−1` | Constants |
   | --- | --- | --- | --- | --- |
   | `breadth` | `market_breadth_snapshots.pct_above_sma200` (float, %) | `>= 60` | `<= 40` | `BREADTH_FAVOURABLE_MIN = 60.0`, `BREADTH_ADVERSE_MAX = 40.0` |
   | `index_trend` | `market_breadth_snapshots.spy_trend` (string) | `uptrend` | `downtrend` | (`mixed` → 0) |
   | `sector_participation` | number of sector ETFs with `trend = uptrend` (int, 0–11) | `>= 7` | `<= 3` | `SECTOR_COUNT = 11`, `SECTORS_FAVOURABLE_MIN = 7`, `SECTORS_ADVERSE_MAX = 3` |
   | `yield_curve` | `curve.spread_10y3m` = `DGS10 − DGS3MO` (pp) | `>= 0.5` | `< 0` | `CURVE_FAVOURABLE_MIN = 0.5`, `CURVE_INVERTED_BELOW = 0.0` |
   | `financial_conditions` | `leading.nfci` (only when `NFCI` is enabled) | `< −0.25` | `> 0` | `NFCI_FAVOURABLE_BELOW = -0.25`, `NFCI_ADVERSE_ABOVE = 0.0` |
   | `liquidity` | `liquidity.change_13w_pct` (net liquidity, %) | `>= 2` | `<= −2` | `LIQUIDITY_FAVOURABLE_MIN = 2.0`, `LIQUIDITY_ADVERSE_MAX = -2.0` |
   | `volatility` | `volatility.vix` (only when `VIXCLS` is enabled) | `< 18` | `> 28` | `VIX_FAVOURABLE_BELOW = 18.0`, `VIX_ADVERSE_ABOVE = 28.0` |

   Every other value scores `0`. The comparison operators are exactly the ones in the table. Values are compared after rounding to 4 dp (the stored precision of both sources), so a score can be recomputed from the stored `value`. `MarketRegimeRules::score(string $key, int|float|string $value): int` throws `InvalidArgumentException` for an unknown key.

2. **Label.** `MarketRegimeRules::label(int $sum, int $available): string`:
   - `available < MIN_AVAILABLE` (4) → `insufficient`, whatever the sum;
   - else `sum >= FAVOURABLE_MIN_SCORE` (3) → `favourable`; `sum <= ADVERSE_MAX_SCORE` (−3) → `adverse`; otherwise `neutral`.

   Constants `REGIME_FAVOURABLE`, `REGIME_NEUTRAL`, `REGIME_ADVERSE`, `REGIME_INSUFFICIENT` live on the model. `RULES_VERSION = 1`: bump it whenever a threshold or the component set changes (Feature 6, calibration), then recompute with `--from`.

3. **Status per component.** Exactly one of:
   - `disabled`: the input is switched off. All four macro components are disabled when `config('macro.enabled')` is false. `financial_conditions` / `volatility` are also disabled when `NFCI` / `VIXCLS` is not enabled (`MacroSeriesRefresher::enabledSeries()`), which is the production default. The same applies to any macro component whose reading the snapshot returns as `null` (series disabled);
   - `missing`: enabled but no reading on or before the date, or the reading cannot be computed (`value = null`);
   - `stale`: a reading exists but is older than allowed (Rule 5);
   - `ok`: counts in the regime.

   Only `ok` components have a `score` and count in `available` and in the sum.

4. **Inputs on date `d`, without look-ahead.** The regime of `d` is a pure function of the stored data, read point-in-time:
   - **Market components** read the main Universe's (`config('ingestion.universe')`) snapshots with **date ≤ `d`** (a snapshot for `d` is computed from bars up to `d`, so it is known after the close of `d`):
     - `breadth`: latest breadth row with non-null `pct_above_sma200`;
     - `index_trend`: latest breadth row with non-null `spy_trend`;
     - `sector_participation`: latest date on which **all `SECTOR_COUNT` sector rows** have a non-null `trend`; value = the count of `uptrend` on that date. A date with fewer known trends is passed over, never scored on a partial count.

     `as_of` is the date of the row (or date) used.
   - **Macro components** read `MacroSnapshotService::snapshot(d, pointInTime: true)` **always**, for the live run as well as the backfill. Only observations with `date + release_lag_days ≤ d` count (lags from `config/macro.php`: rates and VIX 1, H.4.1 liquidity 1, NFCI 5). `value`, `as_of` and `stale` are taken from the reading unchanged.
   - Rows and observations dated after `d` are never read for `d`. Load the snapshot rows once per run and normalise dates with `substr(…, 0, 10)`. Do not filter with a `date <= 'Y-m-d'` SQL string, because SQLite stores `Y-m-d 00:00:00` and that comparison drops the day itself.

5. **Staleness** (umbrella decision 3). Market readings are stale when `d − as_of > MARKET_STALE_AFTER_DAYS` (7 calendar days, the daily limit). Macro readings use the snapshot's own `stale` flag: 7, 16 or 100 days by the anchor series' frequency. The **latest** reading is the one checked. When it is stale, the component is `stale`; the computer never falls back to an older reading. A stale component keeps its `value` and `as_of` so the UI can show them with a stale badge.

6. **Stored row.** One row per session date. `score` = sum of the `ok` scores, `null` when `insufficient`. `available` = number of `ok` components. `components` = an object keyed in `COMPONENTS` order, every key present (including `disabled` ones):

   ```json
   {"breadth":{"status":"ok","score":1,"value":63.5,"as_of":"2026-10-15","stale":false},
    "index_trend":{"status":"ok","score":1,"value":"uptrend","as_of":"2026-10-15","stale":false},
    "sector_participation":{"status":"ok","score":1,"value":8,"as_of":"2026-10-15","stale":false},
    "yield_curve":{"status":"ok","score":0,"value":0.15,"as_of":"2026-10-14","stale":false},
    "financial_conditions":{"status":"disabled","score":null,"value":null,"as_of":null,"stale":false},
    "liquidity":{"status":"ok","score":1,"value":2.1667,"as_of":"2026-10-14","stale":false},
    "volatility":{"status":"disabled","score":null,"value":null,"as_of":null,"stale":false}}
   ```

   - `missing` and `disabled` → `score`, `value` and `as_of` are `null`, `stale` is `false`.
   - `stale` → `score` is `null`, `value` and `as_of` are kept, `stale` is `true`.
   - Value types: float (4 dp) for numeric inputs, int for `sector_participation`, string for `index_trend`.
   - The backend stores no text. Consumers must treat an **absent key** as "not computed by that rules version" (older rows will not have `options_sentiment`).

7. **Sessions.** The dates are `MarketContextComputer::sessionDates()` (main Universe sessions, ≥ 50 % coverage), with every date before the **first** main-Universe breadth snapshot dropped. Earlier sessions would only produce `insufficient` noise.

8. **Isolation** (umbrella decision 5). `market-regime:compute` in the pipeline never changes the exit code and never skips later stages.

## Acceptance Scenarios

Fixtures write snapshot rows and macro observations directly, with dates as `Carbon::parse(…)->startOfDay()` like the real writers. Except in Scenario 6, the config sets `macro.enabled = true`, and `NFCI` and `VIXCLS` keep their default `enabled = false`. `d` = 2026-10-15 (Thursday).

### Scenario 1: Hand-computed favourable row (fixture A)
Given these rows:
- breadth rows on 10-14 (`58.0`, `mixed`), 10-15 (`63.5`, `uptrend`) and 10-16 (`30.0`, `downtrend`);
- 11 sector rows on 10-15 (8 `uptrend`, 2 `mixed`, 1 `downtrend`) and 11 `downtrend` rows on 10-16;
- `DGS10` 10-14 = 4.10, 10-15 = 4.50; `DGS3MO` 10-14 = 3.95, 10-15 = 3.90;
- `WALCL`/`WDTGAL`/`WLRRAOL`: 2026-07-15 = 6,850,000 / 820,000 / 30,000 (NL 6000.0) and 2026-10-14 = 6,950,000 / 800,000 / 20,000 (NL 6130.0).

When `compute(['2026-10-15'])` runs
Then the row is `favourable`, `score` 4, `available` 5, `rules_version` 1, and `components` equals the JSON in Rule 6 exactly. The figures:
- breadth 63.5 ≥ 60 → +1; index `uptrend` → +1; sectors 8 ≥ 7 → +1;
- curve: the cutoff is 10-14 (lag 1), so the spread is 4.10 − 3.95 = 0.15 → 0. The 10-15 observations (spread 0.60, which would score +1) are ignored;
- liquidity: (6130 / 6000 − 1) × 100 = 2.1667 ≥ 2 → +1;
- the 10-16 rows are ignored.

### Scenario 2: No look-ahead on a past date (fixture A, `d` = 2026-10-14)
When `compute(['2026-10-14'])` runs
Then:
- breadth 58.0 → 0 and index `mixed` → 0 (10-14 row);
- sector_participation `missing`: no complete date ≤ 10-14;
- yield_curve `missing`: the cutoff is 10-13 and there is no observation;
- liquidity `missing`: the cutoff is 10-13, so the 10-14 Wednesday is unpublished, and the 07-15 base has no week 91 days before it;
- `available` 2 → `insufficient`, `score` null.

### Scenario 3: Stale boundaries
Given fixture A with its breadth rows replaced by a single row dated 10-08 (`63.5`, `uptrend`)
Then breadth and index_trend are `ok` (age 7). Moved to 10-07 (age 8), both are `stale`, with `value` and `as_of` kept, `score` null, and not counted. Given `DGS10`/`DGS3MO` only up to 10-07, yield_curve is `stale` (age 8 > 7 days).

### Scenario 4: Partial sectors and insufficient
Given only 10 sector rows on 10-15, no earlier complete date, a spread of 0.60 dated 10-14 and no liquidity data
Then sector_participation and liquidity are `missing`. Breadth, index and curve are +1 each (sum 3), but `available` is 3 → `insufficient` with `score` null.

### Scenario 5: Restricted series enabled
Given fixture A plus `macro.series.NFCI.enabled = true` with `NFCI` 2026-10-09 = −0.30 (lag 5 → visible on 10-14), and `macro.series.VIXCLS.enabled = true` with `VIXCLS` 10-14 = 16.5
Then financial_conditions +1 (`as_of` 10-09) and volatility +1 (`as_of` 10-14) → `available` 7, `score` 6, `favourable`.

### Scenario 6: Macro disabled
Given fixture A with `macro.enabled = false`
Then the four macro components are `disabled` (even though observations exist), `available` 3 → `insufficient`.

### Scenario 7: Fresh macro readings per run
Given one `MarketRegimeComputer` instance and no DGS observations
When it computes 10-15, then the DGS observations are inserted, then it computes again
Then the second row has yield_curve `ok`. `MacroSnapshotService` caches per instance, so the computer must resolve a new one inside every `compute()` call.

### Scenario 8: Command, idempotency, backfill
Given a main universe with 7 consecutive sessions and breadth/sector rows on the last 6 of them:
- no option on an empty table → 6 rows (self-seed from the first snapshot);
- a rerun → still 6 rows, and every column except timestamps is unchanged;
- `--from=<session 1>` → 6 rows and the line "Starting at the first market snapshot (<session 2>).";
- `--date=<session 1>` → exit 1 (before the first snapshot);
- `--date=<a non-session>`, a malformed date, or both options → exit 1;
- no breadth snapshot at all → "No market snapshots yet; nothing to compute." and exit 0.

### Scenario 9: Pipeline placement and isolation
- In the existing Market Context block test (macro disabled), the regime stage runs after `macro:refresh` and before `screener:refresh`. It stores one row per computed session (the table was empty, so it self-seeds), each `insufficient`, because with macro disabled at most 3 components can be available. The exit code is unchanged.
- With `MarketRegimeComputer` bound to a fake that throws, the pipeline prints "Market regime stage market-regime:compute failed (exit 1); the pipeline continues.", logs `Log::warning('Market regime stage failed.', {stage, exit})`, runs the Screener refresh and alerts, and exits as it would without the regime.

## Repository Research

### Files Inspected

- `docs/specs/market-context.md` (Feature 3, shared decisions), `docs/specs/market-context-sectors.md`, `docs/specs/macro-series-fred.md`.
- `app/Services/MarketContext/MarketContextComputer.php`:
  - `sessionDates()` is public and throws `RuntimeException` without the main universe;
  - `DEFAULT_LATEST = 5`; dates are written `startOfDay()` and read with `substr`.
- `app/Console/Commands/ComputeMarketContext.php`: the option parsing (`dateOption()`), the exit codes and the output to mirror.
- `database/migrations/2026_10_22_00000{2,3}_*`, `app/Models/MarketBreadthSnapshot.php`, `MarketSectorSnapshot` (`TREND_*` constants), `MarketSectorSnapshotFactory`.
- `app/Services/Macro/MacroSnapshotService.php`:
  - `snapshot(asOf, pointInTime)`, cutoff `date ≤ asOf − release_lag_days`;
  - the reading shape `{value, as_of, stale}`; `null` for disabled series and all-disabled blocks;
  - a per-instance `$loaded` cache. It is not bound as a singleton (no provider binding found);
  - `enabledSeries()` ignores `macro.enabled`.
- `config/macro.php` (lags; `NFCI` and `VIXCLS` off by default; `stale_after_days`).
- `app/Console/Commands/RunIngestionPipeline.php`: `runMarketContext()` then `runMacro()`, then `screener:refresh`. The isolation pattern is `try/catch`, `report`, `warn`, `Log::warning`.
- `routes/console.php`: `ingestion-pipeline` runs on weekdays at 16:30 New York; `macro-refresh` runs every day at 10:00 New York.
- `app/Console/Commands/CopySqliteToPgsql.php` (`SKIPPED_TABLES`), `tests/Feature/{MacroSnapshotServiceTest,IngestionPipelineCommandTest}.php`, `tests/Unit/`, `deploy/README.md` items 7–8, `ARCHITECTURE.md`, `CONSTRAINTS.md`, `CONTEXT.md`, `docs/domain-model.md`, `docs/risks-and-open-questions.md`, `PROGRESS.md`.

### Current Gaps

- No regime table, rules, computer or command.
- The dev database is empty, so no local real-data smoke is possible. Do not restore it.
- Production has sector/breadth history from 2025-10-22. ETF bars come from a 2-year range, so sector trends exist only from about Aug 2025. Macro data has 5 years.

## Resolved Ambiguities And Deviations From The Umbrella

1. **Point-in-time always**, not only for `--from`. The regime of `d` is the same whether it is computed live or later, so history and the live label agree. The cost is that the live run (16:30 New York) uses yields and VIX from `d − 1`. That is roughly what FRED has published by then. A release that lands just after the run (H.4.1 on Thursdays at 16:30) is picked up by the next evening's 5-session recompute (item 7).
2. **"Latest non-stale reading"** means: take the latest reading on or before `d`; if it is stale, the component does not count. There is no fallback to an older reading.
3. **Statuses.** `status` (`ok` / `stale` / `missing` / `disabled`) was added to each component, and every key is always present. `disabled` is kept apart from `missing`, so the UI can tell "not configured" from "no data". `macro.enabled = false` disables the macro components even when old observations exist, because a feed that was switched off must not keep scoring.
4. **Production component set.** With NFCI and VIX off, at most 5 components are available. `favourable` then needs +3 from 4–5 components; with macro disabled the label is always `insufficient`. That is intended ("never a guessed label").
5. **`score` is `null` when `insufficient`**, so the history chart never plots a partial sum. The components still show it.
6. **Sector participation needs all 11 trends** on the same date (the latest complete date ≤ `d`). Absolute thresholds over a partial count would be a guess.
7. **Backfill range and default run.**
   - With no option, the command recomputes the latest 5 sessions, like `market-context:compute`, so late macro publications and healed snapshots are folded in.
   - When the table is **empty**, it computes every session from the first breadth snapshot. In production that is about 252 sessions from 2025-10-22, enough for the UI's 1-year history. The cost is negligible: about 2,800 sector rows and one macro load.
   - The 3-year range of Feature 4 will show only what exists: about 1 year at launch, growing daily.
8. **No recompute after the standalone 10:00 `macro-refresh`.**
   - Regime rows exist only for sessions and are written by the evening pipeline, whose own macro stage runs first.
   - Under point-in-time, the inputs for `d` (dated ≤ `d − lag`) are already published by then.
   - Anything the morning refresh adds (a late release or a revision) is folded into the last 5 sessions at the next evening run.
   - Chaining a recompute to the 10:00 event would add a second writer and change the label of a past session during the next day. `routes/console.php` is unchanged.
9. **Fresh `MacroSnapshotService` per run.** It is resolved with `app(MacroSnapshotService::class)` inside `compute()`, never as a constructor dependency or a singleton (validator note on `macro-series-fred`).
10. **`rules_version` column** (umbrella addition), so history computed under different thresholds can be told apart after calibration or Feature 6.
11. **What "no look-ahead" covers.** It is about publication dates. Macro values are the latest vintage, because there are no ALFRED vintages, so a backfill uses revised values. Bars can be re-adjusted by the source. Both are accepted, documented limitations; the regime inputs used (H.15 yields, H.4.1 levels) are rarely revised.
12. **Extension point for Feature 6.** Feature 6 will:
    - append `options_sentiment` to `COMPONENTS`;
    - add its constants (`OPTIONS_FEAR_MIN_PERCENTILE = 90`, `OPTIONS_COMPLACENCY_MAX_PERCENTILE = 10`) and a `score` branch;
    - add one input reader in the computer (status `disabled` when `put_call.enabled` is false; `stale` and `missing` as here);
    - bump `RULES_VERSION` to 2.

    No other code changes are needed; old rows simply lack the key.
13. **Table not keyed by universe.** `date` is unique, as in the umbrella. The regime describes the main Universe's market, and breadth already carries `universe_id`.

## Technical Approach

1. **Schema.** `database/migrations/2026_10_24_000001_create_market_regime_snapshots_table.php`:
   - columns: `id`, `date` date **unique**, `regime` string(16), `score` smallInteger nullable, `available` unsignedTinyInteger, `components` json, `rules_version` unsignedSmallInteger, timestamps;
   - docblock citing this spec.

   Model `App\Models\MarketRegimeSnapshot`:
   - `#[Fillable]`, `HasFactory`;
   - casts: `date` → `date`, `score`, `available` and `rules_version` → `integer`, `components` → `array`;
   - the `REGIME_*` and `STATUS_*` constants.

   Factory `MarketRegimeSnapshotFactory`, for Feature 4. Add `market_regime_snapshots` to `CopySqliteToPgsql::SKIPPED_TABLES` with the comment "derived, rebuilt by `market-regime:compute`; postdates the cut-over".
2. **Rules.** `App\Services\MarketRegime\MarketRegimeRules`: Rules 1–2 and `MARKET_STALE_AFTER_DAYS = 7`, with a docblock stating "uncalibrated initial values, descriptive only".
3. **Computer.** `App\Services\MarketRegime\MarketRegimeComputer` (constructor: `MarketContextComputer`):
   - `firstSnapshotDate(): ?string`;
   - `sessionDates(?string $from, ?string $date): list<string>` (Rule 7, plus self-seed when the table is empty and no option is given);
   - `compute(list<string> $dates): array{rows: int, latest: ?array{date, regime, score, available}}`.

   `compute()`:
   - loads the main universe's breadth rows and all sector rows up to `max($dates)` once;
   - resolves a fresh `MacroSnapshotService` (only when `macro.enabled`);
   - builds each date's components (Rules 3–6);
   - upserts every row in one transaction with `upsert($rows, ['date'], ['regime','score','available','components','rules_version','updated_at'])`. `upsert` skips casts, so encode `components` with `json_encode(…, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)` and write dates `startOfDay()`.
4. **Command** `ComputeMarketRegime`, signature `market-regime:compute {--date= : Compute one session (Y-m-d)} {--from= : Backfill every session from this date (Y-m-d)}`. Thin, mirroring `ComputeMarketContext`:
   - option validation (exit 1);
   - missing main universe → exit 1;
   - the messages of Scenario 8;
   - on success: `Market regime computed for N session(s) (A to B); latest B: <regime> (score S, K available).`
5. **Pipeline.** A private `runMarketRegime()`, called right after `runMacro()` and before `screener:refresh`:
   - it always runs, even when macro is disabled or the Market Context block was skipped;
   - `$this->call('market-regime:compute')` inside `try/catch (Throwable)` with `report($e)`;
   - a non-zero exit → `warn("Market regime stage market-regime:compute failed (exit {$exit}); the pipeline continues.")` and `Log::warning('Market regime stage failed.', ['stage' => 'market-regime:compute', 'exit' => $exit])`;
   - update the class docblock and remove the "will follow it" note.

## Expected File Changes

- Create:
  - the migration;
  - `app/Models/MarketRegimeSnapshot.php`, `database/factories/MarketRegimeSnapshotFactory.php`;
  - `app/Services/MarketRegime/{MarketRegimeRules,MarketRegimeComputer}.php`;
  - `app/Console/Commands/ComputeMarketRegime.php`.
- Modify: `app/Console/Commands/RunIngestionPipeline.php`, `app/Console/Commands/CopySqliteToPgsql.php`.
- Tests, create:
  - `tests/Unit/MarketRegimeRulesTest.php`: every row of Rule 1 at, just inside and just outside each threshold:
    - breadth 60 / 59.9999 / 40 / 40.0001;
    - sectors 7 / 6 / 4 / 3;
    - curve 0.5 / 0.4999 / 0 / −0.0001;
    - NFCI −0.2501 / −0.25 / 0 / 0.0001;
    - liquidity 2 / 1.9999 / −1.9999 / −2;
    - VIX 17.9999 / 18 / 28 / 28.0001;
    - trend up / mixed / down;
    - an unknown key throws;
    - labels: (3, 4) favourable, (2, 4) neutral, (−3, 4) adverse, (−2, 5) neutral, (3, 3) and (−4, 3) insufficient;
  - `tests/Feature/MarketRegimeComputerTest.php`: Scenarios 1–7, with the hand computations in comments;
  - `tests/Feature/MarketRegimeComputeCommandTest.php`: Scenario 8.
- Tests, modify:
  - `IngestionPipelineCommandTest.php`: Scenario 9; the existing assertions and `Http::assertSentCount` stay unchanged;
  - `MarketDataSchemaTest.php`: `date` is unique (savepoint pattern, PostgreSQL-safe) and `components` round-trips as an array.

## Visual Design Impact

UI involved: no.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update.
  - Scheduling: add the regime stage to the composition line and a "Market Regime stage (isolated)" bullet.
  - New "Market Regime" section: table, rules class, statuses, point-in-time inputs, sessions, default run and self-seed, no recompute after `macro-refresh`, extension point.
- `CONSTRAINTS.md` (Market Context section): update with these rules.
  - `market_regime_snapshots` is derived and written only by `market-regime:compute`.
  - The regime of `d` **MUST** read only snapshots dated ≤ `d` and point-in-time macro readings.
  - Stale, missing or disabled components **MUST NOT** count.
  - The regime **MUST NOT** be labelled with fewer than 4 available components.
  - The backend stores no text, and the wording stays free of advice.
  - The stage **MUST NOT** change the pipeline exit code.
- `CONTEXT.md`: update the Market Regime term: the components, the `insufficient` label ("Sin datos suficientes" / "Not enough data") and uncalibrated thresholds.
- `docs/domain-model.md`: add the **Regime Snapshot** entity and its relation to Breadth/Sector Snapshots and Macro Series.
- `docs/risks-and-open-questions.md`: add these risks.
  - The thresholds are uncalibrated.
  - Production has at most 5 components while NFCI and VIX are off.
  - History starts 2025-10-22 (ETF bars cover 2 years).
  - Backfilled history uses revised macro values (no vintages).
- `deploy/README.md`: new item 9 (Spanish, like items 7–8).
  - There is no configuration.
  - The first pipeline after deploy fills the history by itself.
  - Check `count(*)`, `min(date)` and `max(date)` of `market_regime_snapshots`.
  - If macro data was missing on that first run, rerun `php artisan market-regime:compute --from=2025-10-22`.
- `docs/specs/market-context.md`: Feature 3 corrected (done by the planner).
- `AGENTS.md`: not needed (scope paragraph already lists `market-regime`).

## Implementation Plan

1. Migration, model, factory, `SKIPPED_TABLES`, schema test.
2. `MarketRegimeRules` and its unit test.
3. `MarketRegimeComputer` with Scenarios 1–7.
4. Command with Scenario 8.
5. Pipeline stage with Scenario 9; durable docs; `.\init.ps1`.

## Implementation Tasks

- [ ] Migration, model (constants, casts), factory, copy-command skip, schema test.
- [ ] Rules class (every constant named as in Rule 1–2, `RULES_VERSION`, `MARKET_STALE_AFTER_DAYS`) and its table-driven unit test.
- [ ] Computer: load once, normalise dates, point-in-time macro via a fresh service, statuses, JSON encoding, transactional upsert.
- [ ] Command: options, self-seed, first-snapshot floor, messages, exits.
- [ ] `runMarketRegime()` with isolation and docblock.
- [ ] Docs listed above; `feature_list.json` evidence and `PROGRESS.md`.

## Verification Plan

- `php artisan test --filter="MarketRegime|IngestionPipelineCommand|MarketDataSchema|CopySqliteToPgsql"`.
- `.\init.ps1` exit 0.
- `php artisan market-regime:compute` on the empty dev database prints "No market snapshots yet; nothing to compute." with exit 0.
  - Never run `migrate:fresh`/`reset`/`rollback`, `db:wipe` or `--env=testing` commands against it, and never restore or overwrite `database/database.sqlite`.
  - `php artisan migrate` (additive) is fine.
- Post-deploy check (production, by the owner or the operator, not local), after the first pipeline:
  - the row count is about the number of sessions since 2025-10-22, and `min(date)` = 2025-10-22;
  - the latest row's market values match that day's `market_breadth_snapshots`/`market_sector_snapshots`, and the spread and liquidity match `MacroSnapshotService` at `d` with point-in-time;
  - record the distribution of labels for the later calibration.
- E2E: none. There is no user-visible flow.

## Evidence To Capture

Test names and counts, the `.\init.ps1` summary and the empty-DB command output, recorded in `feature_list.json` evidence and `PROGRESS.md`. After deploy: row count, date range, latest row and label distribution.

## Key Implementation Risks

- SQLite date strings (`Y-m-d 00:00:00`) vs `Y-m-d` comparisons: filter in PHP after `substr`, as in Rule 4.
- `upsert` bypasses casts: encode the JSON explicitly; float vs int in JSON (`JSON_PRESERVE_ZERO_FRACTION`).
- `MacroSnapshotService` instance reuse would freeze macro data within a process (Scenario 7).
- A first run with no macro data seeds a year of `insufficient` rows; the deploy note covers the rerun.

## Validator Checklist

- [ ] Scope stays inside Feature 3: no API, page, Admin, i18n, `options_sentiment` or schedule change.
- [ ] Every Rule 1 constant exists with the exact operator; Scenarios 1–9 pass offline, with hand computations visible in the tests.
- [ ] No look-ahead: market rows dated ≤ `d`, macro always point-in-time; Scenario 2 passes.
- [ ] Statuses and the JSON shape match Rule 6; `insufficient` has `score` null.
- [ ] Pipeline exit code and later stages are unchanged by regime failures; the stage sits after `macro:refresh` and before `screener:refresh`.
- [ ] Table classified in `CopySqliteToPgsql`; portable SQL; durable docs updated; `feature_list.json` and `PROGRESS.md` updated.
