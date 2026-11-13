// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
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
  default: ({ series, indicators }: { series?: unknown[] | null; indicators?: string[] }) => (
    <div
      data-testid="interactive-chart"
      data-bands={series == null || !indicators?.includes('bollinger') ? 'off' : String(series.length)}
      data-panes={series == null ? '' : (indicators ?? []).filter((id) => id !== 'bollinger').join(',')}
    />
  ),
}))

vi.mock('../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api.ts')>()
  return {
    ...actual,
    instrumentApi: { detail: vi.fn(), indicators: vi.fn() },
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

    // The frame shows first as the skeleton, then the data replaces it in place.
    await waitFor(() => expect(screen.getByRole('region', { name: 'Contexto de mercado' }).textContent).toContain('Sector: Tecnología de la información (XLK)'))
    const strip = screen.getByRole('region', { name: 'Contexto de mercado' })
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

    await waitFor(() =>
      expect(within(screen.getByRole('region', { name: 'Contexto de mercado' })).getByText('Vista previa')).toBeTruthy(),
    )
  })

  it('an ETF makes no context request and shows no strip', async () => {
    vi.mocked(instrumentApi.detail).mockResolvedValue(payload({ kind: 'etf' }))
    show('XLK', ALLOWED)

    expect(await screen.findByTestId('interactive-chart')).toBeTruthy()
    await new Promise((resolve) => setTimeout(resolve, 20))
    expect(screen.queryByRole('region', { name: 'Contexto de mercado' })).toBeNull()
    expect(instrumentMarketContextApi.get).not.toHaveBeenCalled()
  })

  it('a Visitor gets the disabled strip with the flag on and nothing with it off, never a request', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    vi.mocked(instrumentApi.detail).mockResolvedValue({ ...payload({ ticker: 'AAPL', kind: 'stock' }), patterns: [PATTERN] })
    show('AAPL', null)

    const strip = await screen.findByRole('region', { name: 'Contexto de mercado' })
    expect(strip.closest('fieldset')?.hasAttribute('disabled')).toBe(true)
    expect(strip.textContent).toContain('Entorno: —')
    expect(screen.queryByText(/no el de la fecha del patrón/)).toBeNull()
    expect(instrumentMarketContextApi.get).not.toHaveBeenCalled()
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
  it('shows the LSE/GBX chart header with US-only panels unavailable', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    vi.mocked(instrumentApi.detail).mockResolvedValue(payload({
      ticker: 'AZN.L', company: 'AstraZeneca', sector: 'Health Care', exchange: 'LSE',
      kind: 'stock', market: 'XLON', currency: 'GBX',
      availability: { company_card: false, fundamentals: false, forecast: false },
    }))
    show('AZN.L', ALLOWED)
    expect(await screen.findByTestId('interactive-chart')).toBeTruthy()
    expect(screen.getByText('AstraZeneca · Health Care · LSE · GBX')).toBeTruthy()
    expect(companyCardApi.get).not.toHaveBeenCalled()
    expect(instrumentMarketContextApi.get).not.toHaveBeenCalled()
    expect(screen.queryByRole('region', { name: 'Contexto de mercado' })).toBeNull()
  })
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

