// @vitest-environment jsdom
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from '../auth/context.ts'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { resetLegalInfoCache } from '../legal/useLegalInfo.ts'
import { companyCardApi, instrumentApi, instrumentMarketContextApi, legalApi, watchlistApi } from '../lib/api.ts'
import type { AuthUser, InstrumentDetailResponse, LegalInfo } from '../lib/api.ts'
import { AAPL_CONTEXT } from '../components/chart/instrumentContext.fixture.ts'
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
    legalApi: { info: vi.fn() },
    instrumentMarketContextApi: { get: vi.fn() },
  }
})

const LEGAL: LegalInfo = {
  privacy_published: true,
  notice_published: false,
  owner: null,
  updated_at: '2026-10-23',
  retention: { sign_in_activity_days: 90, session_minutes: 120, backup_days: 14, server_log_days: 14, notification_days: 90 },
}

const ALLOWED: AuthUser = { id: 1, name: 'Test', email: 'test@example.com', role: 'user', capabilities: { market_context: true } }

const PATTERN = {
  type: 'double_bottom',
  status: 'confirmed' as const,
  start_date: '2026-09-01',
  end_date: '2026-09-30',
  breakout_level: 115,
  points: [{ date: '2026-09-01', price: 100, role: 'left_low' }],
}

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

function show(ticker: string, user: AuthUser | null = auth.user) {
  return render(
    <AuthContext.Provider value={{ ...auth, user }}>
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
  resetLegalInfoCache()
  vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: false })
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

describe('Market Context strip and pattern chips (market-context-patterns)', () => {
  it('an allowed user gets one context request for a stock, the strip and the pattern chips', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    vi.mocked(instrumentMarketContextApi.get).mockResolvedValue(AAPL_CONTEXT)
    vi.mocked(instrumentApi.detail).mockResolvedValue({
      ...payload({ ticker: 'AAPL', company: 'Apple Inc.', exchange: 'NASDAQ', kind: 'stock' }),
      patterns: [PATTERN],
    })
    show('AAPL', ALLOWED)

    const strip = await screen.findByRole('region', { name: 'Contexto de mercado' })
    expect(strip.textContent).toContain('Sector: Tecnología de la información (XLK)')
    expect(strip.textContent).toContain('Fuerza relativa 1/10')
    expect(within(strip).queryByText('Vista previa')).toBeNull()
    expect(screen.getByText('El contexto es el de la última sesión, no el de la fecha del patrón.')).toBeTruthy()
    expect(screen.getByText('Contexto actual:', { exact: false })).toBeTruthy()
    expect(instrumentMarketContextApi.get).toHaveBeenCalledTimes(1)
    expect(vi.mocked(instrumentMarketContextApi.get).mock.calls[0][0]).toBe('AAPL')
  })

  it('an Admin preview carries the chip while the flag is off', async () => {
    vi.mocked(instrumentMarketContextApi.get).mockResolvedValue(AAPL_CONTEXT)
    vi.mocked(instrumentApi.detail).mockResolvedValue(payload({ ticker: 'AAPL', kind: 'stock' }))
    show('AAPL', { ...ALLOWED, role: 'admin' })

    const strip = await screen.findByRole('region', { name: 'Contexto de mercado' })
    await waitFor(() => expect(within(strip).getByText('Vista previa')).toBeTruthy())
  })

  it('an ETF makes no context request and shows no strip', async () => {
    vi.mocked(instrumentApi.detail).mockResolvedValue(payload({ kind: 'etf' }))
    show('XLK', ALLOWED)

    expect(await screen.findByTestId('interactive-chart')).toBeTruthy()
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(screen.queryByRole('region', { name: 'Contexto de mercado' })).toBeNull()
    expect(instrumentMarketContextApi.get).not.toHaveBeenCalled()
  })

  it('a Visitor gets the sign-in box with the flag on and nothing with it off, never a request', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    vi.mocked(instrumentApi.detail).mockResolvedValue({ ...payload({ ticker: 'AAPL', kind: 'stock' }), patterns: [PATTERN] })
    show('AAPL', null)

    expect(await screen.findByText('Inicia sesión para ver el sector y el entorno de mercado de este valor.')).toBeTruthy()
    expect(screen.queryByText(/no el de la fecha del patrón/)).toBeNull()
    cleanup()

    resetLegalInfoCache()
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: false })
    show('AAPL', null)
    expect(await screen.findByTestId('interactive-chart')).toBeTruthy()
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(screen.queryByText(/Inicia sesión para ver el sector/)).toBeNull()
    expect(screen.queryByRole('region', { name: 'Contexto de mercado' })).toBeNull()
    expect(instrumentMarketContextApi.get).not.toHaveBeenCalled()
  })

  it('a user without the capability sees nothing and makes no request', async () => {
    vi.mocked(instrumentApi.detail).mockResolvedValue(payload({ ticker: 'AAPL', kind: 'stock' }))
    show('AAPL')

    expect(await screen.findByTestId('interactive-chart')).toBeTruthy()
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(screen.queryByRole('region', { name: 'Contexto de mercado' })).toBeNull()
    expect(instrumentMarketContextApi.get).not.toHaveBeenCalled()
  })
})

