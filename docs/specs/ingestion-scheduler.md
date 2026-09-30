# Feature Implementation Spec: Schedule the daily EOD ingestion

## Source Feature

- `id`: `ingestion-scheduler`
- `area`: `operations`
- `depends_on`: `ingestion-run-orchestration`, `indicators-compute`, `signals-detect` (all `accepted`)
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Run the full EOD pipeline automatically after the US Market Close on trading days, without a human in the loop. A single Laravel scheduled event fires on weekdays after the configured close time, evaluated in the US/Eastern market timezone so it stays correct across DST, and skips weekends and US market holidays. The event runs a thin wrapper command that composes the three existing commands in order: `ingestion:run` → `indicators:compute` → `signals:detect`. Each stage keeps its own accepted behavior and ledger; nothing inside those commands changes. Overlapping runs are prevented, and because the wrapper is a normal artisan command, a missed run can always be triggered manually.

## Non-Goals

- No admin UI/panel, no ingestion panel, no new data sources.
- No changes to `ingestion:run`, `indicators:compute` or `signals:detect` behavior, signatures or output.
- No queues, background workers, retries/backoff schedulers or `->runInBackground()`.
- No catch-up/backfill for missed days and no "run now if late" logic; the manual path is the recovery mechanism.
- No production cron / Windows Task Scheduler / supervisor configuration (deployment concern).
- No computed holiday calendar, holiday API, or new composer/npm dependency.
- No change to the app-wide `config/app.php` timezone and no market-data schema change.
- No network in tests.

## Job Story

When the US market closes on a trading day,
I want the EOD pipeline to start by itself at the configured time,
so the dataset, Indicator Snapshots and Signals are refreshed for the next session without an operator.

## Users And Permissions

- System (scheduler): invokes `ingestion:pipeline` after close on trading days. No user role involved.
- Developer/Operator (CLI): can run `ingestion:pipeline` or the existing `ingestion:run` manually to recover a missed run.

## Domain Decisions (explicit)

1. **Timezone/DST.** Keep `config/app.php` `timezone` at `UTC`. Register the event with `->timezone(config('ingestion.timezone'))`, default **`America/New_York`**. The wall-clock run time is then interpreted in market local time, so the tz database handles EST/EDT and the UTC fire instant shifts by one hour across DST. Do not change the app-wide timezone (it would ripple into `now()`, stored timestamps and tests).
2. **Configured close time.** New `config/ingestion.php`: `timezone` (`INGESTION_TIMEZONE`), `market_close` (`INGESTION_MARKET_CLOSE`, default `16:00`), `schedule_buffer_minutes` (`INGESTION_SCHEDULE_BUFFER_MINUTES`, default `30`), `universe` (`INGESTION_UNIVERSE`, default `sp500`). The scheduled time is `market_close + buffer` = **`16:30` America/New_York** by default, i.e. after close with a buffer for the source to publish the day's bar.
3. **Holidays.** MVP uses a committed, configurable `holidays` array in `config/ingestion.php` (current + next year), sourced from the official NYSE calendar; no package, no network. Weekends are skipped with the built-in `->weekdays()`. Holidays are skipped at the schedule level with `->skip(...)` **and** guarded inside the wrapper command by `MarketCalendar::isTradingDay()` (defense in depth + directly testable). Half-days (early closes) are trading days and are not skipped. The list is a snapshot: it must be refreshed annually, documented as a residual risk.
4. **Schedule registration.** Register the event in `routes/console.php` with the `Illuminate\Support\Facades\Schedule` facade (already the `commands:` file passed to `withRouting()` in `bootstrap/app.php`). `bootstrap/app.php` is not changed.
5. **Composition.** The scheduled unit is a new wrapper command **`ingestion:pipeline`** that runs `ingestion:run` → `indicators:compute` → `signals:detect` via `$this->call()`. The `signals-detect` spec already fixes that the scheduler composes the pipeline; a wrapper keeps the three accepted commands untouched and is directly testable. If `ingestion:run` fails, the pipeline stops (later stages are skipped); if a later stage fails, the pipeline still attempts the remaining stage and exits `1`.
6. **Missed run.** The manual path is the existing `php artisan ingestion:run {--universe=sp500}` (ingestion only) and `php artisan ingestion:pipeline` (full pipeline, `--force` bypasses the trading-day guard). No catch-up logic; `schedule:run` does not backfill a missed minute.
7. **Overlap.** The event uses `->withoutOverlapping(120)` (primary guard) and the wrapper additionally holds an atomic `Cache::lock('ingestion:pipeline', ttl)`. A second invocation prints "already running" and exits `0` (a skip, not a failure). Concurrent direct `ingestion:run` invocations are outside this feature and documented.
8. **Dev/SQLite caveat.** Verification runs `php artisan schedule:list` and the wrapper command directly; `php artisan schedule:run` is minute-sensitive. `schedule:work`/daemons and the production cron entry are deployment concerns. `init.ps1` must not start any scheduler. Overlap locks use the cache store (`array` in tests, `database` in dev — both support locks).

