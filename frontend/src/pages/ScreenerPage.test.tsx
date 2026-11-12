// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, useLocation } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from '../auth/context.ts'
import type { AuthContextValue } from '../auth/context.ts'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { resetLegalInfoCache } from '../legal/useLegalInfo.ts'
import type { AuthUser } from '../lib/api.ts'
import ScreenerPage from './ScreenerPage.tsx'

const ALLOWED: AuthUser = { id: 1, name: 'Ana', email: 'ana@example.test', role: 'user', capabilities: { market_context: true } }
const NO_ACCESS: AuthUser = { ...ALLOWED, capabilities: { market_context: false } }

let flag = true
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

function auth(user: AuthUser | null, status: 'loading' | 'ready' = 'ready'): AuthContextValue {
  return { user, status, login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn() }
}

function tree(value: AuthContextValue, url: string) {
  return (
    <AuthContext.Provider value={value}>
      <I18nProvider>
        <MemoryRouter initialEntries={[url]}>
          <ScreenerPage />
          <LocationProbe />
        </MemoryRouter>
      </I18nProvider>
    </AuthContext.Provider>
  )
}

beforeEach(() => {
  flag = true
  requests.length = 0
  resetLegalInfoCache()
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
  vi.stubGlobal('fetch', vi.fn(async (input: string) => {
    requests.push(input)
    if (input === '/api/legal') {
      return json({ privacy_published: true, notice_published: false, owner: null, updated_at: '2026-10-23', retention: {}, market_context: flag })
    }
    if (input === '/api/screeners') {
      return json({ screeners: [] })
    }
    if (input.startsWith('/api/screener?')) {
      return json({ universe: { slug: 'sp500', name: 'S&P 500' }, sort: 'rvol_desc', candidates: [], meta: { limit: 50, offset: 0, returned: 0, total: 0 } })
    }
    return new Response(null, { status: 204 })
  }))
})
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  window.localStorage.clear()
})

describe('ScreenerPage sector context filters (market-context-patterns)', () => {
  it('a Visitor with the flag on requests without the sector keys, keeps them in the URL and says so', async () => {
    render(tree(auth(null), '/?sector_trend=uptrend&sector_rs_rank_max=3&rsi_min=40'))

    const notice = await screen.findByText(/El enlace incluye filtros de contexto sectorial, que necesitan una cuenta/)
    expect(notice.getAttribute('role')).toBe('status')
    expect(screen.getAllByRole('link', { name: 'Iniciar sesión' }).some((link) => link.getAttribute('href') === '/login')).toBe(true)
    await waitFor(() => expect(screenerCalls()).toHaveLength(1))
    expect(screenerCalls()[0]).toBe('/api/screener?rsi_min=40&sort=rvol_desc&limit=50')
    expect(screen.getByTestId('url').textContent).toBe('?sector_trend=uptrend&sector_rs_rank_max=3&rsi_min=40')
    // The filter panel shows the sector controls disabled with the shared reason (inside the sector card).
    fireEvent.click(document.getElementById('screener-criteria-trigger-sector')!)
    const card = document.getElementById('screener-criteria-card')!
    expect(within(card).getByTestId('registered-only-reason').textContent).toContain('Disponible al iniciar sesión')
    expect((screen.getByLabelText('Fuerza relativa del sector') as HTMLSelectElement).disabled).toBe(true)
  })

  it('a user without access gets the "not available" notice and no sector controls', async () => {
    flag = false
    render(tree(auth(NO_ACCESS), '/?sector_rs_rank_max=3'))

    await waitFor(() => expect(screen.getByText(/que no están disponibles para tu cuenta/)).toBeTruthy())
    await waitFor(() => expect(screenerCalls()).toEqual(['/api/screener?sort=rvol_desc&limit=50']))
    expect(screen.queryByText('Contexto sectorial')).toBeNull()
  })

  it('an allowed user requests with the sector keys and sees the controls', async () => {
    render(tree(auth(ALLOWED), '/?sector_trend=uptrend&sector_rs_rank_max=3'))

    await waitFor(() => expect(screenerCalls()).toHaveLength(1))
    expect(screenerCalls()[0]).toBe('/api/screener?sector_trend=uptrend&sector_rs_rank_max=3&sort=rvol_desc&limit=50')
    expect(screen.queryByText(/El enlace incluye filtros de contexto sectorial/)).toBeNull()
    fireEvent.click(document.getElementById('screener-criteria-trigger-sector')!)
    expect((screen.getByLabelText('Fuerza relativa del sector') as HTMLSelectElement).value).toBe('3')
    const trend = screen.getByRole('group', { name: 'Tendencia del ETF sectorial de la acción' })
    expect(within(trend).getByRole('button', { name: 'Alcista' }).getAttribute('aria-pressed')).toBe('true')
    expect(within(trend).getByRole('button', { name: 'Cualquiera' }).getAttribute('aria-pressed')).toBe('false')
    expect(screen.getByText('2 filtro(s) activo(s)')).toBeTruthy()
  })

  it('waits for the session before requesting a URL with sector keys', async () => {
    const view = render(tree(auth(null, 'loading'), '/?sector_trend=mixed'))

    await act(() => new Promise((resolve) => setTimeout(resolve, 30)))
    expect(screenerCalls()).toEqual([])

    view.rerender(tree(auth(ALLOWED), '/?sector_trend=mixed'))
    await waitFor(() => expect(screenerCalls()).toEqual(['/api/screener?sector_trend=mixed&sort=rvol_desc&limit=50']))
  })

  it('a URL without sector keys is requested at once, even while the session loads', async () => {
    render(tree(auth(null, 'loading'), '/?rsi_min=40'))

    await waitFor(() => expect(screenerCalls()).toEqual(['/api/screener?rsi_min=40&sort=rvol_desc&limit=50']))
  })
})

