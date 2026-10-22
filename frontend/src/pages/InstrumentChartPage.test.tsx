// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { AuthContext } from '../auth/context.ts'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { companyCardApi, instrumentApi, watchlistApi } from '../lib/api.ts'
import type { InstrumentDetailResponse } from '../lib/api.ts'
import InstrumentChartPage from './InstrumentChartPage.tsx'

// The canvas chart is not under test (and needs a real canvas).
vi.mock('../components/chart/InteractiveChart.tsx', () => ({
  default: () => <div data-testid="interactive-chart" />,
}))

vi.mock('../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api.ts')>()
  return {
    ...actual,
    instrumentApi: { detail: vi.fn() },
    watchlistApi: { list: vi.fn(), add: vi.fn(), remove: vi.fn() },
    companyCardApi: { get: vi.fn(), generate: vi.fn() },
    forecastApi: { predict: vi.fn(), report: vi.fn() },
  }
})

const auth = {
  user: { id: 1, name: 'Test', email: 'test@example.com', role: 'user' as const },
  status: 'ready' as const,
  login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn(),
}

function payload(instrument: Partial<InstrumentDetailResponse['instrument']>): InstrumentDetailResponse {
  return {
    instrument: { ticker: 'XLK', company: 'Technology Select Sector SPDR Fund', sector: 'Information Technology', exchange: 'NYSE Arca', active: true, ...instrument },
    bars: [{ date: '2026-10-01', open: 100, high: 102, low: 99, close: 101, volume: 1000 }],
    snapshot: null,
    signals: [],
    patterns: [],
    meta: { limit: 252, bar_count: 1, latest_bar_date: '2026-10-01' },
  }
}

function show(ticker: string) {
  return render(
    <AuthContext.Provider value={auth}>
      <I18nProvider>
        <MemoryRouter initialEntries={[`/instruments/${ticker}`]}>
          <Routes>
            <Route path="/instruments/:ticker" element={<InstrumentChartPage />} />
          </Routes>
        </MemoryRouter>
      </I18nProvider>
    </AuthContext.Provider>,
  )
}

beforeEach(() => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
  vi.mocked(watchlistApi.list).mockResolvedValue([])
  vi.mocked(companyCardApi.get).mockResolvedValue({ card: null, available: false, fundamentals: null })
})
afterEach(() => {
  cleanup()
  vi.clearAllMocks()
  window.localStorage.clear()
})

it('shows an ETF chart without the Watchlist button, forecast or Company Card', async () => {
  vi.mocked(instrumentApi.detail).mockResolvedValue(payload({ kind: 'etf' }))
  show('XLK')

  expect(await screen.findByTestId('interactive-chart')).toBeTruthy()
  expect(screen.getByRole('heading', { level: 1, name: 'XLK' })).toBeTruthy()
  expect(screen.getByText('Technology Select Sector SPDR Fund · Information Technology · NYSE Arca')).toBeTruthy()
  expect(screen.getByText('Señales activas')).toBeTruthy()
  expect(screen.getByText('Patrones activos')).toBeTruthy()

  expect(screen.queryByRole('button', { name: 'Seguir' })).toBeNull()
  expect(screen.queryByText('Pronóstico Jev')).toBeNull()
  expect(screen.queryByText('Ficha de empresa')).toBeNull()
  expect(watchlistApi.list).not.toHaveBeenCalled()
  expect(companyCardApi.get).not.toHaveBeenCalled()
})

it('never prints an empty separator for a null sector', async () => {
  vi.mocked(instrumentApi.detail).mockResolvedValue(payload({ ticker: 'SPY', company: 'SPDR S&P 500 ETF Trust', sector: null, kind: 'etf' }))
  show('SPY')

  expect(await screen.findByText('SPDR S&P 500 ETF Trust · NYSE Arca')).toBeTruthy()
  expect(document.body.textContent).not.toContain('· ·')
})

it('keeps the stock-only panels for a stock, including an older payload without kind', async () => {
  vi.mocked(instrumentApi.detail).mockResolvedValue(payload({ ticker: 'NVDA', company: 'NVIDIA', exchange: 'NASDAQ' }))
  show('NVDA')

  expect(await screen.findByTestId('interactive-chart')).toBeTruthy()
  expect(screen.getByText('Pronóstico Jev')).toBeTruthy()
  expect(screen.getByText('Ficha de empresa')).toBeTruthy()
  await waitFor(() => expect(screen.getByRole('button', { name: 'Seguir' })).toBeTruthy())
  expect(watchlistApi.list).toHaveBeenCalled()
})
