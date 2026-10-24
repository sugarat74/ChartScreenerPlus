// @vitest-environment jsdom
import { cleanup, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, expect, it } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import type { ScreenerCandidate } from '../../lib/api.ts'
import CandidateTable from './CandidateTable.tsx'

const known: ScreenerCandidate = {
  ticker: 'ACN', company: 'Accenture', sector: 'Information Technology',
  industry: 'IT Consulting & Other Services', country: 'IE', exchange: 'NYSE', active: true,
  date: '2026-10-23', close: 100, change_percent: 1, rvol: 1.2, rsi14: 55,
  signals: [], patterns: [],
}
const unknown: ScreenerCandidate = {
  ...known, ticker: 'CTVA', company: 'Corteva', sector: null, industry: null, country: null,
}

function show() {
  return render(<I18nProvider><MemoryRouter><CandidateTable candidates={[known, unknown]} /></MemoryRouter></I18nProvider>)
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => { cleanup(); window.localStorage.clear() })

it('shows translated sector and HQ country, source industry, and honest unknowns in Spanish', () => {
  const { container } = show()
  const row = screen.getByRole('row', { name: /ACN/ })
  expect(within(row).getByText('Tecnología de la información')).toBeTruthy()
  expect(within(row).getByText('IT Consulting & Other Services')).toBeTruthy()
  expect(within(row).getByText('Irlanda')).toBeTruthy()
  expect(within(row).getByText('País de sede:')).toBeTruthy()
  const empty = screen.getByRole('row', { name: /CTVA/ })
  expect(within(empty.querySelector('dl')!).getAllByText('—')).toHaveLength(3)
  expect(container.querySelector('.overflow-x-auto')).toBeTruthy()
  expect(container.querySelector('dl.flex.flex-wrap')).toBeTruthy()
})

it('shows English fixed labels while preserving the original sub-industry', () => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
  show()
  const row = screen.getByRole('row', { name: /ACN/ })
  expect(within(row).getByText('Information technology')).toBeTruthy()
  expect(within(row).getByText('IT Consulting & Other Services')).toBeTruthy()
  expect(within(row).getByText('Ireland')).toBeTruthy()
  expect(within(row).getByText('HQ country:')).toBeTruthy()
})
