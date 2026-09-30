/**
 * Pure, React-free chart data helpers for the instrument detail payload.
 *
 * The detail API returns only the **latest** Indicator Snapshot (single SMA
 * numbers), so the moving-average overlays are horizontal reference price
 * lines at those latest values — never a computed per-bar series. The engine
 * owns indicator math (`CONSTRAINTS.md` -> Indicators); the SPA must not
 * recompute SMA/EMA client-side, so no math lives here.
 *
 * Nothing in this module imports React or the charting library: it maps the
 * frozen API payload to plain points/levels and is deterministic enough to be
 * exercised by a throwaway node check.
 */

/** One trading year of sessions; the API default and well inside its clamp. */
export const CHART_BAR_LIMIT = 252

/**
 * Literal colors for the canvas. Every entry is exactly one
 * `frontend/src/index.css` `@theme` token hex — no new hues.
 */
export const CHART_COLORS = {
  gain: '#059669', // --color-gain
  loss: '#e63b2e', // --color-secondary
  accent: '#ffcc00', // --color-primary-container
  ink: '#1a1a1a', // --color-on-surface / --color-outline
  surfaceBright: '#faf7f2', // --color-surface-bright
  grid: '#d0cbc3', // --color-outline-variant
  muted: '#4a4a4a', // --color-on-surface-variant
  tertiary: '#0055ff', // --color-tertiary
} as const

/** Structural input types (the API payload is assignable to these). */
export interface ChartBar {
  date: string
  open: number
  high: number
  low: number
  close: number
  volume: number
}

export interface ChartSnapshot {
  sma20: number | null
  sma50: number | null
  sma200: number | null
}

export interface ChartSignal {
  type: string
  metadata: Record<string, number> | null
}

/** A single candlestick; `time` is a `YYYY-MM-DD` business-day string. */
export interface CandlestickPoint {
  time: string
  open: number
  high: number
  low: number
  close: number
}

/** A single volume bar, colored by candle direction. */
export interface VolumePoint {
  time: string
  value: number
  color: string
}

/** A horizontal reference price line (latest SMA value or the pivot). */
export interface ChartLevel {
  price: number
  title: string
  color: string
}

/** Candlesticks from the ascending bars; the SPA never re-orders them. */
export function toCandlestickData(bars: readonly ChartBar[]): CandlestickPoint[] {
  return bars.map((bar) => ({
    time: bar.date,
    open: bar.open,
    high: bar.high,
    low: bar.low,
    close: bar.close,
  }))
}

/** One histogram bar per Daily Bar, colored by candle direction. */
export function toVolumeData(bars: readonly ChartBar[]): VolumePoint[] {
  return bars.map((bar) => ({
    time: bar.date,
    value: bar.volume,
    color: bar.close >= bar.open ? CHART_COLORS.gain : CHART_COLORS.loss,
  }))
}

/**
 * Reference lines for the **latest** snapshot only. A `null` SMA produces no
 * line; a `null` snapshot produces no lines (`[]`).
 */
export function smaLevels(snapshot: ChartSnapshot | null): ChartLevel[] {
  if (snapshot === null) {
    return []
  }

  const levels: ChartLevel[] = []
  if (snapshot.sma20 !== null) {
    levels.push({ price: snapshot.sma20, title: 'SMA 20', color: CHART_COLORS.tertiary })
  }
  if (snapshot.sma50 !== null) {
    levels.push({ price: snapshot.sma50, title: 'SMA 50', color: CHART_COLORS.tertiary })
  }
  if (snapshot.sma200 !== null) {
    levels.push({ price: snapshot.sma200, title: 'SMA 200', color: CHART_COLORS.tertiary })
  }
  return levels
}

/**
 * Exactly one reference line for the active `pivot_breakout_rvol` signal
 * (its engine-computed `metadata.pivot`). Stop and target are **not** modeled
 * anywhere, so they are never drawn or derived here. Any other signal type,
 * a missing signal and a non-numeric pivot all yield no line.
 */
export function signalLevels(signals: readonly ChartSignal[]): ChartLevel[] {
  const levels: ChartLevel[] = []

  for (const signal of signals) {
    if (signal.type !== 'pivot_breakout_rvol') {
      continue
    }

    const pivot = signal.metadata?.pivot
    if (typeof pivot === 'number' && Number.isFinite(pivot)) {
      levels.push({ price: pivot, title: 'PIVOTE', color: CHART_COLORS.accent })
    }
  }

  return levels
}

/** `true` when there is at least one bar to draw. */
export function hasChartData(bars: readonly ChartBar[]): boolean {
  return bars.length > 0
}

/**
 * Drives the soft "historial insuficiente" note: the snapshot is absent or
 * none of the three SMAs is available, so the chart still renders but the
 * reference lines are simply missing. The `bars` argument is part of the
 * documented signature (the caller only asks once bars exist).
 */
export function insufficientHistory(
  _bars: readonly ChartBar[],
  snapshot: ChartSnapshot | null,
): boolean {
  if (snapshot === null) {
    return true
  }
  return snapshot.sma20 === null && snapshot.sma50 === null && snapshot.sma200 === null
}
