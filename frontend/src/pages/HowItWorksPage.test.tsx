// @vitest-environment jsdom
import { cleanup, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { GUIDE_TEXTS } from '../i18n/guide/index.ts'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { MESSAGES } from '../i18n/messages/index.ts'
import { SCREENER_PAGE_SIZES } from '../lib/screenerPagination.ts'
import { SCREENER_PATTERN_TYPES, SCREENER_SIGNAL_TYPES } from '../lib/screenerFilters.ts'
import HowItWorksPage from './HowItWorksPage.tsx'

const escapeRegExp = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

function renderPage() {
  return render(
    <I18nProvider>
      <MemoryRouter>
        <HowItWorksPage />
      </MemoryRouter>
    </I18nProvider>,
  )
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => {
  cleanup()
  window.localStorage.clear()
})

describe('HowItWorksPage', () => {
  it('renders the manual with a table of contents pointing at every section', () => {
    renderPage()
    expect(screen.getByRole('heading', { level: 1, name: 'Cómo funciona Chartiko' })).toBeTruthy()

    const toc = screen.getByRole('navigation', { name: 'Contenido' })
    const links = within(toc).getAllByRole('link')
    expect(links).toHaveLength(GUIDE_TEXTS.es.sections.length + 1)
    for (const link of links) {
      const id = link.getAttribute('href')!.slice(1)
      expect(document.getElementById(id), `missing anchor #${id}`).not.toBeNull()
    }
    expect(screen.getByRole('heading', { level: 2, name: 'Indicadores técnicos' })).toBeTruthy()
    expect(screen.getByText(/no constituye asesoramiento de inversión/i)).toBeTruthy()
  })

  it('renders the English manual', () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    renderPage()
    expect(screen.getByRole('heading', { level: 1, name: 'How Chartiko works' })).toBeTruthy()
    expect(screen.getByRole('navigation', { name: 'Contents' })).toBeTruthy()
    expect(screen.getAllByRole('img')).toHaveLength(25)
    expect(screen.getByRole('img', { name: /blue MACD line crosses below the red signal line/i })).toBeTruthy()
    expect(screen.getByRole('img', { name: /short handle and a rise above the handle high/i })).toBeTruthy()
  })

  it('shows an accessible example for every listed indicator, signal and chart pattern', () => {
    renderPage()
    const indicators = screen.getByRole('region', { name: 'Indicadores técnicos' })
    const signals = screen.getByRole('region', { name: 'Señales técnicas' })
    const patterns = screen.getByRole('region', { name: 'Patrones chartistas' })
    expect(within(indicators).getAllByRole('img')).toHaveLength(6)
    expect(within(signals).getAllByRole('img')).toHaveLength(9)
    expect(within(patterns).getAllByRole('img')).toHaveLength(10)
    expect(within(indicators).getByRole('img', { name: /línea horizontal azul que representa el pivote/i })).toBeTruthy()
    expect(within(signals).getByRole('img', { name: /SMA 50 azul cruza de abajo arriba/i })).toBeTruthy()
    expect(within(patterns).getByRole('img', { name: /máximo del asa/i })).toBeTruthy()
    expect(within(indicators).getByText(/datos ficticios/i)).toBeTruthy()
    expect(within(signals).getByText(/datos ficticios/i)).toBeTruthy()
  })
})

