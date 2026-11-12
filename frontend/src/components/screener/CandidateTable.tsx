/**
 * Fixed-column Candidate table rendered in the server's order. The row
 * interactive elements are the ticker link into the instrument chart and,
 * only when `showCompanyCardLink` is true (signed-in users, decided by
 * `CandidateResults`), a small "AI card" link to the same chart's Company Card
 * panel (`#ai-company-card`). Both only navigate; the table itself stays
 * presentational (no auth read) and nothing is generated from here. There are
 * no watchlist actions. Styling is DESIGN.md tokens only.
 *
 * With `controls` (screener-table-column-controls) every heading gets a sort
 * button (direction shown with an arrow and `aria-sort`) and a filter toggle
 * that opens the shared column filter editor. Both only report the intent:
 * the page patches the URL and the API returns the filtered, ordered page;
 * the table never narrows or reorders rows itself.
 */

import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { Link } from 'react-router'
import { COMPANY_CARD_ANCHOR } from '../chart/CompanyCardPanel.tsx'
import { useI18n } from '../../i18n/useI18n.ts'
import type { ScreenerCandidate, ScreenerFilters, ScreenerSort } from '../../lib/api.ts'
import { patternLabel, signalLabel } from '../../lib/screenerFilters.ts'
import { countryLabel, sectorLabel } from '../../lib/companyClassification.ts'
import {
  CANDIDATE_COLUMNS,
  COLUMN_FILTER_PANEL_ID,
  INDICATOR_COLUMNS,
  columnLabel,
  columnFilterToggleId,
  columnSortDirection,
  isColumnFiltered,
  nextColumnSort,
} from '../../lib/screenerColumns.ts'
import type { CandidateColumn, CandidateColumnId } from '../../lib/screenerColumns.ts'

export interface CandidateTableControls {
  filters: ScreenerFilters
  /** The column whose filter editor is open, if any. */
  openColumn: CandidateColumnId | null
  onSort: (sort: ScreenerSort) => void
  onToggleFilter: (column: CandidateColumnId) => void
}

const SORT_ARROW = { asc: '▲', desc: '▼' } as const

function FunnelIcon() {
  return (
    <svg aria-hidden="true" viewBox="0 0 12 12" className="h-3 w-3" fill="none" stroke="currentColor" strokeWidth="1.5">
      <path d="M1.5 2h9L7 6.5V10L5 9V6.5z" strokeLinejoin="round" />
    </svg>
  )
}

function ControlledHeader({
  column,
  controls,
  currency,
}: {
  column: CandidateColumn
  controls: CandidateTableControls
  currency?: string | null
}) {
  const { t } = useI18n()
  const label = columnLabel(column, t, currency)
  const direction = columnSortDirection(column, controls.filters.sort)
  const filtered = isColumnFiltered(column, controls.filters)
  const open = controls.openColumn === column.id

  return (
    <th
      scope="col"
      aria-sort={direction === 'asc' ? 'ascending' : direction === 'desc' ? 'descending' : 'none'}
      className="px-2 py-1.5"
    >
      <div className={`flex items-center gap-1 whitespace-nowrap ${column.align === 'right' ? 'justify-end' : ''}`}>
        <button
          type="button"
          onClick={() => controls.onSort(nextColumnSort(column, controls.filters.sort))}
          aria-label={
            direction === null
              ? t('screener.columns.sortBy', { column: label })
              : `${t('screener.columns.sortBy', { column: label })}, ${t(direction === 'asc' ? 'screener.columns.sortedAsc' : 'screener.columns.sortedDesc')}`
          }
          className={`inline-flex items-center gap-1 rounded-[4px] border-2 px-1.5 py-1 font-mono text-[10px] font-bold uppercase tracking-wider focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none ${
            direction === null
              ? 'border-transparent text-on-surface-variant hover:border-outline-variant hover:text-on-surface'
              : 'border-outline bg-surface-bright text-on-surface'
          }`}
        >
          <span>{label}</span>
          <span aria-hidden="true" className={direction === null ? 'text-outline-variant' : ''}>
            {direction === null ? '↕' : SORT_ARROW[direction]}
          </span>
        </button>
        <button
          type="button"
          id={columnFilterToggleId(column.id)}
          onClick={() => controls.onToggleFilter(column.id)}
          aria-expanded={open}
          aria-controls={open ? COLUMN_FILTER_PANEL_ID : undefined}
          aria-label={t(filtered ? 'screener.columns.filterButtonActive' : 'screener.columns.filterButton', { column: label })}
          className={`inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-[4px] border-2 focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none ${
            filtered
              ? 'border-outline bg-primary-container text-on-primary-container shadow-[1px_1px_0px_var(--color-shadow)]'
              : open
                ? 'border-outline bg-surface-bright text-on-surface'
                : 'border-outline-variant bg-surface-container text-on-surface-variant hover:text-on-surface'
          }`}
        >
          <FunnelIcon />
        </button>
      </div>
    </th>
  )
}

