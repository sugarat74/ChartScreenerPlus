# Feature Implementation Spec: Expose instrument detail API

## Source Feature

- `id`: `instrument-detail-api`
- `area`: `api`
- `depends_on`: `signals-detect`, `db-schema-market-data` (both `accepted`)
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Expose one read-only, public HTTP endpoint that returns everything the chart screen needs for a single Instrument: its instrument metadata, a bounded window of Daily Bars ordered ascending by date, the latest Indicator Snapshot and the current active Signals. It is the data contract `chart-interactive` (and later `watchlist`) consumes, so the shape must be explicit, stable, bounded and covered by a feature test.

## Non-Goals

- No writes of any kind (no ingest/indicator/signal trigger, no cache warming).
- No per-bar indicator series / MA overlays inside `bars`. Only the **latest** snapshot is returned; per-bar series is a `chart-interactive` decision (see Future Hooks).
- No chart UI / SPA code change (that is `chart-interactive`); no `frontend/` change.
- No screener, watchlist, saved-screeners or candidate ranking.
- No engine call, no new DB schema/migration, no new dependency.
- No public write/auth surface; no admin route change.

## Job Story

When a Visitor or Registered User opens a Candidate to inspect its chart,
I want a single API response with the instrument's recent EOD bars, its latest indicators and its active signals,
so the chart screen can render price, overlays and signal levels from one bounded, deterministic payload without login.

## Users And Permissions

- Visitor (no session): may call the endpoint anonymously. Browsing the screener and charts requires no session (`docs/user-and-access-model.md`, "Access Rules").
- Registered User: same anonymous read access (no ownership involved).
- Admin: same read access; no special path.
- There is no authorization branch on this endpoint: the data is system-owned public market data, not user-owned.

## Decisions (explicit)

1. **Route + method:** `GET /api/instruments/{ticker}`, registered as a public (no-auth) top-level route in `routes/api.php`. Rejected `GET /api/instruments/{id}` because the ticker is the user-facing identifier and what the SPA route will deep-link with. No route-model binding (see Technical Approach).
2. **Auth:** **anonymous/public.** Browsing is explicitly session-free in `docs/user-and-access-model.md`; adding `auth:sanctum` would contradict the product rule and break `chart-interactive`. The feature title's "user_visible_behavior" is about availability of the payload, not about a signed-in user.
3. **Bounding:** count-based query param `?limit=` (a time-window param adds no MVP value for a candle chart). Default **252** (one trading year of sessions), hard maximum **2000**. The value is **clamped silently** to `1..2000` (mirrors `IngestionRunController::index`), never a `422`; a non-numeric value falls back to the default. The limit applies to the **most recent** bars, which are then returned ascending.
4. **Payload:** `{ instrument, bars, snapshot, signals, meta }` — details in API Contract. Decimal columns are serialized as **JSON numbers** (`(float)` at the API boundary), not the `decimal:4` strings the model cast produces, because chart consumers need numeric values; storage/`CONSTRAINTS.md` decimal rules are unchanged. `volume` is an integer. All dates are `Y-m-d` strings.
5. **Ticker lookup:** path value is normalized with `trim` + `strtoupper` before an exact lookup on `instruments.ticker`; matching is therefore case-insensitive. Inactive instruments are still returned (a removed instrument keeps its history per `docs/domain-model.md`).
6. **404 contract:** an unknown ticker returns HTTP `404` with JSON `{"message": "Instrument not found."}` (explicit controller response, not `firstOrFail`).
7. **Latest snapshot:** the `indicator_snapshots` row with the greatest `date` for the instrument (unique per `(instrument_id, date)`, so no tie). If none exists the key is present with value `null`. Every indicator key is always present (JSON `null` for insufficient history, matching `indicators-compute`). The snapshot's `date` is included so the UI can show the as-of date.
8. **Signals:** all current `signals` rows for the instrument (the replace-per-instrument active set), ordered by `type` ascending for a deterministic payload. No signals → `[]` (never `null`).

