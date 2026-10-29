/**
 * "Indicators" columns toggle of the Screener results header
 * (screener-technical-filters-ui): a pressed-state button that adds the
 * optional ADX, %B, ATR % and Stoch %K columns. While an indicator criterion
 * or indicator sort is active the columns are forced visible, so the button
 * stays pressed and is disabled with an explanation.
 */

import { useI18n } from '../../i18n/useI18n.ts'

interface IndicatorsToggleProps {
  visible: boolean
  forced: boolean
  onToggle: () => void
}

export default function IndicatorsToggle({ visible, forced, onToggle }: IndicatorsToggleProps) {
  const { t } = useI18n()
  const hintId = 'screener-indicators-hint'

  return (
    <div className="flex flex-wrap items-center gap-2">
      <button
        type="button"
        aria-pressed={visible}
        disabled={forced}
        aria-describedby={forced ? hintId : undefined}
        onClick={onToggle}
        className={`rounded-md border-2 border-outline px-3 py-2 font-mono text-xs font-bold uppercase tracking-wider shadow-[2px_2px_0px_var(--color-shadow)] focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed ${
          visible ? 'bg-primary text-on-primary' : 'bg-surface-bright text-on-surface'
        }`}
      >
        {t('screener.indicators.toggle')}
      </button>
      {forced ? (
        <span id={hintId} className="font-mono text-[10px] text-on-surface-variant">
          {t('screener.indicators.forced')}
        </span>
      ) : null}
    </div>
  )
}