## Acceptance Scenarios

### Scenario 1: The schedule triggers after the configured close time (DST-aware)

Given the ingestion config (`timezone = America/New_York`, `market_close = 16:00`, buffer `30`),
When the schedule is registered,
Then exactly one `ingestion:pipeline` event exists with expression `30 16 * * 1-5` and timezone `America/New_York`;
and with a test "now" of `2026-07-15 20:30 UTC` (16:30 EDT) the event `isDue`;
and with a test "now" of `2026-01-14 21:30 UTC` (16:30 EST) the event `isDue`;
and `2026-01-14 20:30 UTC` (15:30 EST) is **not** due, proving DST handling.

### Scenario 2: Non-trading days are skipped

Given a weekend or a date listed in `config('ingestion.holidays')`,
When `ingestion:pipeline` runs,
Then it exits `0`, prints a skip message, creates no `IngestionRun`, and sends no engine request.
Given the same day and `--force`, it runs anyway.

### Scenario 3: The scheduled pipeline runs the whole EOD pipeline

Given a universe with instruments and a working engine,
When `ingestion:pipeline` runs on a trading day,
Then one `IngestionRun` reaches `completed`, `indicator_snapshots` are stored, `signals` are stored, and it exits `0`.

### Scenario 4: Overlap is prevented

Given the `ingestion:pipeline` lock is already held,
When the pipeline runs,
Then it exits `0`, prints an "already running" message, creates no run and sends no engine request.

### Scenario 5: Ingestion failure stops the pipeline

Given every instrument fails ingestion (engine error),
When the pipeline runs,
Then the run status is `failed`, `indicators:compute`/`signals:detect` are not invoked, and the command exits `1`.

### Scenario 6: A missed run can be triggered manually

Given the scheduled time has passed (host down, failed cron, holiday correction),
When the operator runs `php artisan ingestion:run` or `php artisan ingestion:pipeline`,
Then the run executes on demand and re-running is idempotent.

## Repository Research

### Files Inspected

- `routes/console.php` — only the `inspire` example; the schedule is not registered yet (`php artisan schedule:list` → "No scheduled tasks have been defined.").
- `bootstrap/app.php` — `withRouting(commands: routes/console.php)`, `statefulApi()`, `admin` alias. Schedule can live in `routes/console.php`.
- `app/Console/Commands/RunIngestion.php` — `ingestion:run {--universe=sp500} {--retry=}`; per-instrument try/catch; exit `0` for completed/partial, `1` for failed/pre-flight.
- `app/Console/Commands/ComputeIndicators.php`, `DetectSignals.php` — same `--ticker`/`--universe` shape; `--universe` defaults to `sp500` and only processes instruments with stored bars; engine failures exit `1`.
- `config/app.php` — `timezone => 'UTC'`; `config/engine.php` — the existing `config/<domain>.php` convention with `env('...', default)`.
- `.env.example` — where the ingestion env block belongs.
- `tests/Feature/IngestionRunTest.php`, `DetectSignalsCommandTest.php` — `RefreshDatabase` + `Http::fake` patterns; `Http::sequence`, `Http::assertNothingSent`, `Carbon` test clock.
- `docs/technical-discovery.md`, `docs/risks-and-open-questions.md` — "scheduler after Market Close (timezone/DST aware)" is the stated intent; the DST/holiday question is listed as an implementation-time question.
- `CONTEXT.md`, `docs/domain-model.md` — an "Ingestion Run" is the whole pipeline (scrape → snapshots → signals); it can be scheduled or manual.
- `ARCHITECTURE.md`, `CONSTRAINTS.md` — ingestion/indicators/signals paths and the offline-test rule.

