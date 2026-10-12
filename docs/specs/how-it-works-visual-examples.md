# Cómo funciona: visual examples

## Goal

Give every indicator and chart pattern explained in the current `/como-funciona` manual its own example image beside the explanation, in Spanish and English.

## Scope

- Illustrate the six current entries in `Indicadores técnicos`: SMA reference levels, RSI, MACD, RVOL, Pivot and Volume.
- Illustrate the four supported Chartist Patterns: Double Bottom, Double Top, Cup with Handle and Bull Flag.
- Keep diagrams schematic, using fictional data and the established design palette. Explain that they are examples, not current market quotes, active Signals or price targets.
- Give each illustration a meaningful accessible image description in both languages and keep the layout usable at narrow widths.
- Preserve the manual's existing rule descriptions, section anchors, access, route and noindex behavior.

## Acceptance

1. Every entry in the two named sections has exactly one distinct diagram; other manual entries do not acquire unrelated images.
2. The SMA illustration shows horizontal reference levels, matching the current chart UI. Pivot and Volume are covered; the future-only EMA, ADX and Bollinger calculations are not presented as visible indicators.
3. Both locales have matching illustration sets, localized descriptions and a fictional-data note. Public text contains no EOD label.
4. A page test checks the ten accessible images and catalog parity; `npm --prefix frontend run lint`, `npm --prefix frontend run test` and `npm --prefix frontend run build` pass.
5. `init.ps1` passes in a configured development worktree; desktop and narrow-width layouts are visually checked.

## Durable Documentation Impact

Update `DESIGN.md` for the illustrated manual and record verification in `PROGRESS.md` and `feature_list.json`. No API, data model or engine documentation changes.
