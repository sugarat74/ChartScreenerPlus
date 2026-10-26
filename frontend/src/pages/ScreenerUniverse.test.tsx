// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from '../auth/context.ts'
import type { AuthContextValue } from '../auth/context.ts'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { resetLegalInfoCache } from '../legal/useLegalInfo.ts'
import type { AuthUser, SavedScreener, ScreenerUniverse } from '../lib/api.ts'
import ScreenerPage from './ScreenerPage.tsx'

const SP500: ScreenerUniverse = { slug: 'sp500', name: 'S&P 500', market: 'US', currency: 'USD', members: 500, latest_date: '2026-10-23', is_default: true }
const IBEX: ScreenerUniverse = { slug: 'ibex35', name: 'IBEX 35', market: 'XMAD', currency: 'EUR', members: 35, latest_date: '2026-10-22', is_default: false }
const NDX: ScreenerUniverse = { slug: 'nasdaq100', name: 'Nasdaq-100', market: 'US', currency: 'USD', members: 100, latest_date: '2026-10-23', is_default: false }

const USER: AuthUser = { id: 1, name: 'Ana', email: 'ana@example.test', role: 'user', capabilities: { market_context: true } }

let universes: ScreenerUniverse[] = []
let saved: SavedScreener[] = []
const requests: string[] = []

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function screenerCalls(): string[] {
  return requests.filter((url) => url.startsWith('/api/screener?'))
}

function LocationProbe() {
  const location = useLocation()
  return <p data-testid="url">{location.search}</p>
}

function auth(user: AuthUser | null): AuthContextValue {
  return { user, status: 'ready', login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn() }
}

function tree(user: AuthUser | null, url: string) {
  return (
    <AuthContext.Provider value={auth(user)}>
      <I18nProvider>
        <MemoryRouter initialEntries={[url]}>
          <ScreenerPage />
          <LocationProbe />
        </MemoryRouter>
      </I18nProvider>
    </AuthContext.Provider>
  )
}

function candidate(ticker: string, currency: string, market: string) {
  return {
    ticker, company: `${ticker} Corp`, sector: 'Financials', industry: null, country: 'ES', exchange: 'BME', active: true,
    date: '2026-10-22', close: 12.5, change_percent: 1, rvol: 1.1, rsi14: 50, signals: [], patterns: [], market, currency,
  }
}

beforeEach(() => {
  universes = [SP500, IBEX, NDX]
  saved = []
  requests.length = 0
  resetLegalInfoCache()
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
  vi.stubGlobal('fetch', vi.fn(async (input: string, init?: RequestInit) => {
    requests.push(input)
    if (input === '/api/legal') {
      return json({ privacy_published: true, notice_published: false, owner: null, updated_at: '2026-10-23', retention: {}, market_context: true })
    }
    if (input === '/api/universes') {
      return json({ universes })
    }
    if (input === '/api/screeners' && (init?.method ?? 'GET') === 'GET') {
      return json({ screeners: saved })
    }
    if (input.startsWith('/api/screener?')) {
      const eu = input.includes('universe=ibex35')
      return json({
        universe: eu ? { slug: 'ibex35', name: 'IBEX 35' } : { slug: 'sp500', name: 'S&P 500' },
        sort: 'rvol_desc',
        candidates: eu ? [candidate('SAN.MC', 'EUR', 'XMAD')] : [candidate('AAPL', 'USD', 'US')],
        meta: { limit: 50, offset: 0, returned: 1, total: 1 },
      })
    }
    return new Response(null, { status: 204 })
  }))
})
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  window.localStorage.clear()
})

