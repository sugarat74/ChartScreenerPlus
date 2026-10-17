# Feature Implementation Spec: Calibrate chartist pattern detection

## Source Feature

- `id`: `chart-patterns-calibration`
- `area`: `engine`
- `depends_on`: `chart-patterns-detect`, `chart-patterns-ui`, `screener-precomputed` (accepted)
- `status`: `in_progress` (planned 2026-10-12; phase 1 implemented 2026-10-16, phase 2 waits for human labels)
- `source`: `feature_list.json`

## Goal

Tune the deterministic pattern rules so that what Chartiko labels as a double top/bottom, cup with handle or bull flag matches what a technical analyst would recognise: fewer false positives without losing clear patterns. The current parameters are the initial values of `docs/specs/chart-patterns-detect.md`; a local run over 494 S&P 500 instruments produced 178 patterns, 97 of them double tops, which is implausibly high. Calibration is driven by a **labelled reference sample** and measured, never by silent threshold changes.

## Non-Goals

- No backtesting or "did the pattern make money" analysis (out of scope): only whether the detected geometry is correct.
- No new pattern types (triangles, head-and-shoulders, wedges, bear flags) and no price targets.
- No machine-learning detector; rules stay deterministic and explainable.
- An AI vision assistant may help label, but labels are confirmed by a person (see optional step).

## Job Story

When I review the patterns Chartiko shows,
I want them to be real, recognisable formations,
so I can trust the pattern filter and the chart overlay.

## Plan

1. **Review sample tool (Admin/CLI only).** `php artisan patterns:review-sample {--per-type=40} {--negatives=40} {--out=storage/app/pattern-review}` exports, for each type, detected instances (mix of forming/confirmed, random seed recorded) plus near-miss negatives (instruments rejected by exactly one rule), each as an SVG chart (closes, key points, breakout level) and one CSV row (`id, ticker, type, status, as_of, points, label=`). Nothing is shown to the public.
2. **Labelling.** A person labels each row `clear` / `acceptable` / `not_a_pattern` (and, for negatives, `missed`). Optional: a vision model pre-labels the SVG/PNG to speed this up (Claude Sonnet, server-side, no personal data); its labels are suggestions only. The labelled CSV is committed under `docs/calibration/` (no personal data).
3. **Metrics** `php artisan patterns:calibration-report {csv}`: precision per type (`clear+acceptable` / reviewed detected), miss rate on negatives, frequency per type over the universe, and the parameter values used.
4. **Rule fixes already identified** (validator notes on `chart-patterns-detect`):
   - cup handle: invalidate when a handle high exceeds the right rim by more than a cap (e.g. 3%) or a close above the rim happens without the volume confirmation;
   - post-breakout invalidation for `bull_flag` (close below the flag low) and `cup_with_handle` (close below the handle low), as double top/bottom already have;
   - consistent `handle_sessions` / `flag_sessions` metadata (both exclude the breakout bar);
   - prior-move lookback includes bar `t1` as the table states.
5. **Parameter tuning** guided by the metrics (tolerances, depth ranges, gaps, minimum prior move, flag retracement). Every change: updated parameter table in `docs/specs/chart-patterns-detect.md`, updated engine tests, re-run of the report.
6. **Roll-out.** Deploy; `patterns:detect` + `screener:refresh` run in the next pipeline (or manually); record before/after frequencies in production.

## Acceptance

- Precision (`clear`+`acceptable`) ≥ 80% per type on the reviewed sample, or the type is documented as "low confidence" with its measured precision.
- No type dominates without justification (e.g. double tops ≤ 40% of all detections unless the sample confirms them).
- All four rule fixes implemented with tests; engine tests, `.\init.ps1` and CI pass.
- Labelled sample, report output and parameter changes recorded in the spec and `PROGRESS.md`.

## Durable Docs

- `docs/specs/chart-patterns-detect.md` (parameter table + calibration findings), `CONSTRAINTS.md` (new parameters, new invalidation rules), `ARCHITECTURE.md` (review/report commands).

## Risks

