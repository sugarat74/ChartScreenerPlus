/**
 * Screener filter helpers. Pure functions with no React dependency so the URL
 * round-trip (parse -> patch -> canonical key) is auditable and deterministic.
 *
 * The SPA owns exactly nine query keys, all named after the screener API
 * params: the criteria (`signal`, `rsi_min`, `rsi_max`, `min_rvol`,
 * `price_above_sma200`, `ma_cross`, `pattern`, `pattern_status`) plus the
 * ranking key `sort`; any other query param (a campaign tag, a future `limit`)
 * is preserved untouched.
 */

import type {
  SavedScreenerFilters,
  ScreenerFilters,
  ScreenerMaCross,
  ScreenerPatternStatus,
  ScreenerPatternType,
  ScreenerSignalType,
  ScreenerSort,
} from './api.ts'
import type { MessageKey, Translate } from '../i18n/translate.ts'

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
]

/** Canonical order of the chartist pattern types (chart-patterns-detect). */
export const SCREENER_PATTERN_TYPES: readonly ScreenerPatternType[] = [
  'double_bottom',
  'double_top',
  'cup_with_handle',
  'bull_flag',
]

export const SCREENER_PATTERN_STATUSES: readonly ScreenerPatternStatus[] = ['any', 'forming', 'confirmed']

/** Preset Min RVOL buttons; `+ Todos` (off) is handled by the control. */
export const MIN_RVOL_PRESETS: readonly number[] = [1, 1.5, 2, 3]

/** API default ranking; also what an absent/invalid `sort` resolves to. */
export const DEFAULT_SCREENER_SORT: ScreenerSort = 'rvol_desc'

/** The six selectable ranking orders (display order); labels live in the catalogs. */
export const SCREENER_SORT_OPTIONS: readonly { value: ScreenerSort; labelKey: MessageKey }[] = [
  { value: 'rvol_desc', labelKey: 'sorts.rvol_desc' },
  { value: 'signal_count_desc', labelKey: 'sorts.signal_count_desc' },
  { value: 'change_desc', labelKey: 'sorts.change_desc' },
  { value: 'change_asc', labelKey: 'sorts.change_asc' },
  { value: 'rsi_desc', labelKey: 'sorts.rsi_desc' },
  { value: 'rsi_asc', labelKey: 'sorts.rsi_asc' },
]

export const EMPTY_SCREENER_FILTERS: ScreenerFilters = {
  signals: [],
  rsiMin: null,
  rsiMax: null,
  minRvol: null,
  priceAboveSma200: false,
  maCross: null,
  patterns: [],
  patternStatus: 'any',
  sort: DEFAULT_SCREENER_SORT,
}

/**
 * The six criterion fields only, for "Limpiar filtros". `sort` is a ranking
 * preference, not a filter, so clearing criteria must preserve the user's
 * selected order.
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
}

function isKnownSignalType(value: string): value is ScreenerSignalType {
  return (SCREENER_SIGNAL_TYPES as readonly string[]).includes(value)
}

/** Dedupe and order arbitrary type strings into the canonical 9-type order. */
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

/** Parse one Min RVOL value: empty/invalid/negative -> `null` (off). */
function parseMinRvol(raw: string | null): number | null {
  if (raw === null || raw.trim() === '') {
    return null
  }
  const value = Number(raw)
  if (!Number.isFinite(value) || value < 0) {
    return null
  }
  return value
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

  return {
    signals: normalizeSignals(rawSignals),
    rsiMin: parseRsiInput(params.get('rsi_min')),
    rsiMax: parseRsiInput(params.get('rsi_max')),
    minRvol: parseMinRvol(params.get('min_rvol')),
    priceAboveSma200: params.get('price_above_sma200') === '1' || params.get('price_above_sma200') === 'true',
    maCross: parseMaCross(params.get('ma_cross')),
    patterns: normalizePatterns(params.getAll('pattern').flatMap((value) => value.split(','))),
    patternStatus: parsePatternStatus(params.get('pattern_status')),
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
  if (patch.sort !== undefined) {
    setOrDelete(next, 'sort', patch.sort === DEFAULT_SCREENER_SORT ? null : patch.sort)
  }

  return next
}

export function hasActiveFilters(filters: ScreenerFilters): boolean {
  return (
    filters.signals.length > 0 ||
    filters.rsiMin !== null ||
    filters.rsiMax !== null ||
    filters.minRvol !== null ||
    filters.priceAboveSma200 ||
    filters.maCross !== null ||
    filters.patterns.length > 0
  )
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
    filters.patterns.length
  )
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
    filters.sort,
  ].join('|')
}

/**
 * Serialize the live filter state into the canonical nine-key definition that
 * is stored with a Saved Screener (API param names, `sort` included). The SPA
 * always emits all nine keys, so a stored definition is always complete and
 * re-appliable without defaults.
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
    pattern_status: filters.patternStatus,
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
  const rsiMin = storedNumber(payload.rsi_min)
  const rsiMax = storedNumber(payload.rsi_max)
  const minRvol = storedNumber(payload.min_rvol)

  return {
    signals: normalizeSignals(Array.isArray(payload.signal) ? payload.signal : []),
    rsiMin: rsiMin === null ? null : parseRsiInput(String(rsiMin)),
    rsiMax: rsiMax === null ? null : parseRsiInput(String(rsiMax)),
    minRvol: minRvol !== null && minRvol >= 0 ? minRvol : null,
    priceAboveSma200: payload.price_above_sma200 === true,
    maCross:
      payload.ma_cross === 'bullish' || payload.ma_cross === 'bearish' ? payload.ma_cross : null,
    patterns: normalizePatterns(Array.isArray(payload.pattern) ? payload.pattern : []),
    patternStatus: parsePatternStatus(payload.pattern_status),
    sort: parseScreenerSort(typeof payload.sort === 'string' ? payload.sort : null),
  }
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

  const sort = SCREENER_SORT_OPTIONS.find((option) => option.value === filters.sort)
  if (sort !== undefined) {
    parts.push(t('saved.describeSort', { label: t(sort.labelKey) }))
  }

  return parts.length > 0 ? parts.join(' · ') : t('saved.describeNone')
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
