/**
 * Textual "Señales activas" panel for the chart page.
 *
 * The canvas is not screen-reader navigable, so every active signal is listed
 * here with the existing `signalLabel()` vocabulary. The `pivot_breakout_rvol`
 * signal additionally shows its engine-computed pivot/close/RVOL; stop and
 * target are not modeled and are never shown. Styling is DESIGN.md tokens.
 */

import { useI18n } from '../../i18n/useI18n.ts'
import type { InstrumentSignal } from '../../lib/api.ts'
import { signalLabel } from '../../lib/screenerFilters.ts'

function finiteOrNull(value: number | undefined): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline gap-1.5">
      <dt className="font-mono text-[10px] uppercase tracking-wider text-on-surface-variant">
        {label}
      </dt>
      <dd className="font-mono text-xs font-bold text-on-surface">{value}</dd>
    </div>
  )
}

export default function SignalLevelsPanel({ signals }: { signals: InstrumentSignal[] }) {
  const { t, formatPrice, formatMultiple, formatMarketDate } = useI18n()

  return (
    <div className="rounded-md border-2 border-outline bg-surface-bright p-4 shadow-[2px_2px_0px_#1a1a1a]">
      <p className="font-headline text-xs font-bold uppercase tracking-wider text-on-surface">
        {t('chart.activeSignals')}
      </p>

      {signals.length === 0 ? (
        <p className="mt-2 text-sm text-on-surface-variant">
          {t('chart.noActiveSignals')}
        </p>
      ) : (
        <ul className="mt-3 flex flex-col gap-3">
          {signals.map((signal) => {
            const pivot = finiteOrNull(signal.metadata?.pivot)
            const close = finiteOrNull(signal.metadata?.close)
            const rvol = finiteOrNull(signal.metadata?.rvol)
            const isPivotSignal = signal.type === 'pivot_breakout_rvol'

            return (
              <li
                key={`${signal.type}-${signal.date}`}
                className="border-b border-outline-variant pb-3 last:border-b-0 last:pb-0"
              >
                <div className="flex flex-wrap items-center gap-2">
                  <span className="rounded-[4px] border border-outline bg-surface-container px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface">
                    {signalLabel(signal.type, t)}
                  </span>
                  <span className="font-mono text-[11px] text-on-surface-variant">
                    {formatMarketDate(signal.date)}
                  </span>
                </div>

                {isPivotSignal ? (
                  <dl className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                    <Metric label={t('chart.metricPivot')} value={formatPrice(pivot)} />
                    <Metric label={t('chart.metricClose')} value={formatPrice(close)} />
                    <Metric label={t('chart.metricRvol')} value={formatMultiple(rvol)} />
                  </dl>
                ) : null}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
