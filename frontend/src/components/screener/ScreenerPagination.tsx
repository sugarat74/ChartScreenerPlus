/**
 * Previous / next navigation and page size for the Candidate list
 * (screener-pagination). Presentational: the page owns the URL state.
 * Token-only styling (DESIGN.md), keyboard-operable native controls.
 */

import { useI18n } from '../../i18n/useI18n.ts'
import { SCREENER_PAGE_SIZES, screenerPageCount } from '../../lib/screenerPagination.ts'

const BUTTON_CLASS =
  'rounded-md border-2 border-outline bg-surface-bright px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0'

interface ScreenerPaginationProps {
  page: number
  limit: number
  total: number
  /** A page is loading: announced with `aria-busy`, controls stay focusable. */
  busy: boolean
  onPageChange: (page: number) => void
  onLimitChange: (limit: number) => void
}

export default function ScreenerPagination({
  page,
  limit,
  total,
  busy,
  onPageChange,
  onLimitChange,
}: ScreenerPaginationProps) {
  const { t } = useI18n()
  const pages = screenerPageCount(total, limit)

  return (
    <nav
      aria-label={t('screener.pagination.aria')}
      aria-busy={busy}
      className="flex flex-wrap items-center justify-between gap-3 rounded-md border-2 border-outline bg-surface-bright px-4 py-3 shadow-[2px_2px_0px_#1a1a1a]"
    >
      <div className="flex items-center gap-2">
        <button
          type="button"
          className={BUTTON_CLASS}
          disabled={page <= 1}
          onClick={() => onPageChange(page - 1)}
        >
          <span aria-hidden="true">←</span> {t('screener.pagination.previous')}
        </button>
        <p className="font-mono text-xs text-on-surface-variant" aria-live="polite">
          {t('screener.pagination.page', { page, pages })}
        </p>
        <button
          type="button"
          className={BUTTON_CLASS}
          disabled={page >= pages}
          onClick={() => onPageChange(page + 1)}
        >
          {t('screener.pagination.next')} <span aria-hidden="true">→</span>
        </button>
      </div>

      <label className="flex items-center gap-2 font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant">
        {t('screener.pagination.perPage')}
        <select
          value={limit}
          onChange={(event) => onLimitChange(Number(event.target.value))}
          className="rounded-md border-2 border-outline bg-surface-bright px-2 py-1 font-mono text-xs text-on-surface shadow-[2px_2px_0px_#1a1a1a] focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
        >
          {SCREENER_PAGE_SIZES.map((size) => (
            <option key={size} value={size}>
              {size}
            </option>
          ))}
        </select>
      </label>
    </nav>
  )
}
