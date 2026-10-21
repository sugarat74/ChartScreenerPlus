import { describe, expect, it } from 'vitest'
import { patternLevels, patternMarkers } from './chartData.ts'
import {
  EMPTY_SCREENER_FILTERS,
  SCREENER_PATTERN_GROUPS,
  SCREENER_PATTERN_TYPES,
  normalizePatterns,
  patternDirection,
  countActiveFilters,
  deserializeScreenerFilters,
  parseScreenerFilters,
  patchScreenerFilters,
  screenerFiltersKey,
  serializeScreenerFilters,
} from './screenerFilters.ts'
import type { SavedScreenerFilters } from './api.ts'

describe('pattern filters in the URL (chart-patterns-ui)', () => {
  it('parses known patterns in canonical order and drops unknown values', () => {
    const filters = parseScreenerFilters(
      new URLSearchParams('pattern=bull_flag,triangle&pattern=double_bottom&pattern_status=confirmed'),
    )
    expect(filters.patterns).toEqual(['double_bottom', 'bull_flag'])
    expect(filters.patternStatus).toBe('confirmed')
    expect(parseScreenerFilters(new URLSearchParams('pattern_status=maybe')).patternStatus).toBe('any')
    // A status without patterns is never kept (it would apply silently later).
    expect(parseScreenerFilters(new URLSearchParams('pattern_status=confirmed')).patternStatus).toBe('any')
    expect(
      serializeScreenerFilters({ ...EMPTY_SCREENER_FILTERS, patterns: [], patternStatus: 'confirmed' }).pattern_status,
    ).toBe('any')
  })

  it('patches only its own keys and omits the default status', () => {
    const next = patchScreenerFilters(new URLSearchParams('utm=x'), {
      patterns: ['cup_with_handle', 'double_top'],
      patternStatus: 'forming',
    })
    // Canonical SPA order: bullish group first (chart-patterns-extended).
    expect(next.get('pattern')).toBe('cup_with_handle,double_top')
    expect(next.get('pattern_status')).toBe('forming')
    expect(next.get('utm')).toBe('x')

    const cleared = patchScreenerFilters(next, { patterns: [], patternStatus: 'any' })
    expect(cleared.has('pattern')).toBe(false)
    expect(cleared.has('pattern_status')).toBe(false)
  })

  it('counts each selected pattern as an active criterion and changes the request key', () => {
    const filters = { ...EMPTY_SCREENER_FILTERS, patterns: ['double_top' as const, 'bull_flag' as const] }
    expect(countActiveFilters(filters)).toBe(2)
    expect(screenerFiltersKey(filters)).not.toBe(screenerFiltersKey(EMPTY_SCREENER_FILTERS))
  })

  it('serializes nine keys and restores Saved Screeners saved before patterns', () => {
    const serialized = serializeScreenerFilters({
      ...EMPTY_SCREENER_FILTERS,
      patterns: ['bull_flag', 'double_bottom'],
      patternStatus: 'confirmed',
    })
    expect(serialized.pattern).toEqual(['double_bottom', 'bull_flag'])
    expect(serialized.pattern_status).toBe('confirmed')

    const legacy: SavedScreenerFilters = {
      signal: ['golden_cross'],
      rsi_min: null,
      rsi_max: null,
      min_rvol: null,
      price_above_sma200: false,
      ma_cross: null,
      sort: 'rvol_desc',
    }
    const restored = deserializeScreenerFilters(legacy)
    expect(restored.patterns).toEqual([])
    expect(restored.patternStatus).toBe('any')
  })
})

describe('pattern chart helpers', () => {
  const bars = ['2026-01-05', '2026-01-30', '2026-02-20'].map((date) => ({
    date,
    open: 1,
    high: 1,
    low: 1,
    close: 1,
    volume: 1,
  }))
  const pattern = {
    type: 'double_bottom',
    status: 'forming',
    breakout_level: 115,
    points: [
      { date: '2026-02-20', price: 100.5, role: 'right_low' },
      { date: '2025-12-01', price: 99, role: 'left_low' },
      { date: '2026-01-30', price: 115, role: 'peak' },
    ],
  }

  it('draws one breakout line per pattern and never a target', () => {
    const levels = patternLevels([pattern, { ...pattern, breakout_level: Number.NaN }], () => 'Doble suelo')
    expect(levels).toEqual([{ price: 115, title: 'Doble suelo', color: '#0055ff' }])
  })

  it('places markers only on loaded bars, sorted, highs above and lows below', () => {
    const markers = patternMarkers([pattern], bars, (role) => role.toUpperCase())
    expect(markers.map((marker) => [marker.time, marker.position, marker.text])).toEqual([
      ['2026-01-30', 'aboveBar', 'PEAK'],
      ['2026-02-20', 'belowBar', 'RIGHT_LOW'],
    ])
  })

  it('places the second-batch highs above the bar and lows below (chart-patterns-extended)', () => {
    const dates = ['2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05', '2026-01-06']
    const loaded = dates.map((date) => ({ ...bars[0], date }))
    const triple = {
      ...pattern,
      type: 'triple_top',
      points: [
        { date: dates[0], price: 100, role: 'left_peak' },
        { date: dates[1], price: 85, role: 'left_trough' },
        { date: dates[2], price: 100.5, role: 'middle_peak' },
        { date: dates[3], price: 85.5, role: 'right_trough' },
        { date: dates[4], price: 100.2, role: 'right_peak' },
      ],
    }
    expect(patternMarkers([triple], loaded, (role) => role).map((marker) => marker.position)).toEqual([
      'aboveBar', 'belowBar', 'aboveBar', 'belowBar', 'aboveBar',
    ])
    const sides = (roles: string[]) =>
      patternMarkers(
        [{ ...pattern, points: roles.map((role, index) => ({ date: dates[index], price: 1, role })) }],
        loaded,
        (role) => role,
      ).map((marker) => marker.position)
    expect(sides(['rim_left_low', 'cup_top', 'rim_right_low', 'handle_high'])).toEqual(['belowBar', 'aboveBar', 'belowBar', 'aboveBar'])
    expect(sides(['pole_peak', 'pole_bottom', 'flag_high'])).toEqual(['aboveBar', 'belowBar', 'aboveBar'])
    expect(sides(['pole_peak', 'pole_bottom', 'pennant_high'])).toEqual(['aboveBar', 'belowBar', 'aboveBar'])
    expect(sides(['pole_start', 'pole_top', 'pennant_low'])).toEqual(['belowBar', 'aboveBar', 'belowBar'])
    expect(sides(['left_low', 'left_crest', 'middle_low', 'right_crest', 'right_low'])).toEqual([
      'belowBar', 'aboveBar', 'belowBar', 'aboveBar', 'belowBar',
    ])
  })
})

describe('pattern vocabulary (chart-patterns-extended)', () => {
  it('has ten types grouped bullish first, then bearish', () => {
    expect(SCREENER_PATTERN_TYPES).toHaveLength(10)
    expect(SCREENER_PATTERN_GROUPS.map((group) => group.direction)).toEqual(['bullish', 'bearish'])
    expect(patternDirection('bear_pennant')).toBe('bearish')
    expect(patternDirection('triple_bottom')).toBe('bullish')
    expect(patternDirection('inverse_cup_with_handle')).toBe('bearish')
    expect(normalizePatterns(['bear_flag', 'nope', 'triple_bottom', 'bear_flag'])).toEqual(['triple_bottom', 'bear_flag'])
  })
})
