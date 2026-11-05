/**
 * Pure summaries for the compact Screener criteria toolbar
 * (screener-filter-panel-compact). They only describe the current filters;
 * parsing, URL keys and counting stay in screenerFilters.ts.
 */

import { MIN_RVOL_PRESETS } from './screenerFilters.ts'

/** RSI range text for the trigger: "30-70", ">= 30", "<= 70" or null when no bound. */
export function rsiSummary(min: number | null, max: number | null): string | null {
  if (min !== null && max !== null) {
    return `${min}-${max}`
  }
  if (min !== null) {
    return `≥ ${min}`
  }
  if (max !== null) {
    return `≤ ${max}`
  }
  return null
}

/** Trigger value text: "label", or "label · value" when a value is active. */
export function triggerText(label: string, value: string | number | null): string {
  return value === null || value === '' ? label : `${label} · ${value}`
}

/**
 * Min RVOL options for the native select: the presets, plus the current value
 * when it comes from a URL or a column funnel and is not one of them, so the
 * select never shows a value that is not the active filter.
 */
export function rvolSelectValues(current: number | null): number[] {
  const values = [...MIN_RVOL_PRESETS]
  if (current !== null && !values.includes(current)) {
    values.push(current)
    values.sort((a, b) => a - b)
  }
  return values
}
