// @vitest-environment jsdom
import { afterEach, expect, it, vi } from 'vitest'
import {
  CHART_INDICATORS_STORAGE_KEY,
  activePanes,
  chartHeight,
  readStoredIndicators,
  toggleIndicator,
  writeStoredIndicators,
} from './chartIndicators.ts'
import {
  CHART_THEME_COLORS,
  bandSignalLevels,
  lastIndicatorValue,
  noBandData,
  noPaneData,
  toBandData,
  toIndicatorData,
} from './chartData.ts'

afterEach(() => {
  window.localStorage.clear()
  vi.restoreAllMocks()
})

it('defaults to nothing and ignores malformed or unknown stored values', () => {
  expect(readStoredIndicators()).toEqual([])
  window.localStorage.setItem(CHART_INDICATORS_STORAGE_KEY, 'not json')
  expect(readStoredIndicators()).toEqual([])
  window.localStorage.setItem(CHART_INDICATORS_STORAGE_KEY, '{"a":1}')
  expect(readStoredIndicators()).toEqual([])
  window.localStorage.setItem(CHART_INDICATORS_STORAGE_KEY, '["sma","bollinger","bollinger"]')
  expect(readStoredIndicators()).toEqual(['bollinger'])
})

it('round-trips the selection and toggles it', () => {
  writeStoredIndicators(['bollinger'])
  expect(readStoredIndicators()).toEqual(['bollinger'])
  expect(toggleIndicator([], 'bollinger')).toEqual(['bollinger'])
  expect(toggleIndicator(['bollinger'], 'bollinger')).toEqual([])
})

it('never throws when storage is blocked', () => {
  vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
    throw new Error('blocked')
  })
  vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
    throw new Error('blocked')
  })
  expect(readStoredIndicators()).toEqual([])
  expect(() => writeStoredIndicators(['bollinger'])).not.toThrow()
})

const SERIES = [
  { date: '2026-09-30', bb_upper: null, bb_middle: null, bb_lower: null },
  { date: '2026-10-01', bb_upper: 105, bb_middle: 100, bb_lower: 95 },
  { date: '2026-10-02', bb_upper: 106, bb_middle: 101, bb_lower: 96 },
]

it('omits warm-up nulls instead of drawing zeros, keeping ascending order', () => {
  expect(toBandData(SERIES, 'bb_upper')).toEqual([
    { time: '2026-10-01', value: 105 },
    { time: '2026-10-02', value: 106 },
  ])
  expect(toBandData([SERIES[0]], 'bb_middle')).toEqual([])
  expect(noBandData([SERIES[0]])).toBe(true)
  expect(noBandData(SERIES)).toBe(false)
  expect(noBandData([])).toBe(true)
})

it('draws one line only for the two band signals, at the crossed band', () => {
  const titles = { upper: 'UP', lower: 'LOW' }
  expect(
    bandSignalLevels(
      [
        { type: 'bollinger_close_above_upper', metadata: { close: 110, bb_upper: 108 } },
        { type: 'bollinger_close_below_lower', metadata: { close: 90, bb_lower: 92 } },
        { type: 'bollinger_squeeze', metadata: { bandwidth: 3, bb_upper: 1 } },
        { type: 'pivot_breakout_rvol', metadata: { pivot: 100 } },
      ],
      titles,
    ).map((level) => [level.price, level.title]),
  ).toEqual([
    [108, 'UP'],
    [92, 'LOW'],
  ])
  expect(bandSignalLevels([{ type: 'bollinger_close_above_upper', metadata: null }], titles)).toEqual([])
  expect(bandSignalLevels([{ type: 'bollinger_close_below_lower', metadata: { bb_upper: 4 } }], titles)).toEqual([])
})

it('has a band color for both themes', () => {
  expect(CHART_THEME_COLORS.light.band).toMatch(/^#/)
  expect(CHART_THEME_COLORS.dark.band).toMatch(/^#/)
})

const FULL = [
  { date: '2026-10-01', adx: null, plus_di: null, minus_di: null, atr14: null, stoch_k: null, stoch_d: null },
  { date: '2026-10-02', adx: 31.5, plus_di: 27, minus_di: 12, atr14: 2.4, stoch_k: 88, stoch_d: 81 },
  { date: '2026-10-03', adx: 32.5, plus_di: 26, minus_di: 13, atr14: 2.5, stoch_k: 90, stoch_d: 85 },
]

it('keeps the pane selection in the closed order and sizes the chart per pane', () => {
  expect(toggleIndicator(['bollinger'], 'stochastic')).toEqual(['bollinger', 'stochastic'])
  expect(activePanes(['stochastic', 'bollinger', 'adx'])).toEqual(['adx', 'stochastic'])
  expect(activePanes(['bollinger'])).toEqual([])
  expect([0, 1, 2, 3].map(chartHeight)).toEqual([420, 540, 660, 780])
  window.localStorage.setItem(CHART_INDICATORS_STORAGE_KEY, '["stochastic","adx","bogus"]')
  expect(readStoredIndicators()).toEqual(['adx', 'stochastic'])
})

it('draws pane lines without warm-up nulls and ends on the snapshot values', () => {
  expect(toIndicatorData(FULL, 'adx')).toEqual([
    { time: '2026-10-02', value: 31.5 },
    { time: '2026-10-03', value: 32.5 },
  ])
  const snapshot = { adx: 32.5, plus_di: 26, minus_di: 13, atr14: 2.5, stoch_k: 90, stoch_d: 85 }
  for (const key of ['adx', 'plus_di', 'minus_di', 'atr14', 'stoch_k', 'stoch_d'] as const) {
    expect(lastIndicatorValue(FULL, key)).toBe(snapshot[key])
  }
  expect(noPaneData(FULL, 'atr')).toBe(false)
  expect(noPaneData([FULL[0]], 'stochastic')).toBe(true)
  expect(noPaneData([], 'adx')).toBe(true)
})

it('has pane colors for both themes', () => {
  for (const theme of ['light', 'dark'] as const) {
    for (const key of ['adx', 'atr', 'stochK', 'stochD', 'reference'] as const) {
      expect(CHART_THEME_COLORS[theme][key]).toMatch(/^#/)
    }
  }
})
