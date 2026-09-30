# Feature Implementation Spec: Expose the screener API

## Source Feature

- `id`: `screener-api`
- `area`: `api`
- `depends_on`: `signals-detect`, `db-schema-market-data` (both `accepted`)
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Expose one read-only, anonymous HTTP endpoint that applies the MVP technical filters over a Universe's Instruments and returns a ranked Candidate list. Each Candidate carries the fields the Screener table needs (instrument identity, latest close, change %, RVOL, RSI14, active signal types) so `screener-filters-ui` and `candidate-list-ranking` have a stable server-side contract from day one. Filtering and sorting happen server-side so the SPA never re-implements ranking.

The filter set is derived from `FilterState` in the `alphapulse/` prototype, reduced to the MVP-relevant technical filters the stored data can actually evaluate.

## Non-Goals

- No UI (`screener-filters-ui`, `candidate-list-ranking` are later); no `frontend/` change.
- No saved screeners, watchlist, ownership or auth (`saved-screeners`, `watchlist`).
- No writes of any kind; no ingestion/indicator/signal trigger.
- No engine call, no new migration/schema change, no new dependency, no caching layer.
- No geometric/chartist patterns and no pattern-confidence score (out of MVP scope; nothing in the schema produces one).
- No text search (`searchQuery`) and no `ema21AboveEma55`, `macdBullishCross`, `adxAbove25`, `pullbackSma50`, `dolvol`, or `confidence` sorts; the param surface is intentionally the MVP set and is extensible later.
- No pagination beyond `limit` (no offset/cursor), no per-bar series, no nested snapshot object.

## Job Story

When a semi-technical retail trader wants a shortlist after the EOD run,
I want to post the same technical criteria (signal, RSI range, RVOL, price vs SMA200, MA cross) once and get a ranked Candidate list,
so I can narrow the S&P 500 to a few charts without checking ticker by ticker.

## Users And Permissions

- Visitor (no session): may call the endpoint anonymously. Browsing the Screener requires no session (`docs/user-and-access-model.md`, "Permissions" / "Access Rules").
- Registered User / Admin: identical anonymous read access (`docs/user-and-access-model.md`); no ownership is involved.
- There is no controller authorization branch. The endpoint carries no `auth:sanctum` and no `admin` middleware.
- The access model's edge case "anonymous users may hit rate limits on Screener queries; the MVP should keep at least a basic throttle" is implemented here with `throttle:60,1` on the route.

## Decisions (explicit)

