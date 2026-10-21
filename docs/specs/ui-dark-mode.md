# Spec: ui-dark-mode

Status: implemented (see "Implementation Findings"). Requested by the user on 2026-10-21: "añade un modo oscuro al diseño web seleccionable en la portada del sitio".

## Goal

Every visitor can switch the whole SPA between the current light design and a dark one with a selector in the header, which is visible on the home page (Screener) and on every other surface. The choice persists in the browser; until a choice is made the SPA follows the operating system (`prefers-color-scheme`).

## User-Visible Behavior

- The header shows a two-option segmented control next to the language selector: light (sun icon) and dark (moon icon). `aria-pressed` marks the active option; the group and each option have localized accessible names (es/en).
- Choosing an option switches the theme at once, without a reload, on every surface: header, Screener, chart page (canvas included), Portal, Admin, legal pages, How it works (schematic charts included).
- The choice is stored in local storage (`chartiko.theme`: `light` or `dark`) only when the user picks one. Without a stored choice the theme follows the OS and reacts if the OS setting changes.
- No flash on load: an inline script in `index.html` sets the theme before the first paint (also for the static fallback shown before React mounts).
- The light theme looks exactly as before.

## Design

- Same token names, dark values. `frontend/src/index.css` keeps the light values in `@theme` and overrides them under `:root[data-theme='dark']`. `DESIGN.md` documents the dark palette as part of the visual source of truth.
- Hard shadows used the literal `#1a1a1a` in 132 class names; they now use `var(--color-shadow)` (light `#1a1a1a`, dark a darker-than-surface black so the offset still reads). Focus shadows keep the brand yellow, which is the same in both themes.
- Checkbox accents use the `primary` token instead of a literal.
- Chart canvas: background, text, grid and borders come from a light/dark palette in `chartData.ts`; data hues (gain, loss, SMA, pivot) are unchanged. The chart is rebuilt when the theme changes.
- How it works schematics: ink and grid use `currentColor` / token classes instead of literals.
- `color-scheme` follows the theme so native controls and scrollbars match.

## Privacy And Legal

- `chartiko.theme` is a preference the user chooses, like `chartiko.locale`: no consent banner needed. The cookie table (es/en) lists it and the "how to delete" text says what happens if it is cleared. `config/legal.php` `updated_at` moves to the release date.

## Out Of Scope

- A third "system" option in the selector (clearing local storage restores it).
- Per-account (server-side) theme preference.
- Theming the favicon or social images.

## Expected Files

- `frontend/index.html` (inline theme script, fallback shadow classes)
- `frontend/src/index.css`, `DESIGN.md`
- `frontend/src/theme/*` (provider, context, hook, storage helpers), `frontend/src/components/ThemeSelector.tsx`, `AppHeader.tsx`, `main.tsx`
- Mechanical class change in the components that use hard shadows or `accent-[#1a1a1a]`
- `frontend/src/lib/chartData.ts`, `components/chart/InteractiveChart.tsx`, `components/TechnicalExample.tsx`
- `frontend/src/i18n/messages/{es,en}.ts`, `frontend/src/i18n/legal/{es,en}.ts`, `config/legal.php`
- Tests: theme provider and selector, no literal shadow colors in class names, legal table row

## Verification

- Vitest: default follows `prefers-color-scheme`; choosing sets `data-theme` and stores the choice; a stored choice wins over the OS; storage failures do not break the page; the selector exposes `aria-pressed`; no class name uses a literal `#1a1a1a` shadow or accent; the cookie table lists `chartiko.theme`.
- Browser QA at 1280 and 390 px in both themes: home, chart page, How it works, Portal, legal; no flash on reload with a stored dark choice.
- `.\init.ps1` and CI pass.

## Implementation Findings

- `src/theme/` (theme.ts, ThemeProvider, context with a light no-op default so isolated components render, useTheme). Provider sits above `I18nProvider` in `main.tsx`; `ThemeSelector` sits before `LanguageSelector` in `AppHeader`.
- `index.html` inline script mirrors `theme.ts` (stored choice, else `prefers-color-scheme`) and runs before the stylesheet paints; storage errors are caught.
- 131 hard-shadow classes and 3 checkbox accents converted by a mechanical replace (48 files, including the `index.html` fallback). Yellow focus shadows kept as literals (same in both themes).
- `--color-shadow` is a plain `:root` variable, not a `@theme` token, because it is only read through `var()` in arbitrary values.
- Dark shadow `#000000`: subtle on `#181715`, but the light outlines carry the brutalist edge; reviewed in screenshots.
- Chart: `CHART_THEME_COLORS` in `chartData.ts`; the chart effect depends on `theme`, so a switch rebuilds it. Dark candle outlines use the candle hue (light outlines looked like empty boxes).
- `TechnicalExample` ink is `currentColor` under `text-on-surface`; grid uses `stroke-outline-variant`. Its test selectors changed from the hex to `currentColor`.
- Legal: `chartiko.theme` row (es/en), deletion text mentions the theme, `config/legal.php` `updated_at` 2026-10-21.
- Tests: `ThemeProvider.test.tsx` (5: OS default and live change, choice stored and wins, stored choice beats OS, invalid value, blocked storage), `themeTokens.test.ts` (2: no `#1a1a1a` in class names across `src` and `index.html`; dark block redefines every light color token except the brand yellow pair; reads the CSS from disk because Vitest does not load `.css` contents), `LegalPage.test.tsx` lists `chartiko.theme`.
- Browser QA (Edge via Playwright, local preview with the API forwarded to production): home, chart, How it works, cookies and Portal (mocked user) at 1280 and 390 px in both themes, 0 px horizontal overflow; with a stored dark choice and the JS delayed 1.5 s the static fallback is already dark before React mounts; toggling switches and stores without reload; light theme unchanged.
- Validator round 1 (revise), fixed: confirmed pattern chips, Forecast badge/button and the Admin backtesting button pair `bg-primary-container` with `text-on-primary-container`; green badges use the new `on-gain` token; the Admin run log stays a black terminal via `.terminal-panel` (light token values pinned inside); ScreenerAlertToggle knob uses `on-primary-container` when checked; chart SMA/breakout lines and pattern markers use `#7aa2ff` in dark; schematic hues are `var(--color-*)` through `style` (presentation attributes do not resolve var()); `legal-compliance-eu.md` and `ARCHITECTURE.md` mention `chartiko.theme`. New test: confirmed chip classes. Re-checked in Edge (dark): chip `#1a1a1a` on `#ffcc00`, Forecast panel readable, light-blue chart lines.
