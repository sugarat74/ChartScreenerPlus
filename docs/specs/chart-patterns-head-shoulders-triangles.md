# Chart patterns: Head and Shoulders and Triangles

## Backlog feature

- **ID:** `chart-patterns-head-shoulders-triangles`
- **Status:** `passing` (self-verified; awaiting independent validation)
- **Depends on:** `chart-patterns-detect`, `chart-patterns-ui`, `chart-patterns-calibration`, `chart-patterns-extended`, `saved-screeners` (all accepted)
- **Request:** 2026-11-10 — start with Head and Shoulders and triangle formations.

## Goal

Extend the existing deterministic Chartist Pattern pipeline so Visitors can filter Candidates by these formations in the Screener and inspect their geometry on the chart. Keep the existing `forming` / `confirmed` lifecycle, active-pattern endpoint and current-data semantics.

## Candidate pattern families

- Head and Shoulders (`head_and_shoulders`) and inverse (`inverse_head_and_shoulders`): three major swing peaks/troughs, with the middle head beyond both shoulders and shoulders within a calibrated tolerance. The neckline is derived from the two intervening pivots; confirmation follows a close through it in the family direction.
- Triangles: ascending, descending and symmetrical geometry from converging swing-high/swing-low boundaries. Detect forming structures and confirm only after a close breaks a boundary.

## Product contract

- Use deterministic OHLCV rules and named, calibrated parameters; no ML, prediction, target, stop or measured move.
- Carry the new types through persisted active patterns, public Candidate payload, Screener pattern filter, chart key points/text description, Saved Screeners and Spanish/English guide and labels.
- Preserve the current last-10-session confirmation lifecycle, one latest instance per type, replace semantics, and existing pattern behavior.
- No Watchlist alerts or AI natural-language Screener support in this feature unless separately requested.

## Implementation decisions

- Triangles keep one stable geometry type (`ascending_triangle`, `descending_triangle`, `symmetrical_triangle`) while forming. `direction` is nullable and is populated only on a confirmed breakout. Head-and-shoulders directions are fixed by orientation; existing patterns are normalized to bullish/bearish direction too.
- `breakout_level` is nullable. Forming patterns with sloped boundaries do not fabricate a horizontal price. Confirmed patterns retain the boundary value at breakout. Geometry endpoints use explicit point roles and the chart draws the segments with localized labels.
- Conservative initial constants are named in `engine/app/patterns/head_shoulders_triangles.py`. Synthetic fixture calibration and the existing sample review are required before acceptance; no representative labeled sample for these five new types exists yet, so precision/recall cannot be claimed and this limitation remains open.

## Acceptance outline

- Synthetic fixtures cover each formation, both H&S orientations, all three triangle geometries, both breakout directions, invalidation, warm-up/insufficient history and non-matching near-misses; real labeled validation remains an acceptance prerequisite.
- The engine returns stable point roles and metadata; API/storage and Screener filters preserve the `forming` / `confirmed` status contract.
- Pattern selection survives a Saved Screener round trip; chart points, accessible text and labels render in Spanish and English.
- Existing ten pattern types and their signals, alerts and AI schema remain unchanged.

## Implementation boundary

Engine detection, pattern storage/API integration, Screener vocabulary/filter, chart presentation and bilingual guide copy are in scope. Threshold selection belongs to `chart-patterns-calibration`; deployment and production universe backfill are separate operational steps.
