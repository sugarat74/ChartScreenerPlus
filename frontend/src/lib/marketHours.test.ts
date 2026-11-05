import { describe, expect, it } from 'vitest'
import type { MarketStatus } from './api.ts'
import { formatBoundary, marketPhase, needsRefresh } from './marketHours.ts'

const T = (iso: string) => Date.parse(iso)

const US: MarketStatus = {
  code: 'US',
  timezone: 'America/New_York',
  regular_hours: { open: '09:30', close: '16:00' },
  is_open: false,
  opens_at: '2026-07-06T13:30:00Z',
  closes_at: '2026-07-06T20:00:00Z',
  early_close: false,
  next_open_at: '2026-07-07T13:30:00Z',
  next_close_at: '2026-07-07T20:00:00Z',
  calendar_year: 2026,
  calendar_covered: true,
  upcoming: [],
}

describe('marketPhase', () => {
  it('is closed before the open, open at it, and closed again at the close', () => {
    expect(marketPhase(US, T('2026-07-06T13:29:59Z'))).toEqual({ open: false, opensAt: T('2026-07-06T13:30:00Z'), stale: false })
    expect(marketPhase(US, T('2026-07-06T13:30:00Z'))).toEqual({ open: true, closesAt: T('2026-07-06T20:00:00Z') })
    expect(marketPhase(US, T('2026-07-06T19:59:59Z')).open).toBe(true)
    expect(marketPhase(US, T('2026-07-06T20:00:00Z'))).toEqual({ open: false, opensAt: T('2026-07-07T13:30:00Z'), stale: false })
  })

  it('uses the next open on a closed day and flags a payload whose next open has passed', () => {
    const weekend = { ...US, opens_at: null, closes_at: null, next_open_at: '2026-07-06T13:30:00Z' }
    expect(marketPhase(weekend, T('2026-07-05T12:00:00Z'))).toEqual({ open: false, opensAt: T('2026-07-06T13:30:00Z'), stale: false })
    expect(marketPhase(weekend, T('2026-07-06T13:30:00Z'))).toMatchObject({ open: false, stale: true })
    expect(needsRefresh([weekend], T('2026-07-06T13:30:00Z'))).toBe(true)
    expect(needsRefresh([US], T('2026-07-06T15:00:00Z'))).toBe(false)
  })

  it('does not ask for a refresh when there is no next open at all', () => {
    const none = { ...US, opens_at: null, closes_at: null, next_open_at: null }
    expect(needsRefresh([none], T('2026-07-06T15:00:00Z'))).toBe(false)
  })
})

describe('formatBoundary', () => {
  it('formats in the viewer timezone, adding the weekday only for another day', () => {
    const now = T('2026-07-06T13:00:00Z')
    expect(formatBoundary('en', T('2026-07-06T13:30:00Z'), now, 'Europe/Madrid')).toBe('15:30')
    expect(formatBoundary('en', T('2026-07-06T13:30:00Z'), now, 'America/New_York')).toBe('09:30')
    expect(formatBoundary('en', T('2026-07-07T13:30:00Z'), now, 'Europe/Madrid')).toMatch(/^Tue,? 15:30$/)
    expect(formatBoundary('es', T('2026-07-07T13:30:00Z'), now, 'Europe/Madrid')).toMatch(/^mar,? 15:30$/)
  })

  it('compares the viewer day, not the UTC day', () => {
    // 23:30Z is 01:30 the next day in Madrid.
    expect(formatBoundary('en', T('2026-07-06T23:30:00Z'), T('2026-07-06T20:00:00Z'), 'Europe/Madrid')).toMatch(/^Tue,? 01:30$/)
    expect(formatBoundary('en', T('2026-07-06T23:30:00Z'), T('2026-07-06T22:30:00Z'), 'Europe/Madrid')).toBe('01:30')
  })
})