/** Sign + color for the change column; the sign is the non-color cue. */
function changeColorClass(value: number | null): string {
  if (value === null || value === 0) {
    return 'text-on-surface-variant'
  }
  return value > 0 ? 'text-gain' : 'text-secondary'
}

interface CandidateTableProps {
  candidates: ScreenerCandidate[]
  /** Show the per-row "AI card" link; default `false` (Visitors). */
  showCompanyCardLink?: boolean
  /** Per-heading sort and filter controls; absent = plain headings. */
  controls?: CandidateTableControls
  /** Currency of the universe (ISO code), shown in the Close heading. */
  currency?: string | null
  /** Append the optional Indicators columns (ADX, %B, ATR %, Stoch %K); default off. */
  showIndicators?: boolean
}

/** Decimals shown for each Indicators column. */
const INDICATOR_DIGITS: Record<string, number> = { adx: 1, bbPctb: 2, atrPct: 2, stochK: 1 }

function indicatorValue(candidate: ScreenerCandidate, id: string): number | null {
  switch (id) {
    case 'adx':
      return candidate.adx ?? null
    case 'bbPctb':
      return candidate.bb_pctb ?? null
    case 'atrPct':
      return candidate.atr_pct ?? null
    default:
      return candidate.stoch_k ?? null
  }
}

const CHIP_CLASS =
  'whitespace-nowrap rounded-[4px] border border-outline bg-surface-container px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface'

function SignalChip({ signal }: { signal: ScreenerCandidate['signals'][number] }) {
  const { t } = useI18n()
  return <span className={CHIP_CLASS}>{signalLabel(signal, t)}</span>
}

function PatternChip({ pattern }: { pattern: NonNullable<ScreenerCandidate['patterns']>[number] }) {
  const { t } = useI18n()
  return (
    <span
      data-testid="candidate-pattern"
      className={`whitespace-nowrap rounded-[4px] border-2 border-dashed border-outline px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider ${
        pattern.status === 'confirmed' ? 'bg-primary-container text-on-primary-container' : 'bg-surface-bright text-on-surface'
      }`}
    >
      {patternLabel(pattern.type, t)} · {t(`patterns.status.${pattern.status}`)}
    </span>
  )
}

const PANEL_MARGIN = 8
const PANEL_MAX_WIDTH = 320

/**
 * Signals + chartist patterns of one row, always one line high: "—" for none,
 * the chip itself for one, and a single trigger for two or more that opens a
 * popover. The popover is portalled to <body> with fixed positioning because
 * the table wrapper's overflow-x-auto would clip an absolute child; it is
 * placed from the trigger rect (flipping above near the viewport bottom,
 * clamped horizontally) and closes on Escape, outside press, scroll or resize.
 */
