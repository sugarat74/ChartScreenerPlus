// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from '../auth/context.ts'
import type { AuthContextValue } from '../auth/context.ts'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { resetLegalInfoCache } from '../legal/useLegalInfo.ts'
import ScreenerPage from './ScreenerPage.tsx'

const MARKET = {
  code: 'US',
  timezone: 'America/New_York',
  regular_hours: { open: '09:30', close: '16:00' },
  is_open: false,
  opens_at: null,
  closes_at: null,
  early_close: false,
  next_open_at: '2099-01-05T14:30:00Z',
  next_close_at: '2099-01-05T21:00:00Z',
  calendar_year: 2099,
  calendar_covered: false,
  upcoming: [],
}

let statusBody: unknown = { markets: [MARKET] }
let statusCode = 200
const requests: string[] = []

function json(body: unknown, status = 200): Response {
  if (status === 204) {
    return new Response(null, { status })
  }
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function page() {
  const auth: AuthContextValue = {
    user: null,
    status: 'ready',
    login: vi.fn(),
    register: vi.fn(),
    logout: vi.fn(),
    setCurrentUser: vi.fn(),
  }
  return (
    <AuthContext.Provider value={auth}>
      <I18nProvider>
        <MemoryRouter initialEntries={['/']}>
          <ScreenerPage />
        </MemoryRouter>
      </I18nProvider>
    </AuthContext.Provider>
  )
}

beforeEach(() => {
  statusBody = { markets: [MARKET] }
  statusCode = 200
  requests.length = 0
  resetLegalInfoCache()
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: string) => {
      requests.push(input)
      if (input === '/api/markets/status') {
        return json(statusBody, statusCode)
      }
      if (input === '/api/universes') {
        return json({ universes: [] })
      }
      if (input.startsWith('/api/screener?')) {
        return json({ universe: { slug: 'sp500', name: 'S&P 500' }, sort: 'rvol_desc', candidates: [], meta: { limit: 50, offset: 0, returned: 0, total: 0 } })
      }
      return new Response(null, { status: 204 })
    }),
  )
})
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  window.localStorage.clear()
})

describe('ScreenerPage market hours strip (market-hours-status)', () => {
  it('shows the strip on the home page with one extra request and the Screener query unchanged', async () => {
    render(page())

    expect(await screen.findByRole('region', { name: 'Horario de los mercados' })).toBeTruthy()
    expect(screen.getByText('NYSE/Nasdaq')).toBeTruthy()
    await waitFor(() => expect(requests.filter((url) => url.startsWith('/api/screener?'))).toEqual(['/api/screener?sort=rvol_desc&limit=50']))
    expect(requests.filter((url) => url === '/api/markets/status')).toHaveLength(1)
  })

  it.each([
    ['the request fails', 500, { message: 'down' }],
    ['no market is public', 200, { markets: [] }],
    ['the answer is empty', 204, null],
  ])('leaves the home page as before when %s', async (_name, code, body) => {
    statusCode = code
    statusBody = body
    render(page())

    await waitFor(() => expect(requests).toContain('/api/markets/status'))
    await screen.findByRole('heading', { level: 1 })
    expect(screen.queryByRole('region', { name: 'Horario de los mercados' })).toBeNull()
    expect(screen.queryByText('Mercados')).toBeNull()
  })
})
