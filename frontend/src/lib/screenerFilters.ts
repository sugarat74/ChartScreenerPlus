/**
 * Screener filter helpers. Pure functions with no React dependency so the URL
 * round-trip (parse -> patch -> canonical key) is auditable and deterministic.
 *
 * The SPA owns exactly thirty query keys, all named after the screener API
 * params: the criteria (`signal`, `rsi_min`, `rsi_max`, `min_rvol`,
 * `price_above_sma200`, `ma_cross`, `pattern`, `pattern_status`, the sector
 * context keys `sector_trend` and `sector_rs_rank_max` of
 * market-context-patterns, and the table column keys `search`, `sector`,
 * `industry`, `country`, `rvol_max`, `close_min`, `close_max`, `change_min`,
 * `change_max` of screener-table-column-controls, and the ten indicator range
 * keys `adx_min`/`adx_max`, `bb_pctb_*`, `bb_width_*`, `atr_pct_*` and
 * `stoch_k_*` of screener-technical-filters-ui) plus the ranking key
 * `sort`; any other query param (a campaign tag, `page`) is preserved
 * untouched.
 */

import type {
  MarketTrend,
  SavedScreenerFilters,
  ScreenerFilters,
  ScreenerMaCross,
  ScreenerPatternStatus,
  ScreenerPatternType,
  ScreenerSignalType,
  ScreenerSort,
} from './api.ts'
import type { MessageKey, Translate } from '../i18n/translate.ts'
import {
  CLASSIFICATION_SECTORS,
  countryLabel,
  sectorLabel,
} from './companyClassification.ts'

/** Canonical order used for the URL and for deterministic request keys. */
export const SCREENER_SIGNAL_TYPES: readonly ScreenerSignalType[] = [
  'golden_cross',
  'death_cross',
  'ma_alignment_bullish',
  'ma_alignment_bearish',
  'pivot_breakout_rvol',
  'rsi_overbought',
  'rsi_oversold',
  'macd_bullish_cross',
  'macd_bearish_cross',
  'adx_trend_strength',
  'bollinger_squeeze',
  'bollinger_close_above_upper',
  'bollinger_close_below_lower',
  'stoch_bullish_cross',
  'stoch_bearish_cross',
]

/**
 * Chartist pattern types grouped by direction (chart-patterns-extended): a
 * bullish pattern confirms with a close above its breakout level, a bearish
 * one with a close below it. Display only, never advice.
 */
export const SCREENER_PATTERN_GROUPS: readonly {
  direction: PatternDirection
  types: readonly ScreenerPatternType[]
}[] = [
  { direction: 'bullish', types: ['double_bottom', 'triple_bottom', 'cup_with_handle', 'bull_flag', 'bull_pennant'] },
  { direction: 'bearish', types: ['double_top', 'triple_top', 'inverse_cup_with_handle', 'bear_flag', 'bear_pennant'] },
]

export type PatternDirection = 'bullish' | 'bearish'

/** Canonical order of the chartist pattern types: bullish group, then bearish. */
export const SCREENER_PATTERN_TYPES: readonly ScreenerPatternType[] = SCREENER_PATTERN_GROUPS.flatMap(
  (group) => group.types,
)

export function patternDirection(type: ScreenerPatternType): PatternDirection {
  return SCREENER_PATTERN_GROUPS.find((group) => group.types.includes(type))?.direction ?? 'bullish'
}

export const SCREENER_PATTERN_STATUSES: readonly ScreenerPatternStatus[] = ['any', 'forming', 'confirmed']

/** `sector_trend` values (the trend of the stock's sector ETF), in display order. */
export const SECTOR_TRENDS: readonly MarketTrend[] = ['uptrend', 'mixed', 'downtrend']

/** Upper bound of `sector_rs_rank_max` (the 11 SPDR sectors). */
export const SECTOR_RS_RANK_MAX = 11

/** The selectable "top N sectors" values, 1 to 11. */
export const SECTOR_RS_RANK_OPTIONS: readonly number[] = Array.from({ length: SECTOR_RS_RANK_MAX }, (_, index) => index + 1)

/** Preset Min RVOL buttons; `+ Todos` (off) is handled by the control. */
export const MIN_RVOL_PRESETS: readonly number[] = [1, 1.5, 2, 3]

/** API default ranking; also what an absent/invalid `sort` resolves to. */
export const DEFAULT_SCREENER_SORT: ScreenerSort = 'rvol_desc'

/**
 * Every selectable ranking order (display order: the original six first, each
 * followed by its opposite direction, then the remaining table columns);
 * labels live in the catalogs.
 */
