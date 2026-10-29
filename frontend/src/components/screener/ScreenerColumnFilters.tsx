/**
 * Column filter strip above the Candidate table (screener-table-column-controls).
 *
 * It shows one chip per column with an active criterion (edit / remove) and,
 * when a heading's filter toggle is open, the editor for that column: a text
 * fragment (Symbol/Company, Industry), a canonical select (Sector, HQ
 * country), inclusive numeric bounds (Close, Change, RVOL, RSI) or the
 * existing signal/pattern selection (Signals). It lives outside the scrolling
 * table, so it is never clipped on narrow screens and stays reachable when a
 * combination returns no Candidates.
 *
 * Text and number drafts commit on "Aplicar" (Enter); selects and chips commit
 * immediately, like the filter panel. Every commit is an ordinary URL patch:
 * the API filters across the full Candidate set before paging.
 */

import { useEffect, useRef, useState } from 'react'
import type { FormEvent, KeyboardEvent } from 'react'
import type { MessageKey } from '../../i18n/translate.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import type { ScreenerFilters, ScreenerPatternStatus, ScreenerPatternType, ScreenerSignalType } from '../../lib/api.ts'
import { CLASSIFICATION_COUNTRIES, countryLabel, sectorLabel } from '../../lib/companyClassification.ts'
import {
  ALL_CANDIDATE_COLUMNS,
  COLUMN_FILTER_PANEL_ID,
  candidateColumn,
  clearColumnPatch,
  columnRanges,
  columnFilterSummary,
  columnLabel,
  columnFilterToggleId,
  isColumnFiltered,
} from '../../lib/screenerColumns.ts'
import type { CandidateColumn, CandidateColumnId } from '../../lib/screenerColumns.ts'
import {
  INDUSTRY_MAX_LENGTH,
  NUMERIC_BOUNDS,
  SCREENER_PATTERN_GROUPS,
  SCREENER_PATTERN_STATUSES,
  SCREENER_SECTORS,
  SCREENER_SIGNAL_TYPES,
  SEARCH_MAX_LENGTH,
  patternLabel,
  signalLabel,
} from '../../lib/screenerFilters.ts'

const LABEL_CLASS = 'font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant'

const INPUT_CLASS =
  'rounded-md border-2 border-outline bg-surface-bright px-3 py-2 font-mono text-sm text-on-surface shadow-[2px_2px_0px_var(--color-shadow)] focus:border-outline focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none aria-[invalid=true]:border-secondary'

const PRIMARY_BUTTON =
  'rounded-md border-2 border-outline bg-primary-container px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none'

const SECONDARY_BUTTON =
  'rounded-md border-2 border-outline bg-surface-bright px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60'

const CHIP_BASE =
  'rounded-[4px] border-2 px-2.5 py-1 font-mono text-[11px] font-bold uppercase tracking-wider transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none'

function chipClass(selected: boolean): string {
  return selected
    ? `${CHIP_BASE} border-outline bg-primary text-on-primary shadow-[1px_1px_0px_#ffcc00]`
    : `${CHIP_BASE} border-outline-variant bg-surface-container text-on-surface-variant`
}

const PATTERN_STATUS_LABELS: Record<ScreenerPatternStatus, MessageKey> = {
  any: 'patterns.status.any',
  forming: 'patterns.status.forming',
  confirmed: 'patterns.status.confirmed',
}

interface ScreenerColumnFiltersProps {
  filters: ScreenerFilters
  openColumn: CandidateColumnId | null
  onChange: (patch: Partial<ScreenerFilters>) => void
  onOpen: (column: CandidateColumnId) => void
  onClose: () => void
  /** Currency of the screened universe, for the Close heading and bounds. */
  currency?: string | null
}

/** Return focus to a heading's filter toggle when it is still rendered. */
function focusToggle(id: CandidateColumnId) {
  document.getElementById(columnFilterToggleId(id))?.focus()
}

