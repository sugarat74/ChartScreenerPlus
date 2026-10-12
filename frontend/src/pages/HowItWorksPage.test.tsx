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
      for (const label of Object.values(messages.sorts)) {
        const base = label.replace(/\s*\(.*\)$/, '')
        expect(listNotes, label).toContain(base)
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
})