describe('Indicator picker and Bollinger overlay (chart-indicator-overlays)', () => {
  const NULLS = { adx: null, plus_di: null, minus_di: null, atr14: null, stoch_k: null, stoch_d: null }
  const SERIES = {
    ticker: 'NVDA',
    series: [
      { date: '2026-09-30', bb_upper: null, bb_middle: null, bb_lower: null, ...NULLS },
      { date: '2026-10-01', bb_upper: 105, bb_middle: 100, bb_lower: 95, ...NULLS },
    ],
    meta: { limit: 252, point_count: 2, latest_date: '2026-10-01' },
  }
  const toggle = () => screen.getByRole('button', { name: 'Bandas de Bollinger' })
  const bands = () => screen.getByTestId('interactive-chart').getAttribute('data-bands')

  beforeEach(() => {
    vi.mocked(instrumentApi.detail).mockResolvedValue(payload({ ticker: 'NVDA', company: 'NVIDIA', exchange: 'NASDAQ' }))
  })

  it('makes no indicators request and draws no band while everything is off', async () => {
    show('NVDA')

    expect(await screen.findByTestId('interactive-chart')).toBeTruthy()
    expect(toggle().getAttribute('aria-pressed')).toBe('false')
    expect(bands()).toBe('off')
    expect(instrumentApi.indicators).not.toHaveBeenCalled()
  })

  it('requests the series once when switched on, remembers the choice and hides it when off', async () => {
    vi.mocked(instrumentApi.indicators).mockResolvedValue(SERIES)
    show('NVDA')
    await screen.findByTestId('interactive-chart')

    fireEvent.click(toggle())
    await waitFor(() => expect(bands()).toBe('2'))
    expect(toggle().getAttribute('aria-pressed')).toBe('true')
    expect(instrumentApi.indicators).toHaveBeenCalledTimes(1)
    expect(window.localStorage.getItem('chartiko.chart.indicators')).toBe('["bollinger"]')

    fireEvent.click(toggle())
    expect(bands()).toBe('off')
    expect(window.localStorage.getItem('chartiko.chart.indicators')).toBe('[]')
    expect(instrumentApi.indicators).toHaveBeenCalledTimes(1)
  })

  it('restores a stored choice and fetches on load', async () => {
    window.localStorage.setItem('chartiko.chart.indicators', '["bollinger"]')
    vi.mocked(instrumentApi.indicators).mockResolvedValue(SERIES)
    show('NVDA')

    await waitFor(() => expect(bands()).toBe('2'))
    expect(toggle().getAttribute('aria-pressed')).toBe('true')
  })

  it('shows a localized error with retry and keeps the chart', async () => {
    vi.mocked(instrumentApi.indicators).mockRejectedValueOnce(new Error('boom')).mockResolvedValue(SERIES)
    show('NVDA')
    await screen.findByTestId('interactive-chart')

    fireEvent.click(toggle())
    const message = await screen.findByText(/No se pudo cargar la serie de indicadores/)
    expect(bands()).toBe('off')

    fireEvent.click(within(message.closest('[role="alert"]') as HTMLElement).getByRole('button', { name: 'Reintentar' }))
    await waitFor(() => expect(bands()).toBe('2'))
    expect(instrumentApi.indicators).toHaveBeenCalledTimes(2)
  })

  it('shows the not-enough-history note when no band point is complete', async () => {
    vi.mocked(instrumentApi.indicators).mockResolvedValue({ ...SERIES, series: [SERIES.series[0]] })
    show('NVDA')
    await screen.findByTestId('interactive-chart')

    fireEvent.click(toggle())
    expect(await screen.findByText(/No hay historial suficiente para las Bandas de Bollinger/)).toBeTruthy()
  })

  it('works with a throwing localStorage for the choice', async () => {
    vi.mocked(instrumentApi.indicators).mockResolvedValue(SERIES)
    const getItem = Storage.prototype.getItem
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(function (this: Storage, key: string) {
      if (key === 'chartiko.chart.indicators') throw new Error('blocked')
      return getItem.call(this, key)
    })
    const setItem = Storage.prototype.setItem
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(function (this: Storage, key: string, value: string) {
      if (key === 'chartiko.chart.indicators') throw new Error('blocked')
      setItem.call(this, key, value)
    })
    show('NVDA')
    await screen.findByTestId('interactive-chart')

    fireEvent.click(toggle())
    await waitFor(() => expect(bands()).toBe('2'))
    vi.restoreAllMocks()
  })
})

describe('Indicator panes (chart-indicator-panels)', () => {
  const NULLS = { bb_upper: null, bb_middle: null, bb_lower: null }
  const SERIES = {
    ticker: 'NVDA',
    series: [
      { date: '2026-10-01', ...NULLS, adx: 30, plus_di: 25, minus_di: 15, atr14: 2.5, stoch_k: 80, stoch_d: 70 },
    ],
    meta: { limit: 252, point_count: 1, latest_date: '2026-10-01' },
  }
  const panes = () => screen.getByTestId('interactive-chart').getAttribute('data-panes')

  beforeEach(() => {
    vi.mocked(instrumentApi.detail).mockResolvedValue(payload({ ticker: 'NVDA', company: 'NVIDIA', exchange: 'NASDAQ' }))
    vi.mocked(instrumentApi.indicators).mockReset()
  })

  it('offers the three pane toggles, all off, with no request', async () => {
    show('NVDA')
    await screen.findByTestId('interactive-chart')
    for (const name of ['ADX (+DI / -DI)', 'ATR', 'Estocástico']) {
      expect(screen.getByRole('button', { name }).getAttribute('aria-pressed')).toBe('false')
    }
    expect(panes()).toBe('')
    expect(instrumentApi.indicators).not.toHaveBeenCalled()
  })

  it('shares one request between Bollinger and the panes and remembers them', async () => {
    vi.mocked(instrumentApi.indicators).mockResolvedValue(SERIES)
    show('NVDA')
    await screen.findByTestId('interactive-chart')

    fireEvent.click(screen.getByRole('button', { name: 'Bandas de Bollinger' }))
    fireEvent.click(screen.getByRole('button', { name: 'ATR' }))
    fireEvent.click(screen.getByRole('button', { name: 'Estocástico' }))
    await waitFor(() => expect(panes()).toBe('atr,stochastic'))
    expect(instrumentApi.indicators).toHaveBeenCalledTimes(1)
    expect(window.localStorage.getItem('chartiko.chart.indicators')).toBe('["bollinger","atr","stochastic"]')

    fireEvent.click(screen.getByRole('button', { name: 'ATR' }))
    expect(panes()).toBe('stochastic')
    expect(instrumentApi.indicators).toHaveBeenCalledTimes(1)
  })

  it('restores a stored pane and shows error and retry like the overlay', async () => {
    window.localStorage.setItem('chartiko.chart.indicators', '["adx"]')
    vi.mocked(instrumentApi.indicators).mockRejectedValueOnce(new Error('boom')).mockResolvedValue(SERIES)
    show('NVDA')

    const message = await screen.findByText(/No se pudo cargar la serie de indicadores/)
    expect(panes()).toBe('')
    fireEvent.click(within(message.closest('[role="alert"]') as HTMLElement).getByRole('button', { name: 'Reintentar' }))
    await waitFor(() => expect(panes()).toBe('adx'))
  })

  it('notes an empty pane when the history is too short', async () => {
    window.localStorage.setItem('chartiko.chart.indicators', '["stochastic"]')
    vi.mocked(instrumentApi.indicators).mockResolvedValue({
      ...SERIES,
      series: [{ date: '2026-10-01', ...NULLS, adx: null, plus_di: null, minus_di: null, atr14: null, stoch_k: null, stoch_d: null }],
    })
    show('NVDA')

    expect(await screen.findByText('No hay historial suficiente para Estocástico.')).toBeTruthy()
  })
})

