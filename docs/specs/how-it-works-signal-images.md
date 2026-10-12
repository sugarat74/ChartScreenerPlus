# Cómo funciona: technical Signal examples

## Goal

Add one explanatory image to each of the nine current technical Signals in `/como-funciona`, matching the existing indicator and Chartist Pattern illustrations.

## Scope

- Illustrate Golden Cross, Death Cross, bullish and bearish moving-average alignment, Pivot Breakout + RVOL, RSI overbought and oversold, and bullish and bearish MACD crosses.
- The drawings are distinct, schematic SVGs using fictional data and the existing DESIGN palette. A note explains that they are examples, not current market quotes or active Signals.
- Give every image an accessible description in Spanish and English. Preserve the existing Signal rules, section structure, route, and noindex behavior.

## Acceptance

1. Each of the nine Signal entries has one distinct image; the existing six indicator and four Chartist Pattern images remain in place.
2. Crosses, alignment order, RSI thresholds and the combination of Pivot breakout with elevated RVOL are accurately depicted.
3. Both languages have matching image kinds and localized descriptions, with a fictional-data note; no public EOD labels are introduced.
4. The focused manual test, SPA lint/build and standard `init.ps1` gate pass. The section is visually checked at desktop and narrow widths.

## Durable documentation

Update `DESIGN.md`, `PROGRESS.md` and `feature_list.json`. No API or engine change.
