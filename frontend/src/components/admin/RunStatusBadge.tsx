import { useI18n } from '../../i18n/useI18n.ts'
import type { IngestionRunStatus } from '../../lib/api.ts'

const CLASSES: Record<IngestionRunStatus, string> = {
  queued: 'border-outline-variant bg-surface-dim text-on-surface-variant',
  running: 'border-tertiary bg-tertiary-container text-on-tertiary-container',
  completed: 'border-gain bg-gain text-primary',
  partial: 'border-secondary bg-primary-container text-on-primary-container',
  failed: 'border-secondary bg-secondary-container text-on-secondary-container',
}

/**
 * Color-coded run status chip. The label is always present so status never
 * depends on color alone (DESIGN.md accessibility baseline).
 */
export default function RunStatusBadge({ status }: { status: IngestionRunStatus }) {
  const { t } = useI18n()

  return (
    <span
      className={`inline-flex items-center rounded-[4px] border-2 px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider ${CLASSES[status]}`}
    >
      {t(`runStatus.${status}` as const)}
    </span>
  )
}