export const SCREENER_SORT_OPTIONS: readonly { value: ScreenerSort; labelKey: MessageKey }[] = [
  { value: 'rvol_desc', labelKey: 'sorts.rvol_desc' },
  { value: 'rvol_asc', labelKey: 'sorts.rvol_asc' },
  { value: 'signal_count_desc', labelKey: 'sorts.signal_count_desc' },
  { value: 'signal_count_asc', labelKey: 'sorts.signal_count_asc' },
  { value: 'change_desc', labelKey: 'sorts.change_desc' },
  { value: 'change_asc', labelKey: 'sorts.change_asc' },
  { value: 'rsi_desc', labelKey: 'sorts.rsi_desc' },
  { value: 'rsi_asc', labelKey: 'sorts.rsi_asc' },
  { value: 'close_desc', labelKey: 'sorts.close_desc' },
  { value: 'close_asc', labelKey: 'sorts.close_asc' },
  { value: 'company_asc', labelKey: 'sorts.company_asc' },
  { value: 'company_desc', labelKey: 'sorts.company_desc' },
  { value: 'sector_asc', labelKey: 'sorts.sector_asc' },
  { value: 'sector_desc', labelKey: 'sorts.sector_desc' },
  { value: 'industry_asc', labelKey: 'sorts.industry_asc' },
  { value: 'industry_desc', labelKey: 'sorts.industry_desc' },
  { value: 'country_asc', labelKey: 'sorts.country_asc' },
  { value: 'country_desc', labelKey: 'sorts.country_desc' },
  { value: 'adx_desc', labelKey: 'sorts.adx_desc' },
  { value: 'adx_asc', labelKey: 'sorts.adx_asc' },
  { value: 'bb_pctb_desc', labelKey: 'sorts.bb_pctb_desc' },
  { value: 'bb_pctb_asc', labelKey: 'sorts.bb_pctb_asc' },
  { value: 'bb_width_desc', labelKey: 'sorts.bb_width_desc' },
  { value: 'bb_width_asc', labelKey: 'sorts.bb_width_asc' },
  { value: 'atr_pct_desc', labelKey: 'sorts.atr_pct_desc' },
  { value: 'atr_pct_asc', labelKey: 'sorts.atr_pct_asc' },
  { value: 'stoch_k_desc', labelKey: 'sorts.stoch_k_desc' },
  { value: 'stoch_k_asc', labelKey: 'sorts.stoch_k_asc' },
]

/** Max length of the `search` (ticker/company) fragment; mirrors the API. */
export const SEARCH_MAX_LENGTH = 100

/** Max length of the `industry` fragment; mirrors the API. */
export const INDUSTRY_MAX_LENGTH = 120

/** Inclusive bounds of each numeric column; mirrors the API. */
export const NUMERIC_BOUNDS = {
  rsi: [0, 100],
  rvol: [0, 1000],
  close: [0, 1_000_000],
  change: [-100, 1000],
  adx: [0, 100],
  bbPctb: [-1, 2],
  bbWidth: [0, 1000],
  atrPct: [0, 100],
  stochK: [0, 100],
} as const satisfies Record<string, readonly [number, number]>

export type NumericColumn = keyof typeof NUMERIC_BOUNDS

/** The five indicator ranges: filter fields and API keys (all inclusive). */
export const INDICATOR_RANGES = [
  { column: 'adx', min: 'adxMin', max: 'adxMax', minKey: 'adx_min', maxKey: 'adx_max' },
  { column: 'bbPctb', min: 'bbPctbMin', max: 'bbPctbMax', minKey: 'bb_pctb_min', maxKey: 'bb_pctb_max' },
  { column: 'bbWidth', min: 'bbWidthMin', max: 'bbWidthMax', minKey: 'bb_width_min', maxKey: 'bb_width_max' },
  { column: 'atrPct', min: 'atrPctMin', max: 'atrPctMax', minKey: 'atr_pct_min', maxKey: 'atr_pct_max' },
  { column: 'stochK', min: 'stochKMin', max: 'stochKMax', minKey: 'stoch_k_min', maxKey: 'stoch_k_max' },
] as const satisfies readonly {
  column: NumericColumn
  min: keyof ScreenerFilters
  max: keyof ScreenerFilters
  minKey: keyof SavedScreenerFilters
  maxKey: keyof SavedScreenerFilters
}[]

type IndicatorField = (typeof INDICATOR_RANGES)[number]['min' | 'max']

/** The sorts that rank by an indicator column (they also reveal the columns). */
export const INDICATOR_SORTS: readonly ScreenerSort[] = [
  'adx_asc', 'adx_desc', 'bb_pctb_asc', 'bb_pctb_desc', 'bb_width_asc', 'bb_width_desc',
  'atr_pct_asc', 'atr_pct_desc', 'stoch_k_asc', 'stoch_k_desc',
]