export default function ScreenerColumnFilters({
  filters,
  openColumn,
  onChange,
  onOpen,
  onClose,
  currency,
}: ScreenerColumnFiltersProps) {
  const { t, formatDecimal } = useI18n()
  const formatValue = (value: number) => formatDecimal(value)
  const active = ALL_CANDIDATE_COLUMNS.filter((column) => isColumnFiltered(column, filters))

  if (active.length === 0 && openColumn === null) {
    return null
  }

  function close(id: CandidateColumnId) {
    onClose()
    focusToggle(id)
  }

  return (
    <div className="flex flex-col gap-3">
      {active.length > 0 ? (
        <div role="group" aria-label={t('screener.columns.activeTitle')} className="flex flex-wrap items-center gap-2">
          <span className={LABEL_CLASS}>{t('screener.columns.activeTitle')}</span>
          {active.map((column) => {
            const summary = columnFilterSummary(column, filters, t, formatValue, currency) ?? columnLabel(column, t, currency)
            return (
              <span
                key={column.id}
                className="inline-flex items-stretch overflow-hidden rounded-[4px] border-2 border-outline bg-primary-container text-on-primary-container shadow-[1px_1px_0px_var(--color-shadow)]"
              >
                <button
                  type="button"
                  onClick={() => onOpen(column.id)}
                  aria-label={t('screener.columns.editChip', { label: summary })}
                  className="px-2 py-1 font-mono text-[11px] font-bold focus:outline-2 focus:-outline-offset-4 focus:outline-outline"
                >
                  {summary}
                </button>
                <button
                  type="button"
                  onClick={() => onChange(clearColumnPatch(column))}
                  aria-label={t('screener.columns.removeChip', { label: summary })}
                  className="border-l-2 border-outline px-2 font-mono text-xs font-black focus:outline-2 focus:-outline-offset-4 focus:outline-outline"
                >
                  <span aria-hidden="true">×</span>
                </button>
              </span>
            )
          })}
        </div>
      ) : null}

      {openColumn !== null ? (
        <ColumnEditor
          // A new column, or the criterion changing from outside (chip
          // removal, back/forward), resets the drafts.
          key={`${openColumn}|${JSON.stringify(pickColumnState(candidateColumn(openColumn), filters))}`}
          column={candidateColumn(openColumn)}
          filters={filters}
          currency={currency}
          onChange={onChange}
          onClose={() => close(openColumn)}
        />
      ) : null}
    </div>
  )
}

/** The current committed values of one column (the editor's reset key). */
function pickColumnState(column: CandidateColumn, filters: ScreenerFilters): unknown {
  switch (column.filter) {
    case 'search':
      return filters.search
    case 'sector':
      return filters.sector
    case 'industry':
      return filters.industry
    case 'country':
      return filters.country
    case 'signals':
      return null
    case 'range':
      return columnRanges(column).map((range) => [filters[range.min], filters[range.max]])
  }
}

interface ColumnEditorProps {
  column: CandidateColumn
  filters: ScreenerFilters
  currency?: string | null
  onChange: (patch: Partial<ScreenerFilters>) => void
  onClose: () => void
}

function ColumnEditor({ column, filters, currency, onChange, onClose }: ColumnEditorProps) {
  const { t } = useI18n()
  const label = columnLabel(column, t, currency)
  const headingId = `${COLUMN_FILTER_PANEL_ID}-title`
  const panelRef = useRef<HTMLElement | null>(null)

  // Opening moves focus into the editor (its first field).
  useEffect(() => {
    panelRef.current?.querySelector<HTMLElement>('input, select, button[aria-pressed]')?.focus()
  }, [])

  function handleKeyDown(event: KeyboardEvent<HTMLElement>) {
    if (event.key === 'Escape') {
      event.stopPropagation()
      onClose()
    }
  }

  function clear() {
    onChange(clearColumnPatch(column))
    onClose()
  }

  return (
    <section
      ref={panelRef}
      id={COLUMN_FILTER_PANEL_ID}
      aria-labelledby={headingId}
      onKeyDown={handleKeyDown}
      className="rounded-md border-2 border-outline bg-surface-bright p-4 shadow-[2px_2px_0px_var(--color-shadow)]"
    >
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 id={headingId} className="font-headline text-sm font-black uppercase tracking-wide text-on-surface">
            {t('screener.columns.filterTitle', { column: label })}
          </h3>
          <p className="font-mono text-[10px] text-on-surface-variant">{t('screener.columns.filterHint')}</p>
        </div>
        <button type="button" onClick={onClose} className={SECONDARY_BUTTON}>
          {t('screener.columns.close')}
        </button>
      </div>

      <div className="mt-3">
        {column.filter === 'search' || column.filter === 'industry' ? (
          <TextEditor column={column} filters={filters} onChange={onChange} onClose={onClose} onClear={clear} />
        ) : null}
        {column.filter === 'sector' || column.filter === 'country' ? (
          <SelectEditor column={column} filters={filters} onChange={onChange} onClear={clear} />
        ) : null}
        {column.filter === 'range' ? (
          <RangeEditor column={column} filters={filters} onChange={onChange} onClose={onClose} onClear={clear} />
        ) : null}
        {column.filter === 'signals' ? <SignalsEditor filters={filters} onChange={onChange} onClear={clear} /> : null}
      </div>
    </section>
  )
}