describe('ScreenerPage universe selector (universe-selector-ui)', () => {
  it('shows nothing extra while only one universe is public and keeps the plain Close heading', async () => {
    universes = [SP500]
    render(tree(null, '/'))

    await waitFor(() => expect(screenerCalls()).toHaveLength(1))
    await screen.findByText('AAPL')
    expect(screenerCalls()[0]).toBe('/api/screener?sort=rvol_desc&limit=50')
    expect(screen.queryByRole('group', { name: 'Universo' })).toBeNull()
    expect(screen.queryByRole('combobox', { name: 'Universo' })).toBeNull()
    expect(screen.getAllByRole('columnheader').some((header) => header.textContent?.startsWith('Cierre'))).toBe(true)
    expect(screen.queryByText('Cierre (USD)')).toBeNull()
    expect(screen.queryByText('Cierre (EUR)')).toBeNull()
  })

  it('also stays hidden when the universe list cannot be loaded', async () => {
    const fetchMock = vi.mocked(fetch)
    const original = fetchMock.getMockImplementation()
    fetchMock.mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) =>
      input === '/api/universes' ? json({ message: 'down' }, 500) : (original as (i: RequestInfo | URL, n?: RequestInit) => Promise<Response>)(input, init),
    )
    render(tree(null, '/'))

    await screen.findByText('AAPL')
    expect(screen.queryByRole('group', { name: 'Universo' })).toBeNull()
  })

  it('renders the list in order, defaults to the first and omits the param for the default', async () => {
    render(tree(null, '/'))

    const group = await screen.findByRole('group', { name: 'Universo' })
    expect(within(group).getAllByRole('button').map((button) => button.querySelector('span')?.textContent)).toEqual([
      'S&P 500',
      'IBEX 35',
      'Nasdaq-100',
    ])
    expect(within(group).getByRole('button', { name: /S&P 500/ }).getAttribute('aria-pressed')).toBe('true')
    await screen.findByText('AAPL')
    expect(screenerCalls()).toEqual(['/api/screener?sort=rvol_desc&limit=50'])
    expect(screen.getByTestId('url').textContent).toBe('')
    // With a selector the Close heading carries the currency.
    expect(screen.getAllByText('Cierre (USD)').length).toBeGreaterThan(0)
  })

  it('choosing a universe updates the URL and refetches with universe=, then back to default omits it', async () => {
    render(tree(null, '/'))
    const group = await screen.findByRole('group', { name: 'Universo' })
    await screen.findByText('AAPL')

    fireEvent.click(within(group).getByRole('button', { name: /IBEX 35/ }))

    await screen.findByText('SAN.MC')
    // A Candidate of another universe opens the same chart route.
    expect(screen.getByRole('link', { name: 'SAN.MC' }).getAttribute('href')).toBe('/instruments/SAN.MC')
    expect(screen.getByTestId('url').textContent).toBe('?universe=ibex35')
    expect(screenerCalls().at(-1)).toBe('/api/screener?sort=rvol_desc&universe=ibex35&limit=50')
    expect(within(group).getByRole('button', { name: /IBEX 35/ }).getAttribute('aria-pressed')).toBe('true')
    // The Close heading shows the universe currency (EUR).
    expect(screen.getAllByText('Cierre (EUR)').length).toBeGreaterThan(0)

    fireEvent.click(within(group).getByRole('button', { name: /S&P 500/ }))
    await screen.findByText('AAPL')
    expect(screen.getByTestId('url').textContent).toBe('')
    expect(screenerCalls().at(-1)).toBe('/api/screener?sort=rvol_desc&limit=50')
  })

  it('a deep link to a universe selects it without a duplicate request', async () => {
    render(tree(null, '/?universe=ibex35&rsi_min=30'))

    await screen.findByText('SAN.MC')
    expect(screenerCalls()).toEqual(['/api/screener?rsi_min=30&sort=rvol_desc&universe=ibex35&limit=50'])
    expect(screen.getByTestId('url').textContent).toBe('?universe=ibex35&rsi_min=30')
  })

  it('an invalid or hidden slug falls back to the default, removes the param and shows no error', async () => {
    render(tree(null, '/?universe=nope&rsi_min=30'))

    await screen.findByText('AAPL')
    await waitFor(() => expect(screen.getByTestId('url').textContent).toBe('?rsi_min=30'))
    expect(screenerCalls()).toEqual(['/api/screener?rsi_min=30&sort=rvol_desc&limit=50'])
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('switching keeps compatible filters and the sort, resets the page and clears the Close bounds on a currency change', async () => {
    render(tree(null, '/?universe=nasdaq100&rsi_max=40&sort=close_desc&search=bank&close_min=10&close_max=50&page=2'))
    const group = await screen.findByRole('group', { name: 'Universo' })
    await waitFor(() => expect(screenerCalls()).toHaveLength(1))

    // Same currency (USD -> USD): the bounds stay, the page restarts.
    fireEvent.click(within(group).getByRole('button', { name: /S&P 500/ }))
    await waitFor(() => expect(screen.getByTestId('url').textContent).not.toContain('universe='))
    let search = new URLSearchParams(screen.getByTestId('url').textContent ?? '')
    expect(search.get('close_min')).toBe('10')
    expect(search.get('close_max')).toBe('50')
    expect(search.has('page')).toBe(false)

    // USD -> EUR: the Close bounds go, everything else stays.
    fireEvent.click(within(group).getByRole('button', { name: /IBEX 35/ }))
    await waitFor(() => expect(screen.getByTestId('url').textContent).toContain('universe=ibex35'))
    search = new URLSearchParams(screen.getByTestId('url').textContent ?? '')
    expect(search.has('close_min')).toBe(false)
    expect(search.has('close_max')).toBe(false)
    expect(search.get('rsi_max')).toBe('40')
    expect(search.get('sort')).toBe('close_desc')
    expect(search.get('search')).toBe('bank')
    await waitFor(() => expect(screenerCalls().at(-1)).toBe('/api/screener?rsi_max=40&search=bank&sort=close_desc&universe=ibex35&limit=50'))
  })

  it('hides the sector context filters for a Madrid universe, drops them from the request and clears them on switch', async () => {
    render(tree(USER, '/?sector_trend=uptrend&sector_rs_rank_max=3'))
    const group = await screen.findByRole('group', { name: 'Universo' })
    await waitFor(() => expect(screenerCalls()).toHaveLength(1))
    expect(screenerCalls()[0]).toBe('/api/screener?sector_trend=uptrend&sector_rs_rank_max=3&sort=rvol_desc&limit=50')
    expect(screen.getByLabelText('Fuerza relativa del sector')).toBeTruthy()

    fireEvent.click(within(group).getByRole('button', { name: /IBEX 35/ }))

    await screen.findByText('SAN.MC')
    expect(screen.getByTestId('url').textContent).toBe('?universe=ibex35')
    expect(screen.queryByLabelText('Fuerza relativa del sector')).toBeNull()
    expect(screen.queryByText('Contexto sectorial')).toBeNull()
    expect(screenerCalls().at(-1)).not.toContain('sector_')
  })

  it('ignores sector keys typed into the URL of a Madrid universe, silently', async () => {
    render(tree(USER, '/?universe=ibex35&sector_trend=uptrend'))

    await screen.findByText('SAN.MC')
    expect(screenerCalls()).toEqual(['/api/screener?sort=rvol_desc&universe=ibex35&limit=50'])
    expect(screen.queryByLabelText('Fuerza relativa del sector')).toBeNull()
    expect(screen.queryByText(/contexto sectorial, que necesitan/)).toBeNull()
  })
})