describe('RSI, MACD, OBV and Supertrend toggles (chart-indicators-rsi-macd-obv-supertrend)', () => {
  const FULL_POINT = {
    bb_upper: null, bb_middle: null, bb_lower: null, adx: null, plus_di: null, minus_di: null, atr14: null,
    stoch_k: null, stoch_d: null, rsi14: 55, macd: 1.2, macd_signal: 1, macd_hist: 0.2,
    obv: 5000, supertrend: 98, supertrend_direction: 1,
  }
  const SERIES = {
    ticker: 'NVDA',
    series: [{ date: '2026-10-01', ...FULL_POINT }],
    meta: { limit: 252, point_count: 1, latest_date: '2026-10-01' },
  }
  const panes = () => screen.getByTestId('interactive-chart').getAttribute('data-panes')
  const NAMES = ['Supertrend (10, 3)', 'RSI (14)', 'MACD (12, 26, 9)', 'OBV']

  beforeEach(() => {
    vi.mocked(instrumentApi.detail).mockResolvedValue(payload({ ticker: 'NVDA', company: 'NVIDIA', exchange: 'NASDAQ' }))
    vi.mocked(instrumentApi.indicators).mockReset()
  })

  it('offers the four toggles off by default without any indicator request', async () => {
    show('NVDA')
    await screen.findByTestId('interactive-chart')
    for (const name of NAMES) {
      expect(screen.getByRole('button', { name }).getAttribute('aria-pressed')).toBe('false')
    }
    expect(instrumentApi.indicators).not.toHaveBeenCalled()
  })

  it('makes a single request for all four, remembers them and keeps the request on toggle-off', async () => {
    vi.mocked(instrumentApi.indicators).mockResolvedValue(SERIES)
    show('NVDA')
    await screen.findByTestId('interactive-chart')

    for (const name of NAMES) {
      fireEvent.click(screen.getByRole('button', { name }))
    }
    await waitFor(() => expect(panes()).toBe('supertrend,rsi,macd,obv'))
    expect(instrumentApi.indicators).toHaveBeenCalledTimes(1)
    expect(window.localStorage.getItem('chartiko.chart.indicators')).toBe('["supertrend","rsi","macd","obv"]')
    for (const name of NAMES) {
      expect(screen.getByRole('button', { name }).getAttribute('aria-pressed')).toBe('true')
    }

    fireEvent.click(screen.getByRole('button', { name: 'RSI (14)' }))
    expect(screen.getByRole('button', { name: 'RSI (14)' }).getAttribute('aria-pressed')).toBe('false')
    expect(instrumentApi.indicators).toHaveBeenCalledTimes(1)
  })

  it('restores stored selections of the new ids on reload', async () => {
    window.localStorage.setItem('chartiko.chart.indicators', '["obv","macd"]')
    vi.mocked(instrumentApi.indicators).mockResolvedValue(SERIES)
    show('NVDA')

    await waitFor(() => expect(panes()).toBe('macd,obv'))
    expect(screen.getByRole('button', { name: 'OBV' }).getAttribute('aria-pressed')).toBe('true')
    expect(instrumentApi.indicators).toHaveBeenCalledTimes(1)
  })
})