interface FieldEditorProps {
  column: CandidateColumn
  filters: ScreenerFilters
  onChange: (patch: Partial<ScreenerFilters>) => void
  onClose: () => void
  onClear: () => void
}

function TextEditor({ column, filters, onChange, onClose, onClear }: FieldEditorProps) {
  const { t } = useI18n()
  const isSearch = column.filter === 'search'
  const committed = isSearch ? filters.search : filters.industry
  const [draft, setDraft] = useState(committed ?? '')
  const inputId = `${COLUMN_FILTER_PANEL_ID}-text`

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    onChange(isSearch ? { search: draft } : { industry: draft })
    onClose()
  }

  return (
    <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
      <div className="flex min-w-0 flex-col gap-1">
        <label htmlFor={inputId} className={LABEL_CLASS}>
          {t(isSearch ? 'screener.columns.searchLabel' : 'screener.columns.industryLabel')}
        </label>
        <input
          id={inputId}
          type="search"
          value={draft}
          maxLength={isSearch ? SEARCH_MAX_LENGTH : INDUSTRY_MAX_LENGTH}
          onChange={(event) => setDraft(event.target.value)}
          aria-describedby={isSearch ? undefined : `${inputId}-hint`}
          className={`w-64 max-w-full ${INPUT_CLASS}`}
        />
        {isSearch ? null : (
          <p id={`${inputId}-hint`} className="font-mono text-[10px] text-on-surface-variant">
            {t('screener.columns.industryHint')}
          </p>
        )}
      </div>
      <div className="flex flex-wrap gap-2">
        <button type="submit" className={PRIMARY_BUTTON}>
          {t('screener.columns.apply')}
        </button>
        <button type="button" onClick={onClear} disabled={committed === null} className={SECONDARY_BUTTON}>
          {t('screener.columns.clear')}
        </button>
      </div>
    </form>
  )
}

