// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { INDICATORS_STORAGE_KEY, readIndicatorsToggle, writeIndicatorsToggle } from './screenerIndicators.ts'

afterEach(() => {
  vi.restoreAllMocks()
  window.localStorage.clear()
})

describe('Indicators toggle storage', () => {
  it('defaults to off and round-trips', () => {
    expect(readIndicatorsToggle()).toBe(false)
    writeIndicatorsToggle(true)
    expect(window.localStorage.getItem(INDICATORS_STORAGE_KEY)).toBe('1')
    expect(readIndicatorsToggle()).toBe(true)
    writeIndicatorsToggle(false)
    expect(readIndicatorsToggle()).toBe(false)
  })

  it('never throws when storage is blocked', () => {
    const boom = () => {
      throw new Error('blocked')
    }
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(boom)
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(boom)
    vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(boom)
    expect(readIndicatorsToggle()).toBe(false)
    expect(() => writeIndicatorsToggle(true)).not.toThrow()
    expect(() => writeIndicatorsToggle(false)).not.toThrow()
  })
})
