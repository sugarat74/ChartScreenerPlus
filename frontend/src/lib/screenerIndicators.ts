/**
 * Remembered state of the optional "Indicators" columns toggle of the
 * Screener table (screener-technical-filters-ui). Per-browser convenience
 * only: every access is wrapped in try/catch so a blocked or throwing
 * `localStorage` (private window, disabled site data) just means "off".
 */

export const INDICATORS_STORAGE_KEY = 'chartiko.screener.indicators'

export function readIndicatorsToggle(): boolean {
  try {
    return window.localStorage.getItem(INDICATORS_STORAGE_KEY) === '1'
  } catch {
    return false
  }
}

export function writeIndicatorsToggle(on: boolean): void {
  try {
    if (on) {
      window.localStorage.setItem(INDICATORS_STORAGE_KEY, '1')
    } else {
      window.localStorage.removeItem(INDICATORS_STORAGE_KEY)
    }
  } catch {
    // Not remembered; the toggle still works for this view.
  }
}
