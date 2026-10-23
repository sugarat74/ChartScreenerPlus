import { useEffect, useId, useRef, useState } from 'react'
import { Link } from 'react-router'
import { useAuth } from '../../auth/useAuth.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import { ApiError, marketContextApi } from '../../lib/api.ts'
import type { MarketHistory, MarketHistoryMetric, MarketHistoryRange } from '../../lib/api.ts'
import { LOGIN_ROUTE } from '../../nav.ts'
import HistoryChart from './HistoryChart.tsx'

export type HistoryOption = {
  metric: MarketHistoryMetric
  /** Shown in the series selector and the chart summary. */
  label: string
  /** Name used in the chart summary; defaults to `label`. */
  chartLabel?: string
  /** Dashed reference lines (e.g. ±3 for the regime score). */
  reference?: number[]
  formatValue: (value: number) => string
}

type Entry = { status: 'loading' } | { status: 'error' } | { status: 'ready'; data: MarketHistory }

const RANGES: readonly MarketHistoryRange[] = ['1y', '3y']

const TOGGLE_CLASS =
  'rounded-[4px] border-2 border-outline px-2 py-0.5 font-mono text-[11px] font-bold uppercase focus:outline-2 focus:outline-offset-2 focus:outline-outline'

/** `first_date` later than `from` + 7 days: the history starts inside the window. */
function startsLate(data: MarketHistory): boolean {
  if (data.first_date === null || data.from === null) {
    return false
  }
  const from = new Date(`${data.from}T00:00:00Z`)
  from.setUTCDate(from.getUTCDate() + 7)
  return data.first_date > from.toISOString().slice(0, 10)
}

/**
 * History slot of a Market Context card (market-context-ui). Registered Users
 * only, enforced by the API (401 for a guest): Visitors get a dashed sign-in
 * box and no request is made; nothing renders while the session loads. The
 * series is fetched on first open, then per metric and range.
 */
