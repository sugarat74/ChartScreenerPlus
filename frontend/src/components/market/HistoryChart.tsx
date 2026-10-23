import { useI18n } from '../../i18n/useI18n.ts'
import type { MarketHistory } from '../../lib/api.ts'

const WIDTH = 640
const HEIGHT = 180
// Every label is drawn inside the viewBox: min/max at the left edge of the
// plot, reference values at its right edge, dates below it.
const PAD = { left: 12, right: 12, top: 14, bottom: 26 }

function dayNumber(isoDate: string): number {
  const [year, month, day] = isoDate.split('-').map(Number)
  return Date.UTC(year, month - 1, day) / 86_400_000
}

/**
 * Inline SVG line of one history metric (market-context-ui): no chart
 * library. The line uses the tertiary token through `currentColor`; a `null`
 * value breaks the path (a gap, never 0). Dashed reference lines carry their
 * value as a label; min/max sit on the left, first/last dates at the bottom
 * and the last value has a marker. The `aria-label` summarises the series.
 */
export default function HistoryChart({
  history,
  metricLabel,
  reference = [],
  formatValue,
}: {
  history: MarketHistory
  metricLabel: string
  reference?: number[]
  formatValue: (value: number) => string
}) {
  const { t, formatMarketDate } = useI18n()
  const points = history.points
  const values = points.map((point) => point.value).filter((value): value is number => value !== null)

  if (points.length === 0 || values.length === 0) {
    return null
  }

  const min = Math.min(...values)
  const max = Math.max(...values)
  const last = [...points].reverse().find((point) => point.value !== null)!
  const low = Math.min(min, ...reference)
  const high = Math.max(max, ...reference)
  const span = high - low || Math.abs(high) || 1
  const yMin = low - span * 0.05
  const yMax = high + span * 0.05

  const firstDay = dayNumber(points[0].date)
  const lastDay = dayNumber(points[points.length - 1].date)
  const daySpan = lastDay - firstDay || 1
  const plotWidth = WIDTH - PAD.left - PAD.right
  const plotHeight = HEIGHT - PAD.top - PAD.bottom
  const x = (isoDate: string) =>
    points.length === 1 ? PAD.left + plotWidth / 2 : PAD.left + ((dayNumber(isoDate) - firstDay) / daySpan) * plotWidth
  const y = (value: number) => PAD.top + (1 - (value - yMin) / (yMax - yMin)) * plotHeight

  let path = ''
  let pen = false
  for (const point of points) {
    if (point.value === null) {
      pen = false
      continue
    }
    path += `${pen ? 'L' : 'M'}${x(point.date).toFixed(1)},${y(point.value).toFixed(1)}`
    pen = true
  }

  const label = t('marketContext.history.chartLabel', {
    metric: metricLabel,
    from: formatMarketDate(points[0].date),
    to: formatMarketDate(points[points.length - 1].date),
    min: formatValue(min),
    max: formatValue(max),
    last: formatValue(last.value!),
  })

  return (
    <svg
      role="img"
      aria-label={label}
      viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
      className="h-auto w-full max-w-[40rem] text-tertiary"
    >
      <rect
        x={PAD.left}
        y={PAD.top}
        width={plotWidth}
        height={plotHeight}
        className="fill-surface text-outline-variant"
        stroke="currentColor"
        strokeWidth={1}
      />
      <g className="text-on-surface-variant" fontFamily="ui-monospace, monospace" fontSize={11}>
        {reference.map((value) => (
          <g key={value} data-reference={value}>
            <line
              x1={PAD.left}
              x2={WIDTH - PAD.right}
              y1={y(value)}
              y2={y(value)}
              stroke="currentColor"
              strokeDasharray="4 4"
              strokeWidth={1}
            />
            <text x={WIDTH - PAD.right - 4} y={y(value) - 3} textAnchor="end" fill="currentColor" data-label="reference">
              {formatValue(value)}
            </text>
          </g>
        ))}
        <text x={PAD.left + 4} y={y(max) + 12} textAnchor="start" fill="currentColor" className="stroke-surface" strokeWidth={3} paintOrder="stroke" data-label="max">
          {formatValue(max)}
        </text>
        {max !== min ? (
          <text x={PAD.left + 4} y={y(min) - 4} textAnchor="start" fill="currentColor" className="stroke-surface" strokeWidth={3} paintOrder="stroke" data-label="min">
            {formatValue(min)}
          </text>
        ) : null}
        <text x={PAD.left} y={HEIGHT - 8} textAnchor="start" fill="currentColor">
          {formatMarketDate(points[0].date)}
        </text>
        <text x={WIDTH - PAD.right} y={HEIGHT - 8} textAnchor="end" fill="currentColor">
          {formatMarketDate(points[points.length - 1].date)}
        </text>
      </g>
      <path d={path} fill="none" stroke="currentColor" strokeWidth={2} strokeLinejoin="round" data-testid="history-line" />
      <circle cx={x(last.date)} cy={y(last.value!)} r={4} fill="currentColor" className="stroke-surface-bright" strokeWidth={2} />
    </svg>
  )
}