describe('manual stays consistent with the interface', () => {
  for (const locale of ['es', 'en'] as const) {
    const guide = GUIDE_TEXTS[locale]
    const messages = MESSAGES[locale]
    const section = (id: string) => guide.sections.find((item) => item.id === id)!
    const terms = (id: string) => (section(id).entries ?? []).map((entry) => entry.term)

    it(`${locale}: every signal is explained under its on-screen name`, () => {
      expect(terms('senales')).toEqual(SCREENER_SIGNAL_TYPES.map((type) => messages.signals[type]))
    })

    it(`${locale}: every chart pattern is explained under its on-screen name`, () => {
      const names = SCREENER_PATTERN_TYPES.map((type) => messages.patterns.types[type])
      expect([...terms('patrones')].sort()).toEqual([...names].sort())
    })

    it(`${locale}: sort options, page sizes and filter labels match the interface`, () => {
      const listNotes = (section('listado').notes ?? []).join(' ')
      // Either the full label is quoted, or «Base» is followed by its qualifier ("«RSI» mayor o menor").
      for (const label of Object.values(messages.sorts)) {
        const [, base, qualifier] = label.match(/^(.*?)\s*\((.*)\)$/) ?? [label, label, '']
        const shorthand = new RegExp(`${escapeRegExp(base)}[»"]\\s+(?:\\S+\\s+(?:o|or)\\s+)?${escapeRegExp(qualifier)}`)
        expect(listNotes.includes(label) || shorthand.test(listNotes), label).toBe(true)
      }
      for (const size of SCREENER_PAGE_SIZES) {
        expect(listNotes).toContain(String(size))
      }
      const filterTerms = terms('filtros').join(' ')
      for (const label of [messages.screener.signalsLegend, messages.screener.minRvolLegend, messages.screener.priceAboveSma200, messages.screener.maCrossLegend, messages.patterns.legend]) {
        expect(filterTerms.toLowerCase(), label).toContain(label.toLowerCase())
      }
    })
  }

  it('never uses end-of-day wording in the public copy', () => {
    expect(JSON.stringify(GUIDE_TEXTS)).not.toMatch(/\bEOD\b|end[- ]of[- ]day|fin de d[ií]a/i)
  })

  it('Spanish and English manuals have the same structure', () => {
    const shape = (locale: 'es' | 'en') =>
      GUIDE_TEXTS[locale].sections.map((section) => ({
        id: section.id,
        paragraphs: section.paragraphs?.length ?? 0,
        steps: section.steps?.length ?? 0,
        entries: (section.entries ?? []).map((entry) => entry.details?.length ?? 0),
        notes: section.notes?.length ?? 0,
      }))
    expect(shape('en')).toEqual(shape('es'))
    expect(GUIDE_TEXTS.en.faq).toHaveLength(GUIDE_TEXTS.es.faq.length)
  })

  it('both manuals illustrate each current indicator and pattern exactly once', () => {
    const expected = ['movingAverages', 'rsi', 'macd', 'rvol', 'pivot', 'volume', 'doubleBottom', 'doubleTop', 'cupWithHandle', 'bullFlag',
      'tripleBottom', 'tripleTop', 'inverseCupWithHandle', 'bearFlag', 'bullPennant', 'bearPennant']
    for (const locale of ['es', 'en'] as const) {
      const sections = GUIDE_TEXTS[locale].sections.filter((item) => item.id === 'indicadores' || item.id === 'patrones')
      const illustrations = sections.flatMap((item) => (item.entries ?? []).map((entry) => entry.illustration))
      expect(illustrations.map((item) => item?.kind)).toEqual(expected)
      expect(illustrations.every((item) => Boolean(item?.alt))).toBe(true)
    }
  })

  it('both manuals illustrate all nine signals with distinct accessible diagrams', () => {
    const expected = ['goldenCross', 'deathCross', 'bullishAlignment', 'bearishAlignment', 'pivotBreakoutRvol', 'rsiOverbought', 'rsiOversold', 'macdBullish', 'macdBearish']
    for (const locale of ['es', 'en'] as const) {
      const signals = GUIDE_TEXTS[locale].sections.find((section) => section.id === 'senales')!
      expect(signals.entries?.map((entry) => entry.illustration?.kind)).toEqual(expected)
      expect(signals.entries?.every((entry) => Boolean(entry.illustration?.alt))).toBe(true)
      expect(signals.notes?.join(' ')).toMatch(locale === 'es' ? /datos ficticios/ : /fictional data/)
    }
  })
})
