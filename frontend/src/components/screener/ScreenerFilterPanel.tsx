/**
 * Screener filter controls. Toggles/selects commit to the URL immediately;
 * the numeric RSI inputs keep a local draft and commit on blur/Enter so typing
 * never fires a request per keystroke. Token-only styling (DESIGN.md).
 *
 * The "Contexto sectorial" fieldset (market-context-patterns) follows the
 * Market Context access: the controls for an allowed session (with a
 * "Vista previa" chip for an Admin preview), a dashed sign-in box for a
 * Visitor once the go-live flag is on, and nothing otherwise.
 */

import type { KeyboardEvent } from 'react'
import type { MarketContextAccess } from '../../auth/useMarketContextAccess.ts'
import type { MessageKey } from '../../i18n/translate.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import type {
  MarketTrend,
  ScreenerFilters,
  ScreenerMaCross,
  ScreenerPatternStatus,
  ScreenerPatternType,
  ScreenerSignalType,
} from '../../lib/api.ts'
import {
  MIN_RVOL_PRESETS,
  SECTOR_RS_RANK_OPTIONS,
  SECTOR_TRENDS,
  SCREENER_PATTERN_STATUSES,
  SCREENER_PATTERN_GROUPS,
  SCREENER_SIGNAL_TYPES,
  parseRsiInput,
  patternLabel,
  signalLabel,
} from '../../lib/screenerFilters.ts'
import MarketSignInBox from '../market/MarketSignInBox.tsx'
import { TREND_ICON } from '../market/marketText.ts'

const CHIP_BASE =
  'rounded-[4px] border-2 px-2.5 py-1 font-mono text-[11px] font-bold uppercase tracking-wider transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none'

function chipClass(selected: boolean): string {
  return selected
    ? `${CHIP_BASE} border-outline bg-primary text-on-primary shadow-[1px_1px_0px_#ffcc00]`
    : `${CHIP_BASE} border-outline-variant bg-surface-container text-on-surface-variant`
}

const LEGEND_CLASS =
  'font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant'

const NUMBER_INPUT_CLASS =
  'w-24 rounded-md border-2 border-outline bg-surface-bright px-3 py-2 font-mono text-sm text-on-surface shadow-[2px_2px_0px_var(--color-shadow)] focus:border-outline focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none'

const MA_CROSS_OPTIONS: ReadonlyArray<{ value: ScreenerMaCross | null; labelKey: MessageKey }> = [
  { value: null, labelKey: 'screener.maCrossAny' },
  { value: 'bullish', labelKey: 'screener.maCrossBullish' },
  { value: 'bearish', labelKey: 'screener.maCrossBearish' },
]

const PATTERN_STATUS_LABELS: Record<ScreenerPatternStatus, MessageKey> = {
  any: 'patterns.status.any',
  forming: 'patterns.status.forming',
  confirmed: 'patterns.status.confirmed',
}

function segmentClass(selected: boolean, index: number): string {
  return [
    'px-3 py-1.5 font-mono text-[11px] font-bold uppercase tracking-wider transition-colors focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60',
    index > 0 ? 'border-l-2 border-outline' : '',
    selected ? 'bg-primary text-on-primary' : 'bg-surface-bright text-on-surface-variant hover:bg-surface-container',
  ].join(' ')
}

function toDraft(value: number | null): string {
  return value === null ? '' : String(value)
}

const SELECT_CLASS =
  'w-fit rounded-md border-2 border-outline bg-surface-bright px-3 py-2 font-mono text-xs text-on-surface shadow-[2px_2px_0px_var(--color-shadow)] focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none'

const SECTOR_TREND_OPTIONS: ReadonlyArray<MarketTrend | null> = [null, ...SECTOR_TRENDS]

interface ScreenerFilterPanelProps {
  filters: ScreenerFilters
  activeCount: number
  /** Market Context access (useMarketContextAccess); absent = no sector fieldset. */
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

  // The RSI inputs are uncontrolled drafts: the DOM holds the keystrokes and
  // only blur/Enter commits them, so typing never writes the URL. The `key`
  // re-mounts an input when the committed value changes from outside (clear,
  // back/forward, deep link) so the draft always reflects the URL.
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

