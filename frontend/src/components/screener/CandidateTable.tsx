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

import { Link } from 'react-router'
import { COMPANY_CARD_ANCHOR } from '../chart/CompanyCardPanel.tsx'
import { useI18n } from '../../i18n/useI18n.ts'
import type { ScreenerCandidate, ScreenerFilters, ScreenerSort } from '../../lib/api.ts'
import { patternLabel, signalLabel } from '../../lib/screenerFilters.ts'
import { countryLabel, sectorLabel } from '../../lib/companyClassification.ts'
import {
  CANDIDATE_COLUMNS,
  COLUMN_FILTER_PANEL_ID,
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
}

export default function CandidateTable({ candidates, showCompanyCardLink = false, controls, currency }: CandidateTableProps) {
  const { t, formatPrice, formatChangePercent, formatMultiple, formatNumber } = useI18n()

  return (
    <div className="overflow-x-auto rounded-md border-2 border-outline bg-surface-bright shadow-[2px_2px_0px_var(--color-shadow)]">
      <table className="w-full border-collapse text-left">
        <thead>
          <tr className="border-b-2 border-outline bg-surface-container font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
            {CANDIDATE_COLUMNS.map((column) =>
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
                  {candidate.signals.length === 0 && (candidate.patterns ?? []).length === 0 ? (
                    <span className="font-mono text-xs text-on-surface-variant">—</span>
                  ) : (
                    <div className="flex flex-wrap gap-1">
                      {candidate.signals.map((signal) => (
                        <span
                          key={signal}
                          className="rounded-[4px] border border-outline bg-surface-container px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface"
                        >
                          {signalLabel(signal, t)}
                        </span>
                      ))}
                      {(candidate.patterns ?? []).map((pattern) => (
                        <span
                          key={`pattern-${pattern.type}`}
                          data-testid="candidate-pattern"
                          className={`rounded-[4px] border-2 border-dashed border-outline px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider ${
                            pattern.status === 'confirmed' ? 'bg-primary-container text-on-primary-container' : 'bg-surface-bright text-on-surface'
                          }`}
                        >
                          {patternLabel(pattern.type, t)} · {t(`patterns.status.${pattern.status}`)}
                        </span>
                      ))}
                    </div>
                  )}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
