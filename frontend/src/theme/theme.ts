/**
 * Color theme of the SPA (docs/specs/ui-dark-mode.md).
 *
 * The theme is the `data-theme` attribute on `<html>`; `index.css` redefines
 * the same color tokens under `:root[data-theme='dark']`. A choice is stored
 * only when the user picks one; until then the operating system decides. The
 * inline script in `index.html` applies the same rule before the first paint,
 * so keep both in sync.
 */

export type Theme = 'light' | 'dark'

export const THEMES: readonly Theme[] = ['light', 'dark']

export const THEME_STORAGE_KEY = 'chartiko.theme'

const DARK_QUERY = '(prefers-color-scheme: dark)'

function isTheme(value: unknown): value is Theme {
  return value === 'light' || value === 'dark'
}

/** The stored choice, or null when there is none or storage is unavailable. */
export function readStoredTheme(): Theme | null {
  try {
    const value = window.localStorage.getItem(THEME_STORAGE_KEY)
    return isTheme(value) ? value : null
  } catch {
    return null
  }
}

/** Store the user's choice; a blocked storage only loses the persistence. */
export function writeStoredTheme(theme: Theme): void {
  try {
    window.localStorage.setItem(THEME_STORAGE_KEY, theme)
  } catch {
    // Private mode or blocked storage: the theme still applies to this visit.
  }
}

/** `prefers-color-scheme` query, or null where `matchMedia` is unavailable. */
export function darkSchemeQuery(): MediaQueryList | null {
  return typeof window.matchMedia === 'function' ? window.matchMedia(DARK_QUERY) : null
}

export function systemTheme(): Theme {
  return darkSchemeQuery()?.matches === true ? 'dark' : 'light'
}

export function applyTheme(theme: Theme): void {
  document.documentElement.dataset.theme = theme
}