describe('ScreenerPage registered-only options (guest-disabled-screener)', () => {
  const REGISTERED_ONLY = /^\/api\/(screeners|screener\/interpret|instruments\/.*\/company-card|watchlist|alerts|notifications)/

  it('shows a Visitor the AI box, Saved Screeners and sector trigger disabled and requests no registered-only API', async () => {
    render(tree(auth(null), '/?rsi_min=40'))

    await waitFor(() => expect(screenerCalls()).toHaveLength(1))
    expect(await screen.findByRole('heading', { name: /Describe lo que buscas/i })).toBeTruthy()
    expect((screen.getByLabelText('Búsqueda en tus palabras') as HTMLInputElement).disabled).toBe(true)
    expect(screen.getByRole('button', { name: 'Screeners guardados' }).getAttribute('aria-disabled')).toBe('true')
    expect(screen.getByRole('button', { name: /^Sector/ })).toBeTruthy()
    expect(requests.filter((url) => REGISTERED_ONLY.test(url))).toEqual([])
  })

  it('activates the AI box and Saved Screeners in place when the session appears', async () => {
    const view = render(tree(auth(null), '/?rsi_min=40'))
    await waitFor(() => expect(screenerCalls()).toHaveLength(1))
    const input = screen.getByLabelText('Búsqueda en tus palabras') as HTMLInputElement

    view.rerender(tree(auth(ALLOWED), '/?rsi_min=40'))
    expect(screen.getByLabelText('Búsqueda en tus palabras')).toBe(input)
    expect(input.disabled).toBe(false)
    await waitFor(() => expect(requests).toContain('/api/screeners'))
    expect(document.querySelector('details')).not.toBeNull()

    view.rerender(tree(auth(null), '/?rsi_min=40'))
    expect((screen.getByLabelText('Búsqueda en tus palabras') as HTMLInputElement).disabled).toBe(true)
    expect(screen.getByRole('button', { name: 'Screeners guardados' }).getAttribute('aria-disabled')).toBe('true')
  })

  it('does not show the sector trigger to a Visitor while the Market Context flag is off', async () => {
    flag = false
    render(tree(auth(null), '/'))

    await waitFor(() => expect(screenerCalls()).toHaveLength(1))
    await waitFor(() => expect(requests).toContain('/api/legal'))
    await act(() => new Promise((resolve) => setTimeout(resolve, 30)))
    expect(screen.queryByRole('button', { name: /^Sector/ })).toBeNull()
  })
})

describe('ScreenerPage compact criteria toolbar (screener-filter-panel-compact)', () => {
  it('a multi-filter deep link shows every value without opening a card and requests the same URL', async () => {
    const url = '/?signal=golden_cross,rsi_oversold&rsi_min=30&rsi_max=70&min_rvol=1.5&price_above_sma200=1&ma_cross=bullish&pattern=double_bottom&pattern_status=confirmed'
    render(tree(auth(null), url))

    await waitFor(() => expect(screenerCalls()).toHaveLength(1))
    expect(screenerCalls()[0]).toBe('/api/screener?signal=golden_cross%2Crsi_oversold&rsi_min=30&rsi_max=70&min_rvol=1.5&price_above_sma200=1&ma_cross=bullish&pattern=double_bottom&pattern_status=confirmed&sort=rvol_desc&limit=50')
    expect(screen.getByRole('button', { name: /^Señales · 2/ })).toBeTruthy()
    expect(screen.getByRole('button', { name: /^RSI · 30-70/ })).toBeTruthy()
    expect(screen.getByRole('button', { name: /^Patrones · 1 · Confirmado/ })).toBeTruthy()
    expect((screen.getByLabelText('RVOL mínimo') as HTMLSelectElement).value).toBe('1.5')
    expect((screen.getByLabelText(/Cruce de medias/) as HTMLSelectElement).value).toBe('bullish')
    expect(screen.getByRole('button', { name: 'Precio > SMA 200' }).getAttribute('aria-pressed')).toBe('true')
    expect(document.getElementById('screener-criteria-card')).toBeNull()
    expect(screenerCalls()).toHaveLength(1)
  })
})
