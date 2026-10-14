import { useI18n } from '../../i18n/useI18n.ts'
import type { Alert } from '../../lib/api.ts'

/** Off / active / paused chip shared by the Portal alert controls (text, not color alone). */
export default function AlertStatusChip({ alert }: { alert: Alert | undefined }) {
  const { t } = useI18n()
  const [label, tone] =
    alert === undefined
      ? [t('alerts.statusOff'), 'bg-surface-container text-on-surface-variant']
      : alert.active
        ? [t('alerts.statusActive'), 'bg-primary-container text-on-primary-container']
        : [t('alerts.statusPaused'), 'bg-tertiary-container text-on-tertiary-container']

  return (
    <span
      className={`rounded-[4px] border border-outline px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider shadow-[1px_1px_0px_#1a1a1a] ${tone}`}
    >
      {label}
    </span>
  )
}