function SignalsCell({
  candidate,
  open,
  onOpenChange,
}: {
  candidate: ScreenerCandidate
  open: boolean
  onOpenChange: (open: boolean) => void
}) {
  const { t } = useI18n()
  const panelId = useId()
  const triggerRef = useRef<HTMLButtonElement>(null)
  const panelRef = useRef<HTMLDivElement>(null)
  const [position, setPosition] = useState<{ top: number; left: number; width: number } | null>(null)
  const patterns = candidate.patterns ?? []
  const count = candidate.signals.length + patterns.length
  const onOpenChangeRef = useRef(onOpenChange)
  useEffect(() => {
    onOpenChangeRef.current = onOpenChange
  })
  const close = useCallback(() => onOpenChangeRef.current(false), [])

  useLayoutEffect(() => {
    if (!open || !triggerRef.current || !panelRef.current) {
      return
    }
    const trigger = triggerRef.current.getBoundingClientRect()
    const panelHeight = panelRef.current.offsetHeight
    const width = Math.min(PANEL_MAX_WIDTH, document.documentElement.clientWidth - PANEL_MARGIN * 2)
    const left = Math.max(PANEL_MARGIN, Math.min(trigger.left, document.documentElement.clientWidth - width - PANEL_MARGIN))
    const fitsBelow = trigger.bottom + 4 + panelHeight <= window.innerHeight - PANEL_MARGIN
    const above = trigger.top - 4 - panelHeight
    setPosition({ top: fitsBelow || above < PANEL_MARGIN ? trigger.bottom + 4 : above, left, width })
  }, [open, count])

  useEffect(() => {
    if (!open) {
      return
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        close()
        triggerRef.current?.focus()
      }
    }
    const onPointerDown = (event: Event) => {
      const target = event.target as Node
      if (!panelRef.current?.contains(target) && !triggerRef.current?.contains(target)) {
        close()
      }
    }
    const onScroll = (event: Event) => {
      if (!(event.target instanceof Node) || !panelRef.current?.contains(event.target)) {
        close()
      }
    }
    document.addEventListener('keydown', onKeyDown)
    document.addEventListener('pointerdown', onPointerDown)
    window.addEventListener('scroll', onScroll, true)
    window.addEventListener('resize', close)
    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.removeEventListener('pointerdown', onPointerDown)
      window.removeEventListener('scroll', onScroll, true)
      window.removeEventListener('resize', close)
      setPosition(null)
    }
  }, [open, close])

  if (count === 0) {
    return <span className="font-mono text-xs text-on-surface-variant">—</span>
  }
  if (count === 1) {
    return candidate.signals.length === 1 ? <SignalChip signal={candidate.signals[0]} /> : <PatternChip pattern={patterns[0]} />
  }

  return (
    <>
      <button
        ref={triggerRef}
        type="button"
        data-testid="candidate-signals-trigger"
        aria-expanded={open}
        aria-controls={open ? panelId : undefined}
        aria-haspopup="true"
        onClick={() => onOpenChange(!open)}
        className={`inline-flex items-center gap-1 whitespace-nowrap rounded-[4px] border-2 border-outline px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none ${
          open ? 'bg-surface-bright' : 'bg-surface-container hover:bg-surface-bright'
        }`}
      >
        <span>{t('screener.signalsCell.trigger', { count })}</span>
        <span aria-hidden="true">{open ? '▴' : '▾'}</span>
      </button>
      {open
        ? createPortal(
            <div
              ref={panelRef}
              id={panelId}
              role="group"
              aria-label={t('screener.signalsCell.panel', { ticker: candidate.ticker })}
              data-testid="candidate-signals-panel"
              style={{
                position: 'fixed',
                top: position?.top ?? 0,
                left: position?.left ?? 0,
                width: position?.width ?? PANEL_MAX_WIDTH,
                visibility: position ? 'visible' : 'hidden',
              }}
              className="z-50 flex max-h-64 flex-wrap gap-1 overflow-y-auto rounded-md border-2 border-outline bg-surface-bright p-2 shadow-[4px_4px_0px_var(--color-shadow)]"
            >
              {candidate.signals.map((signal) => (
                <SignalChip key={signal} signal={signal} />
              ))}
              {patterns.map((pattern) => (
                <PatternChip key={`pattern-${pattern.type}`} pattern={pattern} />
              ))}
            </div>,
            document.body,
          )
        : null}
    </>
  )
}