- Labelling is subjective: use two labels per case where possible and record disagreements.
- Tighter rules reduce coverage: report the miss rate alongside precision.

## Implementation Findings / Phase 1

Recorded 2026-10-16. The user split the work in two phases because acceptance needs a sample labelled by a person. Phase 1 = plan steps 1, 3 and 4 (plus optional machine pre-labels). Phase 2 = human labels, report, parameter tuning (step 5) and roll-out (step 6). No parameter was tuned and no precision target is claimed.

### Rule fixes (step 4)

All in `engine/app/patterns/rules.py`, tests in `engine/tests/test_patterns.py`; the parameter table of `docs/specs/chart-patterns-detect.md` and `CONSTRAINTS.md` are updated.

1. **Cup handle cap.** New constant `HANDLE_MAX_RISE = 0.03`: before the breakout, a handle high above `1.03 × right rim` invalidates the cup, and so does any close above the right rim that is not the volume-confirmed breakout (too early, below the pivot or `rvol < 1.5`). Such a cup used to stay `forming`. Tests: `test_cup_handle_high_capped_above_the_rim` (1.029 kept, 1.031 dropped), `test_cup_with_handle_confirmed_needs_volume` (now expects `None` without volume).
2. **Post-breakout invalidation.** After its breakout, a cup is dropped on a close below the handle low and a bull flag on a close below the flag low. Tests: `test_cup_invalidated_by_a_close_below_the_handle_low_after_the_breakout`, `test_bull_flag_invalidated_by_a_close_below_the_flag_low_after_the_breakout`.
3. **Session metadata.** `handle_sessions` now excludes the breakout bar, like `flag_sessions` already did; the handle length limits (5..25) apply to that count, so a confirmed cup may now have 25 handle sessions instead of 24. Tests: `test_handle_sessions_exclude_the_breakout_bar`, `test_flag_sessions_exclude_the_breakout_bar` (guard).
4. **Prior-move lookback** includes bar `t1` (`low[t1-60..t1]`, `high[t1-60..t1]`) as the table states. Test: `test_double_top_prior_rise_lookback_includes_t1`.

Each new test except the flag-sessions guard fails against the previous `rules.py` (checked by running them against the old module).

### Near misses (engine support for step 1)

`PatternParams` (frozen dataclass, defaults = the constants) is passed to every detector. `engine/app/patterns/near_misses.py` reruns a detector with exactly one rule relaxed. It only does this for types the production rules do not detect on that series, and reports the first relaxation in a fixed order that finds an instance. That instance is therefore rejected by that one rule alone. Relaxations: `peak_tolerance` 3%→5%, `min_depth` 10%→7% (cup 12%→8%), cup `max_depth` 35%→45%, `prior_move` 15%→10%, `min_gap` 15→10, `rim_tolerance` 5%→8%, `low_position` 20–80%→10–90%, `handle_max_depth` 12%→18%, `handle_max_rise` 3%→6%, `handle_max_length` 25→35, `breakout_rvol` 1.5→1.0, `pole_min_rise` 15%→10%, `pole_max_length` 15→20, `flag_max_retrace` 50%→61.8%, `flag_max_length` 20→25. The structural `extremes`, `down_slope` and `lower_volume` rules are switched off one at a time. Endpoints: `POST /patterns/near-misses`, `GET /patterns/parameters`. The engine stays DB-free and the results are never persisted. Tests: 4 engine tests (single-rule report, no near miss for detected types, detection unchanged, endpoints + 422).

### Commands (steps 1 and 3)

- `patterns:review-sample` (`app/Console/Commands/PatternReviewSample.php`, SVG renderer `app/Services/Patterns/PatternReviewChart.php`) is described in `ARCHITECTURE.md` (Patterns Path).
  - `--negatives` is a **total** spread round-robin over the types, not per type. This keeps the labelling load near 140 cases.
  - It refuses to overwrite an existing `sample.csv` without `--force`.
  - The same seed on the same data gives a byte-identical CSV (verified on the real export).
