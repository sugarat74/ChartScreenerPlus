/**
 * Renders whichever result state the Screener currently has: first-load
 * skeleton, refreshing (previous table kept visible), empty (split by whether
 * filters are active), error (with a manual retry) or the Candidate table.
 *
 * Empty is a valid `200 candidates: []`, never an error. There is no auto-retry.
 * The per-row "AI card" link is shown only to signed-in users: the session is
 * read from the already bootstrapped `AuthContext` via `useSignedIn` (no
 * fetch, redirect or prompt), so the Screener page stays anonymous.
 */

import { useSignedIn } from '../../auth/useSignedIn.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import type { ScreenerCandidate } from '../../lib/api.ts'
import CandidateTable from './CandidateTable.tsx'
import type { CandidateTableControls } from './CandidateTable.tsx'

export type ScreenerStatus = 'loading' | 'refreshing' | 'ready' | 'error'

interface CandidateResultsProps {
  status: ScreenerStatus
  candidates: ScreenerCandidate[]
  hasFilters: boolean
  error: string | null
  onRetry: () => void
  onClearFilters: () => void
  /** Column sort/filter controls for the table headings (screener-table-column-controls). */
  controls?: CandidateTableControls
}

function LoadingPanel() {
  const { t } = useI18n()
  return (
    <div
      role="status"
      aria-busy="true"
      className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_var(--color-shadow)]"
    >
      <p className="font-mono text-xs uppercase tracking-wider text-on-surface-variant">
        {t('screener.loadingCandidates')}
      </p>
      <div className="mt-4 flex flex-col gap-3" aria-hidden="true">
        {[0, 1, 2, 3, 4].map((row) => (
          <div key={row} className="flex items-center gap-3">
            <span className="h-4 w-40 rounded-[2px] bg-surface-dim" />
            <span className="h-4 flex-1 rounded-[2px] bg-surface-container" />
            <span className="h-4 w-20 rounded-[2px] bg-surface-container" />
            <span className="h-4 w-16 rounded-[2px] bg-surface-dim" />
          </div>
        ))}
      </div>
    </div>
  )
}

interface EmptyPanelProps {
  hasFilters: boolean
  onClearFilters: () => void
}

function EmptyPanel({ hasFilters, onClearFilters }: EmptyPanelProps) {
  const { t } = useI18n()
  return (
    <div className="rounded-md border-2 border-outline bg-surface-bright p-8 text-center shadow-[2px_2px_0px_var(--color-shadow)]">
      <p className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
        {hasFilters ? t('screener.emptyFilteredTitle') : t('screener.emptyUniverseTitle')}
      </p>
      <p className="mt-2 text-sm text-on-surface-variant">
        {hasFilters ? t('screener.emptyFilteredBody') : t('screener.emptyUniverseBody')}
      </p>
      {hasFilters ? (
        <button
          type="button"
          onClick={onClearFilters}
          className="mt-4 rounded-md border-2 border-outline bg-primary-container px-4 py-2 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
        >
          {t('screener.clearFilters')}
        </button>
      ) : null}
    </div>
  )
}

interface ErrorPanelProps {
  message: string
  onRetry: () => void
}

function ErrorPanel({ message, onRetry }: ErrorPanelProps) {
  const { t } = useI18n()
  return (
    <div
      role="alert"
      className="flex flex-col gap-3 rounded-md border-2 border-secondary bg-secondary-container p-5 shadow-[2px_2px_0px_var(--color-shadow)]"
    >
      <div>
        <p className="font-headline text-sm font-black uppercase tracking-wide text-on-secondary-container">
          {t('screener.errorTitle')}
        </p>
        <p className="mt-1 font-mono text-xs text-on-secondary-container">{message}</p>
      </div>
      <button
        type="button"
        onClick={onRetry}
        className="w-fit rounded-md border-2 border-outline bg-primary-container px-4 py-2 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
      >
        {t('common.retry')}
      </button>
    </div>
  )
}

export default function CandidateResults({
  status,
  candidates,
  hasFilters,
  error,
  onRetry,
  onClearFilters,
  controls,
}: CandidateResultsProps) {
  const { t } = useI18n()
  const signedIn = useSignedIn()

  if (status === 'error') {
    return <ErrorPanel message={error ?? t('common.unknownError')} onRetry={onRetry} />
  }

  if (status === 'loading') {
    return <LoadingPanel />
  }

  return (
    <div aria-busy={status === 'refreshing'} className="flex flex-col gap-2">
      {status === 'refreshing' ? (
        <p role="status" className="font-mono text-[11px] uppercase tracking-wider text-on-surface-variant">
          {t('screener.refreshing')}
        </p>
      ) : null}
      {candidates.length === 0 ? (
        <EmptyPanel hasFilters={hasFilters} onClearFilters={onClearFilters} />
      ) : (
        <CandidateTable candidates={candidates} showCompanyCardLink={signedIn} controls={controls} />
      )}
    </div>
  )
}
