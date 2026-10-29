// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from '../auth/context.ts'
import type { AuthContextValue } from '../auth/context.ts'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { resetLegalInfoCache } from '../legal/useLegalInfo.ts'
import type { ScreenerCandidate } from '../lib/api.ts'
import { INDICATORS_STORAGE_KEY } from '../lib/screenerIndicators.ts'
import ScreenerPage from './ScreenerPage.tsx'

const full: ScreenerCandidate = {
  ticker: 'ACN', company: 'Accenture', sector: 'Information Technology', industry: 'IT Consulting',
  country: 'IE', exchange: 'NYSE', active: true, date: '2026-10-23', close: 100, change_percent: 1, rvol: 1.2, rsi14: 55,
  adx: 25.34, bb_pctb: 0.8765, bb_width: 12.5, atr_pct: 2.345, stoch_k: 81.2, signals: [], patterns: [],
}
const empty: ScreenerCandidate = {
  ...full, ticker: 'NEW', company: 'Newco', adx: null, bb_pctb: null, bb_width: null, atr_pct: null, stoch_k: null,
}
// An older deployment: the fields are simply absent.
const legacy: ScreenerCandidate = { ...full, ticker: 'OLD', company: 'Oldco' }
delete legacy.adx
delete legacy.bb_pctb
delete legacy.bb_width
delete legacy.atr_pct
delete legacy.stoch_k

const requests: string[] = []

function json(body: unknown): Response {
  return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } })
}

function screenerCalls(): string[] {
  return requests.filter((url) => url.startsWith('/api/screener?'))
}

function LocationProbe() {
  return <p data-testid="url">{useLocation().search}</p>
}

const AUTH: AuthContextValue = {
  user: null, status: 'ready', login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn(),
}

function mount(url: string) {
  return render(
    <AuthContext.Provider value={AUTH}>
      <I18nProvider>
        <MemoryRouter initialEntries={[url]}>
          <ScreenerPage />
          <LocationProbe />
        </MemoryRouter>
      </I18nProvider>
    </AuthContext.Provider>,
  )
}

beforeEach(() => {
  requests.length = 0
  resetLegalInfoCache()
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
  vi.stubGlobal('fetch', vi.fn(async (input: string) => {
    requests.push(input)
    if (input === '/api/legal') {
      return json({ privacy_published: true, notice_published: false, owner: null, updated_at: '2026-10-23', retention: {}, market_context: false })
    }
    if (input.startsWith('/api/screener?')) {
      return json({
        universe: { slug: 'sp500', name: 'S&P 500' }, sort: 'rvol_desc', candidates: [full, empty, legacy],
        meta: { limit: 50, offset: 0, returned: 3, total: 3 },
      })
    }
    if (input === '/api/universes') {
      return json({ universes: [] })
    }
    return new Response(null, { status: 204 })
  }))
})
afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  window.localStorage.clear()
})

async function table() {
  return screen.findByRole('table')
}

function headings(): string[] {
  return screen.getAllByRole('columnheader').map((cell) => cell.textContent ?? '')
}

