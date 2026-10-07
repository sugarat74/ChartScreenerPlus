import { useI18n } from '../../../i18n/useI18n.ts'

const BUTTON_CLASS =
  'rounded-md border-2 border-outline bg-surface-bright px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-50'

interface AdminPaginationProps {
  page: number
  lastPage: number
  total: number
  onPage: (page: number) => void
}

/** Previous/next pager for server-paginated Admin lists. */
export default function AdminPagination({ page, lastPage, total, onPage }: AdminPaginationProps) {
  const { t, formatInteger } = useI18n()

  return (
    <nav
      aria-label={t('adminUsers.paginationAria')}
      className="flex flex-wrap items-center justify-between gap-3 font-mono text-xs text-on-surface-variant"
    >
      <span>
        {t('adminUsers.pageOf', {
          page: formatInteger(page),
          last: formatInteger(Math.max(1, lastPage)),
          total: formatInteger(total),
        })}
      </span>
      <div className="flex gap-2">
        <button type="button" className={BUTTON_CLASS} disabled={page <= 1} onClick={() => onPage(page - 1)}>
          {t('adminUsers.previous')}
        </button>
        <button type="button" className={BUTTON_CLASS} disabled={page >= lastPage} onClick={() => onPage(page + 1)}>
          {t('adminUsers.next')}
        </button>
      </div>
    </nav>
  )
}
