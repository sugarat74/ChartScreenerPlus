# Chart indicators: RSI, MACD, OBV and Supertrend

## Source Feature

- `id`: `chart-indicators-rsi-macd-obv-supertrend`
- `area`: `chart`
- `depends_on`: `indicators-expansion-engine`, `indicators-expansion-storage`, `chart-indicator-overlays`, `chart-indicator-panels`
- `status`: `in_progress`
- `source`: `feature_list.json`

## Goal

Add RSI (14), MACD (12,26,9), OBV and Supertrend (10,3) to the chart indicator picker. RSI, MACD and OBV render in synchronized panes below volume. Supertrend renders as an overlay on the price pane. Selection persists in the existing `chartiko.chart.indicators` localStorage entry and remains off by default.

Reuse the stored RSI/MACD snapshots; compute OBV and Supertrend in the Python indicator engine and persist them with each Indicator Snapshot. The chart API exposes history only while a selection is active; the SPA does no indicator math.

## Decisions

- RSI pane: fixed 0..100 scale with dashed 30 and 70 reference lines.
- MACD pane: MACD and Signal lines plus a positive/negative colored histogram, using the existing stored 12/26/9 series.
- OBV: cumulative volume, starts at the first bar's volume; add volume on an up close, subtract on a down close, unchanged on an equal close.
- Supertrend: Wilder ATR period 10 and multiplier 3; direction is a nullable `+1` bullish / `-1` bearish value. The displayed line uses the price scale.
- Supertrend start: the first value is at index 10 (11 bars needed). Wilder ATR is seeded with the mean of TR[1..10] (bar 0 has no true range); the initial direction is `+1` when `close >= (high + low) / 2`, else `-1`. Final bands carry forward (upper only tightens, lower only rises, unless the previous close crossed it) and a flip is tested against the current final band: `-1` to `+1` when `close > final upper`, `+1` to `-1` when `close < final lower`. The line is the final lower band when bullish and the final upper band when bearish.
- Warm-up/missing history is `null`; no partial-window values. New snapshot fields are additive and nullable until recomputation.
- All four toggles are off by default. Existing Bollinger, ADX, ATR and Stochastic behavior and persisted selections remain compatible.

## Acceptance Scenarios

1. A Visitor can toggle each indicator using keyboard or pointer; `aria-pressed` reflects state and the selection is restored on reload.
2. Turning on any indicator makes one shared request to the public stored indicator-history endpoint; with all indicators off there is no indicator request.
3. RSI, MACD and OBV appear in separate synchronized panes; Supertrend follows price. Bollinger and existing panes continue to render.
4. Insufficient history shows the localized existing pane notice; nullable series values are omitted, never plotted as zero.
5. Spanish/English labels, light/dark colors and narrow layouts remain usable; all panes share the chart time scale.

## Implementation Notes

- Extend engine `Snapshot`, `compute_snapshots`, and `indicators/core.py` with OBV and standard Supertrend; preserve existing RSI/MACD values byte-for-byte.
- Add nullable `obv`, `supertrend`, `supertrend_direction` fields to `indicator_snapshots`; include them in `ComputeIndicators`, the detail snapshot payload and the history endpoint.
- Extend the SPA series types, fixed picker IDs, pane mapping, chart renderers, empty-history handling, translations and DESIGN documentation.
- Update the technical-indicators expansion reference and indicator API/compute tests for additive fields. Recompute stored snapshots after migration to populate the new fields.

## Verification

- Engine tests for cumulative OBV ties/up/down, Supertrend initialization, direction changes, warm-up nulls and known fixture values.
- Laravel tests for migration, compute persistence and additive history payload.
- Vitest for selection persistence, lazy single-request behavior, pane data mapping and accessibility state.
- `npm --prefix frontend run lint`, `npm --prefix frontend run build`, and `.\init.ps1` after focused checks.

## Durable Docs

- Update `DESIGN.md`, `docs/specs/technical-indicators-expansion.md`, `feature_list.json` and `PROGRESS.md` to match the implementation and record evidence.
- `ARCHITECTURE.md` and `CONSTRAINTS.md`: update only if implementation changes their existing engine-owned indicator-computation and chart-history API rules.