export default function CandidateTable({
  candidates,
  showCompanyCardLink = false,
  controls,
  currency,
  showIndicators = false,
}: CandidateTableProps) {
  const { t, formatPrice, formatChangePercent, formatMultiple, formatNumber } = useI18n()

  // Only one signals panel is open at a time (keyed by ticker).
  const [openSignals, setOpenSignals] = useState<string | null>(null)

  const columns = showIndicators ? [...CANDIDATE_COLUMNS, ...INDICATOR_COLUMNS] : CANDIDATE_COLUMNS

  return (
    <div className="overflow-x-auto rounded-md border-2 border-outline bg-surface-bright shadow-[2px_2px_0px_var(--color-shadow)]">
      <table className="w-full border-collapse text-left">
        <thead>
          <tr className="border-b-2 border-outline bg-surface-container font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
            {columns.map((column) =>
              controls ? (
                <ControlledHeader key={column.id} column={column} controls={controls} currency={currency} />
              ) : (
                <th key={column.id} scope="col" className={`px-4 py-2 ${column.align === 'right' ? 'text-right' : ''}`}>
                  {columnLabel(column, t, currency)}
                </th>
              ),
            )}
          </tr>
        </thead>
        <tbody>
          {candidates.map((candidate) => {
            const accentRvol = candidate.rvol !== null && candidate.rvol >= 2

            return (
              <tr key={candidate.ticker} className="border-b border-outline-variant align-top">
                <th scope="row" className="min-w-44 px-4 py-2 text-left font-normal">
                  <div className="flex flex-wrap items-center gap-2">
                    <Link
                      to={`/instruments/${encodeURIComponent(candidate.ticker)}`}
                      className="font-headline text-sm font-black text-on-surface underline-offset-2 hover:underline focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
                    >
                      {candidate.ticker}
                    </Link>
                    <span className="rounded-[4px] border border-outline bg-surface-container px-1 py-0.5 font-mono text-[10px] font-bold text-on-surface-variant">
                      {candidate.exchange}
                    </span>
                  </div>
                  <p className="max-w-56 truncate text-xs text-on-surface-variant">
                    {candidate.company}
                  </p>
                  {showCompanyCardLink ? (
                    <Link
                      to={`/instruments/${encodeURIComponent(candidate.ticker)}#${COMPANY_CARD_ANCHOR}`}
                      aria-label={t('companyCard.candidateLinkAria', { ticker: candidate.ticker })}
                      className="font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant underline underline-offset-2 hover:text-on-surface focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
                    >
                      {t('companyCard.candidateLink')}
                    </Link>
                  ) : null}
                </th>

                <td className="min-w-36 px-4 py-2 text-xs text-on-surface-variant">
                  {sectorLabel(candidate.sector, t)}
                </td>
                <td className="min-w-44 max-w-64 break-words px-4 py-2 text-xs text-on-surface-variant">
                  {candidate.industry || t('screener.classificationUnknown')}
                </td>
                <td className="min-w-28 px-4 py-2 text-xs text-on-surface-variant">
                  {countryLabel(candidate.country, t)}
                </td>

                <td className="px-4 py-2 text-right font-mono text-xs text-on-surface">
                  {formatPrice(candidate.close)}
                </td>

                <td
                  className={`px-4 py-2 text-right font-mono text-xs font-bold ${changeColorClass(
                    candidate.change_percent,
                  )}`}
                >
                  {formatChangePercent(candidate.change_percent)}
                </td>

                <td className="px-4 py-2 text-right">
                  {accentRvol ? (
                    <span className="inline-flex rounded-[4px] border-2 border-outline bg-primary-container px-1.5 py-0.5 font-mono text-xs font-black text-on-primary-container shadow-[1px_1px_0px_var(--color-shadow)]">
                      {formatMultiple(candidate.rvol)}
                    </span>
                  ) : (
                    <span className="font-mono text-xs text-on-surface">
                      {formatMultiple(candidate.rvol)}
                    </span>
                  )}
                </td>

                <td className="px-4 py-2 text-right font-mono text-xs text-on-surface">
                  {formatNumber(candidate.rsi14, 1)}
                </td>

                <td className="px-4 py-2">
                  <SignalsCell
                    candidate={candidate}
                    open={openSignals === candidate.ticker}
                    onOpenChange={(next) => setOpenSignals(next ? candidate.ticker : null)}
                  />
                </td>

                {showIndicators
                  ? INDICATOR_COLUMNS.map((column) => (
                      <td
                        key={column.id}
                        data-testid={`candidate-${column.id}`}
                        className="px-4 py-2 text-right font-mono text-xs text-on-surface"
                      >
                        {formatNumber(indicatorValue(candidate, column.id), INDICATOR_DIGITS[column.id])}
                      </td>
                    ))
                  : null}
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
