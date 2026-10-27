// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, RouterProvider, createMemoryRouter, useLocation } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../../auth/AuthContext.tsx'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import type { ScreenerCandidate, ScreenerFilters } from '../../lib/api.ts'
import type { CandidateColumnId } from '../../lib/screenerColumns.ts'
import { EMPTY_SCREENER_FILTERS } from '../../lib/screenerFilters.ts'
import ScreenerPage from '../../pages/ScreenerPage.tsx'
import CandidateTable from './CandidateTable.tsx'
import ScreenerColumnFilters from './ScreenerColumnFilters.tsx'

const row: ScreenerCandidate = {
  ticker: 'ACN', company: 'Accenture', sector: 'Information Technology', industry: 'IT Consulting & Other Services',
  country: 'IE', exchange: 'NYSE', active: true, date: '2026-10-23', close: 100, change_percent: 1, rvol: 1.2, rsi14: 55,
  signals: [], patterns: [],
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  window.localStorage.clear()
})

describe('CandidateTable heading controls', () => {
  function showTable(filters: ScreenerFilters, openColumn: CandidateColumnId | null = null) {
    const onSort = vi.fn()
    const onToggleFilter = vi.fn()
    render(
      <I18nProvider>
        <MemoryRouter>
          <CandidateTable candidates={[row]} controls={{ filters, openColumn, onSort, onToggleFilter }} />
        </MemoryRouter>
      </I18nProvider>,
    )
    return { onSort, onToggleFilter }
  }

  it('gives every heading a sort button and a filter toggle, and exposes the sort state', () => {
    const { onSort } = showTable({ ...EMPTY_SCREENER_FILTERS, sort: 'close_desc' })

    const headers = screen.getAllByRole('columnheader')
    expect(headers).toHaveLength(9)
    expect(headers.map((header) => header.getAttribute('aria-sort'))).toEqual([
      'none', 'none', 'none', 'none', 'descending', 'none', 'none', 'none', 'none',
    ])
    for (const header of headers) {
      expect(within(header).getAllByRole('button')).toHaveLength(2)
    }

    // A sorted column flips; an unsorted one starts in its natural direction.
    fireEvent.click(screen.getByRole('button', { name: 'Ordenar por Cierre, orden descendente' }))
    expect(onSort).toHaveBeenLastCalledWith('close_asc')
    fireEvent.click(screen.getByRole('button', { name: 'Ordenar por Símbolo / Empresa' }))
    expect(onSort).toHaveBeenLastCalledWith('company_asc')
    fireEvent.click(screen.getByRole('button', { name: 'Ordenar por Señales' }))
    expect(onSort).toHaveBeenLastCalledWith('signal_count_desc')
  })

  it('marks an active and an open column filter for assistive technology', () => {
    const { onToggleFilter } = showTable({ ...EMPTY_SCREENER_FILTERS, sector: 'Energy' }, 'rsi')

    const sector = screen.getByRole('button', { name: 'Filtrar Sector (filtro activo)' })
    expect(sector.getAttribute('aria-expanded')).toBe('false')
    const rsi = screen.getByRole('button', { name: 'Filtrar RSI (14)' })
    expect(rsi.getAttribute('aria-expanded')).toBe('true')
    expect(rsi.getAttribute('aria-controls')).toBe('screener-column-filter')

    fireEvent.click(sector)
    expect(onToggleFilter).toHaveBeenLastCalledWith('sector')
  })

  it('labels the controls in English', () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    showTable({ ...EMPTY_SCREENER_FILTERS, sort: 'rvol_asc' })
    expect(screen.getByRole('button', { name: 'Sort by RVOL, ascending order' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Filter HQ country' })).toBeTruthy()
  })
})

describe('ScreenerColumnFilters', () => {
  function showFilters(filters: ScreenerFilters, openColumn: CandidateColumnId | null) {
    const onChange = vi.fn()
    const onOpen = vi.fn()
    const onClose = vi.fn()
    const view = render(
      <I18nProvider>
        <ScreenerColumnFilters filters={filters} openColumn={openColumn} onChange={onChange} onOpen={onOpen} onClose={onClose} />
      </I18nProvider>,
    )
    return { onChange, onOpen, onClose, view }
  }

  it('renders nothing without active column criteria or an open editor', () => {
    const { view } = showFilters({ ...EMPTY_SCREENER_FILTERS, priceAboveSma200: true }, null)
    expect(view.container.textContent).toBe('')
  })

  it('lists active column criteria as chips that edit or remove them', () => {
    const { onChange, onOpen } = showFilters({ ...EMPTY_SCREENER_FILTERS, country: 'IE', closeMin: 10, closeMax: 20 }, null)
    const group = screen.getByRole('group', { name: 'Filtros de columna' })
    expect(within(group).getByText('País: Irlanda')).toBeTruthy()
    expect(within(group).getByText('Cierre 10–20')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'Editar filtro: Cierre 10–20' }))
    expect(onOpen).toHaveBeenLastCalledWith('close')
    fireEvent.click(screen.getByRole('button', { name: 'Quitar filtro: País: Irlanda' }))
    expect(onChange).toHaveBeenLastCalledWith({ country: null })
  })

  it('commits a text fragment on Enter and focuses the field when opened', () => {
    const { onChange, onClose } = showFilters(EMPTY_SCREENER_FILTERS, 'symbol')
    const input = screen.getByLabelText('Ticker o empresa contiene')
    expect(document.activeElement).toBe(input)
    fireEvent.change(input, { target: { value: '  apple ' } })
    fireEvent.submit(input.closest('form')!)
    expect(onChange).toHaveBeenLastCalledWith({ search: '  apple ' })
    expect(onClose).toHaveBeenCalled()
  })

  it('validates numeric bounds before committing them', () => {
    const { onChange } = showFilters(EMPTY_SCREENER_FILTERS, 'rsi')
    const min = screen.getByLabelText('Mín')
    const max = screen.getByLabelText('Máx')

    fireEvent.change(min, { target: { value: '70' } })
    fireEvent.change(max, { target: { value: '30' } })
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }))
    expect(screen.getByRole('alert').textContent).toBe('El mínimo no puede ser mayor que el máximo.')
    expect(min.getAttribute('aria-invalid')).toBe('true')
    expect(onChange).not.toHaveBeenCalled()

    fireEvent.change(max, { target: { value: '120' } })
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }))
    expect(screen.getByRole('alert').textContent).toBe('Introduce números entre 0 y 100.')
    expect(onChange).not.toHaveBeenCalled()

    fireEvent.change(max, { target: { value: '' } })
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }))
    expect(onChange).toHaveBeenLastCalledWith({ rsiMin: 70, rsiMax: null })
  })

  it('commits a canonical sector code from a localized select', () => {
    const { onChange } = showFilters(EMPTY_SCREENER_FILTERS, 'sector')
    const select = screen.getByLabelText('Sector') as HTMLSelectElement
    const options = within(select).getAllByRole('option')
    expect(options).toHaveLength(12)
    expect(options[0].textContent).toBe('Cualquiera')
    fireEvent.change(select, { target: { value: 'Health Care' } })
    expect(onChange).toHaveBeenLastCalledWith({ sector: 'Health Care' })
  })

  it('reuses the signal and pattern selection under Signals', () => {
    const { onChange } = showFilters({ ...EMPTY_SCREENER_FILTERS, signals: ['golden_cross'] }, 'signals')
    const signals = screen.getByRole('group', { name: 'Señales técnicas' })
    const buttons = within(signals).getAllByRole('button')
    expect(buttons).toHaveLength(15)
    fireEvent.click(buttons[0])
    expect(onChange).toHaveBeenLastCalledWith({ signals: [] })
    fireEvent.click(buttons[1])
    expect(onChange).toHaveBeenLastCalledWith({ signals: ['golden_cross', 'death_cross'] })
  })

  it('closes on Escape', () => {
    const { onClose } = showFilters(EMPTY_SCREENER_FILTERS, 'industry')
    fireEvent.keyDown(screen.getByLabelText('Industria contiene'), { key: 'Escape' })
    expect(onClose).toHaveBeenCalled()
  })
})

