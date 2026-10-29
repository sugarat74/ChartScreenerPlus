import { describe, expect, it } from 'vitest'
import { formatDecimal } from '../i18n/format.ts'
import { MESSAGES } from '../i18n/messages/index.ts'
import { translate } from '../i18n/translate.ts'
import type { MessageKey, MessageParams } from '../i18n/translate.ts'
import type { SavedScreenerFilters } from './api.ts'
import {
  EMPTY_SCREENER_CRITERIA,
  forcesIndicatorColumns,
  EMPTY_SCREENER_FILTERS,
  SECTOR_RS_RANK_OPTIONS,
  countActiveFilters,
  describeSavedFilters,
  deserializeScreenerFilters,
  hasActiveFilters,
  parseScreenerFilters,
  patchScreenerFilters,
  screenerFiltersKey,
  serializeScreenerFilters,
  usesMarketContext,
  withoutMarketContext,
} from './screenerFilters.ts'

const es = (key: MessageKey, params?: MessageParams) => translate(MESSAGES.es, key, params)
const en = (key: MessageKey, params?: MessageParams) => translate(MESSAGES.en, key, params)

describe('sector context filters (market-context-patterns)', () => {
  it('parses the two URL params and drops invalid values', () => {
    const parsed = parseScreenerFilters(new URLSearchParams('sector_trend=uptrend&sector_rs_rank_max=3'))
    expect(parsed.sectorTrend).toBe('uptrend')
    expect(parsed.sectorRsRankMax).toBe(3)

    for (const query of ['sector_trend=sideways', 'sector_trend=', 'sector_rs_rank_max=0', 'sector_rs_rank_max=12', 'sector_rs_rank_max=3.5', 'sector_rs_rank_max=abc', 'sector_rs_rank_max=-1']) {
      const filters = parseScreenerFilters(new URLSearchParams(query))
      expect(filters.sectorTrend, query).toBeNull()
      expect(filters.sectorRsRankMax, query).toBeNull()
    }
    expect(SECTOR_RS_RANK_OPTIONS).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11])
  })

  it('patches only its own keys and clears them with null', () => {
    const next = patchScreenerFilters(new URLSearchParams('utm=x&rsi_min=40'), { sectorTrend: 'downtrend', sectorRsRankMax: 11 })
    expect(next.toString()).toBe('utm=x&rsi_min=40&sector_trend=downtrend&sector_rs_rank_max=11')
    expect(patchScreenerFilters(next, { sectorTrend: null, sectorRsRankMax: null }).toString()).toBe('utm=x&rsi_min=40')
    // "Limpiar filtros" clears them too, and keeps the order.
    const cleared = patchScreenerFilters(new URLSearchParams('sector_trend=mixed&sector_rs_rank_max=2&sort=rsi_asc'), EMPTY_SCREENER_CRITERIA)
    expect(cleared.toString()).toBe('sort=rsi_asc')
  })

  it('counts as active criteria and changes the request key', () => {
    const filters = { ...EMPTY_SCREENER_FILTERS, sectorTrend: 'uptrend' as const, sectorRsRankMax: 3 }
    expect(hasActiveFilters(filters)).toBe(true)
    expect(countActiveFilters(filters)).toBe(2)
    expect(hasActiveFilters({ ...EMPTY_SCREENER_FILTERS, sectorRsRankMax: 5 })).toBe(true)
    expect(screenerFiltersKey(filters)).not.toBe(screenerFiltersKey(EMPTY_SCREENER_FILTERS))
    expect(screenerFiltersKey({ ...filters, sectorRsRankMax: 4 })).not.toBe(screenerFiltersKey(filters))
  })

  it('withoutMarketContext drops only the two keys', () => {
    const filters = { ...EMPTY_SCREENER_FILTERS, rsiMin: 40, sectorTrend: 'mixed' as const, sectorRsRankMax: 2 }
    expect(usesMarketContext(filters)).toBe(true)
    expect(withoutMarketContext(filters)).toEqual({ ...EMPTY_SCREENER_FILTERS, rsiMin: 40 })
    expect(usesMarketContext(withoutMarketContext(filters))).toBe(false)
    // Unchanged filters keep their identity (no extra render or request).
    expect(withoutMarketContext(EMPTY_SCREENER_FILTERS)).toBe(EMPTY_SCREENER_FILTERS)
  })

  it('serializes the thirty-key definition', () => {
    const saved = serializeScreenerFilters({ ...EMPTY_SCREENER_FILTERS, sectorTrend: 'uptrend', sectorRsRankMax: 3 })
    expect(Object.keys(saved).sort()).toEqual([
      'adx_max', 'adx_min', 'atr_pct_max', 'atr_pct_min', 'bb_pctb_max', 'bb_pctb_min', 'bb_width_max', 'bb_width_min',
      'change_max', 'change_min', 'close_max', 'close_min', 'country', 'industry',
      'ma_cross', 'min_rvol', 'pattern', 'pattern_status', 'price_above_sma200', 'rsi_max', 'rsi_min', 'rvol_max',
      'search', 'sector', 'sector_rs_rank_max', 'sector_trend', 'signal', 'sort',
      'stoch_k_max', 'stoch_k_min',
    ])
    expect(saved.sector_trend).toBe('uptrend')
    expect(saved.sector_rs_rank_max).toBe(3)
    expect(serializeScreenerFilters(EMPTY_SCREENER_FILTERS).sector_trend).toBeNull()
  })

  it('deserializes a saved definition, a legacy one and a malformed one leniently', () => {
    const base: SavedScreenerFilters = {
      signal: [], rsi_min: null, rsi_max: null, min_rvol: null, price_above_sma200: false, ma_cross: null, sort: 'rvol_desc',
    }
    const restored = deserializeScreenerFilters({ ...base, sector_trend: 'downtrend', sector_rs_rank_max: 11 })
    expect([restored.sectorTrend, restored.sectorRsRankMax]).toEqual(['downtrend', 11])
    // Applying restores both URL params.
    expect(patchScreenerFilters(new URLSearchParams(), restored).toString()).toBe('sector_trend=downtrend&sector_rs_rank_max=11')

    const legacy = deserializeScreenerFilters(base)
    expect([legacy.sectorTrend, legacy.sectorRsRankMax]).toEqual([null, null])

    const odd = deserializeScreenerFilters({ ...base, sector_trend: 'sideways' as never, sector_rs_rank_max: 99 })
    expect([odd.sectorTrend, odd.sectorRsRankMax]).toEqual([null, null])
  })

  it('describes the keys in a saved definition in both languages', () => {
    const definition: SavedScreenerFilters = {
      signal: [], rsi_min: 30, rsi_max: null, min_rvol: null, price_above_sma200: false, ma_cross: null,
      sector_trend: 'uptrend', sector_rs_rank_max: 3, sort: 'rvol_desc',
    }
    expect(describeSavedFilters(definition, es, (value) => formatDecimal('es', value))).toBe(
      'RSI ≥ 30 · Sector Alcista · Top 3 sectores · Orden: Volumen relativo (RVOL mayor)',
    )
    expect(describeSavedFilters(definition, en, (value) => formatDecimal('en', value))).toContain('Sector Uptrend · Top 3 sectors')
  })
})

