---
name: AlphaPulse Terminal
description: Neo-brutalist trading terminal aesthetic — warm paper background, hard black borders, offset shadows and a single yellow accent.
designAssets:
  sourceOfTruth:
    - path: alphapulse/
      authority: source of truth
      notes: React prototype with the real visual language. Treat its tokens and components as authoritative; ignore its fake data and Express server.
  generatedConcepts: []
colors:
  primary: "#1a1a1a"
  on-primary: "#ffffff"
  accent: "#ffcc00"
  on-accent: "#1a1a1a"
  secondary: "#e63b2e"
  tertiary: "#0055ff"
  background: "#f5f0e8"
  surface: "#eee9e0"
  surface-bright: "#faf7f2"
  surface-dim: "#d6d1c9"
  text: "#1a1a1a"
  text-muted: "#4a4a4a"
  outline: "#1a1a1a"
  outline-variant: "#d0cbc3"
  gain: "#059669"
  loss: "#e63b2e"
typography:
  headline:
    fontFamily: "Space Grotesk, sans-serif"
    fontWeight: 700
  body:
    fontFamily: "Inter, sans-serif"
    fontSize: "14px"
  mono:
    fontFamily: "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace"
    fontSize: "12px"
rounded:
  sm: "4px"
  md: "6px"
  full: "9999px"
shadow:
  hard-sm: "2px 2px 0px #1a1a1a"
  hard-accent: "4px 4px 0px #ffcc00"
border:
  width: "2px"
  color: "{colors.outline}"
components:
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.on-accent}"
    border: "2px solid {colors.outline}"
    rounded: "{rounded.md}"
    shadow: "{shadow.hard-sm}"
  card:
    backgroundColor: "{colors.surface-bright}"
    border: "2px solid {colors.outline}"
    rounded: "{rounded.md}"
    shadow: "{shadow.hard-sm}"
  badge:
    fontFamily: "{typography.mono.fontFamily}"
    border: "1px solid {colors.outline}"
    rounded: "{rounded.sm}"
---

# Design Direction

## Overview

AlphaPulse is a professional but opinionated trading terminal: warm paper-like backgrounds, sharp black 2px borders, hard offset shadows instead of blur, and one loud yellow accent. It should feel like a printed financial newspaper crossed with a quantitative terminal — deliberately not a glossy SaaS dashboard.

## Existing Design Assets

- `alphapulse/` (React 19 + Tailwind prototype) is the **source of truth** for colors, typography, layout and components.
- Its data (`masterStocks`, seeded candles, Gemini calls) and its Express backend are **not** authoritative.
- The "PRO TRADER" plan/badge UI in the prototype is decorative and must not drive requirements.

## Generated Concept Images

None. The prototype already provides the visual language; no image generation was used.

## Product Feel

- Dense, data-first, confident. Numbers and status are always visible.
- Mono type for tickers, prices, timestamps, logs and telemetry.
- Headline type (Space Grotesk) for section titles, nav and CTA labels, uppercase with tight tracking.
- Hard edges and offset shadows read as "instrument panel", not "marketing page".

## Colors

- **Primary / ink:** `#1a1a1a` for text, borders and outlines.
- **Accent:** `#ffcc00` for primary actions and highlights (Update EOD, focus, selection).
- **Background / surface:** `#f5f0e8` page, `#eee9e0` panels, `#faf7f2` elevated cards.
- **Signal colors:** gains green (`#059669`), losses red (`#e63b2e`).
- **Context accents:** red (`#e63b2e`) for AI/attention, blue (`#0055ff`) for admin/pipeline.
- Do not introduce new hues; extend the existing palette if truly needed.

## Typography

- **Headline / display:** Space Grotesk, bold/black weights, often uppercase.
- **Body:** Inter at 13-14px for readable prose and table content.
- **Mono:** for tickers, prices, percentages, timestamps, logs and any tabular figure.
- Numeric tables should use mono and right-align.

## Layout

- Sticky top header with brand, market/EOD status, a primary action and the user pill.
- A horizontal tab bar for the main surfaces (Screener, Chart, Admin, Portal).
- Content constrained to a `max-w-7xl` centred column with generous vertical rhythm.
- Screener: filters/inline criteria at the top, ranked table below.
- Admin: telemetry tiles + log stream, side by side on desktop.

## Shapes

- 2px solid `#1a1a1a` borders on cards, buttons, inputs and badges.
- Small radii (4-6px). Avoid pill shapes except status chips and the user avatar.
- Hard offset shadows (`2px 2px 0 #1a1a1a`, larger `4px 4px 0 #ffcc00` for emphasis), never soft/blurred shadows.
- Pressed/active state translates the element by the shadow offset.

## Components

- **Primary button:** yellow fill, 2px border, hard shadow, uppercase headline label.
- **Card/tile:** light surface, 2px border, hard shadow, mono labels for metrics.
- **Table:** mono numerals, right-aligned figures, colored change values, sparkline column.
- **Badge/chip:** small, bordered, mono, color-coded per category.
- **Tabs:** uppercase headline labels, 2px bottom border for the active tab, subtle surface fill.
- **Chart:** candlesticks with SMA overlays, volume bars, marked Signal levels (pivot/stop/target).

## Core Screens

- **Screener:** filter panel + ranked Candidate table. Primary MVP surface.
- **Chart:** interactive candlestick chart with indicators, Signal reference levels and metadata.
- **Admin:** ingestion control, telemetry tiles and run log stream.
- **Portal (Mi Portal & Seguimientos):** Saved Screeners and Watchlist for Registered Users.
- **Copilot IA:** present in the prototype only; out of MVP scope.

## Responsive Baseline

- Desktop-first, usable on tablet. Tables may scroll horizontally on narrow screens.
- Header and tab bar remain usable at small widths (tabs scroll, status text collapses).
- No dedicated mobile layout is required for the MVP.

## Accessibility Baseline

- Maintain strong contrast: near-black text on light surfaces; avoid yellow text on light backgrounds.
- Do not rely on color alone for gain/loss or Signal state; pair with signs/labels.
- Interactive elements need visible focus (border + accent offset) and keyboard operability.
- Data tables should expose proper headers for screen readers.

## Do's and Don'ts

- Do reuse the existing tokens and component patterns.
- Do use mono for all numbers and status/log text.
- Don't introduce soft shadows, gradients, glassmorphism or rounded-full cards.
- Don't add new brand colors or decorative illustrations.
- Don't surface out-of-scope features (AI, alerts, plans) as if they were available.

## Open Design Questions

- Charting library and whether the embedded TradingView widget's built-in look conflicts with the terminal aesthetic.
- Density of the Candidate table on smaller laptops.
- How to represent "stale" vs "fresh" EOD data visually.