### Verified Environment Findings

- Laravel **13.34.0**. `Illuminate\Console\Scheduling\Event` exposes `dailyAt`, `timezone`, `weekdays` (via `ManagesFrequencies`) and `when`, `skip`, `withoutOverlapping`, `name` (via `ManagesAttributes`), plus public `$expression`, `$timezone`, `$description`, `$withoutOverlapping`, `$mutex` and `isDue($app)`; `Schedule::command($command, array $parameters = [])` accepts an options array. `php artisan schedule:list` currently reports no scheduled tasks; no scheduler code exists anywhere in the app.

### Existing Patterns To Follow

- Commands in `app/Console/Commands`, services in `app/Services/<Domain>/`, config in `config/<domain>.php` with `env(key, default)`.
- Offline feature tests with `Http::fake`; `Carbon::setTestNow` for time-dependent behavior.
- Small, focused changes; do not touch accepted command behavior.

### Current Gaps

- No schedule definition, no schedule config, no market-calendar helper, no pipeline wrapper command, no schedule tests.

## Technical Approach

1. **`config/ingestion.php`** (new): `timezone`, `market_close`, `schedule_buffer_minutes`, `universe`, `lock_ttl_seconds` (default `7200`), and `holidays` — a committed list of NYSE holiday dates in `Y-m-d` form for the current and next year. Expected content (verify at implementation time against the official NYSE calendar):

   - 2026: `2026-01-01`, `2026-01-19`, `2026-02-16`, `2026-04-03`, `2026-05-25`, `2026-06-19`, `2026-07-03`, `2026-09-07`, `2026-11-26`, `2026-12-25`
   - 2027: `2027-01-01`, `2027-01-18`, `2027-02-15`, `2027-03-26`, `2027-05-31`, `2027-06-18`, `2027-07-05`, `2027-09-06`, `2027-11-25`, `2027-12-24`

   Note the Saturday→preceding-Friday / Sunday→following-Monday observed rule used above, and the NYSE quirk that a New Year's Day falling on Saturday is **not** observed on the preceding Friday (relevant when refreshing future years). Weekends are not listed.

2. **`app/Services/Market/MarketCalendar.php`** (new): `isHoliday(DateTimeInterface $date): bool` (matches `$date->format('Y-m-d')` against `config('ingestion.holidays')`) and `isTradingDay(DateTimeInterface $date): bool` (`! $date->isWeekend() && ! $this->isHoliday($date)`). Pure, no I/O, no Carbon test-clock dependency of its own.

3. **`app/Console/Commands/RunIngestionPipeline.php`** (new; signature `ingestion:pipeline {--universe= : ...} {--force : Run even on a non-trading day}`):
   - Resolve `$universe` from `--universe` or `config('ingestion.universe')`.
   - Acquire `Cache::lock('ingestion:pipeline', config('ingestion.lock_ttl_seconds'))`; on failure warn "EOD pipeline is already running; skipping." and return `SUCCESS`.
   - Unless `--force`, return `SUCCESS` with "Not a trading day; EOD pipeline skipped." when `! $calendar->isTradingDay(now(config('ingestion.timezone')))`.
   - `$this->call('ingestion:run', ['--universe' => $universe])`; on non-zero, error and return `FAILURE` (later stages skipped).
   - `$this->call('indicators:compute', ['--universe' => $universe])` then `$this->call('signals:detect', ['--universe' => $universe])`; attempt both, return `FAILURE` if either failed, else `SUCCESS`.
   - Release the lock in a `finally`.

