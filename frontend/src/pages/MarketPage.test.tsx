// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { RouterProvider, createMemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../auth/AuthContext.tsx'
import { FIXTURE_CONTEXT, FIXTURE_REGIME, regimeHistory } from '../components/market/marketContext.fixture.ts'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import type { AuthUser, MarketContext } from '../lib/api.ts'
import MarketPage from './MarketPage.tsx'

const USER: AuthUser = { id: 1, name: 'Ana', email: 'ana@example.test', role: 'user' }

const ADVICE = /compra|comprar|venta|vender|recomend|objetivo|probabilidad|previsi[oó]n|pron[oó]stico|\bbuy\b|\bsell\b|target|recommend|probabilit|forecast|predict/i
const EOD = /\bEOD\b|end[- ]of[- ]day|fin de d[ií]a/i

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

type Mock = { context?: MarketContext | 'error' | 'unavailable'; user?: AuthUser | null | 'pending' }

function mockApi({ context = FIXTURE_CONTEXT, user = null }: Mock = {}) {
  const fetchMock = vi.fn(async (input: string) => {
    if (input === '/api/user') {
      if (user === 'pending') return new Promise<Response>(() => {})
      return user ? json({ user }) : json({ message: 'Unauthenticated.' }, 401)
    }
    if (input === '/api/market-context') {
      if (context === 'error') return json({ message: 'Server Error' }, 500)
      if (context === 'unavailable') return json({ message: 'Esta página aún no está disponible.' }, 404)
      return json(context)
    }
    if (input.startsWith('/api/market-context/history')) {
      const range = new URL(input, 'http://localhost').searchParams.get('range') as '1y' | '3y'
      return json(regimeHistory(range))
    }
    return json({})
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

function historyCalls(fetchMock: ReturnType<typeof mockApi>): string[] {
  return fetchMock.mock.calls.map((call) => String(call[0])).filter((url) => url.startsWith('/api/market-context/history'))
}

function renderPage() {
  const router = createMemoryRouter([{ path: '/mercado', element: <MarketPage /> }], { initialEntries: ['/mercado'] })
  return render(
    <I18nProvider>
      <AuthProvider>
        <RouterProvider router={router} />
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

describe('MarketPage (Scenario 1: public page, Visitor)', () => {
  it('renders the regime, reasons, sectors, cards and the legal footer in Spanish', async () => {
    const fetchMock = mockApi()
    renderPage()

    const regime = await screen.findByRole('region', { name: 'Entorno de mercado' })
    // Label with icon and text: never colour alone.
    const label = within(regime).getByTestId('regime-label')
    expect(label.textContent).toBe('●Neutral')
    expect(within(regime).getByText('Puntuación +1')).toBeTruthy()
    expect(within(regime).getByText('5 componentes cuentan (mínimo 4)')).toBeTruthy()
    expect(screen.getAllByText('Datos del 22 oct 2026').length).toBeGreaterThan(0)

    // One reason per component, in rules order.
    const reasons = within(regime).getAllByRole('listitem')
    expect(reasons).toHaveLength(7)
    expect(reasons[0].textContent).toContain('Amplitud · 55,2 % de las acciones del S&P 500 sobre su media de 200 sesiones')
    expect(reasons[0].textContent).toContain('suma 0')
    expect(reasons[1].textContent).toContain('El S&P 500 (SPY) está en tendencia alcista')
    expect(reasons[1].textContent).toContain('▲ suma +1')
    expect(reasons[2].textContent).toContain('6 de 11 sectores en tendencia alcista')
    expect(reasons[3].textContent).toContain('Diferencial 10 años – 3 meses: +0,31 pp')
    expect(reasons[3].textContent).toContain('Datos del 21 oct 2026')
    expect(reasons[4].textContent).toContain('Condiciones financieras · No disponible · fuente no habilitada')
    expect(reasons[4].textContent).toContain('no cuenta')
    expect(reasons[5].textContent).toContain('Liquidez neta: -0,85 % en 13 semanas')
    expect(reasons[6].textContent).toContain('Volatilidad · No disponible · fuente no habilitada')

    // Sectors by rank with row links.
    const table = screen.getByRole('region', { name: 'Sectores ordenados por fuerza relativa' })
    const rows = within(table).getAllByRole('row').slice(1)
    expect(rows).toHaveLength(11)
    expect(rows[0].textContent).toContain('Tecnología de la información')
    expect(rows[0].textContent).toContain('+1,23 %')
    expect(rows[0].textContent).toContain('+3,21 pp')
    expect(rows[0].textContent).toContain('▲Alcista')
    expect(rows[10].textContent).toContain('Energía')
    expect(rows[10].textContent).toContain('▼Bajista')
    expect(within(table).getByRole('link', { name: 'Ver el gráfico de XLK' }).getAttribute('href')).toBe('/instruments/XLK')

    // Every card has a source and "¿Qué significa?".
    for (const title of ['Amplitud del S&P 500', 'Tipos y curva', 'Liquidez neta de la Fed', 'Bancos centrales', 'Datos adelantados']) {
      const card = screen.getByRole('region', { name: title })
      expect(within(card).getByText('¿Qué significa?')).toBeTruthy()
      expect(within(card).getByText(/Fuente/)).toBeTruthy()
    }
    // Per-card sources come from the payload, verbatim.
    const banks = screen.getByRole('region', { name: 'Bancos centrales' })
    expect(within(banks).getByText('Source: ECB statistics, via FRED®')).toBeTruthy()
    expect(within(banks).getByText(/Bajada el 17 sept 2026: de 4,00–4,25 % a 3,75–4,00 %/)).toBeTruthy()
    expect(within(banks).getByText('27 oct 2026 – 28 oct 2026')).toBeTruthy()
    expect(
      within(banks).getAllByRole('link', { name: 'Calendario oficial revisado el 22 oct 2026' }).map((link) => link.getAttribute('href')),
    ).toEqual([
      'https://www.federalreserve.gov/monetarypolicy/fomccalendars.htm',
      'https://www.ecb.europa.eu/press/calendars/mgcgc/html/index.en.html',
    ])
    const rates = screen.getByRole('region', { name: 'Tipos y curva' })
    expect(within(rates).getByText(/H\.15 Selected Interest Rates, via FRED®/)).toBeTruthy()

    // Monthly readings are dated by month; NFCI off.
    const leading = screen.getByRole('region', { name: 'Datos adelantados' })
    expect(within(leading).getAllByText('Datos de septiembre de 2026')).toHaveLength(4)
    expect(within(leading).getByText('No disponible · fuente no habilitada')).toBeTruthy()

    // Legal footer: FRED notice verbatim, ToU and CC BY links, binding sentence.
    const notice = screen.getByTestId('fred-notice')
    expect(notice.textContent).toBe(
      'This product uses the FRED® API but is not endorsed or certified by the Federal Reserve Bank of St. Louis.',
    )
    expect(notice.getAttribute('lang')).toBe('en')
    const terms = screen.getByRole('link', { name: 'Condiciones de uso de la API de FRED®' })
    expect(terms.getAttribute('href')).toBe('https://fred.stlouisfed.org/docs/api/terms_of_use.html')
    expect(terms.getAttribute('rel')).toBe('noopener noreferrer')
    expect(terms.parentElement?.textContent).toBe(
      'Al usar los datos macroeconómicos de esta página aceptas quedar vinculado por las Condiciones de uso de la API de FRED®.',
    )
    expect(screen.getByRole('link', { name: 'CC BY 4.0' }).getAttribute('href')).toBe('https://creativecommons.org/licenses/by/4.0/')
    expect(screen.getByText('Información descriptiva, no es asesoramiento de inversión.')).toBeTruthy()

    // Visitors: a sign-in box instead of every history, and no history request.
    await waitFor(() => expect(screen.getAllByText('Inicia sesión para ver la evolución de 1 y 3 años.')).toHaveLength(6))
    expect(screen.queryByRole('button', { name: 'Ver evolución' })).toBeNull()
    expect(historyCalls(fetchMock)).toEqual([])

    // No preview banner when public; no EOD or advice wording.
    expect(screen.queryByText(/Vista previa/)).toBeNull()
    const text = document.body.textContent ?? ''
    expect(text).not.toMatch(EOD)
    expect(text).not.toMatch(ADVICE)
    expect(text).not.toMatch(/\{\w+\}/)
  })

  it('switches the return column with the period control', async () => {
    mockApi()
    renderPage()

    const week = await screen.findByRole('button', { name: '1 semana' })
    expect(week.getAttribute('aria-pressed')).toBe('true')
    const table = screen.getByRole('region', { name: 'Sectores ordenados por fuerza relativa' })
    fireEvent.click(screen.getByRole('button', { name: '3 meses' }))
    expect(screen.getByRole('button', { name: '3 meses' }).getAttribute('aria-pressed')).toBe('true')
    expect(within(table).getAllByRole('row')[1].textContent).toContain('+9,87 %')
    fireEvent.click(screen.getByRole('button', { name: '1 mes' }))
    expect(within(table).getAllByRole('row')[1].textContent).toContain('+4,50 %')
    // The relative strength column stays at 3 months.
    expect(within(table).getAllByRole('row')[1].textContent).toContain('+3,21 pp')
  })

  it('renders the English page with the same FRED notice', async () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    mockApi()
    renderPage()

    const regime = await screen.findByRole('region', { name: 'Market regime' })
    expect(within(regime).getByTestId('regime-label').textContent).toBe('●Neutral')
    expect(within(regime).getByText('Score +1')).toBeTruthy()
    expect(within(regime).getByText('5 components count (minimum 4)')).toBeTruthy()
    expect(screen.getAllByText('Data as of Oct 22, 2026').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Data for September 2026')).toHaveLength(4)
    expect(screen.getByTestId('fred-notice').textContent).toBe(
      'This product uses the FRED® API but is not endorsed or certified by the Federal Reserve Bank of St. Louis.',
    )
    expect(screen.getByRole('link', { name: 'FRED® API Terms of Use' })).toBeTruthy()
    const text = document.body.textContent ?? ''
    expect(text).not.toMatch(EOD)
    expect(text).not.toMatch(ADVICE)
  })
})

describe('MarketPage states', () => {
  it('Scenario 4: stale blocks and an insufficient regime', async () => {
    const context: MarketContext = {
      ...FIXTURE_CONTEXT,
      regime: {
        ...FIXTURE_REGIME,
        regime: 'insufficient',
        score: null,
        available: 3,
        components: {
          ...FIXTURE_REGIME.components,
          breadth: { status: 'stale', score: null, value: 55.2, as_of: '2026-10-14', stale: true },
          sector_participation: { status: 'missing', score: null, value: null, as_of: null, stale: false },
          // An absent key (not computed by that rules version) is skipped.
          volatility: undefined,
        },
      },
      sectors: { ...FIXTURE_CONTEXT.sectors!, date: '2026-10-14', stale: true },
      breadth: { ...FIXTURE_CONTEXT.breadth!, date: '2026-10-14', stale: true },
    }
    mockApi({ context })
    renderPage()

    const regime = await screen.findByRole('region', { name: 'Entorno de mercado' })
    expect(within(regime).getByTestId('regime-label').textContent).toBe('?Sin datos suficientes')
    expect(within(regime).queryByText(/Puntuación/)).toBeNull()
    expect(within(regime).getByText('Hacen falta al menos 4 componentes con datos al día; hoy cuentan 3.')).toBeTruthy()
    const reasons = within(regime).getAllByRole('listitem')
    expect(reasons).toHaveLength(6)
    expect(reasons[0].textContent).toContain('55,2 %')
    expect(reasons[0].textContent).toContain('Datos del 14 oct 2026')
    expect(reasons[0].textContent).toContain('Desactualizado')
    expect(reasons[0].textContent).toContain('no cuenta')
    expect(reasons[2].textContent).toContain('Participación sectorial · Sin datos')

    const breadth = screen.getByRole('region', { name: 'Amplitud del S&P 500' })
    expect(within(breadth).getByText('Desactualizado').getAttribute('title')).toMatch(/no cuenta en el entorno/)
    const sectors = screen.getByRole('region', { name: 'Sectores' })
    expect(within(sectors).getByText('Desactualizado')).toBeTruthy()
  })

  it('Scenario 5: unavailable page for everyone but Admins', async () => {
    mockApi({ context: 'unavailable' })
    renderPage()

    expect(await screen.findByText('Esta página aún no está disponible.')).toBeTruthy()
    expect(screen.queryByRole('region', { name: 'Entorno de mercado' })).toBeNull()
  })

  it('Scenario 5: the Admin preview shows a banner', async () => {
    mockApi({ context: { ...FIXTURE_CONTEXT, public: false }, user: { ...USER, role: 'admin' } })
    renderPage()

    expect(await screen.findByRole('note')).toBeTruthy()
    expect(screen.getByRole('note').textContent).toBe('Vista previa: esta página aún no es pública; solo la ven los administradores.')
  })

  it('Scenario 6: empty data shows "Todavía no hay datos" per block, never an error', async () => {
    const empty = { value: null, as_of: null, stale: false }
    const context: MarketContext = {
      ...FIXTURE_CONTEXT,
      regime: null,
      sectors: null,
      breadth: null,
      macro: {
        ...FIXTURE_CONTEXT.macro!,
        rates: { dgs3mo: empty, dgs2: empty, dgs10: empty, dgs10_change_20: empty },
        curve: { spread_10y2y: empty, spread_10y3m: empty, inverted: null },
        liquidity: { net_liquidity: empty, change_4w_pct: empty, change_13w_pct: empty },
        leading: {
          icsa: empty, icsa_4w: empty, icsa_4w_vs_52w_min_pct: empty, permit: empty, permit_change_3m_pct: empty,
          nfci: null, oecd_cli: empty, oecd_cli_change_1m: empty,
        },
        central_banks: {
          fed: { ...FIXTURE_CONTEXT.macro!.central_banks.fed!, lower: empty, upper: empty, last_change: null },
          ecb: { ...FIXTURE_CONTEXT.macro!.central_banks.ecb!, deposit_rate: empty, last_change: null },
        },
      },
    }
    mockApi({ context })
    renderPage()

    await screen.findByRole('region', { name: 'Entorno de mercado' })
    // Regime, sectors, breadth and the four macro cards.
    expect(screen.getAllByText('Todavía no hay datos.')).toHaveLength(7)
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('macro disabled: one "not available" card and no FRED notice', async () => {
    mockApi({ context: { ...FIXTURE_CONTEXT, macro: null } })
    renderPage()

    expect(await screen.findByText('Datos macroeconómicos no disponibles.')).toBeTruthy()
    expect(screen.queryByRole('region', { name: 'Tipos y curva' })).toBeNull()
    expect(screen.queryByTestId('fred-notice')).toBeNull()
  })

  it('a load error offers a retry', async () => {
    const fetchMock = mockApi({ context: 'error' })
    renderPage()

    const alert = await screen.findByRole('alert')
    expect(alert.textContent).toContain('No se pudo cargar el contexto de mercado.')
    mockApi()
    fireEvent.click(within(alert).getByRole('button', { name: 'Reintentar' }))
    expect(await screen.findByRole('region', { name: 'Entorno de mercado' })).toBeTruthy()
    expect(fetchMock).toHaveBeenCalled()
  })

  it('renders no history slot while the session loads', async () => {
    const fetchMock = mockApi({ user: 'pending' })
    renderPage()

    await screen.findByRole('region', { name: 'Entorno de mercado' })
    expect(screen.queryByText('Inicia sesión para ver la evolución de 1 y 3 años.')).toBeNull()
    expect(screen.queryByRole('button', { name: 'Ver evolución' })).toBeNull()
    expect(historyCalls(fetchMock)).toEqual([])
  })
})

describe('MarketPage history (Scenario 2: Registered User)', () => {
  it('fetches the regime history on open and on the 3-year toggle', async () => {
    const fetchMock = mockApi({ user: USER })
    renderPage()

    const regime = await screen.findByRole('region', { name: 'Entorno de mercado' })
    const open = await within(regime).findByRole('button', { name: 'Ver evolución' })
    expect(open.getAttribute('aria-expanded')).toBe('false')
    fireEvent.click(open)
    expect(within(regime).getByRole('button', { name: 'Ocultar evolución' }).getAttribute('aria-expanded')).toBe('true')

    await within(regime).findByRole('img')
    expect(historyCalls(fetchMock)).toEqual(['/api/market-context/history?metric=regime_score&range=1y'])
    expect(within(regime).queryByText(/Datos disponibles desde/)).toBeNull()

    fireEvent.click(within(regime).getByRole('button', { name: '3 años' }))
    expect(await within(regime).findByText('Datos disponibles desde 22 oct 2025')).toBeTruthy()
    expect(historyCalls(fetchMock)).toEqual([
      '/api/market-context/history?metric=regime_score&range=1y',
      '/api/market-context/history?metric=regime_score&range=3y',
    ])
    const chart = within(regime).getByRole('img')
    expect(chart.querySelectorAll('[data-reference]')).toHaveLength(3)
  })
})
