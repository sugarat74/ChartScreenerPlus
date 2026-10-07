import { useI18n } from '../../../i18n/useI18n.ts'

export const ADMIN_BUTTON_CLASS =
  'rounded-md border-2 border-outline px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60'

export const ADMIN_TABLE_HEAD_CLASS =
  'border-b-2 border-outline bg-surface-container font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant'

export function AdminLoading() {
  const { t } = useI18n()
  return (
    <div role="status" aria-busy="true" className="flex flex-col gap-2">
      <span className="font-mono text-xs uppercase tracking-wider text-on-surface-variant">{t('common.loading')}</span>
      <div className="h-9 rounded-[2px] bg-surface-container" aria-hidden="true" />
      <div className="h-9 rounded-[2px] bg-surface-container" aria-hidden="true" />
    </div>
  )
}

export function AdminError({ message, onRetry }: { message: string; onRetry: () => void }) {
  const { t } = useI18n()
  return (
    <div
      role="alert"
      className="flex flex-col gap-3 rounded-md border-2 border-secondary bg-secondary-container p-4 shadow-[2px_2px_0px_#1a1a1a]"
    >
      <p className="font-mono text-xs text-on-secondary-container">{message}</p>
      <button type="button" onClick={onRetry} className={`w-fit bg-primary-container text-on-primary-container ${ADMIN_BUTTON_CLASS}`}>
        {t('common.retry')}
      </button>
    </div>
  )
}

export function AdminNoticeLine({ message, tone = 'info' }: { message: string; tone?: 'info' | 'error' }) {
  return (
    <p
      role={tone === 'error' ? 'alert' : 'status'}
      className={
        tone === 'error'
          ? 'rounded-[4px] border-2 border-secondary bg-secondary-container px-3 py-2 font-mono text-xs text-on-secondary-container'
          : 'rounded-[4px] border-2 border-outline bg-surface-container px-3 py-2 font-mono text-xs text-on-surface'
      }
    >
      {message}
    </p>
  )
}