## Acceptance Scenarios

### Scenario 1: Known ticker returns the full detail

Given an instrument `NVDA` with several stored Daily Bars, an Indicator Snapshot on the latest date and two Signals,
When anyone `GET`s `/api/instruments/NVDA`,
Then the response is `200` with `instrument.ticker = "NVDA"`, `bars` ordered ascending by date with numeric OHLCV, `snapshot` equal to the row with the greatest date, and `signals` containing exactly the stored active signals.

### Scenario 2: Unknown ticker

Given no instrument with ticker `ZZZZ`,
When anyone `GET`s `/api/instruments/ZZZZ`,
Then the response is `404` with a JSON `message`, and no other data is returned.

### Scenario 3: Bounded payload

Given an instrument with more than 252 stored bars,
When anyone `GET`s `/api/instruments/{ticker}`,
Then `bars` contains the 252 most recent bars in ascending order (not the oldest), and `GET ...?limit=10` returns the 10 most recent; `?limit=99999` is clamped and the response never exceeds 2000 bars.

### Scenario 4: Sparse instrument

Given an instrument with bars but no snapshot and no signals,
When anyone `GET`s its detail,
Then `snapshot` is `null`, `signals` is `[]`, and the bars are still returned.

### Scenario 5: Case-insensitive lookup

Given an instrument stored as `AAPL`,
When anyone `GET`s `/api/instruments/aapl`,
Then the response is `200` for the same instrument.

## Repository Research

### Files Inspected

- `routes/api.php` — no public group exists yet; auth/admin routes are the only ones. A new top-level `GET` route is public by default (the whole file is the `api` prefix/group from `bootstrap/app.php`).
- `app/Models/{Instrument,DailyBar,IndicatorSnapshot,Signal}.php` — relationships (`dailyBars`, `indicatorSnapshots`, `signals`), `#[Fillable]`, `casts()` (`date` => `date`, OHLC/indicators => `decimal:4`, signal `metadata` => `array`).
- `app/Http/Controllers/Admin/IngestionRunController.php` — repo controller conventions: explicit array payload shaping in private `*Payload()` methods, `?limit` clamped with `min(max(...))`, explicit `abort(404)`, `response()->json([...])`. No API Resource classes exist.
- `app/Http/Controllers/Auth/AuthController.php` — controller/service split and JSON response conventions.
- `database/factories/{Instrument,DailyBar,IndicatorSnapshot,Signal}Factory.php` — available factory states for the test.
- `tests/Feature/AdminIngestionApiTest.php` — `RefreshDatabase`, `getJson`, explicit status + JSON assertions.
- `phpunit.xml` — in-memory SQLite, `RefreshDatabase` convention; no network.
- `docs/domain-model.md` — Daily Bar / Snapshot / Signal definitions, "latest Snapshot stays valid and the date is shown", removed instruments keep history.
- `docs/user-and-access-model.md` — Visitors browse the screener and view charts; "Browsing requires no session".
- `CONSTRAINTS.md` — decimal storage/cast rules; engine/database Chinese wall; offline-test rules.
- `ARCHITECTURE.md` — dependency direction (SPA -> Laravel HTTP API; engine is internal-only) and existing API sections to extend.
- `alphapulse/src/types.ts` (`Candle`), `alphapulse/src/components/InteractiveChartView.tsx` — intent only: the chart wants per-candle `date/open/high/low/close/volume` plus indicator overlays and signal levels; this feature supplies the bounded candle window + latest indicators + signals.

### Existing Patterns To Follow

- Explicit JSON arrays shaped in the controller (no Resource classes), as in `IngestionRunController`.
- Clamp optional query integers rather than returning validation errors (admin `limit` precedent).
- `#[Fillable]` + `casts()` models already expose everything needed; no model/query-builder changes required beyond a bounded, ordered query.
- Feature tests use `RefreshDatabase` and Laravel factories; no network and no engine call in this feature.
- Dates in existing payloads are explicit strings (`toIso8601String()` for datetimes); here use `Y-m-d` for trading dates.

