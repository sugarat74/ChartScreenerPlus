/**
 * Screener surface: URL-backed filters + the Candidate list from `GET /api/screener`.
 *
 * Anonymous by design — no `useAuth`, no redirect, no login prompt. Filtering
 * and ranking stay server-side; this page only serializes the filters, issues
 * the request and renders loading / refreshing / empty / error / result states.
 */

import { useEffect, useMemo, useRef, useState } from 'react'
import { useSearchParams } from 'react-router'
import CandidateResults from '../components/screener/CandidateResults.tsx'
import type { ScreenerStatus } from '../components/screener/CandidateResults.tsx'
import ScreenerFilterPanel from '../components/screener/ScreenerFilterPanel.tsx'
import { ApiError, screenerApi } from '../lib/api.ts'
import type { ScreenerCandidate, ScreenerFilters } from '../lib/api.ts'
import {
  EMPTY_SCREENER_FILTERS,
  countActiveFilters,
  hasActiveFilters,
  parseScreenerFilters,
  patchScreenerFilters,
  screenerFiltersKey,
} from '../lib/screenerFilters.ts'

interface ScreenerData {
  candidates: ScreenerCandidate[]
  returned: number
  total: number
  universeName: string
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

export default function ScreenerPage() {
  const [searchParams, setSearchParams] = useSearchParams()

  // The URL is the single source of truth for the filter state.
  const filters = useMemo(() => parseScreenerFilters(searchParams), [searchParams])
  const filtersKey = screenerFiltersKey(filters)

  const [status, setStatus] = useState<ScreenerStatus>('loading')
  const [data, setData] = useState<ScreenerData | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [retryToken, setRetryToken] = useState(0)

  const dataRef = useRef<ScreenerData | null>(null)
  const lastKeyRef = useRef<string | null>(null)

  // Latest filters without making the fetch effect depend on the memo object
  // identity (which changes whenever any search param changes).
  const filtersRef = useRef(filters)
  useEffect(() => {
    filtersRef.current = filters
  }, [filters])

  useEffect(() => {
    // Suppress a duplicate identical query (the key is the canonical filters).
    if (lastKeyRef.current === filtersKey) {
      return
    }
    lastKeyRef.current = filtersKey

    const controller = new AbortController()
    setStatus(dataRef.current === null ? 'loading' : 'refreshing')
    setError(null)

    screenerApi
      .search(filtersRef.current, controller.signal)
      .then((response) => {
        const next: ScreenerData = {
          candidates: response.candidates,
          returned: response.meta.returned,
          total: response.meta.total,
          universeName: response.universe.name,
        }
        dataRef.current = next
        setData(next)
        setStatus('ready')
      })
      .catch((caught: unknown) => {
        if (isAbortError(caught)) {
          return
        }
        setError(messageFor(caught))
        setStatus('error')
      })

    return () => {
      controller.abort()
      // A remount/re-run for the same key (StrictMode) must be able to refetch.
      lastKeyRef.current = null
    }
  }, [filtersKey, retryToken])

  function update(patch: Partial<ScreenerFilters>) {
    setSearchParams((prev) => patchScreenerFilters(prev, patch), { replace: true })
  }

  function clearFilters() {
    setSearchParams((prev) => patchScreenerFilters(prev, EMPTY_SCREENER_FILTERS), { replace: true })
  }

  function retry() {
    setRetryToken((token) => token + 1)
  }

  const activeCount = countActiveFilters(filters)
  const filtersActive = hasActiveFilters(filters)

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
        <h1 className="font-headline text-3xl font-black tracking-tight uppercase text-on-surface">
          Screener
        </h1>
        <p className="max-w-2xl text-sm text-on-surface-variant">
          Filtros técnicos sobre el universo. El filtrado y el ranking se calculan en el servidor.
        </p>
      </header>

      <ScreenerFilterPanel
        filters={filters}
        activeCount={activeCount}
        onChange={update}
        onClear={clearFilters}
      />

      {data !== null && status !== 'error' ? (
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="font-mono text-xs text-on-surface-variant">
            Mostrando{' '}
            <strong className="font-bold text-on-surface">{data.returned}</strong> de{' '}
            <strong className="font-bold text-on-surface">{data.total}</strong> candidatos
          </p>
          <span
            title="Universo resuelto por el servidor"
            className="rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a]"
          >
            Universo · {data.universeName}
          </span>
        </div>
      ) : null}

      <CandidateResults
        status={status}
        candidates={data?.candidates ?? []}
        hasFilters={filtersActive}
        error={error}
        onRetry={retry}
        onClearFilters={clearFilters}
      />
    </section>
  )
}
