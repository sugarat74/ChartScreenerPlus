---
name: Chartiko Terminal
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

Chartiko is a professional but opinionated trading terminal: warm paper-like backgrounds, sharp black 2px borders, hard offset shadows instead of blur, and one loud yellow accent. It should feel like a printed financial newspaper crossed with a quantitative terminal — deliberately not a glossy SaaS dashboard.

The Admin subnavigation includes a Backtesting tab for explicit Jev historical runs. Its setup, progress, aggregate accuracy and per-bar results use the existing bordered cards, compact mono tables and yellow action button; the confirmation states the maximum number of provider calls before starting.

The Chartiko mark combines an angular C and a chart stroke, with an ink square and hard yellow offset shadow. `frontend/public/favicon.svg` is shared by the header and browser icon. Wordmark: CHARTIKO. EOD labels are restricted to Admin; public surfaces use neutral analysis/data labels and retain session dates.

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
- **Accent:** `#ffcc00` for primary actions and highlights (data actions, focus, selection).
- **Background / surface:** `#f5f0e8` page, `#eee9e0` panels, `#faf7f2` elevated cards.
- **Signal colors:** gains green (`#059669`), losses red (`#e63b2e`).
- **Context accents:** red (`#e63b2e`) for AI/attention, blue (`#0055ff`) for admin/pipeline.
- Do not introduce new hues; extend the existing palette if truly needed.

### Dark theme

Selectable in the header (sun/moon control next to the language selector, `ui-dark-mode`); without a stored choice it follows the operating system. Same token names, dark values under `:root[data-theme='dark']` in `frontend/src/index.css`:

- **Ink → warm off-white:** `primary` / `on-surface` `#f2ede5`, `outline` `#e8e3da`, `on-surface-variant` `#b5afa6`, `outline-variant` `#4a4741`. `on-primary` becomes `#1a1a1a`.
- **Surfaces:** page `#181715`, panels `#2a2825`, elevated cards `#22201d` (container scale `#0f0e0d` → `#3a3834`).
- **Accent unchanged:** `#ffcc00` with `#1a1a1a` ink, same as the light theme.
- **Signals lightened for contrast:** gains `#34d399`, losses/attention `#ff6b5e`, pipeline blue `#7aa2ff`; containers `#4a1f1b` (red) and `#1f2c4d` (blue).
- **Hard shadows:** the offset uses `var(--color-shadow)` (`#1a1a1a` light, `#000000` dark); never write the ink hex in a shadow or accent class (a Vitest guard enforces it).
- **Chart canvas:** background, text, grid and borders follow the theme; candle outlines use each candle's hue in dark, and SMA/breakout lines and pattern markers use the lighter blue.
- **Text on filled accents:** always pair the fill with its own ink: `bg-primary-container text-on-primary-container`, `bg-gain text-on-gain` (`#1a1a1a` in both themes). Never leave the text to inherit `on-surface`, which is off-white in dark.
- **Admin run log** stays a black terminal in both themes (`.terminal-panel` pins the light token values inside it).

## Typography

- **Headline / display:** Space Grotesk, bold/black weights, often uppercase.
- **Body:** Inter at 13-14px for readable prose and table content.
- **Mono:** for tickers, prices, percentages, timestamps, logs and any tabular figure.
- Numeric tables should use mono and right-align.

## Layout

- Sticky top header with brand, language selector and session controls.
- A horizontal tab bar for the main surfaces (Screener, Mercado, Cómo funciona, Admin, Portal). «Mercado» is always visible. It is a link only for a signed-in account with Market Context access (Admins during preview; Registered Users after the legal gate opens). For Visitors and accounts without access it is muted, marked disabled and cannot be activated. The Screener is the homepage; Cómo funciona is the user manual: a table of contents, then one section per topic with entry cards for each filter, indicator, signal and pattern. Open an Instrument chart from a Candidate or Watchlist entry.
- In Cómo funciona, every current indicator, technical Signal and Chartist Pattern entry has a distinct responsive schematic SVG inside its card, with a localized accessible description. The drawings use the existing ink, blue, red, green and yellow palette and are labelled as fictional examples.
- Content constrained to a `max-w-7xl` centred column with generous vertical rhythm.
- Screener: filters/inline criteria at the top, then a compact Saved Screeners disclosure (closed by default for signed-in users), then the ranked table. Visitors see a small sign-in link in that position.
- Admin: telemetry tiles + log stream, side by side on desktop.

## Shapes

- 2px solid `#1a1a1a` borders on cards, buttons, inputs and badges.
- Small radii (4-6px). Avoid pill shapes except status chips and the user avatar.
- Hard offset shadows (`2px 2px 0 #1a1a1a`, written `shadow-[2px_2px_0px_var(--color-shadow)]` so it follows the theme; larger `4px 4px 0 #ffcc00` for emphasis), never soft/blurred shadows.
- Pressed/active state translates the element by the shadow offset.