/** Whether any indicator range criterion is active. */
export function usesIndicatorCriteria(filters: ScreenerFilters): boolean {
  return INDICATOR_RANGES.some((range) => filters[range.min] !== null || filters[range.max] !== null)
}

/** The Indicators columns must show: a criterion or an indicator sort is active. */
export function forcesIndicatorColumns(filters: ScreenerFilters): boolean {
  return usesIndicatorCriteria(filters) || INDICATOR_SORTS.includes(filters.sort)
}

/** The GICS sector names accepted by `sector` (canonical API values). */
export const SCREENER_SECTORS: readonly string[] = CLASSIFICATION_SECTORS

export const EMPTY_SCREENER_FILTERS: ScreenerFilters = {
  signals: [],
  rsiMin: null,
  rsiMax: null,
  minRvol: null,
  priceAboveSma200: false,
  maCross: null,
  patterns: [],
  patternStatus: 'any',
  sectorTrend: null,
  sectorRsRankMax: null,
  search: null,
  sector: null,
  industry: null,
  country: null,
  rvolMax: null,
  closeMin: null,
  closeMax: null,
  changeMin: null,
  changeMax: null,
  adxMin: null,
  adxMax: null,
  bbPctbMin: null,
  bbPctbMax: null,
  bbWidthMin: null,
  bbWidthMax: null,
  atrPctMin: null,
  atrPctMax: null,
  stochKMin: null,
  stochKMax: null,
  sort: DEFAULT_SCREENER_SORT,
}

/**
 * Every criterion field (technical, sector context and table column), for
 * "Limpiar filtros". `sort` is a ranking preference, not a filter, so
 * clearing criteria must preserve the user's selected order.
 */
export const EMPTY_SCREENER_CRITERIA: Partial<ScreenerFilters> = {
  signals: [],
  rsiMin: null,
  rsiMax: null,
  minRvol: null,
  priceAboveSma200: false,
  maCross: null,
  patterns: [],
  patternStatus: 'any',
  sectorTrend: null,
  sectorRsRankMax: null,
  search: null,
  sector: null,
  industry: null,
  country: null,
  rvolMax: null,
  closeMin: null,
  closeMax: null,
  changeMin: null,
  changeMax: null,
  adxMin: null,
  adxMax: null,
  bbPctbMin: null,
  bbPctbMax: null,
  bbWidthMin: null,
  bbWidthMax: null,
  atrPctMin: null,
  atrPctMax: null,
  stochKMin: null,
  stochKMax: null,
}

function isKnownSignalType(value: string): value is ScreenerSignalType {
  return (SCREENER_SIGNAL_TYPES as readonly string[]).includes(value)
}

/** Dedupe and order arbitrary type strings into the canonical 15-type order. */
export function normalizeSignals(values: readonly string[]): ScreenerSignalType[] {
  const known = new Set(values.filter(isKnownSignalType))
  return SCREENER_SIGNAL_TYPES.filter((type) => known.has(type))
}

function isKnownPatternType(value: string): value is ScreenerPatternType {
  return (SCREENER_PATTERN_TYPES as readonly string[]).includes(value)
}

/** Dedupe and order arbitrary type strings into the canonical pattern order. */
export function normalizePatterns(values: readonly string[]): ScreenerPatternType[] {
  const known = new Set(values.filter(isKnownPatternType))
  return SCREENER_PATTERN_TYPES.filter((type) => known.has(type))
}

function parsePatternStatus(raw: unknown): ScreenerPatternStatus {
  return raw === 'forming' || raw === 'confirmed' ? raw : 'any'
}

/** A known sector trend, otherwise `null` (off). */
function parseSectorTrend(raw: unknown): MarketTrend | null {
  return typeof raw === 'string' && (SECTOR_TRENDS as readonly string[]).includes(raw) ? (raw as MarketTrend) : null
}

/** A whole number from 1 to 11, otherwise `null` (off); never forwarded invalid. */
function parseSectorRsRankMax(raw: unknown): number | null {
  const value = typeof raw === 'number' ? raw : typeof raw === 'string' && /^\d{1,2}$/.test(raw) ? Number(raw) : Number.NaN
  return Number.isInteger(value) && value >= 1 && value <= SECTOR_RS_RANK_MAX ? value : null
}

/**
 * Parse one RSI input value: empty/invalid -> `null` (off), otherwise a finite
 * number clamped to `0..100`.
 */
export function parseRsiInput(raw: string | null): number | null {
  if (raw === null || raw.trim() === '') {
    return null
  }
  const value = Number(raw)
  if (!Number.isFinite(value)) {
    return null
  }
  return Math.min(100, Math.max(0, value))
}

/**
 * Parse one Min RVOL value: empty/invalid/negative -> `null` (off); above the
 * API bound it is clamped to it.
 */
