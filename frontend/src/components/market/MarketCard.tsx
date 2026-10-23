import { Fragment, useId } from 'react'
import type { ReactNode } from 'react'
import { useI18n } from '../../i18n/useI18n.ts'
import type { MarketReading } from '../../lib/api.ts'
import { dateLine as readingDateLine } from './marketText.ts'

export const CARD_CLASS =
  'flex min-w-0 flex-col gap-3 rounded-md border-2 border-outline bg-surface-bright p-4 shadow-[2px_2px_0px_var(--color-shadow)]'

const CHIP_CLASS =
  'inline-flex items-center gap-1 rounded-[4px] border px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wide'

/** Text badge for a stale reading or block: never colour alone. */
export function StaleBadge() {
  const { t } = useI18n()
  return (
    <span title={t('marketContext.staleHint')} className={`${CHIP_CLASS} border-secondary text-secondary`}>
      <span aria-hidden="true">!</span>
      {t('marketContext.stale')}
    </span>
  )
}

/** Bordered mono chip (trend, inverted curve…). */
export function Chip({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <span className={`${CHIP_CLASS} border-outline ${className}`}>{children}</span>
}

/** "Todavía no hay datos" inside a card: an empty state, not an error. */
export function NoData() {
  const { t } = useI18n()
  return <p className="font-mono text-xs text-on-surface-variant">{t('marketContext.noData')}</p>
}

/**
 * One labelled reading of a macro card: the formatted value with its own date
 * line and stale badge; "No disponible · fuente no habilitada" when the series
 * is disabled (`null`) and "Sin datos" when it has no value.
 */
export function ReadingRow({
  label,
  reading,
  format,
  monthly = false,
  showDate = true,
}: {
  label: string
  reading: MarketReading | null | undefined
  format: (value: number) => string
  monthly?: boolean
  showDate?: boolean
}) {
  const i18n = useI18n()
  const { t } = i18n
  let value: ReactNode
  if (reading === null || reading === undefined) {
    value = <span className="font-mono text-[11px] text-on-surface-variant">{t('marketContext.sourceDisabled')}</span>
  } else if (reading.value === null) {
    value = <span className="font-mono text-xs text-on-surface-variant">{t('marketContext.noReading')}</span>
  } else {
    value = (
      <span className="flex flex-col items-end gap-0.5">
        <span className="font-mono text-sm font-bold text-on-surface">{format(reading.value)}</span>
        {showDate && reading.as_of !== null ? (
          <span className="flex flex-wrap items-center justify-end gap-1 font-mono text-[10px] text-on-surface-variant">
            {readingDateLine(i18n, reading.as_of, monthly)}
            {reading.stale ? <StaleBadge /> : null}
          </span>
        ) : reading.stale ? (
          <StaleBadge />
        ) : null}
      </span>
    )
  }
  return (
    <div className="flex items-start justify-between gap-3 border-b border-outline-variant py-1.5 last:border-b-0">
      <dt className="min-w-0 text-xs text-on-surface-variant">{label}</dt>
      <dd className="shrink-0 text-right">{value}</dd>
    </div>
  )
}

/**
 * Shell of every Market Context card: title, date line with the stale badge,
 * content, source list, a "¿Qué significa?" disclosure and the history slot.
 */
export default function MarketCard({
  title,
  dateLine,
  stale = false,
  sources = [],
  meaning,
  history,
  children,
  className = '',
}: {
  title: string
  dateLine?: string | null
  stale?: boolean
  sources?: string[]
  meaning: string
  history?: ReactNode
  children: ReactNode
  className?: string
}) {
  const { t } = useI18n()
  const titleId = useId()

  return (
    <section aria-labelledby={titleId} className={`${CARD_CLASS} ${className}`}>
      <header className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id={titleId} className="font-headline text-sm font-bold uppercase tracking-wide text-on-surface">
          {title}
        </h2>
        {dateLine ? (
          <p className="flex flex-wrap items-center gap-2 font-mono text-[11px] text-on-surface-variant">
            {dateLine}
            {stale ? <StaleBadge /> : null}
          </p>
        ) : null}
      </header>
      {children}
      {sources.length > 0 ? (
        <div className="border-t border-outline-variant pt-2 font-mono text-[10px] leading-4 text-on-surface-variant">
          <span className="font-bold uppercase">{t('marketContext.source')}: </span>
          {sources.map((source, index) => (
            <Fragment key={source}>
              {index > 0 ? <span aria-hidden="true"> · </span> : null}
              <span>{source}</span>
            </Fragment>
          ))}
        </div>
      ) : null}
      <details className="group text-xs text-on-surface-variant">
        <summary className="cursor-pointer font-headline text-[11px] font-bold uppercase tracking-wide text-on-surface underline-offset-4 hover:underline focus:outline-2 focus:outline-offset-2 focus:outline-outline">
          {t('marketContext.meaning')}
        </summary>
        <p className="mt-2 leading-5">{meaning}</p>
      </details>
      {history}
    </section>
  )
}
