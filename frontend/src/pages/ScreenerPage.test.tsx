// @vitest-environment jsdom
import { act, cleanup, render, screen, waitFor, within } from '@testing-library/react'
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
    // The filter panel offers a sign-in box instead of the controls.
    expect(screen.getByText('Inicia sesión para filtrar por la tendencia y la fuerza relativa del sector de cada acción.')).toBeTruthy()
    expect(screen.queryByLabelText('Fuerza relativa del sector')).toBeNull()
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
