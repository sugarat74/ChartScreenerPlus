/**
 * "Indicators" toggle row above the chart (chart-indicator-overlays): one
 * button per indicator with `aria-pressed`, native keyboard operation. The
 * parent owns the selection and its persistence; all off by default.
 */

import type { MessageKey } from '../../i18n/translate.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import { CHART_INDICATOR_IDS } from '../../lib/chartIndicators.ts'
import type { ChartIndicatorId } from '../../lib/chartIndicators.ts'

const LABEL_KEYS: Record<ChartIndicatorId, MessageKey> = {
  bollinger: 'chart.indicatorBollinger',
}

interface ChartIndicatorPickerProps {
  selection: readonly ChartIndicatorId[]
  onToggle: (id: ChartIndicatorId) => void
}

export default function ChartIndicatorPicker({ selection, onToggle }: ChartIndicatorPickerProps) {
  const { t } = useI18n()

  return (
    <div role="group" aria-label={t('chart.indicatorsGroup')} className="flex flex-wrap items-center gap-2">
      <span className="font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
        {t('chart.indicatorsLabel')}
      </span>
      {CHART_INDICATOR_IDS.map((id) => {
        const on = selection.includes(id)
        return (
          <button
            key={id}
            type="button"
            aria-pressed={on}
            onClick={() => onToggle(id)}
            className={`rounded-[4px] border-2 border-outline px-2 py-1 font-mono text-[11px] font-bold uppercase tracking-wider shadow-[1px_1px_0px_var(--color-shadow)] focus:outline-none focus-visible:shadow-[3px_3px_0px_#ffcc00] ${
              on
                ? 'bg-primary-container text-on-primary-container'
                : 'bg-surface-bright text-on-surface hover:bg-surface-container'
            }`}
          >
            {t(LABEL_KEYS[id])}
          </button>
        )
      })}
    </div>
  )
}