1. **Route + method:** `GET /api/screener`. A pure read has no side effects, so GET is correct; it keeps anonymous browsing trivial (no CSRF/stateful-session dance that a POST would need), is shareable/deep-linkable, and matches the existing `GET /api/instruments/{ticker}` precedent. Filters travel as query params.
2. **Auth:** **anonymous/public.** Browsing is explicitly session-free and the data is system-owned public market data, so there is no ownership/authorization branch (mirrors `instrument-detail-api` and `CONSTRAINTS.md` → Public API). A basic `throttle:60,1` is applied; it must not be retrofitted onto the instrument endpoint in this feature.
3. **Universe scope:** optional `universe` query param, resolved by `universes.slug`; default `config('ingestion.universe')` (currently `sp500`). Members come from the `instrument_universe` pivot. An unknown slug is a bad request: explicit JSON `404 {"message":"Universe not found."}` (not an empty list). A **valid** universe whose filters match nothing is a `200` with `candidates: []`.
4. **Candidate selection:** the base set is universe members that have **both** at least one `daily_bars` row and at least one `indicator_snapshots` row. Members missing either are excluded even when no indicator filter is set, because they cannot produce the candidate fields. Membership (pivot) is the only universe filter; `instruments.active` is **not** a screener criterion in this feature (the domain rule "removed from a Universe stops producing Candidates" is expressed by pivot detachment). `active` is still returned in the payload.
5. **Filter semantics:** all active filters combine with **AND**. Within `signal`, multiple listed types combine with **OR** (any active match). A `null` indicator (insufficient history) can never satisfy a numeric/derived filter, so such an instrument is excluded whenever that filter is applied.
6. **`limit`:** optional, default **50**, clamped silently to **`1..500`**; a non-numeric value falls back to the default; never a `422`. Mirrors the `instrument-detail-api` `?limit` precedent (and the admin `limit` precedent). `500` covers the whole S&P 500 universe.
7. **Validation strictness:** `limit` is clamped because it cannot change the result set. Every other param can change *which* rows or the order, so a bad value is a `422` (Laravel's standard validation error) rather than a silently different list. Absent/empty params mean "filter not applied".
8. **Change %:** defined as `((latest close - previous close) / previous close) * 100` from the two most recent stored Daily Bars by date. `null` when the instrument has fewer than two bars or the previous close is `0`. Not rounded; converted to a JSON number at the boundary.
9. **Sorting/ranking:** server-side sort orders (below), each with an explicit **ticker ASC** tie-break and **nulls last**. This is the ranking contract `candidate-list-ranking` will expose in the UI ("sorting must be reflected in the API request").
10. **Payload:** explicit arrays shaped in the controller (no Resource classes), like `InstrumentController`/`IngestionRunController`. Decimal columns cross the boundary as JSON numbers (`(float)`); storage stays `decimal` (`CONSTRAINTS.md`). Dates are `Y-m-d`.

## API Contract

`GET /api/screener` — anonymous; all params optional.

| Param | Type / values | Behavior |
| --- | --- | --- |
| `universe` | string slug | default `config('ingestion.universe')`; unknown slug → `404 {"message":"Universe not found."}` |
| `signal` | one or more of the 9 types | comma-separated (`?signal=a,b`) and/or array form (`?signal[]=a&signal[]=b`); instrument must have ≥1 active signal in the list (OR). Unknown type → `422` |
| `rsi_min` | number | latest snapshot `rsi14 >= rsi_min` (inclusive). Non-numeric → `422` |
| `rsi_max` | number | latest snapshot `rsi14 <= rsi_max` (inclusive). Non-numeric → `422`; `rsi_min > rsi_max` → valid, empty result |
| `min_rvol` | number | latest snapshot `rvol >= min_rvol` (inclusive). Non-numeric → `422` |
| `price_above_sma200` | boolean | `1/true/on/yes` → apply (`latest close > latest sma200`, both non-null); `0/false/off/no`/absent → off; anything else → `422` |
| `ma_cross` | `bullish` \| `bearish` | `bullish`: latest `sma50 > sma200`; `bearish`: `sma50 < sma200`; both non-null (equality/null → excluded). Anything else → `422` |
| `sort` | see sort set | default `rvol_desc`; unknown → `422` |
| `limit` | integer | default `50`, clamped `1..500`; non-numeric → default; never `422` |

`signal` types are exactly the 9 fixed strings from `signals-detect` / `SignalFactory`.

**Sort set** (all with ticker-ASC tie-break, nulls last):

| `sort` | Primary key |
| --- | --- |
| `rvol_desc` (default) | latest snapshot `rvol` desc |
| `rsi_desc` / `rsi_asc` | latest snapshot `rsi14` |
| `change_desc` / `change_asc` | computed `change_percent` |
| `signal_count_desc` | number of active signal types desc (the only schema-backed signal-strength proxy; pattern confidence is out of scope) |

```json
{
  "universe": { "slug": "sp500", "name": "S&P 500" },
  "sort": "rvol_desc",
  "candidates": [
    {
      "ticker": "NVDA",
      "company": "Nvidia",
      "sector": "Information Technology",
      "exchange": "NASDAQ",
      "active": true,
      "date": "2026-09-28",
      "close": 178.2,
      "change_percent": 1.25,
      "rvol": 3.4,
      "rsi14": 62.5,
      "signals": ["golden_cross", "pivot_breakout_rvol"]
    }
  ],
  "meta": { "limit": 50, "returned": 1, "total": 1 }
}
```

- `date` = the latest Daily Bar date (as-of). `close`/`rvol`/`rsi14`/`change_percent` are JSON numbers or `null` (insufficient history / fewer than two bars). `signals` is the active set's `type` strings, ordered ascending, `[]` when none. `meta.returned` = rows returned, `meta.total` = rows matched before `limit`.
- Empty result → `200` with `candidates: []`, `meta.returned: 0`, `meta.total: 0`.
- Unknown universe → `404 {"message":"Universe not found."}`; invalid param value → `422` error body.

## Acceptance Scenarios

### Scenario 1: Happy path

Given the `sp500` universe with instruments that have bars, snapshots and signals,
When anyone `GET`s `/api/screener`,
Then the response is `200` with `universe.slug = "sp500"`, `sort = "rvol_desc"` and candidates carrying ticker/company/sector/exchange/active/date/close/change_percent/rvol/rsi14/signals, ranked by RVOL desc with ticker-ASC tie-breaks.

### Scenario 2: Each filter narrows

Given instruments whose latest snapshots differ on `rsi14`, `rvol`, `sma50`/`sma200` and close-vs-`sma200`, and whose signals differ,
When anyone calls with `signal=golden_cross`, `rsi_min`/`rsi_max`, `min_rvol`, `price_above_sma200=1` or `ma_cross=bullish`, separately,
Then only the matching instrument(s) are returned, and combining two params returns their intersection (AND).

### Scenario 3: Sort changes the ranking

Given three matching instruments with distinct RVOL/RSI/change/signal-count,
When anyone calls with each supported `sort` value,
Then the returned order changes accordingly and is stable (equal keys tie-break by ticker).

### Scenario 4: Empty result is not an error

Given a valid universe and a filter combination matching nothing,
When anyone calls `/api/screener?min_rvol=99`,
Then the response is `200` with `candidates: []` and zeroed `meta` counts.

### Scenario 5: Incomplete instruments are excluded

Given a universe member with bars but no snapshot, and another with a snapshot but no bars,
When anyone calls `/api/screener`,
Then neither appears; only members with both are candidates.

### Scenario 6: Bad parameters

Given a request with an unknown `signal` type, an unknown `ma_cross`, a non-numeric `rsi_min`, or an unknown `sort`,
When anyone calls the endpoint,
Then the response is `422`; an unknown `universe` returns `404`; a bad `limit` is clamped, never `422`.

## Repository Research

### Files Inspected

- `routes/api.php` — the public `GET /instruments/{ticker}` route is the only non-auth top-level route; the whole file sits under the `api` prefix/group from `bootstrap/app.php`.
- `app/Http/Controllers/InstrumentController.php` — the controller conventions to mirror: explicit `*Payload()` helpers, `resolveLimit()` clamp, `nullableFloat()`, explicit `response()->json([...])`, no Resource classes.
- `app/Http/Controllers/Admin/IngestionRunController.php` — `limit = min(max(v,1),100)` clamp precedent and explicit array payloads.
- `app/Models/{Instrument,DailyBar,IndicatorSnapshot,Signal,Universe}.php` — relationships (`universes`, `dailyBars`, `indicatorSnapshots`, `signals`), `#[Fillable]`, `casts()` (`date` => date, decimals => `decimal:4`, signal `metadata` => array); `Universe::instruments()` is the pivot relation.
- `database/factories/{Instrument,DailyBar,IndicatorSnapshot,Signal,Universe}Factory.php` — states the test can drive (`inactive()`, random signal types, random indicator values).
- `tests/Feature/InstrumentDetailApiTest.php` — `RefreshDatabase`, `getJson`, `assertJsonPath`/`assertJsonCount`, bulk `DB::table(...)->insert()` for large fixtures, exact key-set assertion.
- `phpunit.xml` — in-memory SQLite, `CACHE_STORE=array` (the throttle cache), no network.
- `config/ingestion.php` — `universe` (default `sp500`), the source for the screener default.
- `docs/user-and-access-model.md` — anonymous screening is allowed; basic throttle expected.
- `docs/domain-model.md` — Screener / Screener Result definitions; removed instrument keeps history; insufficient history is flagged.
- `docs/build-brief.md` — validation: "Golden Cross + RVOL > 2 returns the expected Instruments against a fixed fixture".
- `CONSTRAINTS.md` — Public API rules (anonymous, bounded, decimals as numbers, explicit JSON 404), Signals vocabulary, decimal storage.
- `ARCHITECTURE.md` — dependency direction and the existing API sections to extend.
- `alphapulse/src/types.ts` `FilterState` and `alphapulse/src/components/ScreenerView.tsx` — intent reference: filter chips (`priceAboveSma200`, `goldenCrossRecent`, `ema21AboveEma55`, `macdBullishCross`, `minRvol`, `rsiRange`) and the sort dropdown (`rvol_desc`, `confidence_desc`, `change_desc`, `rsi_desc`). Chartist `selectedPatterns`, `adxAbove25`, `pullbackSma50` and `confidence` are out of MVP scope.

### Existing Patterns To Follow

- Explicit JSON arrays shaped in the controller; no Resource classes (`InstrumentController`).
- Clamp `limit` silently; never `422` for it (`InstrumentController::resolveLimit`, `IngestionRunController::index`).
- Decimal `decimal:4` values converted with `(float)` at the boundary; `volume`/counts as ints.
- Read-only public routes are top-level and anonymous; an unknown resource is an explicit JSON `404`.
- Feature tests use `RefreshDatabase` + factories; no network and no engine.

### Current Gaps

- No `ScreenerController`, no screener route, no screener tests.
- `Instrument` has no `latestBar`/`latestSnapshot` convenience relationships; the candidate query must eagerly load the latest rows and the previous close without N+1.
- No query-param validation helper exists in the repo; this feature introduces the 422-vs-clamp split (documented in Constraints).

## Technical Approach

1. **Controller** `App\Http\Controllers\ScreenerController` (extended `Controller`) with `index(Request $request): JsonResponse`.
2. **Resolve universe:** `Universe::query()->where('slug', $slug)->first()`; if `null`, return `response()->json(['message' => 'Universe not found.'], 404)`. `$slug` defaults to `(string) config('ingestion.universe')`.
3. **Validate params:** resolve `rsi_min`/`rsi_max`/`min_rvol` (numeric), `signal` (comma-string and/or array, subset of the 9 types), `ma_cross` (`bullish|bearish`), `price_above_sma200` (`filter_var(..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)`; `false` → off, `null` → `422`), `sort` (supported set); throw `ValidationException::withMessages()` otherwise. `limit` is clamped with the `resolveLimit` pattern (default `50`, `1..500`).
4. **Load candidates in bulk (no N+1):** add `latestBar()` and `latestSnapshot()` `HasOne` relations to `Instrument` using `->latestOfMany('date')` (unique per `(instrument_id, date)`, so no tie), then
   `$universe->instruments()->with(['latestBar', 'latestSnapshot', 'signals'])->get()`
   and drop members whose `latestBar` or `latestSnapshot` is `null`. This is a fixed, small number of queries regardless of universe size.
5. **Previous close in one bounded query:** build a `instrument_id => previous close` map with one `DB::table('daily_bars as b')` query whose `whereRaw` selects, per instrument, the max `date` strictly below that instrument's max `date` (correlated subquery on `instrument_id`). A window-function subquery (`ROW_NUMBER() OVER (PARTITION BY instrument_id ORDER BY date DESC)`) is an acceptable equivalent. It must not be one query per instrument.
6. **Apply filters in PHP** over the loaded rows (the universe is bounded at ~503): signal membership (`signals->contains(type in list)`), `rsi14` range, `rvol` minimum, close vs `sma200`, and `sma50` vs `sma200`. All comparisons are on the latest snapshot and latest bar; a `null` value fails the filter.
7. **Shape candidates** (private helpers, like `InstrumentController`): ticker/company/sector/exchange/active; `date` = latest bar date; `close` = latest bar close; `change_percent`; `rvol`/`rsi14` via a nullable-float helper; `signals` = active types ascending.
8. **Rank and bound:** compute sort keys, `usort` with a comparator that applies "nulls last" then ticker `strcmp`, assign `meta.total` (matched) and `meta.returned` (after `limit`), and return the envelope.
9. **Route:** in `routes/api.php`, add `Route::get('/screener', [ScreenerController::class, 'index'])->middleware('throttle:60,1');` next to the other public route (with the `use` import).
10. **No other runtime change:** no migration, no engine change, no SPA change, no `init.ps1` change.

## Expected File Changes

- `app/Http/Controllers/ScreenerController.php` — create; `index` + param resolution + filter/sort/shape helpers.
- `app/Models/Instrument.php` — modify; add `latestBar()` / `latestSnapshot()` `HasOne` (`latestOfMany('date')`) relations.
- `routes/api.php` — modify; add the public throttled `GET /screener` route.
- `tests/Feature/ScreenerApiTest.php` — create; filters, sorts, empty result, exclusions, bounds, 404/422, numeric types, structure.
- `ARCHITECTURE.md`, `CONSTRAINTS.md` — update (Screener API / Public API section).
- `docs/specs/screener-api.md` (this file) — created.
- `PROGRESS.md`, `feature_list.json` — update at implementation time (evidence/notes).

## Visual Design Impact

- UI involved: no. No SPA screen changes; `DESIGN.md` is not consulted. The payload is the contract `screener-filters-ui` and `candidate-list-ranking` consume.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — add a "Screener API" section (anonymous route + throttle, filter/sort contract, candidate selection, bounded limit, 404/422 split).
- `CONSTRAINTS.md`: update — extend the Public API section: browse endpoints anonymous; the screener's `limit`-clamp vs `422`-for-semantic-params rule; AND/OR filter semantics; candidates require a latest bar **and** snapshot; sorting has a deterministic ticker tie-break.
- `AGENTS.md`: not needed — no workflow/startup/operating-rule change (`init.ps1` untouched; `php artisan test` already gates it).
- `docs/user-and-access-model.md`: not needed — it already says browsing is anonymous and expects a basic throttle (implemented here).
- `docs/domain-model.md`: not needed — Screener / Screener Result are already defined.

## Implementation Plan

1. Add `latestBar()` / `latestSnapshot()` to `Instrument`.
2. Create `ScreenerController::index` with universe resolution, param validation/clamping, bulk candidate loading, PHP filters, ranking and payload shaping.
3. Register the public throttled route in `routes/api.php`.
4. Add `ScreenerApiTest` covering all acceptance scenarios.
5. Run `php artisan test`, `php artisan route:list --path=api -v`, `.\init.ps1`; update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [x] Add `latestBar()` and `latestSnapshot()` (`HasOne` + `latestOfMany('date')`) to `app/Models/Instrument.php`.
- [x] Create `ScreenerController::index` resolving `universe` by slug (404 when unknown) with default `config('ingestion.universe')`.
- [x] Implement param resolution: `signal` (comma + array), `rsi_min`/`rsi_max`, `min_rvol`, `price_above_sma200`, `ma_cross`, `sort` (422 on bad values) and `limit` (clamp 1..500).
- [x] Load universe members with `latestBar`/`latestSnapshot`/`signals` eager-loaded plus one bulk previous-close query; exclude members missing either latest row.
- [x] Apply the AND filters and the `signal` OR-list in PHP; nulls never satisfy a filter.
- [x] Implement the 6 sort orders with nulls-last and ticker-ASC tie-break; compute `change_percent`; shape the envelope with `meta.returned`/`meta.total`.
- [x] Register the anonymous `GET /screener` route with `throttle:60,1`.
- [x] Add `tests/Feature/ScreenerApiTest.php`.
- [x] Run `php artisan test`, `php artisan route:list --path=api -v`, `.\init.ps1` (exit 0, no server).
- [x] Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `PROGRESS.md`, `feature_list.json`.

## Verification Plan

- `php artisan test --filter=ScreenerApiTest` → all cases pass:
  - `test_returns_ranked_candidates_for_the_default_universe` (envelope keys, candidate fields, default `sp500`).
  - `test_signal_filter_narrows_and_accepts_multiple_types` (`?signal=golden_cross`; `?signal=golden_cross,rsi_overbought` returns the union).
  - `test_rsi_range_narrows_results` (inclusive boundaries; out-of-range excluded).
  - `test_min_rvol_narrows_results`.
  - `test_price_above_sma200_narrows_results` (close below / `sma200` null excluded).
  - `test_ma_cross_narrows_results` (`bullish` vs `bearish`; equality/null excluded).
  - `test_filters_combine_with_and`.
  - `test_each_sort_order_changes_the_ranking` (three instruments; assert the first ticker per `sort`).
  - `test_sort_ties_break_by_ticker_ascending`.
  - `test_empty_result_returns_empty_list_not_error` (200, `candidates: []`, zeroed meta).
  - `test_instruments_missing_a_snapshot_or_bars_are_excluded`.
  - `test_change_percent_is_computed_from_the_last_two_bars` (known closes → expected float; single bar → `null`).
  - `test_limit_is_bounded_and_clamped` (default 50; explicit 1; `?limit=99999` → `meta.limit = 500`; `?limit=abc` → 50).
  - `test_unknown_universe_returns_404_json`.
  - `test_invalid_params_return_422` (unknown signal type, bad `ma_cross`, non-numeric `rsi_min`, unknown `sort`).
  - `test_payload_decimals_are_numbers` (`assertIsFloat` on close/rvol/rsi14/change_percent; no `"…0000"` strings).
  - `test_json_structure` (exact top-level, candidate and meta key sets).
  - `test_endpoint_is_rate_limited` (61st request within a minute → 429).
- `php artisan test` → full suite green (baseline was **97 passed / 614 assertions**).
- `php artisan route:list --path=api -v` → `GET api/screener` present with `api` + `throttle:60,1` only (no `auth:sanctum`, no `admin`).
- `.\init.ps1` → exit 0 (Laravel tests + SPA lint/build + engine tests); unchanged, starts no server. The new suite is covered by the existing `php artisan test` step.
- Persistent E2E: none exists and there is no frontend test runner; this is a read-only API contract with no browser behavior, so feature tests are sufficient. Optional manual smoke: `php artisan db:seed --class=Sp500UniverseSeeder` (or fixture data) + `php artisan serve`, then `curl "http://127.0.0.1:8000/api/screener?signal=golden_cross&min_rvol=2&sort=rvol_desc"`, then stop the server.
- Startup script rule: `init.ps1` stays a non-blocking gate; no long-running processes.

## Evidence To Capture

- `php artisan test --filter=ScreenerApiTest` output (counts/assertions, exit 0).
- `php artisan test` full-suite counts after the change.
- `php artisan route:list --path=api -v` row for `GET api/screener` (anonymous + throttle).
- `.\init.ps1` exit status and confirmation it was not modified / started no server.
- Confirmation `frontend/`, `engine/`, `alphapulse/`, migrations and admin routes were not modified.
- Sample response bodies for one filtered, one sorted, and one empty request.

## Key Implementation Risks

- **N+1 / unbounded query** — loading latest snapshots/bars per instrument in a loop would be ~500 extra queries. Use eager loading plus one bulk previous-close query; keep query count constant.
- **Filter correctness with null history** — SMA200/RVOL/RSI can be `null` early; a null must never satisfy a filter, and `change_percent` must be `null` (not `0`) with fewer than two bars.
- **Determinism** — every sort needs the nulls-last rule and the ticker-ASC tie-break, or equal keys make results non-deterministic and the ranking verifier flaky.
- **Empty vs error** — a valid-but-empty match is `200 []`; only an unknown universe is `404` and only bad param values are `422`. Do not blur these.
- **`limit` must not 422** — clamping is required by `CONSTRAINTS.md`.
- **Scope** — no chartist patterns/confidence, no text search, no extra filters/sorts, no UI, no schema/engine change.

## Future Hooks (not this feature)

- `screener-filters-ui` sends these params and may want `searchQuery`; `candidate-list-ranking` exposes the same `sort` values (its "confidence/signal strength" maps to `signal_count_desc` here).
- A cached universe-independent pre-computation or SQL push-down of the filters can replace the PHP filtering if the universe grows far beyond the S&P 500.
- `saved-screeners` can persist the param set verbatim once this contract is stable.

## Validator Checklist

- [ ] Implementation stays within this feature's scope (read-only anonymous GET; no SPA/engine/schema change; no chartist patterns/text search).
- [ ] The endpoint is anonymous (`api` + throttle only) and bounded (`limit` default 50, clamp 1..500).
- [ ] Each of the 5 filter families narrows correctly on fixtures; filters combine with AND and the `signal` list with OR.
- [ ] All 6 sort orders change the ranking, with a deterministic ticker-ASC tie-break and nulls last.
- [ ] Empty result is `200` with `[]`; unknown universe is JSON `404`; invalid param values are `422`; bad `limit` is clamped.
- [ ] Candidates require both a latest bar and a latest snapshot; `change_percent` uses the last two closes.
- [ ] Decimal values cross the API boundary as JSON numbers while storage stays `decimal`.
- [ ] Verification evidence is present; no E2E harness exists and feature-test coverage is sufficient (explained in the Verification Plan).
- [ ] `feature_list.json` and `PROGRESS.md` were updated correctly.
- [ ] No unrelated product behavior or extra feature work was added.

## Implementation Findings

- **Serialization of whole numbers (same as `instrument-detail-api`).** PHP `json_encode` drops the fractional part of a whole-number float, so a `decimal:4` value like `102.0000` crosses the boundary as the JSON number `102` (decoded as an int) rather than `102.0`. The contract ("JSON numbers, not `decimal:4` strings") still holds. `test_payload_decimals_are_numbers` uses non-integral fixtures (`178.2`, `3.4`, `62.5`) so `assertIsFloat` is meaningful, and the earlier ranking/`change_percent` assertions use non-integral values for strict `assertSame`/`assertJsonPath` equality. No `JSON_PRESERVE_ZERO_FRACTION` flag was added (out of scope).
- **Empty-param handling.** Absent and empty (`?rsi_min=`, `?signal=`, `?sort=`) params all mean "filter not applied"/default, matching the spec's "absent/empty params" rule; `price_above_sma200` with an empty value resolves to `false` via `filter_var('', FILTER_VALIDATE_BOOLEAN, ...)`, which is the "off" state. `signal` is trimmed, blank entries dropped and types deduped before the OR check.
- **Non-string `universe`.** An array-form `?universe[]=x` is treated as an unknown universe (`404`) rather than the default; the spec only defines a string slug. This edge is not covered by a test and is not reachable from the SPA contract.
- **Extra regression tests.** `test_candidate_loading_does_not_scale_queries_with_universe_size` asserts the query count stays below the member count for 20 members, guarding the spec's top risk (N+1), and `test_sorts_place_null_primary_keys_last` proves nulls sort last in both a descending (`rvol_desc`) and an ascending (`rsi_asc`) order. The suite is 20 tests / 185 assertions total.
- **Previous-close query.** Implemented as the spec's acceptable correlated `whereRaw` subquery (`b.date = max(b2.date) where b2.date < max(b3.date)`), one query for the whole universe. `test_change_percent_is_computed_from_the_last_two_bars` covers two-bars, one-bar and zero-previous-close cases.
- **Live smoke (temp data, cleaned up).** `GET /api/screener?universe=smoke_screener&signal=golden_cross&min_rvol=2&sort=rvol_desc` → 200 with SMKAAA (rvol 3.5) then SMKCCC (rvol 2.5); `?sort=rsi_desc` → SMKBBB (70), SMKCCC (55), SMKAAA (40); `?min_rvol=99` → 200 `candidates: []`, `meta.returned/total 0`; `?universe=nope` → 404 `{"message":"Universe not found."}`. Server stopped, port released, smoke rows deleted.
- **Throttle key is shared across throttled routes (framework behavior, accepted).** Laravel's `ThrottleRequests::resolveRequestSignature` keys on `sha1(domain|ip)` and does **not** include the route, so the unnamed `throttle:60,1` on `/api/screener` and the pre-existing unnamed `throttle:6,1` on `POST /api/login` share one counter per IP. Within a minute, ≥6 screener requests from an IP will therefore also make login return `429` (and vice versa). This is pre-existing Laravel behaviour (login was the only throttled route before this feature) and the spec explicitly required `throttle:60,1`; a named `RateLimiter::for('screener', ...)` + `throttle:screener` is the follow-up if the coupling ever matters. Not changed here.
- No migration, engine, SPA, admin route or `init.ps1` change was needed; only `Instrument` (two relations), the new controller, `routes/api.php`, the new test and the docs/harness files changed.
