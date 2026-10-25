import { describe, expect, it } from 'vitest'
import { translate } from '../i18n/translate.ts'
import type { Translate } from '../i18n/translate.ts'
import { en } from '../i18n/messages/en.ts'
import { es } from '../i18n/messages/es.ts'
import type { SavedScreenerFilters } from './api.ts'
import {
  CANDIDATE_COLUMNS,
  candidateColumn,
  clearColumnPatch,
  columnFilterSummary,
  columnSortDirection,
  isColumnFiltered,
  nextColumnSort,
} from './screenerColumns.ts'
import {
  EMPTY_SCREENER_CRITERIA,
  EMPTY_SCREENER_FILTERS,
  SCREENER_SORT_OPTIONS,
  countActiveFilters,
  describeSavedFilters,
  deserializeScreenerFilters,
  parseScreenerFilters,
  patchScreenerFilters,
  screenerFiltersKey,
  serializeScreenerFilters,
} from './screenerFilters.ts'

const tEs: Translate = (key, params) => translate(es, key, params)
const tEn: Translate = (key, params) => translate(en, key, params)
const plain = (value: number) => String(value)

describe('screener table column URL state (screener-table-column-controls)', () => {
  it('parses every column key and patches it back canonically', () => {
    const query =
      'search=+Apple++Inc+&sector=Health+Care&industry=soft&country=ie&close_min=10&close_max=20.5&change_min=-2&change_max=3&rvol_max=4&sort=company_desc&utm=x'
    const filters = parseScreenerFilters(new URLSearchParams(query))
    expect(filters).toMatchObject({
      search: 'Apple Inc',
      sector: 'Health Care',
      industry: 'soft',
      country: 'IE',
      closeMin: 10,
      closeMax: 20.5,
      changeMin: -2,
      changeMax: 3,
      rvolMax: 4,
      sort: 'company_desc',
    })
    expect(countActiveFilters(filters)).toBe(9)

    const rebuilt = patchScreenerFilters(new URLSearchParams('utm=x'), filters)
    expect(parseScreenerFilters(rebuilt)).toEqual(filters)
    expect(rebuilt.get('country')).toBe('IE')
    expect(rebuilt.get('search')).toBe('Apple Inc')
    expect(rebuilt.get('utm')).toBe('x')
  })

  it('normalizes malformed values so the API is never sent a 422', () => {
    const filters = parseScreenerFilters(
      new URLSearchParams(
        `search=%20%20&sector=Tech&country=USA&close_min=abc&close_max=99999999&change_min=-500&rvol_max=-1&industry=${'x'.repeat(130)}&sort=ticker`,
      ),
    )
    expect(filters.search).toBeNull()
    expect(filters.sector).toBeNull()
    expect(filters.country).toBeNull()
    expect(filters.closeMin).toBeNull()
    expect(filters.closeMax).toBe(1_000_000)
    expect(filters.changeMin).toBe(-100)
    expect(filters.rvolMax).toBe(0)
    expect(filters.industry).toHaveLength(120)
    expect(filters.sort).toBe('rvol_desc')
    // Control and format characters never reach the URL.
    expect(parseScreenerFilters(new URLSearchParams('search=a%07%E2%80%8Bb')).search).toBe('a b')
  })

  it('swaps an inverted range instead of producing an API 422', () => {
    const filters = parseScreenerFilters(new URLSearchParams('close_min=30&close_max=10&rsi_min=70&rsi_max=30&min_rvol=3&rvol_max=1'))
    expect([filters.closeMin, filters.closeMax]).toEqual([10, 30])
    expect([filters.rsiMin, filters.rsiMax]).toEqual([30, 70])
    expect([filters.minRvol, filters.rvolMax]).toEqual([1, 3])
  })

  it('changes the request key for every column criterion and keeps a | inside a fragment safe', () => {
    const base = screenerFiltersKey(EMPTY_SCREENER_FILTERS)
    for (const patch of [
      { search: 'a' }, { sector: 'Energy' }, { industry: 'x' }, { country: 'US' }, { rvolMax: 2 },
      { closeMin: 1 }, { closeMax: 1 }, { changeMin: 1 }, { changeMax: 1 }, { sort: 'close_asc' as const },
    ]) {
      expect(screenerFiltersKey({ ...EMPTY_SCREENER_FILTERS, ...patch })).not.toBe(base)
    }
    expect(screenerFiltersKey({ ...EMPTY_SCREENER_FILTERS, search: 'a|b' })).not.toBe(
      screenerFiltersKey({ ...EMPTY_SCREENER_FILTERS, search: 'a', industry: 'b' }),
    )
  })

  it('clears column criteria with the other filters and keeps the sort', () => {
    const before = new URLSearchParams('search=a&sector=Energy&country=US&industry=x&close_min=1&change_max=2&rvol_max=3&sort=close_asc')
    expect(patchScreenerFilters(before, EMPTY_SCREENER_CRITERIA).toString()).toBe('sort=close_asc')
  })

  it('round-trips through a Saved Screener definition', () => {
    const live = {
      ...EMPTY_SCREENER_FILTERS,
      search: 'micro', sector: 'Information Technology', industry: 'software', country: 'US',
      rvolMax: 5, closeMin: 100, closeMax: 500, changeMin: -1, changeMax: 2, sort: 'industry_asc' as const,
    }
    const saved = serializeScreenerFilters(live)
    expect(saved).toMatchObject({ search: 'micro', country: 'US', close_max: 500, sort: 'industry_asc' })
    expect({ ...EMPTY_SCREENER_FILTERS, ...deserializeScreenerFilters(saved) }).toEqual(live)

    // A definition saved before the column keys existed restores them as off.
    const legacy: SavedScreenerFilters = {
      signal: [], rsi_min: null, rsi_max: null, min_rvol: null, price_above_sma200: false, ma_cross: null, sort: 'rvol_desc',
    }
    expect(deserializeScreenerFilters(legacy)).toMatchObject({ search: null, sector: null, closeMin: null, rvolMax: null })
  })

  it('describes the column criteria of a saved definition in both languages', () => {
    const definition: SavedScreenerFilters = {
      signal: [], rsi_min: null, rsi_max: null, min_rvol: null, price_above_sma200: false, ma_cross: null,
      search: 'micro', sector: 'Health Care', country: 'IE', close_min: 10, close_max: 20, rvol_max: 3, sort: 'close_desc',
    }
    expect(describeSavedFilters(definition, tEs, plain)).toBe(
      'Ticker/empresa contiene «micro» · Sector: Salud · País: Irlanda · Cierre 10–20 · RVOL ≤ 3 · Orden: Cierre (mayor)',
    )
    expect(describeSavedFilters(definition, tEn, plain)).toBe(
      'Ticker/company contains "micro" · Sector: Health care · Country: Ireland · Close 10–20 · RVOL ≤ 3 · Sort: Close (highest)',
    )
  })

  it('offers a localized label for every sort', () => {
    expect(SCREENER_SORT_OPTIONS).toHaveLength(18)
    for (const option of SCREENER_SORT_OPTIONS) {
      expect(tEs(option.labelKey)).not.toBe(option.labelKey)
      expect(tEn(option.labelKey)).not.toBe(option.labelKey)
    }
  })
})