describe('ScreenerPage column controls', () => {
  const requests: string[] = []

  function LocationProbe() {
    const location = useLocation()
    return <p data-testid="url">{location.search}</p>
  }

  beforeEach(() => {
    requests.length = 0
    vi.stubGlobal('fetch', vi.fn(async (input: string) => {
      if (input.startsWith('/api/screener')) {
        requests.push(input)
        const url = new URL(input, 'http://localhost')
        const total = url.searchParams.get('search') === 'none' ? 0 : 120
        const limit = Number(url.searchParams.get('limit') ?? 50)
        const offset = Number(url.searchParams.get('offset') ?? 0)
        const rows = Array.from({ length: Math.max(0, Math.min(limit, total - offset)) }, (_, i) => ({ ...row, ticker: `T${offset + i + 1}` }))
        return new Response(JSON.stringify({
          universe: { slug: 'sp500', name: 'S&P 500' }, sort: url.searchParams.get('sort'), candidates: rows,
          meta: { limit, offset, returned: rows.length, total },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } })
      }
      if (input === '/api/user') {
        return new Response(JSON.stringify({ message: 'Unauthenticated.' }), { status: 401, headers: { 'Content-Type': 'application/json' } })
      }
      return new Response(JSON.stringify({}), { status: 200, headers: { 'Content-Type': 'application/json' } })
    }))
  })

  function renderAt(path: string) {
    const router = createMemoryRouter([{ path: '/screener', element: <><ScreenerPage /><LocationProbe /></> }], { initialEntries: [path] })
    return render(<I18nProvider><AuthProvider><RouterProvider router={router} /></AuthProvider></I18nProvider>)
  }

  it('sorts from a heading through the URL and the API, restarting at page 1', async () => {
    renderAt('/screener?page=2')
    await screen.findByText('Página 2 de 3')

    fireEvent.click(screen.getByRole('button', { name: 'Ordenar por Industria' }))
    await waitFor(() => expect(screen.getByTestId('url').textContent).toBe('?sort=industry_asc'))
    await waitFor(() => expect(requests.at(-1)).toContain('sort=industry_asc'))
    expect(requests.at(-1)).not.toContain('offset')
    // The ranking select shows the heading's order too.
    expect((screen.getByRole('combobox', { name: 'Ordenar por' }) as HTMLSelectElement).value).toBe('industry_asc')
  })

  it('applies a column filter to the whole result set and keeps the editor reachable when nothing matches', async () => {
    renderAt('/screener?page=2&sort=close_asc')
    await screen.findByText('Página 2 de 3')

    fireEvent.click(screen.getByRole('button', { name: 'Filtrar Símbolo / Empresa' }))
    const input = await screen.findByLabelText('Ticker o empresa contiene')
    fireEvent.change(input, { target: { value: 'none' } })
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar' }))

    await waitFor(() => expect(screen.getByTestId('url').textContent).toBe('?sort=close_asc&search=none'))
    await waitFor(() => expect(requests.at(-1)).toContain('search=none'))
    expect(requests.at(-1)).toContain('sort=close_asc')
    await screen.findByText('Sin candidatos con estos filtros.')
    // The chip stays visible in the empty state.
    expect(screen.getByRole('button', { name: 'Quitar filtro: Ticker/empresa contiene «none»' })).toBeTruthy()

    // "Limpiar filtros" removes column criteria but keeps the sort.
    fireEvent.click(screen.getAllByRole('button', { name: 'Limpiar filtros' })[0])
    await waitFor(() => expect(screen.getByTestId('url').textContent).toBe('?sort=close_asc'))
  })

  it('restores column criteria from a shared URL', async () => {
    renderAt('/screener?country=ie&close_min=10&sort=country_desc')
    await screen.findByText('Página 1 de 3')
    expect(requests.at(-1)).toContain('country=IE')
    expect(requests.at(-1)).toContain('close_min=10')
    expect(requests.at(-1)).toContain('sort=country_desc')
    expect(screen.getByRole('button', { name: 'Editar filtro: País: Irlanda' })).toBeTruthy()
    expect(screen.getByRole('columnheader', { name: /País de sede/ }).getAttribute('aria-sort')).toBe('descending')
  })
})
