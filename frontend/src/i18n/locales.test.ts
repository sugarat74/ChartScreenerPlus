import { describe, expect, it } from 'vitest'
import {
  DEFAULT_LOCALE,
  LOCALE_DEFINITIONS,
  LOCALE_STORAGE_KEY,
  SUPPORTED_LOCALES,
  matchSupportedLocale,
  readStoredLocale,
  resolveLocale,
  writeStoredLocale,
} from './locales.ts'

function memoryStorage(initial: Record<string, string> = {}) {
  const data = new Map(Object.entries(initial))
  return {
    getItem: (key: string) => data.get(key) ?? null,
    setItem: (key: string, value: string) => {
      data.set(key, value)
    },
    data,
  }
}

const throwingStorage = {
  getItem: (): string | null => {
    throw new DOMException('blocked', 'SecurityError')
  },
  setItem: (): void => {
    throw new DOMException('quota', 'QuotaExceededError')
  },
}

describe('locale registry', () => {
  it('defaults to Spanish and describes every supported locale', () => {
    expect(DEFAULT_LOCALE).toBe('es')
    expect(SUPPORTED_LOCALES).toContain(DEFAULT_LOCALE)
    for (const code of SUPPORTED_LOCALES) {
      expect(LOCALE_DEFINITIONS[code].nativeName.length).toBeGreaterThan(0)
      expect(() => new Intl.NumberFormat(LOCALE_DEFINITIONS[code].intl)).not.toThrow()
    }
  })
})

describe('matchSupportedLocale', () => {
  it.each([
    ['en', 'en'],
    ['en-GB', 'en'],
    ['EN_us', 'en'],
    [' es-419 ', 'es'],
    ['es', 'es'],
  ])('maps %j to %j', (tag, expected) => {
    expect(matchSupportedLocale(tag)).toBe(expected)
  })

  it.each(['fr', 'de-DE', '', '***', null, undefined])('rejects %j', (tag) => {
    expect(matchSupportedLocale(tag)).toBeNull()
  })
})

describe('resolveLocale', () => {
  it('prefers an explicit saved choice over the browser', () => {
    expect(resolveLocale('en', ['es-ES'])).toBe('en')
    expect(resolveLocale('es', ['en-US'])).toBe('es')
  })

  it('uses the first supported browser language, regional variants included', () => {
    expect(resolveLocale(null, ['fr-FR', 'en-GB', 'es'])).toBe('en')
    expect(resolveLocale(undefined, ['es-MX'])).toBe('es')
  })

  it('ignores an invalid saved value and falls back safely', () => {
    expect(resolveLocale('klingon', ['en-US'])).toBe('en')
    expect(resolveLocale('EN', [])).toBe('es')
  })

  it('falls back to Spanish when nothing is supported', () => {
    expect(resolveLocale(null, ['fr-FR', 'de'])).toBe('es')
    expect(resolveLocale(null, [])).toBe('es')
  })
})

describe('locale persistence', () => {
  it('round-trips the choice through storage', () => {
    const storage = memoryStorage()
    expect(writeStoredLocale('en', storage)).toBe(true)
    expect(storage.data.get(LOCALE_STORAGE_KEY)).toBe('en')
    expect(readStoredLocale(storage)).toBe('en')
    expect(resolveLocale(readStoredLocale(storage), ['es-ES'])).toBe('en')
  })

  it('never throws when storage is unavailable or blocked', () => {
    expect(readStoredLocale(null)).toBeNull()
    expect(writeStoredLocale('en', null)).toBe(false)
    expect(readStoredLocale(throwingStorage)).toBeNull()
    expect(writeStoredLocale('en', throwingStorage)).toBe(false)
    expect(resolveLocale(readStoredLocale(throwingStorage), ['en-US'])).toBe('en')
  })
})
