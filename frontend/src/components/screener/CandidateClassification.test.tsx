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
  const headers = screen.getAllByRole('columnheader').map((header) => header.textContent)
  expect(headers.slice(0, 5)).toEqual(['Símbolo / Empresa', 'Sector', 'Industria', 'País de sede', 'Cierre'])
  const row = screen.getByRole('row', { name: /ACN/ })
  const cells = within(row).getAllByRole('cell')
  expect(cells.slice(0, 3).map((cell) => cell.textContent)).toEqual([
    'Tecnología de la información', 'IT Consulting & Other Services', 'Irlanda',
  ])
  const empty = screen.getByRole('row', { name: /CTVA/ })
  expect(within(empty).getAllByRole('cell').slice(0, 3).map((cell) => cell.textContent)).toEqual(['—', '—', '—'])
  expect(container.querySelector('.overflow-x-auto')).toBeTruthy()
})

it('shows English fixed labels while preserving the original sub-industry', () => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
  show()
  const row = screen.getByRole('row', { name: /ACN/ })
  expect(within(row).getByText('Information technology')).toBeTruthy()
  expect(within(row).getByText('IT Consulting & Other Services')).toBeTruthy()
  expect(within(row).getByText('Ireland')).toBeTruthy()
  expect(screen.getByRole('columnheader', { name: 'HQ country' })).toBeTruthy()
})
