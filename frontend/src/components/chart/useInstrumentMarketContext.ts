import { useEffect, useState } from 'react'
import { useMarketContextAccess } from '../../auth/useMarketContextAccess.ts'
import type { MarketContextAccess } from '../../auth/useMarketContextAccess.ts'
import { ApiError, instrumentMarketContextApi } from '../../lib/api.ts'
import type { InstrumentMarketContext } from '../../lib/api.ts'

export type InstrumentMarketContextState =
  /** Nothing to fetch: not a stock, or the caller has no access. */
  | { status: 'off' }
  | { status: 'loading' }
  | { status: 'ready'; data: InstrumentMarketContext }
  | { status: 'error' }
  /** The API answered 401 (the session ended): offer a sign-in link. */
  | { status: 'signIn' }
  /** The API answered 404 (no access any more, or not a stock): show nothing. */
  | { status: 'none' }

type Settled = { key: string; state: Exclude<InstrumentMarketContextState, { status: 'off' | 'loading' }> }

function isAbortError(error: unknown): boolean {
  return error instanceof Error && error.name === 'AbortError'
}

/**
 * The chart page's Market Context (market-context-patterns, SPA "Chart
 * page"): one `GET /api/instruments/{ticker}/market-context` request, only for
 * a stock and only when the access hook says `allowed`. The result feeds both
 * the strip and the pattern chips. A failure never affects the chart.
 */
export function useInstrumentMarketContext(
  ticker: string | null,
  isStock: boolean,
): { access: MarketContextAccess; state: InstrumentMarketContextState; retry: () => void } {
  const access = useMarketContextAccess()
  const [retryToken, setRetryToken] = useState(0)
  const [settled, setSettled] = useState<Settled | null>(null)
  const enabled = ticker !== null && isStock && access.status === 'allowed'
  const key = enabled ? `${ticker}|${retryToken}` : null

  useEffect(() => {
    if (key === null || ticker === null) {
      return
    }
    const controller = new AbortController()

    instrumentMarketContextApi.get(ticker, controller.signal).then(
      (data) => setSettled({ key, state: { status: 'ready', data } }),
      (error: unknown) => {
        if (isAbortError(error)) {
          return
        }
        if (error instanceof ApiError && error.status === 401) {
          setSettled({ key, state: { status: 'signIn' } })
        } else if (error instanceof ApiError && error.status === 404) {
          setSettled({ key, state: { status: 'none' } })
        } else {
          setSettled({ key, state: { status: 'error' } })
        }
      },
    )

    return () => controller.abort()
  }, [key, ticker])

  let state: InstrumentMarketContextState
  if (key === null) {
    state = { status: 'off' }
  } else if (settled === null || settled.key !== key) {
    state = { status: 'loading' }
  } else {
    state = settled.state
  }

  return { access, state, retry: () => setRetryToken((token) => token + 1) }
}
