/**
 * Screener surface: URL-backed filters + ranking + the Candidate list from
 * `GET /api/screener`.
 *
 * Anonymous by design — no `useAuth`, no redirect, no login prompt. Filtering
 * and ranking stay server-side; this page only serializes the filters/sort,
 * issues the request and renders loading / refreshing / empty / error / result
 * states.
 */

import { useEffect, useMemo, useRef, useState } from 'react'
import { useSearchParams } from 'react-router'
import CandidateResults from '../components/screener/CandidateResults.tsx'
import type { ScreenerStatus } from '../components/screener/CandidateResults.tsx'
import SavedScreenersPanel from '../components/screener/SavedScreenersPanel.tsx'
import ScreenerFilterPanel from '../components/screener/ScreenerFilterPanel.tsx'
import ScreenerSortControl from '../components/screener/ScreenerSortControl.tsx'
import Interpolate from '../i18n/Interpolate.tsx'
import type { Translate } from '../i18n/translate.ts'
import { useI18n, useTranslateRef } from '../i18n/useI18n.ts'
import { ApiError, screenerApi } from '../lib/api.ts'
import type { ScreenerCandidate, ScreenerFilters } from '../lib/api.ts'
import {
  EMPTY_SCREENER_CRITERIA,
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

function messageFor(error: unknown, t: Translate): string {
  if (error instanceof ApiError) {
    const firstValidation = Object.values(error.errors)[0]?.[0]
    return firstValidation ?? error.message
  }

  return t('common.networkError')
}

function isAbortError(error: unknown): boolean {
  return error instanceof Error && error.name === 'AbortError'
}

export default function ScreenerPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const { t } = useI18n()
  const tRef = useTranslateRef()

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
        setError(messageFor(caught, tRef.current))
        setStatus('error')
      })

    return () => {
      controller.abort()
      // A remount/re-run for the same key (StrictMode) must be able to refetch.
      lastKeyRef.current = null
    }
  }, [filtersKey, retryToken, tRef])

  function update(patch: Partial<ScreenerFilters>) {
    setSearchParams((prev) => patchScreenerFilters(prev, patch), { replace: true })
  }

  function applySavedFilters(patch: Partial<ScreenerFilters>) {
    // Applying a stored Screener writes the same owned keys as `update`, so the
    // fetch effect keyed on `screenerFiltersKey` restores the results.
    setSearchParams((prev) => patchScreenerFilters(prev, patch), { replace: true })
  }

  function clearFilters() {
    // Only the criteria are cleared: the selected ranking must survive.
    setSearchParams((prev) => patchScreenerFilters(prev, EMPTY_SCREENER_CRITERIA), {
      replace: true,
    })
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
            {t('screener.badge')}
          </span>
          <span className="font-mono text-[11px] uppercase tracking-wider text-on-surface-variant">
            {t('screener.access')}
          </span>
        </div>
        <h1 className="font-headline text-3xl font-black tracking-tight uppercase text-on-surface">
          {t('screener.title')}
        </h1>
        <p className="max-w-2xl text-sm text-on-surface-variant">
          {t('screener.intro')}
        </p>
      </header>

      <ScreenerFilterPanel
        filters={filters}
        activeCount={activeCount}
        onChange={update}
        onClear={clearFilters}
      />

      <SavedScreenersPanel filters={filters} onApply={applySavedFilters} />

      <div className="flex flex-wrap items-center justify-between gap-3">
        {data !== null ? (
          <p className="font-mono text-xs text-on-surface-variant">
            <Interpolate
              template={t('screener.showing')}
              values={{
                returned: <strong className="font-bold text-on-surface">{data.returned}</strong>,
                total: <strong className="font-bold text-on-surface">{data.total}</strong>,
              }}
            />
          </p>
        ) : null}
        <div className="flex flex-wrap items-center gap-3">
          <ScreenerSortControl value={filters.sort} onChange={(sort) => update({ sort })} />
          {data !== null ? (
            <span
              title={t('screener.universeTitle')}
              className="rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a]"
            >
              {t('screener.universe', { name: data.universeName })}
            </span>
          ) : null}
        </div>
      </div>

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