## Components

- **Primary button:** yellow fill, 2px border, hard shadow, uppercase headline label.
- **Card/tile:** light surface, 2px border, hard shadow, mono labels for metrics.
- **Table:** mono numerals, right-aligned figures, colored change values, sparkline column.
- **Badge/chip:** small, bordered, mono, color-coded per category.
- **Tabs:** uppercase headline labels, 2px bottom border for the active tab, subtle surface fill.
- **Chart:** candlesticks with SMA overlays, volume bars, marked Signal levels (pivot/stop/target). MVP (`chart-interactive`) renders with the self-hosted `lightweight-charts` and draws the **latest-snapshot** SMA reference lines plus the pivot only; stop/target stay an ambition until the engine models them. Chart canvas colors use the exact token hexes, and the library's TradingView attribution stays visible.

## Core Screens

- **Screener:** filter panel + ranked Candidate table. Primary MVP surface. The Candidate table places Sector, Industria and País de sede in three separate columns immediately after Símbolo / Empresa, followed by the existing technical columns; narrow screens keep the horizontally scrollable table. Every heading (screener-table-column-controls) has a compact sort button (label + `↕` unsorted, bordered with `▲`/`▼` when active, `aria-sort` on the `th`; text columns start A–Z, figures highest first, a second click flips) and a small bordered funnel toggle (yellow `primary-container` fill when that column has a filter, `aria-expanded`). The toggle opens one bordered editor card above the table (outside the scroll area, so it is never clipped): text field, localized select, Mín/Máx inputs with an inline red-ink error, or the signal/pattern chips; «Aplicar», «Quitar filtro», «Cerrar», Escape closes and returns focus. Active column criteria show as yellow chips (summary + `×`) under a «Filtros de columna» label, also in the empty state. The filter panel ends with a «Contexto sectorial» fieldset (market-context-patterns) for accounts with Market Context access: a `Cualquiera / ▲ Alcista / ◆ Mixta / ▼ Bajista` segmented control (same classes as «Cruce de medias», `aria-pressed`, wraps under 390 px), a labelled «Fuerza relativa del sector» select (`Todos los sectores`, `Top N de 11`), a mono hint and a dashed yellow «Vista previa» chip for Admins while not public. Visitors (once public) get a dashed sign-in box instead; anyone else no fieldset. When a shared URL carries sector filters the session cannot use, a dashed `role="status"` note above the results says they were left out (with a sign-in link for Visitors).
- **Chart:** interactive candlestick chart with indicators, Signal reference levels and metadata. For a stock, a «Contexto de mercado» strip sits between the page header and the OHLC/chart card (market-context-patterns): a 2px bordered card with hard shadow, uppercase title, dashed yellow «Vista previa» chip for Admins while not public, a mono «Datos del {fecha}» line, a wrapping row with «Sector: {nombre} (XLK)» (underlined mono ETF link), «Tendencia del sector» + the bordered trend chip, mono «Fuerza relativa N/M», «Entorno: ● Neutral» (regime icon + text in its colour, its own date when it differs) and red-ink «Desactualizado» chips; then an underlined mono «Ver contexto de mercado» link and a small footer (disclaimer, FRED® notice verbatim in English). Visitors (once public) get a dashed sign-in box; ETFs nothing. «Patrones activos» repeats three compact mono chips per pattern (`▲ Sector Alcista`, `Fuerza relativa 1/10`, `● Entorno: Neutral`) after a visually hidden «Contexto actual» label, with one mono note that the context is from the latest session, not the pattern's date.
- **Experimental forecast:** a compact bordered panel follows the chart's technical context. It has the yellow action, an experimental badge, a horizon selector, a minimum-probability field and a plain-language unavailable state. Not rendered for Visitors (nor while the session loads). The three class probabilities and dates use the existing dense data style.
- **Admin:** ingestion control, telemetry tiles and run log stream.
- **Portal (Mi Portal & Seguimientos):** Saved Screeners and Watchlist for Registered Users. Each Saved Screener row carries a small square switch "Avisarme de nuevos candidatos" with an off/active/paused status chip; the Watchlist column ends with an "Alertas de mi Watchlist" card (Signal-type checkboxes, yellow save, pause/delete).
- **Account (`/portal/cuenta`):** reached from the header name chip (initial + "Cuenta") and a Portal button. Four cards in a two-column grid (one column on mobile): Profile (read-only email, name field, yellow save), Password, Download my data (yellow button) and a danger zone in the error styling (`border-secondary bg-secondary-container`) with the typed confirmation word, the password and a red (`bg-secondary`) delete button that stays disabled until both are filled. Admins see a note instead of the deletion form.
- **Notifications:** an icon-only bell in the header (signed-in users only) with a red bordered count badge capped at "9+"; the inbox `/portal/notificaciones` lists cards newest first (New/Read chip, data date, kind title, ticker chips linking to charts) with "Marcar todas como leídas" and Anterior/Siguiente paging.
- **AI Company Card (Ficha IA):** a bordered card after the forecast panel on the chart page (`#ai-company-card`), titled "Ficha de empresa". Not rendered for Visitors (nor while the session loads); signed-in users see loading, empty + yellow "Generar previsión", generating (button disabled, "puede tardar hasta un minuto"), not-available, stale (+ "Actualizar previsión") and `role="alert"` error states. It has two clearly labelled blocks: first a prominent "Previsión a 3 meses" heading (`font-headline` xl) with the yellow `IA` badge and the warning line, the generate/stale/error states, the neutral stance chip (never gain/loss colors), confidence, summary, macro/sector and drivers/risks; then "Métricas clave" with a bordered mono "Fuente: SEC (10-K/10-Q)" badge and a one-column (two from `sm`) grid of ten bordered tiles (label, mono value with a fixed unit, small mono period line such as "TTM a 27 jun 2026 · 10-Q" or "Precio del {fecha}", or `—` plus the reason, e.g. "no aplica a entidades financieras"; period lines wrap at 390 px), a mono source footer (SEC EDGAR, closing-price date, update time) and a bold pending-filing note; then the AI name/ticker with `IA` badge, sector/industry, business text, limitations, plain-text sources and a mono footer with generation date, model and context sent. The metrics render even without an AI card. For signed-in users only, each Candidate row carries a small mono underlined "Ficha IA" link under the company name.
- **Mercado (`/mercado`, Market Context):** `max-w-7xl` column: uppercase headline, one-line intro and «Datos del {fecha}»; a dashed yellow «Vista previa» note for Admins while the page is not public. Then a full-width regime card (large bordered label with icon **and** text: `▲ Favorable` gain, `● Neutral` ink, `▼ Adverso` loss, `? Sin datos suficientes` muted; mono score and «N componentes cuentan», a reasons list where each line ends with `▲ suma +1` / `● suma 0` / `▼ resta 1` or «no cuenta»), a full-width sector table (1 semana / 1 mes / 3 meses segmented control with `aria-pressed`, mono right-aligned signed figures in gain/loss colour with an explicit sign, bordered trend chips `▲ Alcista` / `◆ Mixta` / `▼ Bajista`, rows linking to the ETF chart, a focusable horizontal-scroll region under `sm`), then a `1 / 2 / 3` column grid of cards (Breadth, rates and curve with an «Invertida» chip, liquidity, central banks, leading data). Every card: title, date line, a bordered red-ink «Desactualizado» chip when stale (text, never colour alone), mono source line and a «¿Qué significa?» `<details>`. History: a bordered «Ver evolución» button opening an inline SVG line (tertiary blue, dashed labelled reference lines, 1 año / 3 años toggle). The page is for Registered Users only: a Visitor sees the headline, intro and a dashed sign-in box once the page is public, «Esta página aún no está disponible» before. The footer carries the disclaimer, the FRED® notice (English, verbatim), the binding clause with the FRED Terms of Use link and the OECD CC BY note.
- **Admin › Contexto de mercado (`/admin/market-context`):** a tab after Ingesta with the public/macro switches as mono text, a runs table (status as text, times, exit code, yellow «Recalcular» per command, disabled while active or when macro is off), snapshot coverage with the regime distribution and a series table (last observation, refresh, attempt, error and stale as text). EOD wording is allowed here.
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
- Don't surface out-of-scope features (AI, plans, real-time or push notifications) as if they were available. In-app alerts are available and evaluated once a day.

## Open Design Questions

- ~~Charting library and whether the embedded TradingView widget's built-in look conflicts with the terminal aesthetic.~~ Resolved by `chart-interactive` (2026-09-30): **self-hosted Lightweight Charts 5.2.1**, not the embedded widget. The terminal aesthetic is preserved by mapping every canvas color to an `index.css` token (surface/grid/text/gain/loss/accent) inside the same 2px-border, hard-shadow card; the library's attribution logo stays visible.
- Density of the Candidate table on smaller laptops.
- ~~How to represent "stale" vs "fresh" EOD data visually.~~ Resolved by `market-context-ui` (2026-10-23): every reading carries its own date line; a stale one adds a bordered «Desactualizado» / «Stale» text chip in the loss ink with an explanatory `title`, never colour alone.
