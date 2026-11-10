/**
 * Which indicators the chart draws (chart-indicator-overlays). The choice is a
 * per-browser convenience kept in `localStorage`; every access is guarded so a
 * blocked or throwing storage only loses the persistence. Nothing is on by
 * default, so an untouched chart (and its requests) stays as it was.
 */

export const CHART_INDICATORS_STORAGE_KEY = 'chartiko.chart.indicators'

/** Closed list of price overlays and sub-pane toggles. */
export const CHART_INDICATOR_IDS = ['bollinger', 'supertrend', 'rsi', 'macd', 'obv', 'adx', 'atr', 'stochastic'] as const

export type ChartIndicatorId = (typeof CHART_INDICATOR_IDS)[number]

/** The toggles that get their own pane under the volume pane, in pane order. */
export const CHART_PANE_IDS = ['rsi', 'macd', 'obv', 'adx', 'atr', 'stochastic'] as const

export type ChartPaneId = (typeof CHART_PANE_IDS)[number]

/** Active panes of a selection, in a fixed order independent of toggle order. */
export function activePanes(selection: readonly ChartIndicatorId[]): ChartPaneId[] {
  return CHART_PANE_IDS.filter((id) => selection.includes(id))
}

/** Price + volume height, and what each extra pane adds, in CSS pixels. */
export const CHART_BASE_HEIGHT = 420
export const CHART_PANE_HEIGHT = 120

export function chartHeight(paneCount: number): number {
  return CHART_BASE_HEIGHT + CHART_PANE_HEIGHT * Math.max(0, paneCount)
}

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