function parseMinRvol(raw: string | null): number | null {
  if (raw === null || raw.trim() === '') {
    return null
  }
  const value = Number(raw)
  if (!Number.isFinite(value) || value < 0) {
    return null
  }
  return Math.min(value, NUMERIC_BOUNDS.rvol[1])
}

/**
 * Parse one numeric column bound: empty/invalid -> `null` (off), otherwise a
 * finite number clamped to the column's API bounds.
 */
export function parseBoundInput(raw: string | null | undefined, column: NumericColumn): number | null {
  if (raw === null || raw === undefined || raw.trim() === '') {
    return null
  }
  const value = Number(raw)
  if (!Number.isFinite(value)) {
    return null
  }
  const [min, max] = NUMERIC_BOUNDS[column]
  return Math.min(max, Math.max(min, value))
}

/** A stored/typed number re-parsed through `parseBoundInput`. */
function boundNumber(value: unknown, column: NumericColumn): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? parseBoundInput(String(value), column) : null
}

/**
 * A `[min, max]` pair in order: an inverted pair (the API would answer `422`)
 * is swapped, so a hand-edited URL keeps its meaning and never errors.
 */
function orderedRange(min: number | null, max: number | null): [number | null, number | null] {
  return min !== null && max !== null && min > max ? [max, min] : [min, max]
}

/**
 * Normalize a free-text fragment like the API: control characters become
 * spaces, format characters are removed, whitespace collapses, blank is
 * `null` and the text is cut to `max` characters (code points).
 */
export function parseTextInput(raw: unknown, max: number): string | null {
  if (typeof raw !== 'string') {
    return null
  }
  const text = raw
    .replace(/\p{Cf}+/gu, '')
    .replace(/[\p{Cc}\s]+/gu, ' ')
    .trim()
  return text === '' ? null : Array.from(text).slice(0, max).join('').trim()
}

/** A known GICS sector name, otherwise `null` (off). */
function parseSector(raw: unknown): string | null {
  return typeof raw === 'string' && SCREENER_SECTORS.includes(raw) ? raw : null
}

/** Two ASCII letters upper-cased (ISO alpha-2), otherwise `null` (off). */
function parseCountry(raw: unknown): string | null {
  return typeof raw === 'string' && /^[A-Za-z]{2}$/.test(raw) ? raw.toUpperCase() : null
}

function parseMaCross(raw: string | null): ScreenerMaCross | null {
  return raw === 'bullish' || raw === 'bearish' ? raw : null
}

function isScreenerSort(value: string | null): value is ScreenerSort {
  return value !== null && SCREENER_SORT_OPTIONS.some((option) => option.value === value)
}

/** Absent/unknown `sort` values fall back to the API default (never a `422`). */
export function parseScreenerSort(value: string | null): ScreenerSort {
  return isScreenerSort(value) ? value : DEFAULT_SCREENER_SORT
}

/**
 * Strict and lenient: unknown or invalid values are dropped, never forwarded,
 * so a hand-edited URL can never produce an API `422`.
 */
export function parseScreenerFilters(params: URLSearchParams): ScreenerFilters {
  const rawSignals = params.getAll('signal').flatMap((value) => value.split(','))
  const patterns = normalizePatterns(params.getAll('pattern').flatMap((value) => value.split(',')))
  const [rsiMin, rsiMax] = orderedRange(parseRsiInput(params.get('rsi_min')), parseRsiInput(params.get('rsi_max')))
  const [minRvol, rvolMax] = orderedRange(parseMinRvol(params.get('min_rvol')), parseBoundInput(params.get('rvol_max'), 'rvol'))
  const [closeMin, closeMax] = orderedRange(parseBoundInput(params.get('close_min'), 'close'), parseBoundInput(params.get('close_max'), 'close'))
  const [changeMin, changeMax] = orderedRange(
    parseBoundInput(params.get('change_min'), 'change'),
    parseBoundInput(params.get('change_max'), 'change'),
  )
  const indicators = Object.fromEntries(
    INDICATOR_RANGES.flatMap((range) => {
      const [min, max] = orderedRange(
        parseBoundInput(params.get(range.minKey), range.column),
        parseBoundInput(params.get(range.maxKey), range.column),
      )
      return [[range.min, min], [range.max, max]]
    }),
  ) as Pick<ScreenerFilters, IndicatorField>

  return {
    signals: normalizeSignals(rawSignals),
    rsiMin,
    rsiMax,
    minRvol,
    priceAboveSma200: params.get('price_above_sma200') === '1' || params.get('price_above_sma200') === 'true',
    maCross: parseMaCross(params.get('ma_cross')),
    patterns,
    // A status only narrows selected patterns; without them it is `any`.
    patternStatus: patterns.length > 0 ? parsePatternStatus(params.get('pattern_status')) : 'any',
    sectorTrend: parseSectorTrend(params.get('sector_trend')),
    sectorRsRankMax: parseSectorRsRankMax(params.get('sector_rs_rank_max')),
    search: parseTextInput(params.get('search'), SEARCH_MAX_LENGTH),
    sector: parseSector(params.get('sector')),
    industry: parseTextInput(params.get('industry'), INDUSTRY_MAX_LENGTH),
    country: parseCountry(params.get('country')),
    rvolMax,
    closeMin,
    closeMax,
    changeMin,
    changeMax,
    ...indicators,
    sort: parseScreenerSort(params.get('sort')),
  }
}

