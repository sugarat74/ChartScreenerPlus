import { describe, expect, it } from 'vitest'
import type { ScreenerUniverse } from './api.ts'
import {
  canonicalUniverseParams,
  hasSectorContext,
  parseUniverseParam,
  resolveUniverse,
  switchUniverse,
  withUniverseParam,
} from './screenerUniverse.ts'

const SP500: ScreenerUniverse = { slug: 'sp500', name: 'S&P 500', market: 'US', currency: 'USD', members: 500, latest_date: '2026-10-23', is_default: true }
const NDX: ScreenerUniverse = { slug: 'nasdaq100', name: 'Nasdaq-100', market: 'US', currency: 'USD', members: 100, latest_date: '2026-10-23', is_default: false }
const IBEX: ScreenerUniverse = { slug: 'ibex35', name: 'IBEX 35', market: 'XMAD', currency: 'EUR', members: 35, latest_date: '2026-10-23', is_default: false }
const ALL = [SP500, NDX, IBEX]

describe('universe URL state', () => {
  it('parses a well-formed slug and ignores junk', () => {
    expect(parseUniverseParam(new URLSearchParams('universe=ibex35'))).toBe('ibex35')
    expect(parseUniverseParam(new URLSearchParams('universe=<script>'))).toBeNull()
    expect(parseUniverseParam(new URLSearchParams(''))).toBeNull()
  })

  it('resolves an unknown or absent slug to the default universe', () => {
    expect(resolveUniverse('ibex35', ALL)).toBe(IBEX)
    expect(resolveUniverse('nope', ALL)).toBe(SP500)
    expect(resolveUniverse(null, ALL)).toBe(SP500)
    expect(resolveUniverse(null, [])).toBeNull()
  })

  it('drops an unknown, hidden or default slug and a slug when only one universe is listed', () => {
    expect(canonicalUniverseParams(new URLSearchParams('universe=nope&rsi_min=30'), ALL)?.toString()).toBe('rsi_min=30')
    expect(canonicalUniverseParams(new URLSearchParams('universe=sp500'), ALL)?.toString()).toBe('')
    expect(canonicalUniverseParams(new URLSearchParams('universe=ibex35'), [SP500])?.toString()).toBe('')
    expect(canonicalUniverseParams(new URLSearchParams('universe=ibex35'), ALL)).toBeNull()
    expect(canonicalUniverseParams(new URLSearchParams('rsi_min=30'), ALL)).toBeNull()
  })

  it('only a US universe has the sector context', () => {
    expect(hasSectorContext(SP500)).toBe(true)
    expect(hasSectorContext(NDX)).toBe(true)
    expect(hasSectorContext(IBEX)).toBe(false)
    expect(hasSectorContext(null)).toBe(true)
  })

  it('withUniverseParam sets or removes the slug', () => {
    expect(withUniverseParam(new URLSearchParams('a=1'), 'ibex35').toString()).toBe('a=1&universe=ibex35')
    expect(withUniverseParam(new URLSearchParams('universe=ibex35&a=1'), null).toString()).toBe('a=1')
  })
})

describe('switchUniverse', () => {
  const FILTERS =
    'signal=golden_cross&rsi_max=40&search=bank&sector=Financials&industry=x&country=ES&sort=close_desc&page=3&limit=100&utm=1'

  it('omits the param for the default universe and resets the page, keeping everything compatible', () => {
    const next = switchUniverse(new URLSearchParams(`universe=nasdaq100&${FILTERS}`), SP500, NDX)
    expect(next.has('universe')).toBe(false)
    expect(next.has('page')).toBe(false)
    expect(next.get('signal')).toBe('golden_cross')
    expect(next.get('rsi_max')).toBe('40')
    expect(next.get('search')).toBe('bank')
    expect(next.get('sector')).toBe('Financials')
    expect(next.get('industry')).toBe('x')
    expect(next.get('country')).toBe('ES')
    expect(next.get('sort')).toBe('close_desc')
    expect(next.get('limit')).toBe('100')
    expect(next.get('utm')).toBe('1')
  })

  it('keeps the Close bounds while the currency is the same', () => {
    const next = switchUniverse(new URLSearchParams('close_min=10&close_max=50'), NDX, SP500)
    expect(next.get('universe')).toBe('nasdaq100')
    expect(next.get('close_min')).toBe('10')
    expect(next.get('close_max')).toBe('50')
  })

  it('clears the Close bounds when the currency changes, in both directions', () => {
    const toEur = switchUniverse(new URLSearchParams('close_min=10&close_max=50&change_min=1'), IBEX, SP500)
    expect(toEur.get('universe')).toBe('ibex35')
    expect(toEur.has('close_min')).toBe(false)
    expect(toEur.has('close_max')).toBe(false)
    expect(toEur.get('change_min')).toBe('1')

    const back = switchUniverse(new URLSearchParams('universe=ibex35&close_min=5'), SP500, IBEX)
    expect(back.has('close_min')).toBe(false)
  })

  it('clears the sector context keys only for a universe without them', () => {
    const params = 'sector_trend=uptrend&sector_rs_rank_max=3'
    expect(switchUniverse(new URLSearchParams(params), IBEX, SP500).toString()).toBe('universe=ibex35')
    const kept = switchUniverse(new URLSearchParams(params), NDX, SP500)
    expect(kept.get('sector_trend')).toBe('uptrend')
    expect(kept.get('sector_rs_rank_max')).toBe('3')
  })
})
