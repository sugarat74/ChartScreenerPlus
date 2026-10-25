/**
 * Screener surface: URL-backed filters + ranking + the Candidate list from
 * `GET /api/screener`.
 *
 * Anonymous by design — no `useAuth`, no redirect, no login prompt. Filtering
 * and ranking stay server-side; this page only serializes the filters/sort,
 * issues the request and renders loading / refreshing / empty / error / result
 * states. The signed-in affordances (natural-language input, Saved Screeners)
 * live in their own components and only patch the URL like `update`. The table
 * headings sort and filter by column (screener-table-column-controls) through
 * the same `update`; the column filter editor sits above the table.
 *
 * The sector context filters (market-context-patterns) need Market Context
 * access: the page reads `useMarketContextAccess` (the bootstrapped session,
 * no fetch, no redirect) and requests without them for anyone else, with a
 * notice; the URL keeps them. While access is still loading, a URL with those
 * keys waits instead of requesting twice.
 */

import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { useMarketContextAccess } from '../auth/useMarketContextAccess.ts'
import CandidateResults from '../components/screener/CandidateResults.tsx'
import type { ScreenerStatus } from '../components/screener/CandidateResults.tsx'
import SavedScreenersPanel from '../components/screener/SavedScreenersPanel.tsx'
import ScreenerAiQuery from '../components/screener/ScreenerAiQuery.tsx'
import ScreenerColumnFilters from '../components/screener/ScreenerColumnFilters.tsx'
import ScreenerFilterPanel from '../components/screener/ScreenerFilterPanel.tsx'
import ScreenerPagination from '../components/screener/ScreenerPagination.tsx'
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
  usesMarketContext,
  withoutMarketContext,
} from '../lib/screenerFilters.ts'
import type { CandidateColumnId } from '../lib/screenerColumns.ts'
import { LOGIN_ROUTE } from '../nav.ts'
import {
  parseScreenerPagination,
  patchScreenerPagination,
  resetScreenerPage,
  screenerOffset,
  screenerPageCount,
  screenerPageRange,
} from '../lib/screenerPagination.ts'

interface ScreenerData {
  candidates: ScreenerCandidate[]
  offset: number
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

  const access = useMarketContextAccess()
  // The URL is the single source of truth for the filter state.
  const filters = useMemo(() => parseScreenerFilters(searchParams), [searchParams])
  // What is requested: the sector context keys only with Market Context access.
  const allowed = access.status === 'allowed'
  const effective = useMemo(() => (allowed ? filters : withoutMarketContext(filters)), [allowed, filters])
  const sectorKeysInUrl = usesMarketContext(filters)
  const dropped = sectorKeysInUrl && (access.status === 'signIn' || access.status === 'hidden') ? access.status : null
  // A URL with sector keys waits for the access to resolve (one request, not two).
  const waiting = sectorKeysInUrl && access.status === 'loading'
  const pagination = useMemo(() => parseScreenerPagination(searchParams), [searchParams])
  // The fetch key covers the filters and the page, so paging refetches too.
  const filtersKey = waiting ? null : `${screenerFiltersKey(effective)}|${pagination.page}|${pagination.limit}`
  const resultsRef = useRef<HTMLDivElement | null>(null)

  const [status, setStatus] = useState<ScreenerStatus>('loading')
  const [data, setData] = useState<ScreenerData | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [retryToken, setRetryToken] = useState(0)
  // The column whose filter editor is open (screener-table-column-controls);
  // UI state only, the criteria themselves live in the URL.
  const [openColumn, setOpenColumn] = useState<CandidateColumnId | null>(null)

  const dataRef = useRef<ScreenerData | null>(null)
  const lastKeyRef = useRef<string | null>(null)

  // Latest filters without making the fetch effect depend on the memo object
  // identity (which changes whenever any search param changes).
  const filtersRef = useRef(effective)
  const paginationRef = useRef(pagination)
  // react-router recreates `setSearchParams` on every URL change; reading it
  // through a ref keeps the fetch effect keyed on the query only.
  const setSearchParamsRef = useRef(setSearchParams)
  useEffect(() => {
    filtersRef.current = effective
    paginationRef.current = pagination
    setSearchParamsRef.current = setSearchParams
  }, [effective, pagination, setSearchParams])