function setOrDelete(params: URLSearchParams, key: string, value: string | null): void {
  if (value === null) {
    params.delete(key)
  } else {
    params.set(key, value)
  }
}

/**
 * Patch only the owned keys (in canonical form) and preserve every other query
 * param untouched. `undefined` means "not part of this patch"; an explicit
 * `null`/`false`/`[]` clears the key. The default sort is omitted from the URL,
 * so an unmodified ranking keeps the URL clean.
 */
export function patchScreenerFilters(
  prev: URLSearchParams,
  patch: Partial<ScreenerFilters>,
): URLSearchParams {
  const next = new URLSearchParams(prev)

  if (patch.signals !== undefined) {
    const signals = normalizeSignals(patch.signals)
    setOrDelete(next, 'signal', signals.length > 0 ? signals.join(',') : null)
  }
  if (patch.rsiMin !== undefined) {
    setOrDelete(next, 'rsi_min', patch.rsiMin === null ? null : String(patch.rsiMin))
  }
  if (patch.rsiMax !== undefined) {
    setOrDelete(next, 'rsi_max', patch.rsiMax === null ? null : String(patch.rsiMax))
  }
  if (patch.minRvol !== undefined) {
    setOrDelete(next, 'min_rvol', patch.minRvol === null ? null : String(patch.minRvol))
  }
  if (patch.priceAboveSma200 !== undefined) {
    setOrDelete(next, 'price_above_sma200', patch.priceAboveSma200 ? '1' : null)
  }
  if (patch.maCross !== undefined) {
    setOrDelete(next, 'ma_cross', patch.maCross)
  }
  if (patch.patterns !== undefined) {
    const patterns = normalizePatterns(patch.patterns)
    setOrDelete(next, 'pattern', patterns.length > 0 ? patterns.join(',') : null)
  }
  if (patch.patternStatus !== undefined) {
    setOrDelete(next, 'pattern_status', patch.patternStatus === 'any' ? null : patch.patternStatus)
  }
  if (patch.sectorTrend !== undefined) {
    setOrDelete(next, 'sector_trend', parseSectorTrend(patch.sectorTrend))
  }
  if (patch.sectorRsRankMax !== undefined) {
    const rank = parseSectorRsRankMax(patch.sectorRsRankMax)
    setOrDelete(next, 'sector_rs_rank_max', rank === null ? null : String(rank))
  }
  if (patch.search !== undefined) {
    setOrDelete(next, 'search', parseTextInput(patch.search, SEARCH_MAX_LENGTH))
  }
  if (patch.sector !== undefined) {
    setOrDelete(next, 'sector', parseSector(patch.sector))
  }
  if (patch.industry !== undefined) {
    setOrDelete(next, 'industry', parseTextInput(patch.industry, INDUSTRY_MAX_LENGTH))
  }
  if (patch.country !== undefined) {
    setOrDelete(next, 'country', parseCountry(patch.country))
  }
  const bounds: [keyof ScreenerFilters, string, NumericColumn][] = [
    ['rvolMax', 'rvol_max', 'rvol'],
    ['closeMin', 'close_min', 'close'],
    ['closeMax', 'close_max', 'close'],
    ['changeMin', 'change_min', 'change'],
    ['changeMax', 'change_max', 'change'],
  ]
  for (const [field, key, column] of bounds) {
    const value = patch[field]
    if (value !== undefined) {
      const bound = boundNumber(value, column)
      setOrDelete(next, key, bound === null ? null : String(bound))
    }
  }
  for (const range of INDICATOR_RANGES) {
    for (const [field, key] of [[range.min, range.minKey], [range.max, range.maxKey]] as const) {
      const value = patch[field]
      if (value !== undefined) {
        const bound = boundNumber(value, range.column)
        setOrDelete(next, key, bound === null ? null : String(bound))
      }
    }
  }
  if (patch.sort !== undefined) {
    setOrDelete(next, 'sort', patch.sort === DEFAULT_SCREENER_SORT ? null : patch.sort)
  }

  return next
}

