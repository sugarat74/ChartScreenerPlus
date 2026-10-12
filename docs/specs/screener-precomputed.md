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
3. **When it runs:** `ingestion:pipeline` (new stage after `patterns:detect`, before `alerts:evaluate`), `RunIngestionJob` after a manual Admin run, and `deploy/deploy.sh` after `migrate`. Running a stage command by hand (`indicators:compute`, `signals:detect`, `patterns:detect`) requires `screener:refresh` afterwards (documented).
4. **Query** `CandidateQuery` reads `screener_rows` joined to the Universe membership: filters as SQL (`EXISTS` on `signals` / `chart_patterns` for the OR lists and pattern status; comparisons on indexed columns, nulls never match), order `nulls last` + key + `ticker` (byte order; `COLLATE "C"` on PostgreSQL), `count()` for `total`, `offset/limit` for the page. `candidates()` (all, used by alerts) and `page()` (controller) share it.

## Acceptance

- Same JSON (keys, values, order, `meta`) as before for every filter/sort/paging combination, proven by an old-vs-new comparison script over a filter matrix on real data.
- Query count constant; request time no longer grows with the number of instruments.
- Pipeline, Admin job and deploy keep the rows fresh; alerts read fresh rows.
- Tests: existing Screener/Patterns/Alerts suites (refreshing rows after seeding data), new builder/refresh tests (qualification, deletion, values), pipeline order.

## Durable Docs

- `ARCHITECTURE.md` Screener API (precomputed rows, refresh points), `CONSTRAINTS.md` (rows refreshed by pipeline/job/deploy; manual stage commands need `screener:refresh`; no per-request PHP filtering), `deploy/README.md` (deploy step).

## Implementation Findings

1. **Exact JSON on SQLite.** The first old-vs-new comparison found differences only in the last digits of `change_percent`: SQLite returns REAL values as 15-digit text. The payload now recomputes `change_percent` from the stored `close` / `previous_close` decimals (as the per-request code did); the `double` column is used only for filtering/sorting. After the fix: 540 checks (15 filter sets × 6 sorts × 5 pages + full lists for alerts), 0 differences, with and without 178 locally detected patterns.
2. **Count before ordering.** `total` is counted on the filtered query before `ORDER BY` is added (PostgreSQL rejects an aggregate with non-grouped order columns).
3. **Scale (local SQLite, disposable copy):** 5,494 instruments → refresh 1.9 s; page queries 11–19 ms (first page, deep page `offset=4000`, combined numeric filters by change, signal filter). With 494 real instruments: refresh 0.4 s.
4. **Tests.** `refreshScreenerRows()` helper in `tests/TestCase.php` for tests that seed market data directly; `ScreenerRowBuilderTest` covers qualification, values (change %, flags, `ma_cross`, signals, patterns), idempotence and deletion, and that requests read rows rather than live data; the pipeline and `RunIngestionJob` tests assert the refresh.