describe('Saved Screeners and universes', () => {
  it('shows the universe chip and applying restores universe plus filters', async () => {
    saved = [
      {
        id: 1,
        name: 'Bancos',
        universe: { slug: 'ibex35', name: 'IBEX 35' },
        filters: { signal: [], rsi_min: null, rsi_max: 40, min_rvol: null, price_above_sma200: false, ma_cross: null, sort: 'rvol_desc', universe: 'ibex35' },
      },
      {
        id: 2,
        name: 'Clásico',
        universe: { slug: 'sp500', name: 'S&P 500' },
        filters: { signal: [], rsi_min: 20, rsi_max: null, min_rvol: null, price_above_sma200: false, ma_cross: null, sort: 'rvol_desc' },
      },
    ]
    const { container } = render(tree(USER, '/?rsi_min=10&close_min=5'))
    await screen.findByRole('group', { name: 'Universo' })
    const panel = await waitFor(() => {
      const details = container.querySelector('details')
      expect(details).not.toBeNull()
      return details as HTMLElement
    })
    const chips = await within(panel).findAllByTestId('saved-universe-chip')
    expect(chips.map((chip) => chip.textContent)).toEqual(['Universo: IBEX 35', 'Universo: S&P 500'])

    fireEvent.click(within(panel).getAllByRole('button', { name: 'Aplicar' })[0])
    await waitFor(() => expect(screen.getByTestId('url').textContent).toContain('universe=ibex35'))
    const search = new URLSearchParams(screen.getByTestId('url').textContent ?? '')
    expect(search.get('rsi_max')).toBe('40')
    expect(search.has('rsi_min')).toBe(false)

    fireEvent.click(within(panel).getAllByRole('button', { name: 'Aplicar' })[1])
    await waitFor(() => expect(screen.getByTestId('url').textContent).not.toContain('universe='))
    expect(new URLSearchParams(screen.getByTestId('url').textContent ?? '').get('rsi_min')).toBe('20')
  })

  it('saves the current non-default universe with the filters', async () => {
    const created: unknown[] = []
    const base = vi.mocked(fetch).getMockImplementation() as (i: RequestInfo | URL, n?: RequestInit) => Promise<Response>
    vi.mocked(fetch).mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (input === '/api/screeners' && init?.method === 'POST') {
        created.push(JSON.parse(String(init.body)))
        return json({ screener: { id: 9, name: 'EU', universe: { slug: 'ibex35', name: 'IBEX 35' }, filters: {} } }, 201)
      }
      return base(input, init)
    })
    const { container } = render(tree(USER, '/?universe=ibex35'))
    await screen.findByText('SAN.MC')
    const panel = await waitFor(() => {
      const details = container.querySelector('details')
      expect(details).not.toBeNull()
      return details as HTMLElement
    })

    fireEvent.change(within(panel).getByLabelText('Nombre'), { target: { value: 'EU' } })
    fireEvent.click(within(panel).getByRole('button', { name: 'Guardar screener' }))

    await waitFor(() => expect(created).toHaveLength(1))
    expect(created[0]).toMatchObject({ name: 'EU', filters: { universe: 'ibex35', sort: 'rvol_desc' } })
  })

  it('does not send a universe key for the default universe', async () => {
    const created: unknown[] = []
    const base = vi.mocked(fetch).getMockImplementation() as (i: RequestInfo | URL, n?: RequestInit) => Promise<Response>
    vi.mocked(fetch).mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (input === '/api/screeners' && init?.method === 'POST') {
        created.push(JSON.parse(String(init.body)))
        return json({ screener: { id: 9, name: 'US', universe: { slug: 'sp500', name: 'S&P 500' }, filters: {} } }, 201)
      }
      return base(input, init)
    })
    const { container } = render(tree(USER, '/'))
    await screen.findByText('AAPL')
    const panel = await waitFor(() => {
      const details = container.querySelector('details')
      expect(details).not.toBeNull()
      return details as HTMLElement
    })

    fireEvent.change(within(panel).getByLabelText('Nombre'), { target: { value: 'US' } })
    fireEvent.click(within(panel).getByRole('button', { name: 'Guardar screener' }))

    await waitFor(() => expect(created).toHaveLength(1))
    expect((created[0] as { filters: Record<string, unknown> }).filters).not.toHaveProperty('universe')
  })
})

