# Feature Implementation Spec: Calibrate chartist pattern detection

## Source Feature

- `id`: `chart-patterns-calibration`
- `area`: `engine`
- `depends_on`: `chart-patterns-detect`, `chart-patterns-ui`, `screener-precomputed` (accepted)
- `status`: `not_started` (planned 2026-10-12, to develop later)
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