4. **`routes/console.php`** (modify): compute the run time from config and register the single event:

   ```php
   $timezone = config('ingestion.timezone');
   $runTime = Carbon::createFromFormat('H:i', config('ingestion.market_close'), $timezone)
       ->addMinutes((int) config('ingestion.schedule_buffer_minutes'))
       ->format('H:i');

   Schedule::command('ingestion:pipeline', ['--universe' => config('ingestion.universe')])
       ->name('ingestion-pipeline')
       ->dailyAt($runTime)
       ->timezone($timezone)
       ->weekdays()
       ->skip(fn () => app(MarketCalendar::class)->isHoliday(now($timezone)))
       ->withoutOverlapping(120);
   ```

5. **`.env.example`** (modify): add a documented block for `INGESTION_TIMEZONE`, `INGESTION_MARKET_CLOSE`, `INGESTION_SCHEDULE_BUFFER_MINUTES`, `INGESTION_UNIVERSE`.

6. **Tests** (new, offline): `MarketCalendar` unit tests; pipeline command feature tests (`Http::fake`) for happy path, holiday skip + `--force`, overlap, and ingestion-failure-stops-pipeline; a schedule test that boots the console, finds the event and proves expression/timezone/DST due-times with `Carbon::setTestNow`.

## Expected File Changes

- `config/ingestion.php` — create; schedule config + holiday list.
- `app/Services/Market/MarketCalendar.php` — create; trading-day/holiday helper.
- `app/Console/Commands/RunIngestionPipeline.php` — create; `ingestion:pipeline` wrapper.
- `routes/console.php` — modify; register the scheduled event.
- `.env.example` — modify; ingestion schedule env block.
- `tests/Unit/MarketCalendarTest.php` — create.
- `tests/Feature/IngestionPipelineCommandTest.php` — create.
- `tests/Feature/IngestionScheduleTest.php` — create.
- `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/risks-and-open-questions.md` — modify (see below).
- `PROGRESS.md`, `feature_list.json` — modify; status/evidence.

Explicitly **not** changed: `bootstrap/app.php`, `init.ps1`, `app/Console/Commands/{RunIngestion,ComputeIndicators,DetectSignals}.php`, `engine/`, `frontend/`, `alphapulse/`, migrations/models.

## Visual Design Impact

- UI involved: no. `DESIGN.md` is not applicable to this slice.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — add a "Scheduling (Daily EOD Pipeline)" subsection: schedule location, market timezone, config source, market calendar/holiday rule, `ingestion:pipeline` composition and exit codes, overlap protection, and the "production needs a cron/host scheduler; no catch-up" note.
- `CONSTRAINTS.md`: update — add an Operations/Scheduling section with the MUST rules in Technical Approach (timezone stays UTC in `config/app.php`; schedule timezone from `config/ingestion.php`; config-driven close/buffer/holidays; no holiday deps/network; wrapper composes and must not change the three commands; `withoutOverlapping` + cache lock; `init.ps1` starts no scheduler; offline/time-mocked tests).
- `AGENTS.md`: not needed — no workflow, startup-path or operating-rule change (`init.ps1` is untouched and starts no daemon).
- `docs/risks-and-open-questions.md`: update — mark the DST/holiday scheduling question decided, and add the holiday-list-refresh and production-scheduler risks.
- `docs/technical-discovery.md`, `CONTEXT.md`, `docs/domain-model.md`: not needed — they already state the after-close, DST-aware intent and that an Ingestion Run is the full pipeline.

## Key Implementation Risks

