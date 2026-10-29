import { useEffect, useState } from 'react'
import { instrumentApi } from '../../lib/api.ts'
import type { InstrumentIndicatorPoint } from '../../lib/api.ts'
import { CHART_BAR_LIMIT } from '../../lib/chartData.ts'

export type InstrumentIndicatorsState =
  /** No indicator is switched on: nothing is requested. */
  | { status: 'off' }
  | { status: 'loading' }
  | { status: 'ready'; series: InstrumentIndicatorPoint[] }
  | { status: 'error' }

type Settled = { key: string; state: Extract<InstrumentIndicatorsState, { status: 'ready' | 'error' }> }

function isAbortError(error: unknown): boolean {
  return error instanceof Error && error.name === 'AbortError'
}

/**
 * Stored indicator history for the chart: one
 * `GET /api/instruments/{ticker}/indicators` request, made only while
 * `enabled` (at least one indicator is on). A failure never affects the
 * candles; the caller shows an inline error with `retry`.
 */
export function useInstrumentIndicators(
  ticker: string | null,
  enabled: boolean,
): { state: InstrumentIndicatorsState; retry: () => void } {
  const [retryToken, setRetryToken] = useState(0)
  const [settled, setSettled] = useState<Settled | null>(null)
  const key = enabled && ticker !== null ? `${ticker}|${retryToken}` : null

  useEffect(() => {
    if (key === null || ticker === null) {
      return
    }
    const controller = new AbortController()

    instrumentApi.indicators(ticker, { limit: CHART_BAR_LIMIT, signal: controller.signal }).then(
      (payload) => setSettled({ key, state: { status: 'ready', series: payload.series } }),
      (error: unknown) => {
        if (isAbortError(error)) {
          return
        }
        setSettled({ key, state: { status: 'error' } })
      },
    )

    return () => controller.abort()
  }, [key, ticker])

  let state: InstrumentIndicatorsState
  if (key === null) {
    state = { status: 'off' }
  } else if (settled === null || settled.key !== key) {
    state = { status: 'loading' }
  } else {
    state = settled.state
  }

  return { state, retry: () => setRetryToken((token) => token + 1) }
}