  useEffect(() => {
    if (filtersKey === null) {
      return
    }
    // Suppress a duplicate identical query (the key is the canonical filters).
    if (lastKeyRef.current === filtersKey) {
      return
    }
    lastKeyRef.current = filtersKey

    const controller = new AbortController()
    setStatus(dataRef.current === null ? 'loading' : 'refreshing')
    setError(null)

    const page = paginationRef.current
    screenerApi
      .search(filtersRef.current, controller.signal, { limit: page.limit, offset: screenerOffset(page) })
      .then((response) => {
        if (controller.signal.aborted) {
          return
        }
        // A page past the end (the list shrank, or a stale link): go to the
        // last page that has candidates instead of showing an empty list;
        // with no candidates at all, drop the page from the URL.
        if (response.meta.returned === 0 && page.page > 1) {
          const last = response.meta.total > 0 ? screenerPageCount(response.meta.total, page.limit) : 1
          setSearchParamsRef.current((prev) => patchScreenerPagination(prev, { page: last }), { replace: true })
          if (response.meta.total > 0) {
            return
          }
        }
        const next: ScreenerData = {
          candidates: response.candidates,
          offset: response.meta.offset,
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

  // Any filter or ranking change restarts at page 1 (the page size is kept).
  function update(patch: Partial<ScreenerFilters>) {
    setSearchParams((prev) => resetScreenerPage(patchScreenerFilters(prev, patch)), { replace: true })
  }

  function applySavedFilters(patch: Partial<ScreenerFilters>) {
    // Applying a stored Screener writes the same owned keys as `update`, so the
    // fetch effect keyed on `screenerFiltersKey` restores the results.
    setSearchParams((prev) => resetScreenerPage(patchScreenerFilters(prev, patch)), { replace: true })
  }

  function applyInterpretedFilters(patch: Partial<ScreenerFilters>) {
    // The AI result is ordinary filter state, applied through the same path.
    setSearchParams((prev) => resetScreenerPage(patchScreenerFilters(prev, patch)), { replace: true })
  }

  function restoreSearchParams(previous: URLSearchParams) {
    // Undo of an AI search: back to the exact URL the user had before it.
    setSearchParams(new URLSearchParams(previous), { replace: true })
  }

  function clearFilters() {
    // Only the criteria are cleared: the selected ranking must survive.
    setSearchParams((prev) => resetScreenerPage(patchScreenerFilters(prev, EMPTY_SCREENER_CRITERIA)), {
      replace: true,
    })
  }

  // Page changes are history entries (Back returns to the previous page);
  // the view goes back to the top of the list.
  function goToPage(page: number) {
    setSearchParams((prev) => patchScreenerPagination(prev, { page }))
    resultsRef.current?.scrollIntoView?.({ block: 'start' })
  }

  function changePageSize(limit: number) {
    setSearchParams((prev) => patchScreenerPagination(prev, { limit }), { replace: true })
  }

  function retry() {
    setRetryToken((token) => token + 1)
  }

  const activeCount = countActiveFilters(effective)
  const filtersActive = hasActiveFilters(effective)

  return (
    <section className="flex flex-col gap-6">
      <header className="flex flex-col gap-2">
        <div className="flex flex-wrap items-center gap-3">
          <span className="rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_var(--color-shadow)]">
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
        <Link
          to="/como-funciona"
          className="w-fit font-mono text-xs font-bold text-on-surface underline underline-offset-4 hover:text-primary focus:outline-2 focus:outline-offset-2 focus:outline-outline"
        >
          {t('screener.howItWorksLink')}
        </Link>
      </header>

      <ScreenerAiQuery
        searchParams={searchParams}
        onApply={applyInterpretedFilters}
        onRestore={restoreSearchParams}
      />

      <ScreenerFilterPanel
        filters={effective}
        activeCount={activeCount}
        access={access}
        onChange={update}
        onClear={clearFilters}
      />

      <SavedScreenersPanel filters={effective} onApply={applySavedFilters} />

      {dropped !== null ? (
        <p
          role="status"
          className="rounded-md border-2 border-dashed border-outline-variant bg-surface-container px-3 py-2 text-xs text-on-surface-variant"
        >
          {dropped === 'signIn' ? t('screener.sectorContext.droppedSignIn') : t('screener.sectorContext.droppedUnavailable')}
          {dropped === 'signIn' ? (
            <>
              {' '}
              <Link
                to={LOGIN_ROUTE}
                className="font-mono font-bold text-on-surface underline underline-offset-4 focus:outline-2 focus:outline-offset-2 focus:outline-outline"
              >
                {t('screener.sectorContext.signIn')}
              </Link>
            </>
          ) : null}
        </p>
      ) : null}

      <div ref={resultsRef} className="flex scroll-mt-4 flex-wrap items-center justify-between gap-3">
        {data !== null ? (
          <p className="font-mono text-xs text-on-surface-variant">
            <Interpolate
              template={t('screener.showingRange')}
              values={{
                from: <strong className="font-bold text-on-surface">{screenerPageRange(data.offset, data.returned).from}</strong>,
                to: <strong className="font-bold text-on-surface">{screenerPageRange(data.offset, data.returned).to}</strong>,
                total: <strong className="font-bold text-on-surface">{data.total}</strong>,
              }}
            />
          </p>
        ) : null}
        <div className="flex flex-wrap items-center gap-3">
          <ScreenerSortControl value={effective.sort} onChange={(sort) => update({ sort })} />
          {data !== null ? (
            <span
              title={t('screener.universeTitle')}
              className="rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_var(--color-shadow)]"
            >
              {t('screener.universe', { name: data.universeName })}
            </span>
          ) : null}
        </div>
      </div>

      <ScreenerColumnFilters
        filters={effective}
        openColumn={openColumn}
        onChange={update}
        onOpen={setOpenColumn}
        onClose={() => setOpenColumn(null)}
      />

      <CandidateResults
        status={status}
        candidates={data?.candidates ?? []}
        hasFilters={filtersActive}
        error={error}
        onRetry={retry}
        onClearFilters={clearFilters}
        controls={{
          filters: effective,
          openColumn,
          onSort: (sort) => update({ sort }),
          onToggleFilter: (column) => setOpenColumn((current) => (current === column ? null : column)),
        }}
      />

      {data !== null && data.total > 0 && status !== 'error' ? (
        <ScreenerPagination
          page={pagination.page}
          limit={pagination.limit}
          total={data.total}
          busy={status === 'loading' || status === 'refreshing'}
          onPageChange={goToPage}
          onLimitChange={changePageSize}
        />
      ) : null}
    </section>
  )
}
