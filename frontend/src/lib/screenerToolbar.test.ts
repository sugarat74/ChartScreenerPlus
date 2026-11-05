import { describe, expect, it } from 'vitest'
import { rsiSummary, rvolSelectValues, triggerText } from './screenerToolbar.ts'

describe('screenerToolbar summaries', () => {
  it('summarises the RSI range', () => {
    expect(rsiSummary(null, null)).toBeNull()
    expect(rsiSummary(30, null)).toBe('≥ 30')
    expect(rsiSummary(null, 70)).toBe('≤ 70')
    expect(rsiSummary(30, 70)).toBe('30-70')
    expect(rsiSummary(0, 0)).toBe('0-0')
  })

  it('appends the value only when there is one', () => {
    expect(triggerText('Señales', null)).toBe('Señales')
    expect(triggerText('Señales', 2)).toBe('Señales · 2')
    expect(triggerText('RSI', '30-70')).toBe('RSI · 30-70')
  })

  it('keeps a non-preset RVOL value selectable', () => {
    expect(rvolSelectValues(null)).toEqual([1, 1.5, 2, 3])
    expect(rvolSelectValues(2)).toEqual([1, 1.5, 2, 3])
    expect(rvolSelectValues(2.5)).toEqual([1, 1.5, 2, 2.5, 3])
  })
})
