import { describe, expect, it } from 'vitest'
import { SUPPORTED_LOCALES } from './locales.ts'
import { MESSAGES } from './messages/index.ts'
import type { Messages } from './messages/es.ts'
import { translate } from './translate.ts'
import type { MessageKey } from './translate.ts'

function flatten(node: unknown, prefix = ''): Record<string, string> {
  const result: Record<string, string> = {}
  for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
    const path = prefix === '' ? key : `${prefix}.${key}`
    if (typeof value === 'string') {
      result[path] = value
    } else {
      Object.assign(result, flatten(value, path))
    }
  }
  return result
}

function placeholders(text: string): string[] {
  return [...new Set([...text.matchAll(/\{(\w+)\}/g)].map((match) => match[1]))].sort()
}

describe('catalog completeness', () => {
  const reference = flatten(MESSAGES.es)

  it('has a catalog for every supported locale', () => {
    expect(Object.keys(MESSAGES).sort()).toEqual([...SUPPORTED_LOCALES].sort())
  })

  it.each(SUPPORTED_LOCALES)('%s has exactly the Spanish keys, non-empty, with the same placeholders', (locale) => {
    const catalog = flatten(MESSAGES[locale])
    expect(Object.keys(catalog).sort()).toEqual(Object.keys(reference).sort())
    for (const [key, text] of Object.entries(reference)) {
      expect(catalog[key].trim(), `${locale}: ${key}`).not.toBe('')
      expect(placeholders(catalog[key]), `${locale}: ${key}`).toEqual(placeholders(text))
    }
  })

  it('keeps EOD wording out of every non-Admin message', () => {
    for (const locale of SUPPORTED_LOCALES) {
      for (const [key, text] of Object.entries(flatten(MESSAGES[locale]))) {
        if (key.startsWith('admin.')) {
          continue
        }
        expect(text, `${locale}: ${key}`).not.toMatch(/\bEOD\b|end[- ]of[- ]day|fin de d[ií]a/i)
      }
    }
  })
})

describe('translate', () => {
  it('interpolates named placeholders', () => {
    expect(translate(MESSAGES.en, 'screener.showing', { returned: 3, total: 10 })).toBe(
      'Showing 3 of 10 candidates',
    )
    expect(translate(MESSAGES.es, 'meta.instrumentTitle', { ticker: 'NVDA' })).toBe(
      'Gráfico de NVDA | Chartiko',
    )
  })

  it('keeps unknown placeholders and the raw template without params', () => {
    expect(translate(MESSAGES.es, 'screener.showing')).toBe('Mostrando {returned} de {total} candidatos')
    expect(translate(MESSAGES.es, 'screener.showing', { returned: 1 })).toBe(
      'Mostrando 1 de {total} candidatos',
    )
  })

  it('falls back to the fallback catalog, then to the key', () => {
    const partial = { ...MESSAGES.en, common: { ...MESSAGES.en.common, retry: undefined } } as unknown as Messages
    expect(translate(partial, 'common.retry', undefined, MESSAGES.es)).toBe('Reintentar')
    expect(translate(partial, 'common.retry')).toBe('common.retry')
    expect(translate(MESSAGES.es, 'missing.key' as MessageKey)).toBe('missing.key')
  })
})