/** The table column criteria that are single values (one count each). */
function columnCriteria(filters: ScreenerFilters): unknown[] {
  return [
    filters.search,
    filters.sector,
    filters.industry,
    filters.country,
    filters.rvolMax,
    filters.closeMin,
    filters.closeMax,
    filters.changeMin,
    filters.changeMax,
    ...INDICATOR_RANGES.flatMap((range) => [filters[range.min], filters[range.max]]),
  ]
}

export function hasActiveFilters(filters: ScreenerFilters): boolean {
  return countActiveFilters(filters) > 0
}

/** Number of active criteria, used for the "Limpiar filtros" affordance. */
export function countActiveFilters(filters: ScreenerFilters): number {
  return (
    filters.signals.length +
    (filters.rsiMin !== null ? 1 : 0) +
    (filters.rsiMax !== null ? 1 : 0) +
    (filters.minRvol !== null ? 1 : 0) +
    (filters.priceAboveSma200 ? 1 : 0) +
    (filters.maCross !== null ? 1 : 0) +
    filters.patterns.length +
    (filters.sectorTrend !== null ? 1 : 0) +
    (filters.sectorRsRankMax !== null ? 1 : 0) +
    columnCriteria(filters).filter((value) => value !== null).length
  )
}

/** The filters use a sector context key (needs Market Context access). */
export function usesMarketContext(filters: ScreenerFilters): boolean {
  return filters.sectorTrend !== null || filters.sectorRsRankMax !== null
}

/**
 * The same filters without the sector context keys: what the page requests
 * for a session without Market Context access (the URL keeps the keys).
 */
export function withoutMarketContext(filters: ScreenerFilters): ScreenerFilters {
  return usesMarketContext(filters) ? { ...filters, sectorTrend: null, sectorRsRankMax: null } : filters
}

/** Deterministic serialization for effect deps + duplicate-request suppression. */
export function screenerFiltersKey(filters: ScreenerFilters): string {
  return [
    filters.signals.join(','),
    filters.rsiMin ?? '',
    filters.rsiMax ?? '',
    filters.minRvol ?? '',
    filters.priceAboveSma200 ? '1' : '',
    filters.maCross ?? '',
    filters.patterns.join(','),
    filters.patterns.length > 0 ? filters.patternStatus : 'any',
    filters.sectorTrend ?? '',
    filters.sectorRsRankMax ?? '',
    // Text values are JSON-encoded so a `|` typed in a fragment cannot
    // collide with the separator.
    JSON.stringify([filters.search, filters.sector, filters.industry, filters.country]),
    filters.rvolMax ?? '',
    filters.closeMin ?? '',
    filters.closeMax ?? '',
    filters.changeMin ?? '',
    filters.changeMax ?? '',
    ...INDICATOR_RANGES.flatMap((range) => [filters[range.min] ?? '', filters[range.max] ?? '']),
    filters.sort,
  ].join('|')
}

/**
 * Serialize the live filter state into the canonical thirty-key definition
 * that is stored with a Saved Screener (API param names, `sort` included). The
 * SPA always emits all thirty keys, so a stored definition is always complete
 * and re-appliable without defaults.
 */
export function serializeScreenerFilters(filters: ScreenerFilters): SavedScreenerFilters {
  return {
    signal: normalizeSignals(filters.signals),
    rsi_min: filters.rsiMin,
    rsi_max: filters.rsiMax,
    min_rvol: filters.minRvol,
    price_above_sma200: filters.priceAboveSma200,
    ma_cross: filters.maCross,
    pattern: normalizePatterns(filters.patterns),
    pattern_status: filters.patterns.length > 0 ? filters.patternStatus : 'any',
    sector_trend: filters.sectorTrend,
    sector_rs_rank_max: filters.sectorRsRankMax,
    search: filters.search,
    sector: filters.sector,
    industry: filters.industry,
    country: filters.country,
    rvol_max: filters.rvolMax,
    close_min: filters.closeMin,
    close_max: filters.closeMax,
    change_min: filters.changeMin,
    change_max: filters.changeMax,
    adx_min: filters.adxMin,
    adx_max: filters.adxMax,
    bb_pctb_min: filters.bbPctbMin,
    bb_pctb_max: filters.bbPctbMax,
    bb_width_min: filters.bbWidthMin,
    bb_width_max: filters.bbWidthMax,
    atr_pct_min: filters.atrPctMin,
    atr_pct_max: filters.atrPctMax,
    stoch_k_min: filters.stochKMin,
    stoch_k_max: filters.stochKMax,
    sort: filters.sort,
  }
}

/** Number or `null`; anything else (missing key, string, NaN) is dropped. */
function storedNumber(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null
}