### Current Gaps

- No public API beyond auth; no `InstrumentController`; no instrument-detail route.
- No conversion helper for `decimal:4` strings to numbers at the API boundary (the model cast returns strings like `"61.5000"`).
- The SPA has no `instrumentApi` client (deliberately left to `chart-interactive`).

## Technical Approach

1. **Controller** `App\Http\Controllers\InstrumentController` (extended `Controller`) with one action `show(Request $request, string $ticker): JsonResponse`.
   - Normalize: `$ticker = strtoupper(trim($ticker))`.
   - Look up: `Instrument::query()->where('ticker', $ticker)->first()`; if `null`, return `response()->json(['message' => 'Instrument not found.'], 404)`. Do **not** type-hint a model or use `firstOrFail`, so the JSON body and status are exactly the contract.
   - Clamp limit: `$limit = (int) $request->query('limit', 252); if (! is_numeric($request->query('limit'))) { $limit = 252; } $limit = min(max($limit, 1), 2000);` (an absent/empty value uses the default; `0`/negatives clamp to `1`).
   - Bars: `$instrument->dailyBars()->orderByDesc('date')->limit($limit)->get()->reverse()->values()` — the DB selects the newest N, the payload presents them ascending. Map each to `{date: $bar->date->format('Y-m-d'), open/high/low/close: (float), volume: (int)}`.
   - Snapshot: `$instrument->indicatorSnapshots()->orderByDesc('date')->first()`; map to the full key set (identical field names to the model) with `date` formatted `Y-m-d` and nullable decimals converted via `(float)` or left `null`; or `null` when the query returns `null`.
   - Signals: `$instrument->signals()->orderBy('type')->get()`; map to `{type, date (Y-m-d), metadata}` (`metadata` is already an array/null).
   - Return `response()->json(['instrument' => ..., 'bars' => ..., 'snapshot' => ..., 'signals' => ..., 'meta' => ...])`.
2. **Route** in `routes/api.php`, above/with the other public routes:
   `Route::get('/instruments/{ticker}', [InstrumentController::class, 'show']);`
   No middleware, no `where` constraint (tickers may contain `.`/`-`, e.g. `BRK.B`); normalization + the lookup handle everything else.
3. **Private shaping helpers** on the controller (`instrumentPayload`, `barPayload`, `snapshotPayload`, `signalPayload`, `nullableFloat`) so the test can rely on a fixed key set and the implementation stays audit-friendly.
4. **No other runtime change:** no migration, no model change, no SPA change, no `init.ps1` change.

## API Contract

`GET /api/instruments/{ticker}` — anonymous; `?limit=` optional (default `252`, clamped `1..2000`, most recent bars).

```json
{
  "instrument": { "ticker": "NVDA", "company": "Nvidia", "sector": "Information Technology", "exchange": "NASDAQ", "active": true },
  "bars": [
    { "date": "2026-09-28", "open": 176.5000, "high": 179.7500, "low": 175.0000, "close": 178.2000, "volume": 1234567 }
  ],
  "snapshot": {
    "date": "2026-09-28",
    "sma20": 170.1, "sma50": 165.2, "sma200": 150.3,
    "ema21": 171.2, "ema55": 166.4,
    "rsi14": 62.5, "adx": 28.7,
    "macd": 3.1, "macd_signal": 2.4, "macd_hist": 0.7,
    "bb_upper": 180.1, "bb_middle": 170.1, "bb_lower": 160.1,
    "rvol": 1.8
  },
  "signals": [
    { "type": "golden_cross", "date": "2026-09-28", "metadata": {} }
  ],
  "meta": { "limit": 252, "bar_count": 1, "latest_bar_date": "2026-09-28" }
}
```

- `snapshot: null` when the instrument has no snapshot; every snapshot key is always present otherwise (value `null` when history was insufficient). `signals` is `[]` when none. `volume` is an integer; OHLC/indicators are JSON numbers; `meta.latest_bar_date` is `null` when the instrument has no bars.
- `404` → `{ "message": "Instrument not found." }`.