  function handleNumberKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'Enter') {
      event.currentTarget.blur()
    }
  }

  return (
    <section className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_var(--color-shadow)]">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
            {t('screener.filtersTitle')}
          </h2>
          <p className="font-mono text-[11px] text-on-surface-variant">
            {t('screener.filtersHint')}
          </p>
        </div>
        <p className="font-mono text-[11px] uppercase tracking-wider text-on-surface-variant">
          {activeCount > 0
            ? t('screener.activeFilters', { count: activeCount })
            : t('screener.noActiveFilters')}
        </p>
      </header>

      <div className="mt-4 flex flex-wrap gap-x-8 gap-y-5">
        <fieldset className="flex min-w-60 flex-col gap-2">
          <legend className={LEGEND_CLASS}>
            {filters.signals.length > 0
              ? t('screener.signalsLegendCount', { count: filters.signals.length })
              : t('screener.signalsLegend')}
          </legend>
          <div className="flex flex-wrap gap-2">
            {SCREENER_SIGNAL_TYPES.map((type) => {
              const selected = filters.signals.includes(type)
              return (
                <button
                  key={type}
                  type="button"
                  aria-pressed={selected}
                  onClick={() => toggleSignal(type)}
                  className={chipClass(selected)}
                >
                  {signalLabel(type, t)}
                </button>
              )
            })}
          </div>
        </fieldset>

        <fieldset className="flex flex-col gap-2">
          <legend className={LEGEND_CLASS}>RSI (14)</legend>
          <div className="flex flex-wrap items-end gap-3">
            <div className="flex flex-col gap-1">
              <label htmlFor="screener-rsi-min" className={LEGEND_CLASS}>
                {t('screener.rsiMin')}
              </label>
              <input
                id="screener-rsi-min"
                key={`rsi-min-${filters.rsiMin ?? 'off'}`}
                type="number"
                inputMode="numeric"
                min={0}
                max={100}
                step={1}
                defaultValue={toDraft(filters.rsiMin)}
                onBlur={(event) => onChange({ rsiMin: parseRsiInput(event.target.value) })}
                onKeyDown={handleNumberKeyDown}
                className={NUMBER_INPUT_CLASS}
              />
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="screener-rsi-max" className={LEGEND_CLASS}>
                {t('screener.rsiMax')}
              </label>
              <input
                id="screener-rsi-max"
                key={`rsi-max-${filters.rsiMax ?? 'off'}`}
                type="number"
                inputMode="numeric"
                min={0}
                max={100}
                step={1}
                defaultValue={toDraft(filters.rsiMax)}
                onBlur={(event) => onChange({ rsiMax: parseRsiInput(event.target.value) })}
                onKeyDown={handleNumberKeyDown}
                className={NUMBER_INPUT_CLASS}
              />
            </div>
          </div>
          <p className="font-mono text-[10px] text-on-surface-variant">{t('screener.rsiEmptyHint')}</p>
        </fieldset>

        <fieldset className="flex flex-col gap-2">
          <legend className={LEGEND_CLASS}>{t('screener.minRvolLegend')}</legend>
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              aria-pressed={filters.minRvol === null}
              onClick={() => onChange({ minRvol: null })}
              className={chipClass(filters.minRvol === null)}
            >
              {t('screener.minRvolAll')}
            </button>
            {MIN_RVOL_PRESETS.map((preset) => {
              const selected = filters.minRvol === preset
              return (
                <button
                  key={preset}
                  type="button"
                  aria-pressed={selected}
                  onClick={() => onChange({ minRvol: selected ? null : preset })}
                  className={chipClass(selected)}
                >
                  {formatMultiple(preset)}
                </button>
              )
            })}
          </div>
        </fieldset>

        <fieldset className="flex flex-col gap-2">
          <legend className={LEGEND_CLASS}>{t('screener.priceLegend')}</legend>
          <button
            type="button"
            aria-pressed={filters.priceAboveSma200}
            onClick={() => onChange({ priceAboveSma200: !filters.priceAboveSma200 })}
            className={`w-fit ${chipClass(filters.priceAboveSma200)}`}
          >
            {t('screener.priceAboveSma200')}
          </button>
        </fieldset>

        <fieldset className="flex flex-col gap-2">
          <legend className={LEGEND_CLASS}>{t('screener.maCrossLegend')}</legend>
          <div
            role="group"
            aria-label={t('screener.maCrossAria')}
            className="inline-flex w-fit overflow-hidden rounded-[4px] border-2 border-outline"
          >
            {MA_CROSS_OPTIONS.map((option, index) => {
              const selected = filters.maCross === option.value
              return (
                <button
                  key={option.labelKey}
                  type="button"
                  aria-pressed={selected}
                  onClick={() => onChange({ maCross: option.value })}
                  className={segmentClass(selected, index)}
                >
                  {t(option.labelKey)}
                </button>
              )
            })}
          </div>
        </fieldset>

        <fieldset className="flex min-w-60 flex-col gap-2">
          <legend className={LEGEND_CLASS}>
            {filters.patterns.length > 0
              ? t('patterns.legendCount', { count: filters.patterns.length })
              : t('patterns.legend')}
          </legend>
          {SCREENER_PATTERN_GROUPS.map((group) => (
            <div
              key={group.direction}
              role="group"
              aria-labelledby={`pattern-group-${group.direction}`}
              className="flex flex-col gap-1"
            >
              <span id={`pattern-group-${group.direction}`} className="font-mono text-[10px] uppercase text-on-surface-variant">
                {t(`patterns.groups.${group.direction}`)}
              </span>
              <div className="flex flex-wrap gap-2">
                {group.types.map((type) => {
                  const selected = filters.patterns.includes(type)
                  return (
                    <button
                      key={type}
                      type="button"
                      aria-pressed={selected}
                      onClick={() => togglePattern(type)}
                      className={chipClass(selected)}
                    >
                      {patternLabel(type, t)}
                    </button>
                  )
                })}
              </div>
            </div>
          ))}
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
                  className={segmentClass(selected, index)}
                >
                  {t(PATTERN_STATUS_LABELS[status])}
                </button>
              )
            })}
          </div>
          <p className="max-w-xs font-mono text-[10px] text-on-surface-variant">{t('patterns.hint')}</p>
        </fieldset>

        {access?.status === 'allowed' ? (
          <fieldset className="flex min-w-0 flex-col gap-2">
            <legend className={LEGEND_CLASS}>
              {t('screener.sectorContext.legend')}
              {access.preview ? (
                <span className="ml-2 rounded-[4px] border border-dashed border-outline bg-primary-container px-1.5 py-0.5 text-[10px] text-on-primary-container">
                  {t('screener.sectorContext.preview')}
                </span>
              ) : null}
            </legend>
            <span className="font-mono text-[10px] uppercase text-on-surface-variant">{t('screener.sectorContext.trendLabel')}</span>
            <div
              role="group"
              aria-label={t('screener.sectorContext.trendAria')}
              className="inline-flex w-fit flex-wrap overflow-hidden rounded-[4px] border-2 border-outline"
            >
              {SECTOR_TREND_OPTIONS.map((trend, index) => {
                const selected = filters.sectorTrend === trend
                return (
                  <button
                    key={trend ?? 'any'}
                    type="button"
                    aria-pressed={selected}
                    onClick={() => onChange({ sectorTrend: trend })}
                    className={segmentClass(selected, index)}
                  >
                    {trend === null ? (
                      t('screener.sectorContext.trendAny')
                    ) : (
                      <>
                        <span aria-hidden="true">{TREND_ICON[trend]} </span>
                        {t(`marketContext.trend.${trend}`)}
                      </>
                    )}
                  </button>
                )
              })}
            </div>
            <label htmlFor="screener-sector-rank" className="font-mono text-[10px] uppercase text-on-surface-variant">
              {t('screener.sectorContext.rankLabel')}
            </label>
            <select
              id="screener-sector-rank"
              value={filters.sectorRsRankMax === null ? '' : String(filters.sectorRsRankMax)}
              onChange={(event) => onChange({ sectorRsRankMax: event.target.value === '' ? null : Number(event.target.value) })}
              className={SELECT_CLASS}
            >
              <option value="">{t('screener.sectorContext.rankAll')}</option>
              {SECTOR_RS_RANK_OPTIONS.map((n) => (
                <option key={n} value={String(n)}>
                  {t('screener.sectorContext.rankTop', { n })}
                </option>
              ))}
            </select>
            <p className="max-w-xs font-mono text-[10px] text-on-surface-variant">{t('screener.sectorContext.hint')}</p>
          </fieldset>
        ) : null}

        {access?.status === 'signIn' ? (
          <fieldset className="flex min-w-0 max-w-sm flex-col gap-2">
            <legend className={LEGEND_CLASS}>{t('screener.sectorContext.legend')}</legend>
            <MarketSignInBox
              title={t('screener.sectorContext.signInTitle')}
              body={t('screener.sectorContext.signInBody')}
              link={t('screener.sectorContext.signIn')}
            />
          </fieldset>
        ) : null}
      </div>

      <div className="mt-5 flex flex-wrap items-center justify-between gap-3 border-t-2 border-outline-variant pt-4">
        <p className="font-mono text-[11px] text-on-surface-variant">
          {activeCount > 0
            ? t('screener.filtersInUrl')
            : t('screener.noFiltersHint')}
        </p>
        <button
          type="button"
          onClick={onClear}
          disabled={activeCount === 0}
          className="rounded-md border-2 border-outline bg-surface-bright px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
        >
          {t('screener.clearFilters')}
        </button>
      </div>
    </section>
  )
}
