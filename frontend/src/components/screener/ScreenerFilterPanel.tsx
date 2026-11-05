/**
 * Compact Screener criteria toolbar (screener-filter-panel-compact).
 *
 * One row of small controls that show their current value: Signals, RSI,
 * Patterns and Sector context open one inline editor card (one at a time,
 * aria-expanded/aria-controls, Esc closes and returns focus, no focus trap);
 * Min RVOL and MA cross are native selects and Price > SMA 200 a toggle.
 * Filters keep their options, URL keys and requests: every control calls
 * `onChange` with the same patch as before. Toggles/selects commit
 * immediately; the RSI inputs commit on blur/Enter and when the card closes.
 *
 * Sector context (market-context-patterns) follows the Market Context
 * access: controls for an allowed session (with a "Vista previa" chip for an
 * Admin preview), a dashed sign-in box for a Visitor once public, nothing
 * otherwise. Token-only styling (DESIGN.md).
 */

import { useRef, useState } from 'react'
import type { KeyboardEvent } from 'react'
import type { MarketContextAccess } from '../../auth/useMarketContextAccess.ts'
import type { MessageKey } from '../../i18n/translate.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import type { ScreenerFilters, ScreenerMaCross } from '../../lib/api.ts'
import { rsiSummary, rvolSelectValues, triggerText } from '../../lib/screenerToolbar.ts'
import { TREND_ICON } from '../market/marketText.ts'
import {
  PatternsEditor,
  RsiEditor,
  SectorEditor,
  SignalsEditor,
} from './ScreenerCriteriaEditors.tsx'

type CriteriaGroup = 'signals' | 'rsi' | 'patterns' | 'sector'

const CARD_ID = 'screener-criteria-card'

const CONTROL_BASE =
  'min-h-9 w-full rounded-md border-2 border-outline px-2 py-1.5 font-mono text-[10px] font-bold uppercase tracking-normal sm:px-3 sm:text-[11px] sm:tracking-wider shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none sm:w-auto'

function controlClass(active: boolean): string {
  return `${CONTROL_BASE} ${active ? 'bg-primary text-on-primary' : 'bg-surface-bright text-on-surface'}`
}

const MA_CROSS_OPTIONS: ReadonlyArray<{ value: ScreenerMaCross | null; labelKey: MessageKey }> = [
  { value: null, labelKey: 'screener.maCrossAny' },
  { value: 'bullish', labelKey: 'screener.maCrossBullish' },
  { value: 'bearish', labelKey: 'screener.maCrossBearish' },
]

interface ScreenerFilterPanelProps {
  filters: ScreenerFilters
  activeCount: number
  /** Market Context access (useMarketContextAccess); absent = no sector control. */
  access?: MarketContextAccess
  onChange: (patch: Partial<ScreenerFilters>) => void
  onClear: () => void
}