describe('candidate columns', () => {
  it('maps the nine displayed columns to an ascending and a descending sort', () => {
    expect(CANDIDATE_COLUMNS.map((column) => column.id)).toEqual([
      'symbol', 'sector', 'industry', 'country', 'close', 'change', 'rvol', 'rsi', 'signals',
    ])
    const sorts = CANDIDATE_COLUMNS.flatMap((column) => [column.asc, column.desc])
    expect(new Set(sorts).size).toBe(18)
    expect([...sorts].sort()).toEqual(SCREENER_SORT_OPTIONS.map((option) => option.value).sort())
  })

  it('starts text columns A-Z and figures highest first, then flips', () => {
    const symbol = candidateColumn('symbol')
    const close = candidateColumn('close')
    expect(nextColumnSort(symbol, 'rvol_desc')).toBe('company_asc')
    expect(nextColumnSort(symbol, 'company_asc')).toBe('company_desc')
    expect(nextColumnSort(symbol, 'company_desc')).toBe('company_asc')
    expect(nextColumnSort(close, 'rvol_desc')).toBe('close_desc')
    expect(nextColumnSort(close, 'close_desc')).toBe('close_asc')
    expect(columnSortDirection(candidateColumn('rvol'), 'rvol_desc')).toBe('desc')
    expect(columnSortDirection(candidateColumn('signals'), 'signal_count_asc')).toBe('asc')
    expect(columnSortDirection(close, 'rvol_desc')).toBeNull()
  })

  it('reports, summarizes and clears each column criterion', () => {
    const filters = {
      ...EMPTY_SCREENER_FILTERS,
      search: 'abc', minRvol: 1.5, signals: ['golden_cross' as const], patterns: ['bull_flag' as const], changeMax: 2,
    }
    expect(CANDIDATE_COLUMNS.filter((column) => isColumnFiltered(column, filters)).map((column) => column.id)).toEqual([
      'symbol', 'change', 'rvol', 'signals',
    ])
    expect(columnFilterSummary(candidateColumn('rvol'), filters, tEs, plain)).toBe('RVOL ≥ 1.5')
    expect(columnFilterSummary(candidateColumn('change'), filters, tEn, plain)).toBe('Chg % ≤ 2')
    expect(columnFilterSummary(candidateColumn('signals'), filters, tEs, plain)).toBe('Señales y patrones (2)')
    expect(columnFilterSummary(candidateColumn('symbol'), filters, tEs, plain)).toBe('Ticker/empresa contiene «abc»')
    expect(columnFilterSummary(candidateColumn('close'), filters, tEs, plain)).toBeNull()
    expect(clearColumnPatch(candidateColumn('rvol'))).toEqual({ minRvol: null, rvolMax: null })
    expect(clearColumnPatch(candidateColumn('signals'))).toEqual({ signals: [], patterns: [], patternStatus: 'any' })
  })
})
