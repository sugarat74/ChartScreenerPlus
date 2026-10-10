/**
 * Instrument chart surface: `/instruments/:ticker` (deep link) and `/chart`
 * (no ticker selected, guidance only).
 *
 * The page itself stays anonymous — it never calls `useAuth`, never redirects
 * and never prompts for login. The only auth-aware element is the watchlist
 * toggle in the header, which reads the session to offer "Seguir"/"Siguiendo"
 * or, for a Visitor, a plain sign-in link (never a redirect), so the chart
 * remains browseable. The page consumes the frozen
 * `GET /api/instruments/{ticker}?limit=252` payload and owns loading / ready /
 * insufficient-history / empty / not-found / error states; the chart itself is
 * presentational.
 */

import { Suspense, lazy, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router'
import PatternPanel from '../components/chart/PatternPanel.tsx'
import SignalLevelsPanel from '../components/chart/SignalLevelsPanel.tsx'
// `lightweight-charts` is a large dependency: load the canvas chunk only when
// the chart is actually rendered so the Screener bundle stays lean.
const InteractiveChart = lazy(() => import('../components/chart/InteractiveChart.tsx'))
import WatchlistButton from '../components/watchlist/WatchlistButton.tsx'
import { useI18n, useTranslateRef } from '../i18n/useI18n.ts'
import { ApiError, apiErrorMessage, instrumentApi } from '../lib/api.ts'
import type { InstrumentDetailResponse } from '../lib/api.ts'
import { CHART_BAR_LIMIT, insufficientHistory, signalLevels, smaLevels } from '../lib/chartData.ts'

type ChartStatus = 'loading' | 'ready' | 'not_found' | 'error'

/** One settled request, keyed so a stale ticker/retry never renders. */
interface LoadedResult {
  key: string
  status: Exclude<ChartStatus, 'loading'>
  payload: InstrumentDetailResponse | null
  error: string | null
}

function isAbortError(error: unknown): boolean {
  return error instanceof Error && error.name === 'AbortError'
}

const ACTION_LINK_CLASS =
  'w-fit rounded-md border-2 border-outline bg-primary-container px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none'

function ScreenerLink() {
  const { t } = useI18n()
  return (
    <Link to="/screener" className={ACTION_LINK_CLASS}>
      {t('chart.backToScreener')}
    </Link>
  )
}

function RetryButton({ onRetry }: { onRetry: () => void }) {
  const { t } = useI18n()
  return (
    <button type="button" onClick={onRetry} className={ACTION_LINK_CLASS}>
      {t('common.retry')}
    </button>
  )
}

function LegendValue({
  label,
  value,
  accent = 'none',
}: {
  label: string
  value: string
  accent?: 'gain' | 'loss' | 'none'
}) {
  const accentClass =
    accent === 'gain' ? 'text-gain' : accent === 'loss' ? 'text-secondary' : 'text-on-surface'

  return (
    <span className="inline-flex items-baseline gap-1">
      <span className="text-[10px] uppercase tracking-wider text-on-surface-variant">{label}</span>
      <span className={`font-bold ${accentClass}`}>{value}</span>
    </span>
  )
}

function GuidancePanel() {
  const { t } = useI18n()
  return (
    <div className="rounded-md border-2 border-outline bg-surface-bright p-8 text-center shadow-[2px_2px_0px_#1a1a1a]">
      <p className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
        {t('chart.guidanceTitle')}
      </p>
      <p className="mt-2 text-sm text-on-surface-variant">{t('chart.guidanceBody')}</p>
      <div className="mt-4 flex justify-center">
        <ScreenerLink />
      </div>
    </div>
  )
}

function LoadingPanel() {
  const { t } = useI18n()
  return (
    <div
      role="status"
      aria-busy="true"
      className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]"
    >
      <p className="font-mono text-xs uppercase tracking-wider text-on-surface-variant">
        {t('chart.loadingInstrument')}
      </p>
      <div className="mt-4 h-[420px] rounded-[2px] bg-surface-container" aria-hidden="true" />
    </div>
  )
}

function NotFoundPanel({ message }: { message: string }) {
  const { t } = useI18n()
  return (
    <div className="rounded-md border-2 border-outline bg-surface-bright p-8 text-center shadow-[2px_2px_0px_#1a1a1a]">
      <p className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
        {t('chart.notFoundTitle')}
      </p>
      <p className="mt-2 font-mono text-xs text-on-surface-variant">{message}</p>
      <div className="mt-4 flex justify-center">
        <ScreenerLink />
      </div>
    </div>
  )
}

