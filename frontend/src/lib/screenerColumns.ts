/**
 * Candidate table column controls (screener-table-column-controls): which
 * sort orders and which filter belong to each displayed column. Pure data and
 * helpers, no React. Every control only patches the URL-owned filter state;
 * filtering and ordering stay server-side.
 */

import type { ScreenerFilters, ScreenerSort } from './api.ts'
import type { MessageKey, Translate } from '../i18n/translate.ts'
import { describeColumnCriteria, describeRange } from './screenerFilters.ts'
import type { NumericColumn } from './screenerFilters.ts'

export type CandidateColumnId =
  | 'symbol'
  | 'sector'
  | 'industry'
  | 'country'
  | 'close'
  | 'change'
  | 'rvol'
  | 'rsi'
  | 'signals'

export type ColumnFilterKind = 'search' | 'sector' | 'industry' | 'country' | 'range' | 'signals'

type RangeField = 'rsiMin' | 'rsiMax' | 'minRvol' | 'rvolMax' | 'closeMin' | 'closeMax' | 'changeMin' | 'changeMax'

export interface ColumnRange {
  bounds: NumericColumn
  min: RangeField
  max: RangeField
}

export interface CandidateColumn {
  id: CandidateColumnId
  labelKey: MessageKey
  align: 'left' | 'right'
  asc: ScreenerSort
  desc: ScreenerSort
  /** Direction of the first activation: text A–Z, figures highest first. */
  first: 'asc' | 'desc'
  filter: ColumnFilterKind
  range?: ColumnRange
}

/** The nine displayed columns, in table order. */
export const CANDIDATE_COLUMNS: readonly CandidateColumn[] = [
  { id: 'symbol', labelKey: 'screener.colSymbol', align: 'left', asc: 'company_asc', desc: 'company_desc', first: 'asc', filter: 'search' },
  { id: 'sector', labelKey: 'screener.classificationSector', align: 'left', asc: 'sector_asc', desc: 'sector_desc', first: 'asc', filter: 'sector' },
  { id: 'industry', labelKey: 'screener.classificationIndustry', align: 'left', asc: 'industry_asc', desc: 'industry_desc', first: 'asc', filter: 'industry' },
  { id: 'country', labelKey: 'screener.classificationCountry', align: 'left', asc: 'country_asc', desc: 'country_desc', first: 'asc', filter: 'country' },
  {
    id: 'close', labelKey: 'screener.colClose', align: 'right', asc: 'close_asc', desc: 'close_desc', first: 'desc', filter: 'range',
    range: { bounds: 'close', min: 'closeMin', max: 'closeMax' },
  },
  {
    id: 'change', labelKey: 'screener.colChange', align: 'right', asc: 'change_asc', desc: 'change_desc', first: 'desc', filter: 'range',
    range: { bounds: 'change', min: 'changeMin', max: 'changeMax' },
  },
  {
    id: 'rvol', labelKey: 'screener.colRvol', align: 'right', asc: 'rvol_asc', desc: 'rvol_desc', first: 'desc', filter: 'range',
    range: { bounds: 'rvol', min: 'minRvol', max: 'rvolMax' },
  },
  {
    id: 'rsi', labelKey: 'screener.colRsi', align: 'right', asc: 'rsi_asc', desc: 'rsi_desc', first: 'desc', filter: 'range',
    range: { bounds: 'rsi', min: 'rsiMin', max: 'rsiMax' },
  },
  { id: 'signals', labelKey: 'screener.colSignals', align: 'left', asc: 'signal_count_asc', desc: 'signal_count_desc', first: 'desc', filter: 'signals' },
]

/**
 * The heading of a column. Close carries its currency ("Cierre (EUR)") once
 * the universe's currency is known, so a price bound is never ambiguous (D8).
 */
export function columnLabel(column: CandidateColumn, t: Translate, currency?: string | null): string {
  if (column.id === 'close' && currency) {
    return t('screener.colCloseCurrency', { currency })
  }
  return t(column.labelKey)
}

export function candidateColumn(id: CandidateColumnId): CandidateColumn {
  return CANDIDATE_COLUMNS.find((column) => column.id === id) ?? CANDIDATE_COLUMNS[0]
}

/** The direction this column is currently sorted in, or `null`. */
export function columnSortDirection(column: CandidateColumn, sort: ScreenerSort): 'asc' | 'desc' | null {
  if (sort === column.asc) {
    return 'asc'
  }
  return sort === column.desc ? 'desc' : null
}

/** First activation uses the column's natural direction; next ones flip it. */
export function nextColumnSort(column: CandidateColumn, sort: ScreenerSort): ScreenerSort {
  const current = columnSortDirection(column, sort)
  if (current === null) {
    return column.first === 'asc' ? column.asc : column.desc
  }
  return current === 'asc' ? column.desc : column.asc
}

/** The patch that clears only this column's criteria. */
export function clearColumnPatch(column: CandidateColumn): Partial<ScreenerFilters> {
  switch (column.filter) {
    case 'search':
      return { search: null }
    case 'sector':
      return { sector: null }
    case 'industry':
      return { industry: null }
    case 'country':
      return { country: null }
    case 'signals':
      return { signals: [], patterns: [], patternStatus: 'any' }
    case 'range':
      return column.range ? { [column.range.min]: null, [column.range.max]: null } : {}
  }
}

/** Whether this column has an active criterion. */
export function isColumnFiltered(column: CandidateColumn, filters: ScreenerFilters): boolean {
  switch (column.filter) {
    case 'search':
      return filters.search !== null
    case 'sector':
      return filters.sector !== null
    case 'industry':
      return filters.industry !== null
    case 'country':
      return filters.country !== null
    case 'signals':
      return filters.signals.length > 0 || filters.patterns.length > 0
    case 'range':
      return column.range !== undefined && (filters[column.range.min] !== null || filters[column.range.max] !== null)
  }
}

/** Localized one-line summary of this column's active criterion, or `null`. */
export function columnFilterSummary(
  column: CandidateColumn,
  filters: ScreenerFilters,
  t: Translate,
  formatValue: (value: number) => string,
  currency?: string | null,
): string | null {
  if (!isColumnFiltered(column, filters)) {
    return null
  }
  switch (column.filter) {
    case 'signals':
      return t('screener.columns.signalsSummary', { count: filters.signals.length + filters.patterns.length })
    case 'range':
      return column.range
        ? describeRange(columnLabel(column, t, currency), filters[column.range.min], filters[column.range.max], t, formatValue)
        : null
    case 'search':
      return describeColumnCriteria({ search: filters.search }, t, formatValue)[0] ?? null
    case 'sector':
      return describeColumnCriteria({ sector: filters.sector }, t, formatValue)[0] ?? null
    case 'industry':
      return describeColumnCriteria({ industry: filters.industry }, t, formatValue)[0] ?? null
    case 'country':
      return describeColumnCriteria({ country: filters.country }, t, formatValue)[0] ?? null
  }
}

/** DOM id of the shared column filter editor (target of `aria-controls`). */
export const COLUMN_FILTER_PANEL_ID = 'screener-column-filter'

/** DOM id of a column's filter toggle in the table header. */
export function columnFilterToggleId(id: CandidateColumnId): string {
  return `screener-column-filter-toggle-${id}`
}