describe('Screener Indicators columns (screener-technical-filters-ui)', () => {
  it('keeps the default table and request unchanged: nine columns, no indicator params', async () => {
    mount('/')
    await table()

    expect(headings()).toHaveLength(9)
    expect(screenerCalls()).toEqual(['/api/screener?sort=rvol_desc&limit=50'])
    expect(screen.queryByTestId('candidate-adx')).toBeNull()
    const toggle = screen.getByRole('button', { name: 'Indicadores' })
    expect(toggle.getAttribute('aria-pressed')).toBe('false')
  })

  it('the toggle adds exactly four columns without a new request and is remembered', async () => {
    const view = mount('/')
    await table()

    fireEvent.click(screen.getByRole('button', { name: 'Indicadores' }))
    expect(headings()).toHaveLength(13)
    expect(headings().slice(9).map((text) => text.replace(/[↕▲▼]/g, '').trim())).toEqual(['ADX (14)', '%B (20, 2)', 'ATR %', 'Estocástico %K'])
    expect(screenerCalls()).toHaveLength(1)
    expect(window.localStorage.getItem(INDICATORS_STORAGE_KEY)).toBe('1')
    expect(screen.getByRole('button', { name: 'Indicadores' }).getAttribute('aria-pressed')).toBe('true')

    view.unmount()
    mount('/')
    await table()
    expect(headings()).toHaveLength(13)

    fireEvent.click(screen.getByRole('button', { name: 'Indicadores' }))
    expect(headings()).toHaveLength(9)
    expect(window.localStorage.getItem(INDICATORS_STORAGE_KEY)).toBeNull()
  })

  it('renders locale numbers and a dash for null or missing values', async () => {
    window.localStorage.setItem(INDICATORS_STORAGE_KEY, '1')
    mount('/')
    await table()

    const rows = screen.getAllByRole('row').slice(1)
    const cells = (row: HTMLElement) => ['adx', 'bbPctb', 'atrPct', 'stochK'].map((id) => within(row).getByTestId(`candidate-${id}`).textContent)
    expect(cells(rows[0])).toEqual(['25,3', '0,88', '2,35', '81,2'])
    expect(cells(rows[1])).toEqual(['—', '—', '—', '—'])
    expect(cells(rows[2])).toEqual(['—', '—', '—', '—'])
  })

  it('an indicator criterion in the URL forces the columns, requests the keys and shows a chip', async () => {
    mount('/?adx_min=25&bb_width_max=10&utm=x')
    await table()

    expect(headings()).toHaveLength(13)
    expect(screenerCalls()).toEqual(['/api/screener?adx_min=25&bb_width_max=10&sort=rvol_desc&limit=50'])
    const toggle = screen.getByRole('button', { name: 'Indicadores' }) as HTMLButtonElement
    expect(toggle.disabled).toBe(true)
    expect(toggle.getAttribute('aria-pressed')).toBe('true')
    expect(screen.getByRole('button', { name: 'Editar filtro: ADX (14) ≥ 25' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Editar filtro: Ancho de bandas % ≤ 10' })).toBeTruthy()
  })

  it('sorts from a heading, exposes the direction and flips it', async () => {
    window.localStorage.setItem(INDICATORS_STORAGE_KEY, '1')
    mount('/')
    await table()

    fireEvent.click(screen.getByRole('button', { name: 'Ordenar por ADX (14)' }))
    await waitFor(() => expect(screen.getByTestId('url').textContent).toBe('?sort=adx_desc'))
    const header = screen.getByRole('columnheader', { name: /ADX \(14\)/ })
    expect(header.getAttribute('aria-sort')).toBe('descending')

    fireEvent.click(screen.getByRole('button', { name: /Ordenar por ADX \(14\)/ }))
    await waitFor(() => expect(screen.getByTestId('url').textContent).toBe('?sort=adx_asc'))
    expect(screen.getByRole('columnheader', { name: /ADX \(14\)/ }).getAttribute('aria-sort')).toBe('ascending')
    await waitFor(() => expect(screenerCalls().at(-1)).toBe('/api/screener?sort=adx_asc&limit=50'))
  })

  it('the %B filter edits the bandwidth too, validates, and Clear filters keeps the sort', async () => {
    mount('/?sort=stoch_k_desc')
    await table()
    // An indicator sort alone reveals the columns.
    expect(headings()).toHaveLength(13)

    fireEvent.click(screen.getByRole('button', { name: 'Filtrar %B (20, 2)' }))
    const panel = await screen.findByRole('region', { name: /Filtrar: %B \(20, 2\)/ })
    fireEvent.change(within(panel).getByLabelText('Mín'), { target: { value: '3' } })
    fireEvent.click(within(panel).getByRole('button', { name: 'Aplicar' }))
    expect(within(panel).getByRole('alert').textContent).toMatch(/entre -1 y 2/)
    expect(screenerCalls()).toHaveLength(1)

    fireEvent.change(within(panel).getByLabelText('Mín'), { target: { value: '0.2' } })
    fireEvent.change(within(panel).getByLabelText('Ancho de bandas %: Mín'), { target: { value: '5' } })
    fireEvent.change(within(panel).getByLabelText('Ancho de bandas %: Máx'), { target: { value: '2' } })
    fireEvent.click(within(panel).getByRole('button', { name: 'Aplicar' }))
    expect(within(panel).getByRole('alert').textContent).toMatch(/mínimo no puede ser mayor/)

    fireEvent.change(within(panel).getByLabelText('Ancho de bandas %: Máx'), { target: { value: '20' } })
    fireEvent.click(within(panel).getByRole('button', { name: 'Aplicar' }))
    await waitFor(() =>
      expect(screen.getByTestId('url').textContent).toBe('?sort=stoch_k_desc&bb_pctb_min=0.2&bb_width_min=5&bb_width_max=20'),
    )
    expect(screen.getByRole('button', { name: /Editar filtro: %B \(20, 2\) ≥ 0,2 · Ancho de bandas % 5–20/ })).toBeTruthy()

    fireEvent.click(screen.getAllByRole('button', { name: 'Limpiar filtros' })[0])
    await waitFor(() => expect(screen.getByTestId('url').textContent).toBe('?sort=stoch_k_desc'))
  })

  it('works when localStorage throws', async () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
      throw new Error('blocked')
    })
    mount('/')
    await table()

    expect(headings()).toHaveLength(9)
    fireEvent.click(screen.getByRole('button', { name: /^(Indicadores|Indicators)$/ }))
    expect(headings()).toHaveLength(13)
    fireEvent.click(screen.getByRole('button', { name: /^(Indicadores|Indicators)$/ }))
    expect(headings()).toHaveLength(9)
  })
})