- **Holiday list drift** — a missing holiday causes a harmless idempotent run attempt (bars/snapshots unchanged, signals recomputed), but the list must be refreshed annually. Document the source and refresh note.
- **DST correctness** — only correct if the event really carries `->timezone('America/New_York')`; prove the UTC fire instants in tests, do not rely on the machine clock.
- **Overlap locks** — require a cache store that supports locks (`database`/`array` do); a crashed run holds the lock until the TTL. Document the TTL.
- **Scope** — do not modify the three accepted commands or their outputs; compose only.
- **Time-sensitive tests** — use `Carbon::setTestNow`; never assert against the real clock.
- **Source readiness** — at close + buffer the EOD bar may not exist yet; a run with 0 new bars is a normal, idempotent outcome, not a bug.

## Implementation Plan

1. Add `config/ingestion.php` (config + holidays) and `.env.example` keys.
2. Add `MarketCalendar` and its unit tests.
3. Add `RunIngestionPipeline` (`ingestion:pipeline`) with lock + trading-day guard + composition.
4. Register the event in `routes/console.php`.
5. Add pipeline feature tests and the schedule test.
6. Run `php artisan test`, `php artisan schedule:list`, `php artisan list`, and `.\init.ps1`.
7. Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/risks-and-open-questions.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [ ] Create `config/ingestion.php` (timezone, market_close, schedule_buffer_minutes, universe, lock_ttl_seconds, holidays) and document the holiday source/refresh note.
- [ ] Add the `INGESTION_*` block to `.env.example`.
- [ ] Create `app/Services/Market/MarketCalendar.php` (`isHoliday`, `isTradingDay`).
- [ ] Create `app/Console/Commands/RunIngestionPipeline.php` (`ingestion:pipeline`, cache lock, trading-day guard + `--force`, ingestion→indicators→signals, exit codes).
- [ ] Register the scheduled event in `routes/console.php` (compute `market_close + buffer`, `->timezone()`, `->weekdays()`, holiday `->skip()`, `->withoutOverlapping(120)`, `->name()`).
- [ ] Add `tests/Unit/MarketCalendarTest.php` (holiday, weekend, trading day, committed-list spot check).
- [ ] Add `tests/Feature/IngestionPipelineCommandTest.php` (happy path, holiday skip, `--force`, overlap, ingestion-failure-stops).
- [ ] Add `tests/Feature/IngestionScheduleTest.php` (event expression/timezone/overlap + DST due-times via `Carbon::setTestNow`).
- [ ] Run `php artisan test`, `php artisan schedule:list`, `php artisan list`, `.\init.ps1` (exit 0, no server/daemon).
- [ ] Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/risks-and-open-questions.md`, `PROGRESS.md`, `feature_list.json`.

## Verification Plan

- `php artisan schedule:list` → one event for `ingestion:pipeline`, expression `30 16 * * 1-5` (or the configured close+buffer), showing the market timezone.
- `php artisan test` → new suites pass:
  - `MarketCalendar`: a configured holiday is not a trading day; a weekend is not; a normal weekday is.
  - `IngestionPipelineCommandTest`: happy path stores a completed run + snapshots + signals (exit 0); a holiday date exits 0 with no run and `Http::assertNothingSent()`; `--force` runs on a holiday; a held lock yields an "already running" exit 0 with no run; an all-fail ingestion run yields `failed`, exits 1, and `indicators:compute`/`signals:detect` are never requested.
  - `IngestionScheduleTest`: the event exists with expression `30 16 * * 1-5` and timezone `America/New_York` and overlap protection; `isDue` is true at `2026-07-15 20:30 UTC` and `2026-01-14 21:30 UTC` and false at `2026-01-14 20:30 UTC` (`Carbon::setTestNow`).
- `php artisan list` → `ingestion:pipeline` registered; `ingestion:run`/`indicators:compute`/`signals:detect` unchanged.
- `.\init.ps1` → exit 0 with the Laravel suite, SPA lint/build and engine tests; **no change needed** because it already runs `php artisan test`. It must not start `schedule:run`/`schedule:work` or any daemon (per the harness rule).
- Optional manual smoke: with a temporary universe and the real engine, `php artisan ingestion:pipeline --force` runs the three stages, a second run is idempotent, then clean up and release the port.
- Persistent E2E: none exists; this is a CLI/scheduler flow covered by feature tests. State that explicitly in the evidence (no browser/API surface is added).

## Evidence To Capture

- `php artisan schedule:list` output showing the event, expression and timezone.
- `php artisan test` output and counts for `MarketCalendarTest`, `IngestionPipelineCommandTest`, `IngestionScheduleTest`.
- The DST due-time assertions and the holiday-skip / overlap / failure-stop assertions.
- `php artisan list` row for `ingestion:pipeline`.
- `.\init.ps1` output (exit 0) and confirmation the script was not required to change.
- Confirmation `frontend/`, `engine/`, `alphapulse/`, the schema, `bootstrap/app.php` and the three accepted commands were not modified.

## Validator Checklist

- [ ] Scope respected: no admin UI, no new data source, no changes to `ingestion:run`/`indicators:compute`/`signals:detect`, no deployment/cron setup, no new dependency.
- [ ] The event is registered in `routes/console.php`, uses `America/New_York` (or the configured market timezone), runs after the configured close and on weekdays only.
- [ ] DST is proven by tests (same wall-clock NY time → different UTC instants in summer/winter), not asserted by hand.
- [ ] Non-trading days are skipped (weekends + configured holidays) and the reason is test-proven; `--force` bypasses only the command guard for manual recovery.
- [ ] `ingestion:pipeline` composes ingestion → indicators → signals, stops on ingestion failure, and is idempotent on re-run.
- [ ] Overlap is prevented (`withoutOverlapping` + cache lock); a skip exits 0.
- [ ] A missed run is manually triggerable via the existing `ingestion:run` / `ingestion:pipeline`.
- [ ] Tests are offline (`Http::fake`) and time-dependent behavior uses `Carbon::setTestNow`.
- [ ] `config/app.php` timezone stays UTC; `init.ps1` starts no scheduler; the repo remains restartable via `.\init.ps1`.
- [ ] `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/risks-and-open-questions.md`, `PROGRESS.md`, `feature_list.json` updated correctly.

## Implementation Findings

Implementation followed the spec; the notes below are recorded because they affect how the feature is verified or interpreted.

1. **`schedule:list` converts the expression to `config('app.timezone')` for display.** Laravel's `ScheduleListCommand` renders times in the app timezone unless `--timezone` is passed. With `config/app.php` `timezone = UTC`, the default `php artisan schedule:list` output is `30 20 * * 1-5` (16:30 `America/New_York` converted to UTC). The event's **raw** expression is `30 16 * * 1-5` with `timezone = America/New_York`, which is what `IngestionScheduleTest` asserts directly and what `php artisan schedule:list --timezone=America/New_York` displays. This is expected DST-aware behavior, not a bug; evidence records both outputs.
2. **`withoutOverlapping` is a `skip()` filter, not part of `isDue()`.** `Event::isDue()` only evaluates the cron expression and environment, while `filtersPass()` evaluates `when`/`skip` (including the overlap mutex). The DST due-time test therefore uses `isDue()`, and the holiday skip is proven by the command-level guard test, the `MarketCalendar` unit tests, and the schedule-level `filtersPass()` test. The overlap protection is proven by the command-level lock test plus the asserted `withoutOverlapping`/`expiresAt` event attributes.
3. **`MarketCalendar` uses `DateTimeInterface::format('N')`** (6/7 = weekend) rather than Carbon's `isWeekend()` so the helper is genuinely pure and accepts any `DateTimeInterface`; behavior is identical to the spec's `! $date->isWeekend()`.
4. **An extra command-level weekend test was added.** Scenario 2 covers "a weekend or a date listed in `config('ingestion.holidays')`"; the unit tests prove weekends and the schedule's weekday filter, and `IngestionPipelineCommandTest` now proves both the holiday and the weekend paths exit `0` with no run and no engine request.
5. **No persistent E2E harness exists.** This is a CLI/scheduler flow with no browser/API surface, so it is covered by the offline feature/unit tests recorded in `feature_list.json` (no live-engine smoke was needed; `Http::fake` covers all three stages).

