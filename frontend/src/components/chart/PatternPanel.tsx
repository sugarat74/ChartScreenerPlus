/**
 * Textual "Patrones activos" panel for the chart page (chart-patterns-ui).
 *
 * The canvas is not screen-reader navigable, so every active chartist pattern
 * is listed here with its status, dates, breakout level and key points. The
 * breakout level is the only price a pattern exposes: no target or stop is
 * shown. Patterns are detected automatically and are informational only.
 */

import { useI18n } from '../../i18n/useI18n.ts'
import type { MessageKey } from '../../i18n/translate.ts'
import type { InstrumentPattern } from '../../lib/api.ts'
import { patternLabel } from '../../lib/screenerFilters.ts'

export default function PatternPanel({ patterns }: { patterns: InstrumentPattern[] }) {
  const { t, formatPrice, formatMarketDate } = useI18n()

  return (
    <div className="rounded-md border-2 border-outline bg-surface-bright p-4 shadow-[2px_2px_0px_#1a1a1a]">
      <p className="font-headline text-xs font-bold uppercase tracking-wider text-on-surface">
        {t('patterns.activeTitle')}
      </p>

      {patterns.length === 0 ? (
        <p className="mt-2 text-sm text-on-surface-variant">{t('patterns.none')}</p>
      ) : (
        <ul className="mt-3 flex flex-col gap-3">
          {patterns.map((pattern) => (
            <li
              key={pattern.type}
              className="border-b border-outline-variant pb-3 last:border-b-0 last:pb-0"
            >
              <div className="flex flex-wrap items-center gap-2">
                <span className="rounded-[4px] border-2 border-dashed border-outline bg-surface-container px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface">
                  {patternLabel(pattern.type, t)}
                </span>
                <span className="font-mono text-[11px] font-bold uppercase text-on-surface">
                  {t(`patterns.status.${pattern.status}`)}
                </span>
                <span className="font-mono text-[11px] text-on-surface-variant">
                  {t('patterns.span', {
                    start: formatMarketDate(pattern.start_date),
                    end: formatMarketDate(pattern.end_date),
                  })}
                </span>
              </div>
              <dl className="mt-2 flex flex-wrap items-baseline gap-x-4 gap-y-1">
                <div className="flex items-baseline gap-1.5">
                  <dt className="font-mono text-[10px] uppercase tracking-wider text-on-surface-variant">
                    {t('patterns.breakoutLevel')}
                  </dt>
                  <dd className="font-mono text-xs font-bold text-on-surface">
                    {formatPrice(Number.isFinite(pattern.breakout_level) ? pattern.breakout_level : null)}
                  </dd>
                </div>
                {pattern.points.map((point) => (
                  <div key={`${point.role}-${point.date}`} className="flex items-baseline gap-1.5">
                    <dt className="font-mono text-[10px] uppercase tracking-wider text-on-surface-variant">
                      {t(`patterns.roles.${point.role}` as MessageKey)}
                    </dt>
                    <dd className="font-mono text-xs text-on-surface">
                      {formatPrice(point.price)} · {formatMarketDate(point.date)}
                    </dd>
                  </div>
                ))}
              </dl>
            </li>
          ))}
        </ul>
      )}
      <p className="mt-3 font-mono text-[10px] text-on-surface-variant">{t('patterns.disclaimer')}</p>
    </div>
  )
}