## Expected File Changes

- `app/Http/Controllers/InstrumentController.php` — create; public read action + explicit payload shaping.
- `routes/api.php` — modify; add the public `GET /instruments/{ticker}` route (with `use App\Http\Controllers\InstrumentController;`).
- `tests/Feature/InstrumentDetailApiTest.php` — create; unknown 404, ordered bars, latest snapshot, active signals, bounding/clamping, sparse instrument, case-insensitive, numeric types.
- `ARCHITECTURE.md`, `CONSTRAINTS.md` — update (public API section).
- `docs/specs/instrument-detail-api.md` (this file) — created.
- `PROGRESS.md`, `feature_list.json` — update at implementation time (evidence/notes).

## Visual Design Impact

- UI involved: no. No SPA screen changes; `DESIGN.md` is not consulted. The payload is the contract `chart-interactive` will render later.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — add an "Instrument Detail API" subsection under Dependency Direction / API: route, anonymous access, payload shape, bounding, explicit 404.
- `CONSTRAINTS.md`: update — add a short "Public API" MUST block: browse endpoints are anonymous; read endpoints return bounded payloads with a hard max; decimal values cross the API boundary as JSON numbers while storage stays decimal; unknown ticker is a JSON `404`, never a 500.
- `AGENTS.md`: not needed — no workflow/startup change (`init.ps1` untouched; `php artisan test` already gates it).
- `docs/user-and-access-model.md`: not needed — it already states browsing requires no session; if desired, a one-line note naming this endpoint is optional.
- `docs/domain-model.md`: not needed — no domain change.

## Implementation Plan

1. Create `InstrumentController::show` with normalization, clamped limit, ordered/bounded bars, latest snapshot, ordered signals and the explicit 404.
2. Register the public route in `routes/api.php`.
3. Add `InstrumentDetailApiTest` covering all acceptance scenarios with factories.
4. Run `php artisan test`, `php artisan route:list --path=api -v`, and `.\init.ps1`.
5. Update `ARCHITECTURE.md` / `CONSTRAINTS.md` / `PROGRESS.md` / `feature_list.json`.

## Implementation Tasks

- [x] Create `app/Http/Controllers/InstrumentController.php` (`show` + `instrumentPayload`/`barPayload`/`snapshotPayload`/`signalPayload`/`nullableFloat`).
- [x] Normalize the ticker (`trim` + `strtoupper`) and return the explicit JSON `404` for an unknown ticker.
- [x] Clamp `?limit` (default `252`, `1..2000`) and query the newest bars, returned ascending.
- [x] Select the latest snapshot by max `date` (`null` when none) and the active signals ordered by `type`.
- [x] Register `GET /instruments/{ticker}` as a public route in `routes/api.php`.
- [x] Add `tests/Feature/InstrumentDetailApiTest.php` (unknown 404, ordering, latest snapshot, signals, bounds/clamp, sparse, case-insensitive, numeric types).
- [x] Run `php artisan test`, `php artisan route:list --path=api -v`, `.\init.ps1` (exit 0, no server).
- [x] Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Verification Plan