/**
 * Leniently deserialize a stored definition into a `Partial<ScreenerFilters>`
 * patch. Unknown keys/values are dropped and the RSI values are re-parsed
 * through `parseRsiInput` (clamped to `0..100`), so a stored payload can never
 * produce an invalid URL or an API `422`. Every owned key is returned, so
 * applying restores the saved view exactly.
 */
export function deserializeScreenerFilters(
  payload: SavedScreenerFilters,
): Partial<ScreenerFilters> {
  const storedRsiMin = storedNumber(payload.rsi_min)
  const storedRsiMax = storedNumber(payload.rsi_max)
  const storedMinRvol = storedNumber(payload.min_rvol)
  const storedPatterns = normalizePatterns(Array.isArray(payload.pattern) ? payload.pattern : [])
  const [rsiMin, rsiMax] = orderedRange(
    storedRsiMin === null ? null : parseRsiInput(String(storedRsiMin)),
    storedRsiMax === null ? null : parseRsiInput(String(storedRsiMax)),
  )
  const [minRvol, rvolMax] = orderedRange(
    storedMinRvol !== null && storedMinRvol >= 0 ? Math.min(storedMinRvol, NUMERIC_BOUNDS.rvol[1]) : null,
    boundNumber(payload.rvol_max, 'rvol'),
  )
  const [closeMin, closeMax] = orderedRange(boundNumber(payload.close_min, 'close'), boundNumber(payload.close_max, 'close'))
  const [changeMin, changeMax] = orderedRange(boundNumber(payload.change_min, 'change'), boundNumber(payload.change_max, 'change'))
  // Absent on the 20-key definitions saved before the indicator filters: off.
  const indicators = Object.fromEntries(
    INDICATOR_RANGES.flatMap((range) => {
      const [min, max] = orderedRange(boundNumber(payload[range.minKey], range.column), boundNumber(payload[range.maxKey], range.column))
      return [[range.min, min], [range.max, max]]
    }),
  ) as Pick<ScreenerFilters, IndicatorField>

  return {
    signals: normalizeSignals(Array.isArray(payload.signal) ? payload.signal : []),
    rsiMin,
    rsiMax,
    minRvol,
    priceAboveSma200: payload.price_above_sma200 === true,
    maCross:
      payload.ma_cross === 'bullish' || payload.ma_cross === 'bearish' ? payload.ma_cross : null,
    patterns: storedPatterns,
    patternStatus: storedPatterns.length > 0 ? parsePatternStatus(payload.pattern_status) : 'any',
    // Absent on Screeners saved before market-context-patterns: off.
    sectorTrend: parseSectorTrend(payload.sector_trend),
    sectorRsRankMax: parseSectorRsRankMax(payload.sector_rs_rank_max),
    // Absent on Screeners saved before screener-table-column-controls (and in
    // the natural-language answer): off.
    search: parseTextInput(payload.search, SEARCH_MAX_LENGTH),
    sector: parseSector(payload.sector),
    industry: parseTextInput(payload.industry, INDUSTRY_MAX_LENGTH),
    country: parseCountry(payload.country),
    rvolMax,
    closeMin,
    closeMax,
    changeMin,
    changeMax,
    ...indicators,
    sort: parseScreenerSort(typeof payload.sort === 'string' ? payload.sort : null),
  }
}

/**
 * Map the natural-language interpreter's filters (API param names, already
 * validated server-side) to a complete SPA patch. It goes through the same
 * lenient path as a Saved Screener, so every owned key (sort included) is
 * replaced and nothing can produce an invalid URL.
 */
export function interpretedFiltersToPatch(filters: SavedScreenerFilters): Partial<ScreenerFilters> {
  return deserializeScreenerFilters(filters)
}

/**
 * Compact, human-readable summary of a stored definition in the active
 * language; `formatValue` renders thresholds with the locale's separators.
 * Lenient: a malformed stored payload is described, never thrown on.
 */