export default function ScreenerFilterPanel({
  filters,
  activeCount,
  access,
  onChange,
  onClear,
}: ScreenerFilterPanelProps) {
  const { t, formatMultiple } = useI18n()
  const [openGroup, setOpenGroup] = useState<CriteriaGroup | null>(null)
  const cardRef = useRef<HTMLDivElement>(null)
  const triggerRefs = useRef<Partial<Record<CriteriaGroup, HTMLButtonElement | null>>>({})

  const showSector = access?.status === 'allowed' || access?.status === 'signIn'
  // A group that is no longer drawn (e.g. the universe changed) cannot stay open.
  const openNow = openGroup === 'sector' && !showSector ? null : openGroup

  // Blur whatever has focus inside the card before it unmounts so a typed RSI
  // draft is committed (blur handler) and never lost.
  function commitCardDraft() {
    const active = document.activeElement
    if (active instanceof HTMLElement && cardRef.current?.contains(active)) {
      active.blur()
    }
  }

  function toggleGroup(group: CriteriaGroup) {
    commitCardDraft()
    setOpenGroup(openNow === group ? null : group)
  }

  function handleCardKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    if (event.key !== 'Escape' || openNow === null) {
      return
    }
    event.stopPropagation()
    const group = openNow
    commitCardDraft()
    setOpenGroup(null)
    triggerRefs.current[group]?.focus()
  }

  const rsiValue = rsiSummary(filters.rsiMin, filters.rsiMax)
  const sectorParts: string[] = []
  if (filters.sectorTrend !== null) {
    sectorParts.push(`${TREND_ICON[filters.sectorTrend]} ${t(`marketContext.trend.${filters.sectorTrend}`)}`)
  }
  if (filters.sectorRsRankMax !== null) {
    sectorParts.push(t('screener.sectorContext.rankTop', { n: filters.sectorRsRankMax }))
  }
  const patternsValue =
    filters.patterns.length === 0
      ? null
      : filters.patternStatus === 'any'
        ? filters.patterns.length
        : `${filters.patterns.length} · ${t(`patterns.status.${filters.patternStatus}`)}`

  const triggers: ReadonlyArray<{
    group: CriteriaGroup
    text: string
    active: boolean
  }> = [
    {
      group: 'signals',
      text: triggerText(t('screener.toolbar.signals'), filters.signals.length > 0 ? filters.signals.length : null),
      active: filters.signals.length > 0,
    },
    { group: 'rsi', text: triggerText('RSI', rsiValue), active: rsiValue !== null },
    { group: 'patterns', text: triggerText(t('screener.toolbar.patterns'), patternsValue), active: filters.patterns.length > 0 },
    ...(showSector
      ? [
          {
            group: 'sector' as const,
            text: triggerText(t('screener.toolbar.sector'), sectorParts.length > 0 ? sectorParts.join(' · ') : null),
            active: sectorParts.length > 0,
          },
        ]
      : []),
  ]

  function renderTrigger(group: CriteriaGroup) {
    const item = triggers.find((entry) => entry.group === group)
    if (!item) {
      return null
    }
    const open = openNow === group
    return (
      <button
        key={group}
        ref={(node) => {
          triggerRefs.current[group] = node
        }}
        id={`screener-criteria-trigger-${group}`}
        type="button"
        aria-expanded={open}
        aria-controls={CARD_ID}
        onClick={() => toggleGroup(group)}
        className={`${controlClass(item.active)} flex items-center justify-between gap-2 text-left`}
      >
        <span>{item.text}</span>
        <span aria-hidden="true" className={`text-[9px] transition-transform ${open ? 'rotate-180' : ''}`}>
          ▼
        </span>
      </button>
    )
  }

  const rvolValues = rvolSelectValues(filters.minRvol)

  return (
    <section className="rounded-md border-2 border-outline bg-surface-bright p-3 shadow-[2px_2px_0px_var(--color-shadow)] sm:p-4">
      <header className="flex flex-wrap items-center gap-x-3 gap-y-1">
        <h2 className="order-1 font-headline text-sm font-black uppercase tracking-wide text-on-surface sm:text-base">
          {t('screener.filtersTitle')}
        </h2>
        <button
          type="button"
          onClick={onClear}
          disabled={activeCount === 0}
          title={t('screener.filtersInUrl')}
          className="order-2 ml-auto rounded-md border-2 border-outline bg-surface-bright px-2 py-1 font-headline text-[11px] font-bold sm:px-3 sm:text-xs uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60 sm:order-3 sm:ml-0"
        >
          {t('screener.clearFilters')}
        </button>
        <p className="order-3 basis-full font-mono text-[10px] text-on-surface-variant sm:order-2 sm:flex-1 sm:basis-auto sm:text-[11px]">
          <span>{t('screener.filtersHint')}</span>{' '}
          <span className="font-bold uppercase tracking-wider sm:ml-3">
            {activeCount > 0
              ? t('screener.activeFilters', { count: activeCount })
              : t('screener.noActiveFilters')}
          </span>
        </p>
      </header>

      <div className="mt-3 grid grid-cols-2 items-center gap-2 sm:flex sm:flex-wrap">
        {renderTrigger('signals')}
        {renderTrigger('rsi')}
        <select
          aria-label={t('screener.minRvolLegend')}
          value={filters.minRvol === null ? '' : String(filters.minRvol)}
          onChange={(event) => onChange({ minRvol: event.target.value === '' ? null : Number(event.target.value) })}
          className={controlClass(filters.minRvol !== null)}
        >
          <option value="">{t('screener.toolbar.rvolAll')}</option>
          {rvolValues.map((value) => (
            <option key={value} value={String(value)}>
              {t('screener.toolbar.rvolMin', { value: formatMultiple(value) })}
            </option>
          ))}
        </select>
        <button
          type="button"
          aria-pressed={filters.priceAboveSma200}
          onClick={() => onChange({ priceAboveSma200: !filters.priceAboveSma200 })}
          className={controlClass(filters.priceAboveSma200)}
        >
          {t('screener.priceAboveSma200')}
        </button>
        <select
          aria-label={t('screener.maCrossAria')}
          value={filters.maCross ?? ''}
          onChange={(event) => onChange({ maCross: event.target.value === '' ? null : (event.target.value as ScreenerMaCross) })}
          className={controlClass(filters.maCross !== null)}
        >
          {MA_CROSS_OPTIONS.map((option) => (
            <option key={option.labelKey} value={option.value ?? ''}>
              {t('screener.toolbar.maCross', { value: t(option.labelKey) })}
            </option>
          ))}
        </select>
        {renderTrigger('patterns')}
        {showSector ? renderTrigger('sector') : null}
      </div>

      {openNow !== null ? (
        <div
          ref={cardRef}
          id={CARD_ID}
          role="group"
          aria-labelledby={`screener-criteria-trigger-${openNow}`}
          onKeyDown={handleCardKeyDown}
          className="mt-3 rounded-md border-2 border-outline bg-surface-container p-3 shadow-[2px_2px_0px_var(--color-shadow)]"
        >
          {openNow === 'signals' ? <SignalsEditor filters={filters} onChange={onChange} /> : null}
          {openNow === 'rsi' ? <RsiEditor filters={filters} onChange={onChange} /> : null}
          {openNow === 'patterns' ? <PatternsEditor filters={filters} onChange={onChange} /> : null}
          {openNow === 'sector' && access ? <SectorEditor filters={filters} access={access} onChange={onChange} /> : null}
        </div>
      ) : null}
    </section>
  )
}