function EmptyPanel({ ticker }: { ticker: string }) {
  const { t } = useI18n()
  return (
    <div className="rounded-md border-2 border-outline bg-surface-bright p-8 text-center shadow-[2px_2px_0px_#1a1a1a]">
      <p className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
        {t('chart.emptyTitle', { ticker })}
      </p>
      <p className="mt-2 text-sm text-on-surface-variant">{t('chart.emptyBody')}</p>
      <div className="mt-4 flex justify-center">
        <ScreenerLink />
      </div>
    </div>
  )
}

function ErrorPanel({ message, onRetry }: { message: string; onRetry: () => void }) {
  const { t } = useI18n()
  return (
    <div
      role="alert"
      className="flex flex-col gap-3 rounded-md border-2 border-secondary bg-secondary-container p-5 shadow-[2px_2px_0px_#1a1a1a]"
    >
      <div>
        <p className="font-headline text-sm font-black uppercase tracking-wide text-on-secondary-container">
          {t('chart.errorTitle')}
        </p>
        <p className="mt-1 font-mono text-xs text-on-secondary-container">{message}</p>
      </div>
      <RetryButton onRetry={onRetry} />
    </div>
  )
}

export default function InstrumentChartPage() {
  const { t, formatPrice, formatCompact, formatMarketDate } = useI18n()
  const tRef = useTranslateRef()
  const params = useParams<{ ticker: string }>()
  const requestedTicker =
    params.ticker !== undefined && params.ticker !== '' ? params.ticker : null
  const normalizedTicker = requestedTicker === null ? null : requestedTicker.trim().toUpperCase()

  const [result, setResult] = useState<LoadedResult | null>(null)
  const [retryToken, setRetryToken] = useState(0)

  useEffect(() => {
    // `/chart` has no ticker: guidance only, never a request.
    if (normalizedTicker === null) {
      return
    }

    const key = `${normalizedTicker}|${retryToken}`
    const controller = new AbortController()

    instrumentApi
      .detail(normalizedTicker, { limit: CHART_BAR_LIMIT, signal: controller.signal })
      .then((payload) => {
        setResult({ key, status: 'ready', payload, error: null })
      })
      .catch((caught: unknown) => {
        if (isAbortError(caught)) {
          return
        }
        if (caught instanceof ApiError && caught.status === 404) {
          setResult({ key, status: 'not_found', payload: null, error: caught.message })
          return
        }
        setResult({
          key,
          status: 'error',
          payload: null,
          error: apiErrorMessage(caught, tRef.current('common.networkError')),
        })
      })

    return () => {
      controller.abort()
    }
  }, [normalizedTicker, retryToken, tRef])

  function retry() {
    setRetryToken((token) => token + 1)
  }

  // A settled result only renders while it matches the current request key, so
  // a ticker change or retry shows loading and never the previous instrument.
  const requestKey = normalizedTicker === null ? null : `${normalizedTicker}|${retryToken}`
  const active = result !== null && requestKey !== null && result.key === requestKey ? result : null
  const status: ChartStatus = active === null ? 'loading' : active.status
  const current = active !== null && active.status === 'ready' ? active.payload : null
  const error = active?.error ?? null

  const pivotLabel = t('chart.pivot')
  const lastBar = current !== null && current.bars.length > 0 ? current.bars[current.bars.length - 1] : null
  const averages = current === null ? [] : smaLevels(current.snapshot)
  const pivot = current === null ? null : (signalLevels(current.signals, pivotLabel)[0] ?? null)
  const needsHistory = current !== null && insufficientHistory(current.bars, current.snapshot)

  const heading = current?.instrument.ticker ?? normalizedTicker ?? t('nav.chart')

  return (
    <section className="flex flex-col gap-6">
      <header className="flex flex-col gap-2">
        <div className="flex flex-wrap items-center gap-3">
          <span className="rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a]">
            {t('chart.badge')}
          </span>
          <span className="font-mono text-[11px] uppercase tracking-wider text-on-surface-variant">
            {t('chart.access')}
          </span>
        </div>

        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h1 className="font-headline text-3xl font-black tracking-tight uppercase text-on-surface">
              {heading}
            </h1>
            {current !== null ? (
              <p className="mt-1 text-sm text-on-surface-variant">
                {current.instrument.company} · {current.instrument.sector} ·{' '}
                {current.instrument.exchange}
              </p>
            ) : (
              <p className="mt-1 max-w-2xl text-sm text-on-surface-variant">{t('chart.intro')}</p>
            )}
          </div>

          <div className="flex flex-wrap items-center gap-3">
            {current !== null ? (
              <span className="rounded-[4px] border border-outline bg-primary-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-primary-container shadow-[1px_1px_0px_#1a1a1a]">
                {t('chart.analysisBadge')}
              </span>
            ) : null}
            {current?.meta.latest_bar_date ? (
              <span className="font-mono text-[11px] uppercase tracking-wider text-on-surface-variant">
                {t('chart.lastSession', { date: formatMarketDate(current.meta.latest_bar_date) })}
              </span>
            ) : null}
            {current !== null ? <WatchlistButton ticker={current.instrument.ticker} /> : null}
            <ScreenerLink />
          </div>
        </div>
      </header>

      {normalizedTicker === null ? <GuidancePanel /> : null}

      {normalizedTicker !== null && status === 'not_found' ? (
        <NotFoundPanel message={error ?? t('chart.notFoundFallback')} />
      ) : null}

      {normalizedTicker !== null && status === 'error' ? (
        <ErrorPanel message={error ?? t('common.unknownError')} onRetry={retry} />
      ) : null}

      {normalizedTicker !== null && status !== 'not_found' && status !== 'error' && current === null ? (
        <LoadingPanel />
      ) : null}

      {current !== null && current.bars.length === 0 ? (
        <EmptyPanel ticker={current.instrument.ticker} />
      ) : null}

      {current !== null && current.bars.length > 0 && lastBar !== null ? (
        <>
          <div className="rounded-md border-2 border-outline bg-surface-bright p-3 shadow-[2px_2px_0px_#1a1a1a]">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 font-mono text-xs">
              <span className="font-bold text-on-surface">{formatMarketDate(lastBar.date)}</span>
              <LegendValue label={t('chart.legendOpen')} value={formatPrice(lastBar.open)} />
              <LegendValue label={t('chart.legendHigh')} value={formatPrice(lastBar.high)} accent="gain" />
              <LegendValue label={t('chart.legendLow')} value={formatPrice(lastBar.low)} accent="loss" />
              <LegendValue
                label={t('chart.legendClose')}
                value={formatPrice(lastBar.close)}
                accent={lastBar.close >= lastBar.open ? 'gain' : 'loss'}
              />
              <LegendValue label={t('chart.legendVolume')} value={formatCompact(lastBar.volume)} />
              <LegendValue label="RSI" value={formatPrice(current.snapshot?.rsi14 ?? null)} />
              <LegendValue label="RVOL" value={formatPrice(current.snapshot?.rvol ?? null)} />
            </div>

            <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 font-mono text-[11px] text-on-surface-variant">
              {averages.length === 0 ? (
                <span>{t('chart.noAverages')}</span>
              ) : (
                averages.map((level) => (
                  <span key={level.title}>
                    {level.title} {formatPrice(level.price)}
                  </span>
                ))
              )}
              {pivot !== null ? (
                <span className="font-bold text-on-surface">
                  {pivotLabel} {formatPrice(pivot.price)}
                </span>
              ) : null}
            </div>

            {needsHistory ? (
              <p className="mt-3 rounded-[4px] border border-outline-variant bg-surface-container px-2 py-1.5 text-xs text-on-surface-variant">
                {t('chart.insufficientHistory')}
              </p>
            ) : null}

            <div className="mt-3">
              <Suspense
                fallback={
                  <div
                    role="status"
                    aria-busy="true"
                    className="flex h-[420px] items-center justify-center bg-surface-bright"
                  >
                    <span className="font-mono text-xs uppercase tracking-wider text-on-surface-variant">
                      {t('chart.loadingChart')}
                    </span>
                  </div>
                }
              >
                <InteractiveChart
                  ticker={current.instrument.ticker}
                  bars={current.bars}
                  snapshot={current.snapshot}
                  signals={current.signals}
                  patterns={current.patterns ?? []}
                />
              </Suspense>
            </div>
          </div>

          <SignalLevelsPanel signals={current.signals} />
          <PatternPanel patterns={current.patterns ?? []} />
        </>
      ) : null}
    </section>
  )
}