export function describeSavedFilters(
  filters: SavedScreenerFilters,
  t: Translate,
  formatValue: (value: number) => string,
): string {
  const parts: string[] = []

  if (Array.isArray(filters.signal) && filters.signal.length > 0) {
    parts.push(filters.signal.map((type) => signalLabel(type, t)).join(', '))
  }
  if (typeof filters.rsi_min === 'number') {
    parts.push(t('saved.describeRsiMin', { value: formatValue(filters.rsi_min) }))
  }
  if (typeof filters.rsi_max === 'number') {
    parts.push(t('saved.describeRsiMax', { value: formatValue(filters.rsi_max) }))
  }
  if (typeof filters.min_rvol === 'number') {
    parts.push(t('saved.describeMinRvol', { value: formatValue(filters.min_rvol) }))
  }
  if (filters.price_above_sma200 === true) {
    parts.push(t('saved.describePriceAboveSma200'))
  }
  if (filters.ma_cross === 'bullish') {
    parts.push(t('saved.describeMaBullish'))
  }
  if (filters.ma_cross === 'bearish') {
    parts.push(t('saved.describeMaBearish'))
  }
  if (Array.isArray(filters.pattern) && filters.pattern.length > 0) {
    const labels = filters.pattern.map((type) => patternLabel(type, t)).join(', ')
    const status = filters.pattern_status
    parts.push(status === 'forming' || status === 'confirmed' ? `${labels} (${t(`patterns.status.${status}`)})` : labels)
  }
  const sectorTrend = parseSectorTrend(filters.sector_trend)
  if (sectorTrend !== null) {
    parts.push(t('screener.sectorContext.summaryTrend', { trend: t(`marketContext.trend.${sectorTrend}`) }))
  }
  const sectorRank = parseSectorRsRankMax(filters.sector_rs_rank_max)
  if (sectorRank !== null) {
    parts.push(t('screener.sectorContext.summaryRank', { n: sectorRank }))
  }

  parts.push(...describeColumnCriteria(deserializeScreenerFilters(filters), t, formatValue))

  const sort = SCREENER_SORT_OPTIONS.find((option) => option.value === filters.sort)
  if (sort !== undefined) {
    parts.push(t('saved.describeSort', { label: t(sort.labelKey) }))
  }

  return parts.length > 0 ? parts.join(' · ') : t('saved.describeNone')
}

/**
 * Summary of an inclusive range on a column: `Cierre 10–20`, `Cierre ≥ 10`
 * or `Cierre ≤ 20`; `null` when both bounds are off.
 */
export function describeRange(
  column: string,
  min: number | null,
  max: number | null,
  t: Translate,
  formatValue: (value: number) => string,
): string | null {
  if (min !== null && max !== null) {
    return t('screener.columns.summary.between', { column, min: formatValue(min), max: formatValue(max) })
  }
  if (min !== null) {
    return t('screener.columns.summary.atLeast', { column, value: formatValue(min) })
  }
  if (max !== null) {
    return t('screener.columns.summary.atMost', { column, value: formatValue(max) })
  }
  return null
}

/** Label of each indicator range (bandwidth has a chip but no column of its own). */
export const INDICATOR_LABEL_KEYS: Record<(typeof INDICATOR_RANGES)[number]['column'], MessageKey> = {
  adx: 'screener.colAdx',
  bbPctb: 'screener.colBbPctb',
  bbWidth: 'screener.colBbWidth',
  atrPct: 'screener.colAtrPct',
  stochK: 'screener.colStochK',
}

/**
 * Localized summaries of the table column criteria that the technical
 * summary does not already describe (RSI and the RVOL minimum are described
 * with the technical criteria).
 */
export function describeColumnCriteria(
  filters: Partial<ScreenerFilters>,
  t: Translate,
  formatValue: (value: number) => string,
): string[] {
  const parts: string[] = []
  if (filters.search) {
    parts.push(t('screener.columns.summary.search', { value: filters.search }))
  }
  if (filters.sector) {
    parts.push(t('screener.columns.summary.sector', { value: sectorLabel(filters.sector, t) }))
  }
  if (filters.industry) {
    parts.push(t('screener.columns.summary.industry', { value: filters.industry }))
  }
  if (filters.country) {
    parts.push(t('screener.columns.summary.country', { value: countryLabel(filters.country, t) }))
  }
  const ranges = [
    describeRange(t('screener.colClose'), filters.closeMin ?? null, filters.closeMax ?? null, t, formatValue),
    describeRange(t('screener.colChange'), filters.changeMin ?? null, filters.changeMax ?? null, t, formatValue),
    describeRange(t('screener.colRvol'), null, filters.rvolMax ?? null, t, formatValue),
    ...INDICATOR_RANGES.map((range) =>
      describeRange(t(INDICATOR_LABEL_KEYS[range.column]), filters[range.min] ?? null, filters[range.max] ?? null, t, formatValue),
    ),
  ]
  for (const range of ranges) {
    if (range !== null) {
      parts.push(range)
    }
  }
  return parts
}

/**
 * Display label for a Signal type in the active language. The raw type string
 * is always what goes over the wire; an unknown type falls back to itself.
 */
export function signalLabel(type: string, t: Translate): string {
  return isKnownSignalType(type) ? t(`signals.${type}` as const) : type
}

/** Display label for a chartist pattern type; unknown types fall back to themselves. */
export function patternLabel(type: string, t: Translate): string {
  return isKnownPatternType(type) ? t(`patterns.types.${type}` as const) : type
}