describe('US-only panels follow market and availability (universe-selector-ui)', () => {
  const MADRID = {
    ticker: 'SAN.MC',
    company: 'Banco Santander',
    sector: 'Financials',
    exchange: 'BME',
    kind: 'stock' as const,
    market: 'XMAD' as const,
    currency: 'EUR',
    availability: { company_card: false, fundamentals: false, forecast: false },
  }

  it('a Spanish stock shows exchange and currency and hides forecast, Company Card and Market Context, with no request or error', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    vi.mocked(instrumentApi.detail).mockResolvedValue(payload(MADRID))
    show('SAN.MC', ALLOWED)

    expect(await screen.findByTestId('interactive-chart')).toBeTruthy()
    expect(screen.getByText('Banco Santander · Financials · BME · EUR')).toBeTruthy()
    expect(screen.getByText('Señales activas')).toBeTruthy()
    await waitFor(() => expect(screen.getByRole('button', { name: 'Seguir' })).toBeTruthy())
    await new Promise((resolve) => setTimeout(resolve, 20))

    expect(screen.queryByText('Pronóstico Jev')).toBeNull()
    expect(screen.queryByText('Ficha de empresa')).toBeNull()
    expect(screen.queryByRole('region', { name: 'Contexto de mercado' })).toBeNull()
    expect(screen.queryByRole('alert')).toBeNull()
    expect(companyCardApi.get).not.toHaveBeenCalled()
    expect(instrumentMarketContextApi.get).not.toHaveBeenCalled()
  })

  it('a Visitor does not get the Market Context sign-in box on a Spanish stock', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    vi.mocked(instrumentApi.detail).mockResolvedValue(payload(MADRID))
    show('SAN.MC', null)

    expect(await screen.findByTestId('interactive-chart')).toBeTruthy()
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(screen.queryByText(/Inicia sesión para ver el sector/)).toBeNull()
  })

  it('each availability flag drives its own panel', async () => {
    vi.mocked(instrumentApi.detail).mockResolvedValue(
      payload({ ticker: 'XYZ', exchange: 'NASDAQ', currency: 'USD', market: 'US', availability: { company_card: true, fundamentals: true, forecast: false } }),
    )
    show('XYZ')

    expect(await screen.findByTestId('interactive-chart')).toBeTruthy()
    expect(screen.getByText('Ficha de empresa')).toBeTruthy()
    expect(screen.queryByText('Pronóstico Jev')).toBeNull()
    expect(screen.getByText(/Technology Select Sector SPDR Fund · Information Technology · NASDAQ · USD/)).toBeTruthy()
  })
})