describe('registered-only panels visible but disabled (guest-disabled-chart)', () => {
  function tree(user: AuthUser | null, status: 'loading' | 'ready' = 'ready') {
    return (
      <AuthContext.Provider value={{ ...auth, user, status }}>
        <I18nProvider>
          <MemoryRouter initialEntries={['/instruments/AAPL#ai-company-card']}>
            <Routes>
              <Route path="/instruments/:ticker" element={<InstrumentChartPage />} />
            </Routes>
          </MemoryRouter>
        </I18nProvider>
      </AuthContext.Provider>
    )
  }

  beforeEach(() => {
    Element.prototype.scrollIntoView = vi.fn()
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    vi.mocked(instrumentApi.detail).mockResolvedValue(payload({ ticker: 'AAPL', kind: 'stock', market: 'US', exchange: 'NASDAQ' }))
    vi.mocked(instrumentMarketContextApi.get).mockResolvedValue(AAPL_CONTEXT)
    vi.mocked(companyCardApi.get).mockResolvedValue({ card: null, available: true, fundamentals: null })
  })

  it('a Visitor sees Jev, the Company Card, the strip and a dimmed Seguir, and makes no registered-only request', async () => {
    render(tree(null))

    expect(await screen.findByTestId('interactive-chart')).toBeTruthy()
    expect(await screen.findByRole('region', { name: 'Contexto de mercado' })).toBeTruthy()
    expect(screen.getByRole('heading', { name: 'Pronóstico Jev' })).toBeTruthy()
    expect(document.getElementById('ai-company-card')).toBeTruthy()
    const follow = screen.getByRole('button', { name: 'Seguir' })
    expect(follow.getAttribute('aria-disabled')).toBe('true')
    expect(follow.hasAttribute('disabled')).toBe(false)
    fireEvent.click(follow)
    expect(screen.getAllByText('Disponible al iniciar sesión').length).toBeGreaterThanOrEqual(3)
    await new Promise((resolve) => setTimeout(resolve, 20))

    expect(watchlistApi.list).not.toHaveBeenCalled()
    expect(watchlistApi.add).not.toHaveBeenCalled()
    expect(companyCardApi.get).not.toHaveBeenCalled()
    expect(instrumentMarketContextApi.get).not.toHaveBeenCalled()
    expect(Element.prototype.scrollIntoView).toHaveBeenCalledTimes(1)
  })

  it('flips in place at login and back at logout without refetching the chart', async () => {
    const { rerender } = render(tree(null))
    expect(await screen.findByRole('region', { name: 'Contexto de mercado' })).toBeTruthy()
    const chartCalls = vi.mocked(instrumentApi.detail).mock.calls.length

    rerender(tree(ALLOWED))
    await waitFor(() => expect(companyCardApi.get).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(watchlistApi.list).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(instrumentMarketContextApi.get).toHaveBeenCalledTimes(1))
    expect(screen.getByRole('button', { name: 'Seguir' }).getAttribute('aria-disabled')).toBeNull()
    expect(instrumentApi.detail).toHaveBeenCalledTimes(chartCalls)

    rerender(tree(null))
    expect(screen.getByRole('button', { name: 'Seguir' }).getAttribute('aria-disabled')).toBe('true')
    expect(screen.getByRole('heading', { name: 'Pronóstico Jev' })).toBeTruthy()
    expect(instrumentApi.detail).toHaveBeenCalledTimes(chartCalls)
  })

  it('shows none of the stock-only panels to a Visitor on an ETF', async () => {
    vi.mocked(instrumentApi.detail).mockResolvedValue(payload({ ticker: 'XLK', kind: 'etf' }))
    render(tree(null))
    expect(await screen.findByTestId('interactive-chart')).toBeTruthy()
    expect(screen.queryByRole('heading', { name: 'Pronóstico Jev' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Seguir' })).toBeNull()
    expect(document.getElementById('ai-company-card')).toBeNull()
  })

  it('hides the panels disabled for a Visitor on a non-US stock (login would not unlock them)', async () => {
    vi.mocked(instrumentApi.detail).mockResolvedValue(
      payload({ ticker: 'SAN.MC', kind: 'stock', market: 'XMAD', availability: { company_card: false, fundamentals: false, forecast: false } }),
    )
    render(tree(null))
    expect(await screen.findByTestId('interactive-chart')).toBeTruthy()
    expect(screen.queryByRole('heading', { name: 'Pronóstico Jev' })).toBeNull()
    expect(document.getElementById('ai-company-card')).toBeNull()
    expect(screen.queryByRole('region', { name: 'Contexto de mercado' })).toBeNull()
  })
})
