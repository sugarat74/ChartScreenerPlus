/**
 * Which indicators the chart draws (chart-indicator-overlays). The choice is a
 * per-browser convenience kept in `localStorage`; every access is guarded so a
 * blocked or throwing storage only loses the persistence. Nothing is on by
 * default, so an untouched chart (and its requests) stays as it was.
 */

export const CHART_INDICATORS_STORAGE_KEY = 'chartiko.chart.indicators'

/** Closed list of indicator toggles. Feature 7 appends the sub-pane ones. */
export const CHART_INDICATOR_IDS = ['bollinger'] as const

export type ChartIndicatorId = (typeof CHART_INDICATOR_IDS)[number]

function isIndicatorId(value: unknown): value is ChartIndicatorId {
  return typeof value === 'string' && (CHART_INDICATOR_IDS as readonly string[]).includes(value)
}

/** Stored selection; missing, malformed or unreadable storage means "none". */
export function readStoredIndicators(): ChartIndicatorId[] {
  try {
    const raw = window.localStorage.getItem(CHART_INDICATORS_STORAGE_KEY)
    if (raw === null) {
      return []
    }
    const parsed: unknown = JSON.parse(raw)
    if (!Array.isArray(parsed)) {
      return []
    }
    return CHART_INDICATOR_IDS.filter((id) => parsed.some((entry) => entry === id && isIndicatorId(entry)))
  } catch {
    return []
  }
}

export function writeStoredIndicators(selection: readonly ChartIndicatorId[]): void {
  try {
    window.localStorage.setItem(CHART_INDICATORS_STORAGE_KEY, JSON.stringify(selection))
  } catch {
    // Private mode or blocked storage: the choice still applies to this visit.
  }
}

/** Selection with one indicator switched on or off, in the closed-list order. */
export function toggleIndicator(
  selection: readonly ChartIndicatorId[],
  id: ChartIndicatorId,
): ChartIndicatorId[] {
  return CHART_INDICATOR_IDS.filter((entry) => (entry === id ? !selection.includes(id) : selection.includes(entry)))
}
