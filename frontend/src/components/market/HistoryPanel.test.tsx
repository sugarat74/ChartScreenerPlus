// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../../auth/AuthContext.tsx'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import type { MarketHistory } from '../../lib/api.ts'
import HistoryPanel from './HistoryPanel.tsx'
import type { HistoryOption } from './HistoryPanel.tsx'

const USER = { id: 1, name: 'Ana', email: 'ana@example.test', role: 'user' }

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function history(overrides: Partial<MarketHistory> = {}): MarketHistory {
  return {
    metric: 'dgs10',
    range: '1y',
    disabled: false,
    from: '2025-10-21',
    to: '2026-10-21',
    first_date: '2021-10-21',
    points: [
      { date: '2025-10-21', value: 4.2 },
      { date: '2026-04-21', value: 4.4 },
      { date: '2026-10-21', value: 4.11 },
    ],
    ...overrides,
  }
}

function mockApi(answer: (url: URL) => Response | Promise<Response>, user: unknown = USER) {
  const fetchMock = vi.fn(async (input: string) => {
    if (input === '/api/user') return user ? json({ user }) : json({ message: 'Unauthenticated.' }, 401)
    if (input.startsWith('/api/market-context/history')) return answer(new URL(input, 'http://localhost'))
    return json({})
  })
  vi.stubGlobal('fetch', fetchMock)
  return () => fetchMock.mock.calls.map((call) => String(call[0])).filter((url) => url.startsWith('/api/market-context/history'))
}

const format = (value: number) => value.toFixed(2)

const OPTIONS: HistoryOption[] = [
  { metric: 'dgs10', label: 'Bono a 10 años (%)', formatValue: format },
  { metric: 'spread_10y3m', label: 'Diferencial 10 años – 3 meses (pp)', reference: [0], formatValue: format },
]

function renderPanel(options: HistoryOption[] = OPTIONS, selectLabel?: string) {
  return render(
    <I18nProvider>
      <AuthProvider>
        <MemoryRouter>
          <HistoryPanel options={options} selectLabel={selectLabel} />
        </MemoryRouter>
      </AuthProvider>
    </I18nProvider>,
  )
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  window.localStorage.clear()
})

describe('HistoryPanel', () => {
  it('requests each metric and range once, on demand', async () => {
    const calls = mockApi((url) => json(history({ metric: url.searchParams.get('metric')!, range: url.searchParams.get('range') as '1y' })))
    renderPanel()

    fireEvent.click(await screen.findByRole('button', { name: 'Ver evolución' }))
    await screen.findByRole('img')
    expect(calls()).toEqual(['/api/market-context/history?metric=dgs10&range=1y'])

    fireEvent.change(screen.getByLabelText('Serie del gráfico'), { target: { value: 'spread_10y3m' } })
    await waitFor(() => expect(calls()).toHaveLength(2))
    fireEvent.click(screen.getByRole('button', { name: '3 años' }))
    await waitFor(() => expect(calls()).toHaveLength(3))
    // Back to a loaded combination: no new request.
    fireEvent.click(screen.getByRole('button', { name: '1 año' }))
    expect(calls()).toEqual([
      '/api/market-context/history?metric=dgs10&range=1y',
      '/api/market-context/history?metric=spread_10y3m&range=1y',
      '/api/market-context/history?metric=spread_10y3m&range=3y',
    ])
  })

  it('requests the sector relative strength of the selected sector', async () => {
    const calls = mockApi(() => json(history()))
    renderPanel(
      [
        { metric: 'sector_rs_60:XLK', label: 'XLK · Tecnología', formatValue: format },
        { metric: 'sector_rs_60:XLE', label: 'XLE · Energía', formatValue: format },
      ],
      'Sector del gráfico',
    )

    fireEvent.click(await screen.findByRole('button', { name: 'Ver evolución' }))
    await screen.findByRole('img')
    fireEvent.change(screen.getByLabelText('Sector del gráfico'), { target: { value: 'sector_rs_60:XLE' } })
    await waitFor(() =>
      expect(calls()).toEqual([
        '/api/market-context/history?metric=sector_rs_60%3AXLK&range=1y',
        '/api/market-context/history?metric=sector_rs_60%3AXLE&range=1y',
      ]),
    )
  })

  it('shows empty, disabled and "available from" states', async () => {
    mockApi((url) => {
      const metric = url.searchParams.get('metric')
      if (metric === 'dgs10') return json(history({ points: [], from: null, to: null, first_date: null }))
      return json(history({ metric: 'spread_10y3m', disabled: true, points: [] }))
    })
    renderPanel()

    fireEvent.click(await screen.findByRole('button', { name: 'Ver evolución' }))
    expect(await screen.findByText('Sin datos para este periodo.')).toBeTruthy()
    fireEvent.change(screen.getByLabelText('Serie del gráfico'), { target: { value: 'spread_10y3m' } })
    expect(await screen.findByText('No disponible · fuente no habilitada')).toBeTruthy()
  })

  it('treats a series of gaps only (every regime insufficient) as empty', async () => {
    mockApi(() => json(history({ points: [{ date: '2026-10-01', value: null }, { date: '2026-10-02', value: null }] })))
    renderPanel([OPTIONS[0]])

    fireEvent.click(await screen.findByRole('button', { name: 'Ver evolución' }))
    expect(await screen.findByText('Sin datos para este periodo.')).toBeTruthy()
    expect(screen.queryByRole('img')).toBeNull()
  })

  it('shows when the history starts inside the window', async () => {
    mockApi(() => json(history({ range: '3y', from: '2023-10-21', first_date: '2025-10-22' })))
    renderPanel([OPTIONS[0]])

    fireEvent.click(await screen.findByRole('button', { name: 'Ver evolución' }))
    expect(await screen.findByText('Datos disponibles desde 22 oct 2025')).toBeTruthy()
  })

  it('shows an error with a retry', async () => {
    let fail = true
    const calls = mockApi(() => (fail ? json({ message: 'Server Error' }, 500) : json(history())))
    renderPanel([OPTIONS[0]])

    fireEvent.click(await screen.findByRole('button', { name: 'Ver evolución' }))
    const alert = await screen.findByRole('alert')
    expect(alert.textContent).toContain('No se pudo cargar la evolución.')
    fail = false
    fireEvent.click(screen.getByRole('button', { name: 'Reintentar' }))
    expect(await screen.findByRole('img')).toBeTruthy()
    expect(calls()).toHaveLength(2)
  })

  it('an expired session (401) shows the sign-in box', async () => {
    mockApi(() => json({ message: 'Unauthenticated.' }, 401))
    renderPanel([OPTIONS[0]])

    fireEvent.click(await screen.findByRole('button', { name: 'Ver evolución' }))
    expect(await screen.findByText('Inicia sesión para ver la evolución de 1 y 3 años.')).toBeTruthy()
    expect(screen.getByRole('link', { name: 'Iniciar sesión' }).getAttribute('href')).toBe('/login')
  })

  it('Visitors get the sign-in box and no request', async () => {
    const calls = mockApi(() => json(history()), null)
    renderPanel()

    expect(await screen.findByText('Evolución histórica')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Ver evolución' })).toBeNull()
    expect(calls()).toEqual([])
  })
})