- `php artisan test --filter=InstrumentDetailApiTest` → all cases pass:
  - `test_unknown_ticker_returns_404_json` (`assertNotFound` + `assertJsonPath('message', ...)`).
  - `test_returns_bars_ordered_ascending` (seed bars out of order; assert the array's dates are ascending).
  - `test_returns_latest_snapshot_and_signals` (two snapshots/ dates → the newest one; signals match the stored set).
  - `test_bars_are_bounded_by_limit` (default returns the newest 252 of e.g. 300; `?limit=10` returns 10 newest; `?limit=99999` clamps; ascending).
  - `test_sparse_instrument_returns_null_snapshot_and_empty_signals`.
  - `test_ticker_lookup_is_case_insensitive`.
  - `test_payload_decimals_are_numbers` (`assertIsFloat`/`assertIsInt` on `bars.0.close` and `volume`).
  - `test_json_structure` for the exact top-level and nested keys (`instrument`, `bars`, `snapshot`, `signals`, `meta`).
- `php artisan test` → full suite green (was 88 passing / 530 assertions).
- `php artisan route:list --path=api -v` → `GET api/instruments/{ticker}` present with the `api` middleware only (no `auth:sanctum`, no `admin`).
- `.\init.ps1` → exit 0 (Laravel tests + SPA lint/build + engine tests); it must remain unchanged and start no server. The new suite is covered by the existing `php artisan test` step.
- Persistent E2E: none exists and no frontend test runner exists; this is a read-only API contract fully covered by feature tests (no browser behavior), so no E2E coverage is added. Optional manual smoke: `php artisan serve` + `curl http://127.0.0.1:8000/api/instruments/NVDA` on a seeded DB, then stop the server.
- Startup script rule: `init.ps1` stays a non-blocking gate; no long-running processes.

## Evidence To Capture

- `php artisan test --filter=InstrumentDetailApiTest` output (counts/assertions, exit 0).
- `php artisan test` full-suite counts after the change.
- `php artisan route:list --path=api -v` row for `GET api/instruments/{ticker}` (public).
- `.\init.ps1` exit status and confirmation it was not modified / started no server.
- Confirmation `frontend/`, `engine/`, `alphapulse/`, migrations/models and admin routes were not modified.

## Validator Checklist

- [ ] Implementation stays within this feature's scope (read-only public GET; no SPA/engine/schema change).
- [ ] Acceptance scenarios pass (full detail, unknown 404, bounding, sparse instrument, case-insensitivity).
- [ ] The endpoint is anonymous and returns bounded, ordered bars; the latest snapshot is the max-date row; signals are the current active set.
- [ ] Decimal values cross the API boundary as JSON numbers while storage stays `decimal`.
- [ ] Verification evidence is present.
- [ ] Persistent E2E coverage was added/updated when the feature has an observable user/API flow and an E2E harness exists, or the spec explains why it is not needed (no harness/runner exists; feature-test coverage is sufficient).
- [ ] `feature_list.json` and `PROGRESS.md` were updated correctly.
- [ ] No unrelated product behavior or extra feature work was added.

## Future Hooks (not this feature)

- `chart-interactive` may need a per-bar indicator series (SMA/EMA/Bollinger bands) to draw overlays. That is intentionally **not** in this payload; extend the response (e.g. an optional `?include=indicators` or extra bar fields) in that feature rather than changing this contract silently.
- `watchlist` can reuse this endpoint for a follower's instrument view; no ownership is involved.

## Implementation Findings

- The limit logic was extracted into a private `resolveLimit(Request): int` helper instead of the inline snippet in Technical Approach step 1; behavior is identical (non-numeric/absent → `252`, otherwise `min(max(value, 1), 2000)`).
- PHP `json_encode` drops the fractional part of a whole-number float, so a `decimal:4` value like `175.0000` crosses the boundary as the JSON number `175`, not `175.0`. This is still a JSON number (and a JS number), so the contract ("JSON numbers, not `decimal:4` strings") holds; `test_payload_decimals_are_numbers` asserts the float/int type with non-integral OHLC values and separately asserts the `"178.2000"` cast string is never serialized. No `JSON_PRESERVE_ZERO_FRACTION` flag was added (out of scope; the chart consumes JS numbers).
- `test_bars_are_bounded_by_limit` seeds **2010** bars with one bulk `DB::table('daily_bars')->insert()` so the hard maximum (2000) is genuinely exercised, and additionally asserts `?limit=0`/`?limit=-5` clamp to `1` and `?limit=abc` falls back to `252`.
- The endpoint is a pure Eloquent read; no migration, model, engine, SPA or `init.ps1` change was needed (see Evidence in `feature_list.json` for the scope confirmation).