- `patterns:calibration-report` prints markdown with:
  - precision per type, with a `>= 80%` / "low confidence" column;
  - miss rate per type and rule;
  - agreement between `suggested_label` and `label`;
  - frequency per type over the universe;
  - the parameters.

  Unknown labels are listed and ignored.
- Tests: `tests/Feature/PatternCalibrationCommandsTest.php` (4 tests, 86 assertions) with the fixture `tests/Fixtures/pattern-calibration/{sample.csv,parameters.json}`.
- A bug was found while viewing the real charts and fixed: bar dates are stored as `YYYY-MM-DD 00:00:00`, so `where('date', '<=', as_of)` dropped the as-of bar from every chart. The command now uses `whereDate`, and the test asserts that the as-of date is an axis label.

### Real sample

- Data: the local dev SQLite copy (S&P 500, 494 instruments with bars, latest bar 2026-10-02), migrated, with the engine running locally.
- Export: `docs/calibration/sample-2026-10-16/`, seed `20261016`, `--per-type=40 --negatives=40`, 138 cases.

| Type | Detected forming | Detected confirmed | Near misses (rules) |
| --- | ---: | ---: | --- |
| double_top | 27 | 13 | 10 (extremes 4, min_depth 2, peak_tolerance 2, prior_move 2) |
| double_bottom | 31 | 9 | 10 (extremes 4, peak_tolerance 3, prior_move 3) |
| cup_with_handle | 14 | 1 | 10 (extremes 10) |
| bull_flag | 2 | 1 | 10 (flag_max_retrace 6, pole_max_length 2, pole_min_rise 1, down_slope 1) |

Cups and flags have fewer detected cases than `--per-type` because that is every instance in the universe.

### Frequency before and after the rule fixes (same DB, as-of 2026-10-02, 494 instruments)

| Type | Before (forming / confirmed) | After (forming / confirmed) |
| --- | --- | --- |
| double_top | 84 / 13 (97) | 84 / 13 (97) |
| double_bottom | 52 / 9 (61) | 52 / 9 (61) |
| cup_with_handle | 16 / 1 (17) | 14 / 1 (15) |
| bull_flag | 2 / 1 (3) | 2 / 1 (3) |
| total | 178 | 176 |

The fixes are correctness fixes and barely change frequency. Double tops are still 55% of all detections (the acceptance asks for at most 40% unless the sample confirms them). That is phase 2 work: tuning driven by the labels.

### Machine pre-labels (optional helper)

The SVGs were rendered to PNG with headless Edge and each of the 138 charts was viewed by Claude, which filled **only** `suggested_label`. `label` and `notes` are empty for the person. These are suggestions, not labels, and the report never counts them.

Indicative only, from the suggestions:

| Type | Clear | Acceptable | Not a pattern | Implied precision |
| --- | ---: | ---: | ---: | ---: |
| double_top | 2 | 21 | 17 | 57.5% |
| double_bottom | 2 | 15 | 23 | 42.5% |
| cup_with_handle | 1 | 10 | 4 | 73% |
| bull_flag | 1 | 2 | 0 | 3 of 3 |

Suggested `missed` near misses: double_top 2 of 10, double_bottom 0 of 10, cup 1 of 10, flag 4 of 10.

Recurring reasons in the suggestions, as hypotheses for phase 2 (not decisions):

- Key points set by a single-bar wick or spike, with the closes telling a different story.
- The left peak or low is not the local extreme: a higher top or lower low just before `t1`, or a lower high inside a downtrend for tops and cup left rims.
- A double bottom whose right low has no rebound yet (price still at the lows).
- Double bottoms that are pullbacks inside uptrends, and wide ranges read as tops or bottoms.
- The same geometry reported as both a double top and a cup (CVX).
- One data artifact: CTVA's last bar is about 12 vs about 78, likely an unadjusted corporate action, and it produced a "confirmed" double top.
- Flags look under-detected: a flat flag is rejected by `down_slope`, and orderly flags retrace 55–62%.

### Not done in phase 1 (by decision)

- Human labelling.
- Running the report on labels.
- Parameter tuning (step 5).
- Roll-out (step 6).
- Any precision claim.

`chart-patterns-calibration` stays `in_progress`.
