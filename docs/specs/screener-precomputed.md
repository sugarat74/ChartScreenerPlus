# Feature Implementation Spec: Precomputed Screener rows with SQL filtering and paging

## Source Feature

- `id`: `screener-precomputed`
- `area`: `backend`
- `depends_on`: `screener-api`, `screener-pagination`, `chart-patterns-ui`, `alerts-engine` (all accepted)
- `status`: implemented 2026-10-12 at the user's request ("opción C")
- `source`: `feature_list.json`

## Goal

Keep `GET /api/screener` fast as the number of Instruments grows from ~500 to thousands. Today every request loads the whole Universe, filters and ranks it in PHP and then pages it. Instead, one row per Instrument with everything the Candidate list needs is **precomputed once per data update** in `screener_rows`, and each request filters, ranks, counts and pages **in SQL**. The API contract, the results and their order must not change.

## Non-Goals

- No API, payload, filter or UI change (same params, same `422`s, same JSON).
- No new universes, ingestion concurrency or incremental indicators (separate work).
- No HTTP/edge caching layer.

## Design

1. **Table `screener_rows`** (one row per Instrument that has a latest Daily Bar and a latest Indicator Snapshot): `instrument_id` (unique, FK cascade), `ticker`, `company`, `sector`, `exchange`, `active`, `date` (latest bar), `close` and `previous_close` (`decimal(12,4)`), `change_percent` (`double`, computed in PHP exactly as before), `rvol` (`decimal(8,4)`), `rsi14`, `sma50`, `sma200` (`decimal(12,4)`), `price_above_sma200` (bool), `ma_cross` (`bullish`/`bearish`/null), `signal_count`, `signals` (json, sorted unique types), `patterns` (json, the candidate pattern summaries), timestamps. Indexes on the sort and filter columns.
2. **Refresh** `App\Services\Screener\ScreenerRowBuilder::refresh()`: computes every row with the previous per-request logic (latest bar/snapshot, previous close per instrument, signals, patterns), then inside one transaction upserts them and deletes rows of instruments that no longer qualify. Command `php artisan screener:refresh`.
3. **When it runs:** `ingestion:pipeline` (new stage after `patterns:detect`, before `alerts:evaluate`), the end of every ingestion run (`IngestionRunner::process`: Admin job, `ingestion:run`, retries), `ingestion:scrape`, the S&P 500 seeder, and `deploy/deploy.sh` (after the PgBouncer check before activation, and again after activation, non-fatal). Running `indicators:compute`, `signals:detect` or `patterns:detect` by hand requires `screener:refresh` afterwards (documented).
4. **Query** `CandidateQuery` reads `screener_rows` joined to the Universe membership: filters as SQL (`EXISTS` on `signals` / `chart_patterns` for the OR lists and pattern status; comparisons on indexed columns, nulls never match), order `nulls last` + key + `ticker` (byte order; `COLLATE "C"` on PostgreSQL), `count()` for `total`, `offset/limit` for the page. `candidates()` (all, used by alerts) and `page()` (controller) share it.

## Acceptance

- Same JSON (keys, values, order, `meta`) as before for every filter/sort/paging combination, proven by an old-vs-new comparison script over a filter matrix on real data.
- Query count constant; request time no longer grows with the number of instruments.
- Pipeline, Admin job and deploy keep the rows fresh; alerts read fresh rows.
- Tests: existing Screener/Patterns/Alerts suites (refreshing rows after seeding data), new builder/refresh tests (qualification, deletion, values), pipeline order.

## Durable Docs

- `ARCHITECTURE.md` Screener API (precomputed rows, refresh points), `CONSTRAINTS.md` (rows refreshed by pipeline/job/deploy; manual stage commands need `screener:refresh`; no per-request PHP filtering), `deploy/README.md` (deploy step).

## Implementation Findings

1. **Float precision.** The first old-vs-new comparison found differences in the last digits of `change_percent`. Root cause (found by the independent review): Laravel binds PHP floats as strings with `precision=14`, so the stored sort key lost digits; near-equal values such as 10.000000000000009 (0.70→0.77) and 9.9999999999999929 (3.0→3.3) became ties and the ranking fell back to the ticker. Fixed by storing `change_percent` as a 17-significant-digit string (exact round trip on SQLite and PostgreSQL), and the payload recomputes the value from the stored `close` / `previous_close` decimals as before. Regression test `test_equal_change_percentages_keep_their_exact_order` (fails without the fix). After the fix: 540 checks on real data (15 filter sets × 6 sorts × 5 pages + full lists, with and without 178 patterns) and the reviewer's 784 adversarial checks, 0 differences.
2. **Count before ordering.** `total` is counted on the filtered query before `ORDER BY` is added (PostgreSQL rejects an aggregate with non-grouped order columns).
3. **Scale (local SQLite, disposable copy):** 5,494 instruments → refresh 1.9 s; page queries 11–19 ms (first page, deep page `offset=4000`, combined numeric filters by change, signal filter). With 494 real instruments: refresh 0.4 s.
4. **Tests.** `refreshScreenerRows()` helper in `tests/TestCase.php` for tests that seed market data directly; `ScreenerRowBuilderTest` covers qualification, values (change %, flags, `ma_cross`, signals, patterns), idempotence and deletion, and that requests read rows rather than live data; byte-order tie-break (`BF.B`/`BF-C`/`BFA`/`BFB`, also covers `COLLATE "C"` in the PostgreSQL CI job); query bound ≤ 4 per request; the pipeline skips alerts and exits 1 when the refresh fails; ingestion runs refresh rows with the new date/close and a failing refresh never fails the run.
5. **Stale-row deletion** uses a correlated "no bar or no snapshot" check instead of binding every id, so it has no parameter limit.
