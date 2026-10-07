import { describe, expect, it } from 'vitest'
import {
  formatChangePercent,
  formatCompact,
  formatDateTime,
  formatDecimal,
  formatMarketDate,
  formatMultiple,
  formatPrice,
} from './format.ts'

describe('locale-aware number formatting', () => {
  it('uses the decimal separator of the locale', () => {
    expect(formatPrice('en', 1234.5)).toBe('1,234.50')
    expect(formatPrice('es', 1234.5)).toBe('1234,50')
    expect(formatPrice('es', 12345.5)).toBe('12.345,50')
  })

  it('keeps an explicit sign on changes', () => {
    expect(formatChangePercent('en', 1.234)).toBe('+1.23%')
    expect(formatChangePercent('es', -1.234)).toBe('-1,23%')
    expect(formatChangePercent('es', 0)).toBe('0,00%')
  })

  it('formats thresholds without padding', () => {
    expect(formatDecimal('es', 1.5)).toBe('1,5')
    expect(formatDecimal('en', 1.5)).toBe('1.5')
    expect(formatDecimal('es', 30)).toBe('30')
    expect(formatDecimal('en', null)).toBe('—')
  })

  it('formats multiples and compact volumes', () => {
    expect(formatMultiple('en', 2.25)).toBe('2.3x')
    expect(formatMultiple('es', 1.5)).toBe('1,5x')
    expect(formatCompact('en', 1_500_000)).toBe('1.5M')
    expect(formatCompact('es', 1_500_000)).toMatch(/^1,5\s?M$/)
  })

  it('renders missing values as a dash in every locale', () => {
    for (const locale of ['es', 'en'] as const) {
      expect(formatPrice(locale, null)).toBe('—')
      expect(formatPrice(locale, Number.NaN)).toBe('—')
      expect(formatMultiple(locale, null)).toBe('—')
      expect(formatChangePercent(locale, null)).toBe('—')
      expect(formatDateTime(locale, null)).toBe('—')
      expect(formatDateTime(locale, 'not-a-date')).toBe('—')
    }
  })
})

describe('market-session dates', () => {
  it('formats the calendar day without a time-zone shift', () => {
    expect(formatMarketDate('en', '2026-10-05')).toBe('Oct 5, 2026')
    expect(formatMarketDate('es', '2026-10-05')).toBe('5 oct 2026')
    expect(formatMarketDate('en', '2026-01-01')).toBe('Jan 1, 2026')
  })

  it('passes through values that are not ISO calendar dates', () => {
    expect(formatMarketDate('es', '2026/10/05')).toBe('2026/10/05')
    expect(formatMarketDate('en', null)).toBe('—')
  })
})