describe('indicator range filters (screener-technical-filters-ui)', () => {
  it('parses, clamps, orders and patches the ten indicator keys canonically', () => {
    const filters = parseScreenerFilters(
      new URLSearchParams('adx_min=25&adx_max=500&bb_pctb_min=-5&bb_pctb_max=1.5&bb_width_min=20&bb_width_max=5&atr_pct_min=abc&stoch_k_max=20&utm=x'),
    )
    expect(filters).toMatchObject({
      adxMin: 25, adxMax: 100, bbPctbMin: -1, bbPctbMax: 1.5, bbWidthMin: 5, bbWidthMax: 20, atrPctMin: null, atrPctMax: null,
      stochKMin: null, stochKMax: 20,
    })
    expect(countActiveFilters(filters)).toBe(7)
    expect(forcesIndicatorColumns(filters)).toBe(true)

    const rebuilt = patchScreenerFilters(new URLSearchParams('utm=x'), filters)
    expect(rebuilt.get('adx_max')).toBe('100')
    expect(rebuilt.get('utm')).toBe('x')
    expect(parseScreenerFilters(rebuilt)).toEqual(filters)
    expect(screenerFiltersKey(filters)).not.toBe(screenerFiltersKey(EMPTY_SCREENER_FILTERS))
  })

  it('an indicator sort forces the columns; the default does not', () => {
    expect(forcesIndicatorColumns(EMPTY_SCREENER_FILTERS)).toBe(false)
    expect(forcesIndicatorColumns({ ...EMPTY_SCREENER_FILTERS, sort: 'atr_pct_asc' })).toBe(true)
    expect(parseScreenerFilters(new URLSearchParams('sort=bb_width_desc')).sort).toBe('bb_width_desc')
  })

  it('Clear filters removes the ten keys and keeps the sort', () => {
    const before = new URLSearchParams('adx_min=25&stoch_k_max=20&bb_width_min=3&sort=adx_desc')
    const cleared = patchScreenerFilters(before, EMPTY_SCREENER_CRITERIA)
    expect(cleared.toString()).toBe('sort=adx_desc')
  })

  it('round-trips the thirty-key definition and loads a legacy twenty-key row', () => {
    const filters = { ...EMPTY_SCREENER_FILTERS, adxMin: 20, bbPctbMax: 0.2, bbWidthMin: 4, atrPctMax: 3.5, stochKMin: 10, sort: 'stoch_k_asc' as const }
    const saved = serializeScreenerFilters(filters)
    expect(saved).toMatchObject({ adx_min: 20, bb_pctb_max: 0.2, bb_width_min: 4, atr_pct_max: 3.5, stoch_k_min: 10, adx_max: null })
    expect(deserializeScreenerFilters(saved)).toEqual(filters)

    const legacy: SavedScreenerFilters = {
      signal: [], rsi_min: 30, rsi_max: null, min_rvol: null, price_above_sma200: false, ma_cross: null, sort: 'rvol_desc',
    }
    const restored = deserializeScreenerFilters(legacy)
    expect(restored).toMatchObject({ rsiMin: 30, adxMin: null, adxMax: null, stochKMax: null, bbWidthMin: null })
    expect(patchScreenerFilters(new URLSearchParams('adx_min=40'), restored).toString()).toBe('rsi_min=30')
  })

  it('describes the indicator ranges in both languages and ignores malformed stored values', () => {
    const definition: SavedScreenerFilters = {
      signal: [], rsi_min: null, rsi_max: null, min_rvol: null, price_above_sma200: false, ma_cross: null,
      adx_min: 25, bb_width_max: 10, stoch_k_min: 'x' as never, atr_pct_max: 999, sort: 'adx_desc',
    }
    expect(describeSavedFilters(definition, es, (value) => formatDecimal('es', value))).toBe(
      'ADX (14) ≥ 25 · Ancho de bandas % ≤ 10 · ATR % ≤ 100 · Orden: ADX (mayor)',
    )
    expect(describeSavedFilters(definition, en, (value) => formatDecimal('en', value))).toContain('Band width % ≤ 10')
  })
})