export default function HistoryPanel({
  options,
  selectLabel,
}: {
  options: HistoryOption[]
  selectLabel?: string
}) {
  const { user, status } = useAuth()
  const { t, formatMarketDate } = useI18n()
  const panelId = useId()
  const selectId = useId()
  const [open, setOpen] = useState(false)
  const [metric, setMetric] = useState<MarketHistoryMetric | null>(null)
  const [range, setRange] = useState<MarketHistoryRange>('1y')
  const [entries, setEntries] = useState<Record<string, Entry>>({})
  const [signedOut, setSignedOut] = useState(false)

  const [reload, setReload] = useState(0)
  const requested = useRef(new Set<string>())
  const mounted = useRef(true)

  const option = options.find((item) => item.metric === metric) ?? options[0]
  const key = option ? `${option.metric}|${range}` : ''
  const entry = entries[key]
  const signedIn = user !== null

  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])

  useEffect(() => {
    if (!open || !signedIn || signedOut || key === '' || requested.current.has(key)) {
      return
    }
    requested.current.add(key)
    const [requestMetric, requestRange] = key.split('|') as [MarketHistoryMetric, MarketHistoryRange]
    setEntries((current) => ({ ...current, [key]: { status: 'loading' } }))
    marketContextApi.history(requestMetric, requestRange).then(
      (data) => {
        if (mounted.current) setEntries((current) => ({ ...current, [key]: { status: 'ready', data } }))
      },
      (error: unknown) => {
        if (!mounted.current) return
        if (error instanceof ApiError && error.status === 401) {
          setSignedOut(true)
        }
        setEntries((current) => ({ ...current, [key]: { status: 'error' } }))
      },
    )
  }, [open, signedIn, signedOut, key, reload])

  if (status === 'loading' || option === undefined) {
    return null
  }

  if (user === null || signedOut) {
    return (
      <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border-2 border-dashed border-outline-variant bg-surface-container px-3 py-2">
        <div className="min-w-0">
          <p className="font-headline text-[11px] font-bold uppercase tracking-wide text-on-surface">
            {t('marketContext.history.signInTitle')}
          </p>
          <p className="text-xs text-on-surface-variant">{t('marketContext.history.signInBody')}</p>
        </div>
        <Link
          to={LOGIN_ROUTE}
          className="font-mono text-xs font-bold text-on-surface underline underline-offset-4 focus:outline-2 focus:outline-offset-2 focus:outline-outline"
        >
          {t('marketContext.history.signIn')}
        </Link>
      </div>
    )
  }

  function retry() {
    requested.current.delete(key)
    setReload((value) => value + 1)
  }

  let body = null
  if (entry === undefined || entry.status === 'loading') {
    body = <p className="font-mono text-xs text-on-surface-variant">{t('marketContext.history.loading')}</p>
  } else if (entry.status === 'error') {
    body = (
      <div role="alert" className="flex flex-wrap items-center gap-2 font-mono text-xs text-secondary">
        {t('marketContext.history.error')}
        <button type="button" onClick={retry} className={`${TOGGLE_CLASS} bg-surface-bright text-on-surface`}>
          {t('marketContext.retry')}
        </button>
      </div>
    )
  } else if (entry.data.disabled) {
    body = <p className="font-mono text-xs text-on-surface-variant">{t('marketContext.sourceDisabled')}</p>
  } else if (entry.data.points.every((point) => point.value === null)) {
    // No points, or only gaps (e.g. every regime row `insufficient`).
    body = <p className="font-mono text-xs text-on-surface-variant">{t('marketContext.history.empty')}</p>
  } else {
    body = (
      <>
        <HistoryChart
          history={entry.data}
          metricLabel={option.chartLabel ?? option.label}
          reference={option.reference}
          formatValue={option.formatValue}
        />
        {startsLate(entry.data) ? (
          <p className="font-mono text-[11px] text-on-surface-variant">
            {t('marketContext.history.availableFrom', { date: formatMarketDate(entry.data.first_date) })}
          </p>
        ) : null}
      </>
    )
  }

  return (
    <div className="flex flex-col gap-2 border-t border-outline-variant pt-2">
      <button
        type="button"
        aria-expanded={open}
        aria-controls={panelId}
        onClick={() => setOpen((value) => !value)}
        className="w-fit rounded-md border-2 border-outline bg-surface-bright px-2.5 py-1 font-headline text-[11px] font-bold uppercase tracking-wide text-on-surface shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
      >
        {open ? t('marketContext.history.hide') : t('marketContext.history.show')}
      </button>
      <div id={panelId} hidden={!open} className="flex flex-col gap-2">
        {open ? (
          <>
            <div className="flex flex-wrap items-center gap-3">
              {options.length > 1 ? (
                <label htmlFor={selectId} className="flex items-center gap-2 font-mono text-[11px] text-on-surface-variant">
                  {selectLabel ?? t('marketContext.history.metricAria')}
                  <select
                    id={selectId}
                    value={option.metric}
                    onChange={(event) => setMetric(event.target.value as MarketHistoryMetric)}
                    className="rounded-[4px] border-2 border-outline bg-surface-bright px-1.5 py-0.5 font-mono text-[11px] text-on-surface focus:outline-2 focus:outline-offset-2 focus:outline-outline"
                  >
                    {options.map((item) => (
                      <option key={item.metric} value={item.metric}>
                        {item.label}
                      </option>
                    ))}
                  </select>
                </label>
              ) : null}
              <div role="group" aria-label={t('marketContext.history.rangeAria')} className="flex gap-1">
                {RANGES.map((value) => (
                  <button
                    key={value}
                    type="button"
                    aria-pressed={range === value}
                    onClick={() => setRange(value)}
                    className={`${TOGGLE_CLASS} ${range === value ? 'bg-primary-container text-on-primary-container' : 'bg-surface-bright text-on-surface'}`}
                  >
                    {t(`marketContext.history.range.${value}`)}
                  </button>
                ))}
              </div>
            </div>
            {body}
          </>
        ) : null}
      </div>
    </div>
  )
}