describe('Natural-language Screener and universes', () => {
  it('sends the selected universe, keeps it after applying and never applies sector keys outside the US', async () => {
    const bodies: Record<string, unknown>[] = []
    const base = vi.mocked(fetch).getMockImplementation() as (i: RequestInfo | URL, n?: RequestInit) => Promise<Response>
    vi.mocked(fetch).mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (input === '/api/screener/interpret') {
        bodies.push(JSON.parse(String(init?.body)))
        return json({
          filters: { signal: [], rsi_min: null, rsi_max: 30, min_rvol: null, price_above_sma200: false, ma_cross: null, sector_trend: 'uptrend', sector_rs_rank_max: 3, sort: 'rvol_desc' },
          unsupported: [],
          explanation: 'RSI como máximo 30.',
        })
      }
      return base(input, init)
    })
    render(tree(USER, '/?universe=ibex35'))
    await screen.findByText('SAN.MC')

    fireEvent.change(screen.getByLabelText('Búsqueda en tus palabras'), { target: { value: 'sobrevendidas' } })
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar filtros' }))

    await screen.findByText('RSI como máximo 30.')
    expect(bodies).toEqual([{ query: 'sobrevendidas', locale: 'es', universe: 'ibex35' }])
    await waitFor(() => expect(screen.getByTestId('url').textContent).toBe('?universe=ibex35&rsi_max=30'))
  })

  it('sends no universe key for the default universe', async () => {
    const bodies: Record<string, unknown>[] = []
    const base = vi.mocked(fetch).getMockImplementation() as (i: RequestInfo | URL, n?: RequestInit) => Promise<Response>
    vi.mocked(fetch).mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
      if (input === '/api/screener/interpret') {
        bodies.push(JSON.parse(String(init?.body)))
        return json({ filters: { signal: [], rsi_min: null, rsi_max: 30, min_rvol: null, price_above_sma200: false, ma_cross: null, sort: 'rvol_desc' }, unsupported: [], explanation: 'ok' })
      }
      return base(input, init)
    })
    render(tree(USER, '/'))
    await screen.findByText('AAPL')

    fireEvent.change(screen.getByLabelText('Búsqueda en tus palabras'), { target: { value: 'sobrevendidas' } })
    fireEvent.click(screen.getByRole('button', { name: 'Aplicar filtros' }))

    await waitFor(() => expect(bodies).toHaveLength(1))
    expect(bodies[0]).toEqual({ query: 'sobrevendidas', locale: 'es' })
  })
})