function SelectEditor({ column, filters, onChange, onClear }: Omit<FieldEditorProps, 'onClose'>) {
  const { t } = useI18n()
  const isSector = column.filter === 'sector'
  const value = (isSector ? filters.sector : filters.country) ?? ''
  const selectId = `${COLUMN_FILTER_PANEL_ID}-select`
  const options = isSector
    ? SCREENER_SECTORS.map((sector) => ({ value: sector, label: sectorLabel(sector, t) }))
    : CLASSIFICATION_COUNTRIES.map((country) => ({ value: country, label: countryLabel(country, t) }))

  return (
    <div className="flex flex-wrap items-end gap-3">
      <div className="flex flex-col gap-1">
        <label htmlFor={selectId} className={LABEL_CLASS}>
          {t(isSector ? 'screener.columns.sectorLabel' : 'screener.columns.countryLabel')}
        </label>
        <select
          id={selectId}
          value={value}
          onChange={(event) => {
            const next = event.target.value === '' ? null : event.target.value
            onChange(isSector ? { sector: next } : { country: next })
          }}
          className={`w-fit ${INPUT_CLASS} text-xs`}
        >
          <option value="">{t('screener.columns.anyOption')}</option>
          {options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </div>
      <button type="button" onClick={onClear} disabled={value === ''} className={SECONDARY_BUTTON}>
        {t('screener.columns.clear')}
      </button>
    </div>
  )
}

function toDraft(value: number | null): string {
  return value === null ? '' : String(value)
}

function RangeEditor({ column, filters, onChange, onClose, onClear }: FieldEditorProps) {
  const { t, formatNumber } = useI18n()
  const ranges = columnRanges(column)
  const [drafts, setDrafts] = useState(() =>
    ranges.map((range) => ({ min: toDraft(filters[range.min]), max: toDraft(filters[range.max]) })),
  )
  const [error, setError] = useState<{ kind: 'invalid' | 'inverted'; index: number } | null>(null)

  if (ranges.length === 0) {
    return null
  }
  const errorId = `${COLUMN_FILTER_PANEL_ID}-error`
  const hintId = `${COLUMN_FILTER_PANEL_ID}-hint`
  const committed = ranges.some((range) => filters[range.min] !== null || filters[range.max] !== null)

  function read(raw: string, lower: number, upper: number): number | null | undefined {
    if (raw.trim() === '') {
      return null
    }
    const value = Number(raw)
    return Number.isFinite(value) && value >= lower && value <= upper ? value : undefined
  }

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const patch: Partial<ScreenerFilters> = {}
    for (const [index, range] of ranges.entries()) {
      const [lower, upper] = NUMERIC_BOUNDS[range.bounds]
      const min = read(drafts[index].min, lower, upper)
      const max = read(drafts[index].max, lower, upper)
      if (min === undefined || max === undefined) {
        setError({ kind: 'invalid', index })
        return
      }
      if (min !== null && max !== null && min > max) {
        setError({ kind: 'inverted', index })
        return
      }
      Object.assign(patch, { [range.min]: min, [range.max]: max })
    }
    setError(null)
    onChange(patch)
    onClose()
  }

  function setDraft(index: number, side: 'min' | 'max', value: string) {
    setDrafts((current) => current.map((draft, i) => (i === index ? { ...draft, [side]: value } : draft)))
  }

  const describedBy = error === null ? hintId : `${hintId} ${errorId}`
  const errorRange = error === null ? null : NUMERIC_BOUNDS[ranges[error.index].bounds]

  return (
    <form onSubmit={submit} noValidate className="flex flex-col gap-2">
      <div className="flex flex-wrap items-end gap-3">
        {ranges.map((range, index) => {
          const [lower, upper] = NUMERIC_BOUNDS[range.bounds]
          const suffix = index === 0 ? '' : `-${index}`
          const minId = `${COLUMN_FILTER_PANEL_ID}-min${suffix}`
          const maxId = `${COLUMN_FILTER_PANEL_ID}-max${suffix}`
          const prefix = range.labelKey ? `${t(range.labelKey)}: ` : ''
          return (
            <div key={range.min} className="flex flex-wrap items-end gap-3">
              <div className="flex flex-col gap-1">
                <label htmlFor={minId} className={LABEL_CLASS}>
                  {prefix}
                  {t('screener.columns.min')}
                </label>
                <input
                  id={minId}
                  type="number"
                  inputMode="decimal"
                  min={lower}
                  max={upper}
                  step="any"
                  value={drafts[index].min}
                  onChange={(event) => setDraft(index, 'min', event.target.value)}
                  aria-invalid={error !== null && error.index === index}
                  aria-describedby={describedBy}
                  className={`w-28 ${INPUT_CLASS}`}
                />
              </div>
              <div className="flex flex-col gap-1">
                <label htmlFor={maxId} className={LABEL_CLASS}>
                  {prefix}
                  {t('screener.columns.max')}
                </label>
                <input
                  id={maxId}
                  type="number"
                  inputMode="decimal"
                  min={lower}
                  max={upper}
                  step="any"
                  value={drafts[index].max}
                  onChange={(event) => setDraft(index, 'max', event.target.value)}
                  aria-invalid={error !== null && error.index === index}
                  aria-describedby={describedBy}
                  className={`w-28 ${INPUT_CLASS}`}
                />
              </div>
            </div>
          )
        })}
        <div className="flex flex-wrap gap-2">
          <button type="submit" className={PRIMARY_BUTTON}>
            {t('screener.columns.apply')}
          </button>
          <button type="button" onClick={onClear} disabled={!committed} className={SECONDARY_BUTTON}>
            {t('screener.columns.clear')}
          </button>
        </div>
      </div>
      <p id={hintId} className="font-mono text-[10px] text-on-surface-variant">
        {t('screener.columns.rangeHint')}
      </p>
      {error !== null && errorRange !== null ? (
        <p id={errorId} role="alert" className="font-mono text-xs font-bold text-secondary">
          {error.kind === 'inverted'
            ? t('screener.columns.rangeInverted')
            : t('screener.columns.rangeInvalid', { min: formatNumber(errorRange[0], 0), max: formatNumber(errorRange[1], 0) })}
        </p>
      ) : null}
    </form>
  )
}

