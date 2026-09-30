/**
 * Instrument chart surface: `/instruments/:ticker` (deep link) and `/chart`
 * (no ticker selected, guidance only).
 *
 * Anonymous by design — no `useAuth`, no redirect, no login prompt. The page
 * consumes the frozen `GET /api/instruments/{ticker}?limit=252` payload and
 * owns loading / ready / insufficient-history / empty / not-found / error
 * states; the chart itself is presentational.
 */

import { Suspense, lazy, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router'
import SignalLevelsPanel from '../components/chart/SignalLevelsPanel.tsx'
// `lightweight-charts` is a large dependency: load the canvas chunk only when
// the chart is actually rendered so the Screener bundle stays lean.
const InteractiveChart = lazy(() => import('../components/chart/InteractiveChart.tsx'))
import { ApiError, instrumentApi } from '../lib/api.ts'
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

function messageFor(error: unknown): string {
  if (error instanceof ApiError) {
    const firstValidation = Object.values(error.errors)[0]?.[0]
    return firstValidation ?? error.message
  }

  return 'No se pudo contactar con el servidor. Inténtalo de nuevo.'
}

function isAbortError(error: unknown): boolean {
  return error instanceof Error && error.name === 'AbortError'
}

function formatLevel(value: number | null): string {
  return value === null ? '—' : value.toFixed(2)
}

function formatVolume(value: number): string {
  if (value >= 1_000_000_000) {
    return `${(value / 1_000_000_000).toFixed(2)}B`
  }
  if (value >= 1_000_000) {
    return `${(value / 1_000_000).toFixed(2)}M`
  }
  if (value >= 1_000) {
    return `${(value / 1_000).toFixed(1)}K`
  }
  return String(value)
}

const ACTION_LINK_CLASS =
  'w-fit rounded-md border-2 border-outline bg-primary-container px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none'

function ScreenerLink() {
  return (
    <Link to="/screener" className={ACTION_LINK_CLASS}>
      Volver al Screener
    </Link>
  )
}

function RetryButton({ onRetry }: { onRetry: () => void }) {
  return (
    <button type="button" onClick={onRetry} className={ACTION_LINK_CLASS}>
      Reintentar
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
  return (
    <div className="rounded-md border-2 border-outline bg-surface-bright p-8 text-center shadow-[2px_2px_0px_#1a1a1a]">
      <p className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
        Selecciona un candidato
      </p>
      <p className="mt-2 text-sm text-on-surface-variant">
        Abre el gráfico de un instrumento desde la tabla del Screener para ver sus velas EOD,
        volumen, medias móviles y niveles de señal.
      </p>
      <div className="mt-4 flex justify-center">
        <ScreenerLink />
      </div>
    </div>
  )
}

function LoadingPanel() {
  return (
    <div
      role="status"
      aria-busy="true"
      className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]"
    >
      <p className="font-mono text-xs uppercase tracking-wider text-on-surface-variant">
        Cargando instrumento…
      </p>
      <div className="mt-4 h-[420px] rounded-[2px] bg-surface-container" aria-hidden="true" />
    </div>
  )
}

function NotFoundPanel({ message }: { message: string }) {
  return (
    <div className="rounded-md border-2 border-outline bg-surface-bright p-8 text-center shadow-[2px_2px_0px_#1a1a1a]">
      <p className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
        Instrumento no encontrado
      </p>
      <p className="mt-2 font-mono text-xs text-on-surface-variant">{message}</p>
      <div className="mt-4 flex justify-center">
        <ScreenerLink />
      </div>
    </div>
  )
}

function EmptyPanel({ ticker }: { ticker: string }) {
  return (
    <div className="rounded-md border-2 border-outline bg-surface-bright p-8 text-center shadow-[2px_2px_0px_#1a1a1a]">
      <p className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
        Sin datos EOD para {ticker}
      </p>
      <p className="mt-2 text-sm text-on-surface-variant">
        Este instrumento todavía no tiene barras diarias almacenadas.
      </p>
      <div className="mt-4 flex justify-center">
        <ScreenerLink />
      </div>
    </div>
  )
}

function ErrorPanel({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div
      role="alert"
      className="flex flex-col gap-3 rounded-md border-2 border-secondary bg-secondary-container p-5 shadow-[2px_2px_0px_#1a1a1a]"
    >
      <div>
        <p className="font-headline text-sm font-black uppercase tracking-wide text-on-secondary-container">
          No se pudo cargar el instrumento
        </p>
        <p className="mt-1 font-mono text-xs text-on-secondary-container">{message}</p>
      </div>
      <RetryButton onRetry={onRetry} />
    </div>
  )
}

export default function InstrumentChartPage() {
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
        setResult({ key, status: 'error', payload: null, error: messageFor(caught) })
      })

    return () => {
      controller.abort()
    }
  }, [normalizedTicker, retryToken])

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

  const lastBar = current !== null && current.bars.length > 0 ? current.bars[current.bars.length - 1] : null
  const averages = current === null ? [] : smaLevels(current.snapshot)
  const pivot = current === null ? null : (signalLevels(current.signals)[0] ?? null)
  const needsHistory = current !== null && insufficientHistory(current.bars, current.snapshot)

  const heading = current?.instrument.ticker ?? normalizedTicker ?? 'Chart'

  return (
    <section className="flex flex-col gap-6">
      <header className="flex flex-col gap-2">
        <div className="flex flex-wrap items-center gap-3">
          <span className="rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a]">
            Superficie
          </span>
          <span className="font-mono text-[11px] uppercase tracking-wider text-on-surface-variant">
            EOD · sin sesión
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
              <p className="mt-1 max-w-2xl text-sm text-on-surface-variant">
                Gráfico interactivo EOD de un instrumento.
              </p>
            )}
          </div>

          <div className="flex flex-wrap items-center gap-3">
            {current !== null ? (
              <span className="rounded-[4px] border border-outline bg-primary-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-primary-container shadow-[1px_1px_0px_#1a1a1a]">
                EOD
              </span>
            ) : null}
            {current?.meta.latest_bar_date ? (
              <span className="font-mono text-[11px] uppercase tracking-wider text-on-surface-variant">
                Última sesión · {current.meta.latest_bar_date}
              </span>
            ) : null}
            <ScreenerLink />
          </div>
        </div>
      </header>

      {normalizedTicker === null ? <GuidancePanel /> : null}

      {normalizedTicker !== null && status === 'not_found' ? (
        <NotFoundPanel message={error ?? 'Instrument not found.'} />
      ) : null}

      {normalizedTicker !== null && status === 'error' ? (
        <ErrorPanel message={error ?? 'Error desconocido.'} onRetry={retry} />
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
              <span className="font-bold text-on-surface">{lastBar.date}</span>
              <LegendValue label="O" value={formatLevel(lastBar.open)} />
              <LegendValue label="H" value={formatLevel(lastBar.high)} accent="gain" />
              <LegendValue label="L" value={formatLevel(lastBar.low)} accent="loss" />
              <LegendValue
                label="C"
                value={formatLevel(lastBar.close)}
                accent={lastBar.close >= lastBar.open ? 'gain' : 'loss'}
              />
              <LegendValue label="Vol" value={formatVolume(lastBar.volume)} />
              <LegendValue
                label="RSI"
                value={formatLevel(current.snapshot?.rsi14 ?? null)}
              />
              <LegendValue label="RVOL" value={formatLevel(current.snapshot?.rvol ?? null)} />
            </div>

            <div className="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 font-mono text-[11px] text-on-surface-variant">
              {averages.length === 0 ? (
                <span>Sin medias móviles disponibles</span>
              ) : (
                averages.map((level) => (
                  <span key={level.title}>
                    {level.title} {formatLevel(level.price)}
                  </span>
                ))
              )}
              {pivot !== null ? (
                <span className="font-bold text-on-surface">
                  PIVOTE {formatLevel(pivot.price)}
                </span>
              ) : null}
            </div>

            {needsHistory ? (
              <p className="mt-3 rounded-[4px] border border-outline-variant bg-surface-container px-2 py-1.5 text-xs text-on-surface-variant">
                Historial insuficiente: se muestran las velas disponibles, pero todavía no hay
                medias móviles (SMA 20/50/200) para la última sesión.
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
                      Cargando gráfico…
                    </span>
                  </div>
                }
              >
                <InteractiveChart
                  ticker={current.instrument.ticker}
                  bars={current.bars}
                  snapshot={current.snapshot}
                  signals={current.signals}
                />
              </Suspense>
            </div>
          </div>

          <SignalLevelsPanel signals={current.signals} />
        </>
      ) : null}
    </section>
  )
}