function SignalsEditor({
  filters,
  onChange,
  onClear,
}: {
  filters: ScreenerFilters
  onChange: (patch: Partial<ScreenerFilters>) => void
  onClear: () => void
}) {
  const { t } = useI18n()

  function toggleSignal(type: ScreenerSignalType) {
    const signals = filters.signals.includes(type)
      ? filters.signals.filter((value) => value !== type)
      : [...filters.signals, type]
    onChange({ signals })
  }

  function togglePattern(type: ScreenerPatternType) {
    const patterns = filters.patterns.includes(type)
      ? filters.patterns.filter((value) => value !== type)
      : [...filters.patterns, type]
    onChange(patterns.length > 0 ? { patterns } : { patterns, patternStatus: 'any' })
  }

  const committed = filters.signals.length > 0 || filters.patterns.length > 0

  return (
    <div className="flex flex-col gap-3">
      <p className="max-w-2xl font-mono text-[10px] text-on-surface-variant">{t('screener.columns.signalsHint')}</p>
      <div role="group" aria-label={t('screener.signalsLegend')} className="flex flex-wrap gap-2">
        {SCREENER_SIGNAL_TYPES.map((type) => {
          const selected = filters.signals.includes(type)
          return (
            <button key={type} type="button" aria-pressed={selected} onClick={() => toggleSignal(type)} className={chipClass(selected)}>
              {signalLabel(type, t)}
            </button>
          )
        })}
      </div>
      {SCREENER_PATTERN_GROUPS.map((group) => (
        <div key={group.direction} role="group" aria-label={t(`patterns.groups.${group.direction}`)} className="flex flex-wrap gap-2">
          {group.types.map((type) => {
            const selected = filters.patterns.includes(type)
            return (
              <button key={type} type="button" aria-pressed={selected} onClick={() => togglePattern(type)} className={chipClass(selected)}>
                {patternLabel(type, t)}
              </button>
            )
          })}
        </div>
      ))}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div
          role="group"
          aria-label={t('patterns.statusAria')}
          className="inline-flex w-fit overflow-hidden rounded-[4px] border-2 border-outline"
        >
          {SCREENER_PATTERN_STATUSES.map((status, index) => {
            const selected = filters.patternStatus === status
            return (
              <button
                key={status}
                type="button"
                aria-pressed={selected}
                disabled={filters.patterns.length === 0}
                onClick={() => onChange({ patternStatus: status })}
                className={[
                  'px-3 py-1.5 font-mono text-[11px] font-bold uppercase tracking-wider transition-colors focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60',
                  index > 0 ? 'border-l-2 border-outline' : '',
                  selected ? 'bg-primary text-on-primary' : 'bg-surface-bright text-on-surface-variant hover:bg-surface-container',
                ].join(' ')}
              >
                {t(PATTERN_STATUS_LABELS[status])}
              </button>
            )
          })}
        </div>
        <button type="button" onClick={onClear} disabled={!committed} className={SECONDARY_BUTTON}>
          {t('screener.columns.clear')}
        </button>
      </div>
    </div>
  )
}
