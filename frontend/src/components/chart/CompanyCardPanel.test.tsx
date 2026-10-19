// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from '../../auth/context.ts'
import type { AuthContextValue } from '../../auth/context.ts'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import { MESSAGES } from '../../i18n/messages/index.ts'
import { ApiError, companyCardApi } from '../../lib/api.ts'
import type { CompanyCard, FundamentalReason, Fundamentals, ScreenerCandidate } from '../../lib/api.ts'
import CandidateResults from '../screener/CandidateResults.tsx'
import CandidateTable from '../screener/CandidateTable.tsx'
import CompanyCardPanel from './CompanyCardPanel.tsx'

vi.mock('../../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api.ts')>()
  return { ...actual, companyCardApi: { get: vi.fn(), generate: vi.fn() } }
})

const auth = {
  user: { id: 1, name: 'Test', email: 'test@example.com', role: 'user' as const },
  status: 'ready' as const,
  login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn(),
}

function tree(signedIn: boolean, status: 'loading' | 'ready' = 'ready', entry = '/instruments/NVDA') {
  return (
    <AuthContext.Provider value={{ ...auth, status, user: signedIn ? auth.user : null }}>
      <I18nProvider>
        <MemoryRouter initialEntries={[entry]}>
          <CompanyCardPanel ticker="NVDA" />
        </MemoryRouter>
      </I18nProvider>
    </AuthContext.Provider>
  )
}

function show(signedIn: boolean, status: 'loading' | 'ready' = 'ready', entry = '/instruments/NVDA') {
  return render(tree(signedIn, status, entry))
}

const DEEP_LINK = '/instruments/NVDA#ai-company-card'

function card(overrides: Partial<CompanyCard> = {}): CompanyCard {
  return {
    ticker: 'NVDA',
    locale: 'es',
    schema_version: 'company-card-v2',
    model: 'fake-model',
    generated_at: '2026-10-15T10:00:00Z',
    fresh: true,
    context_mode: 'identity',
    context_as_of_date: null,
    fundamentals_period_end: '2026-07-27',
    content: {
      company: { name: 'NVIDIA Corporation', ticker: 'NVDA', sector: 'Information Technology', industry: 'Semiconductors', description: 'Diseña GPUs.' },
      outlook: {
        horizon_months: 3,
        stance: 'bullish',
        summary: 'La demanda sigue fuerte.',
        macro_context: 'Tipos altos.',
        sector_context: 'Inversión en centros de datos.',
        drivers: ['Centros de datos'],
        risks: ['Restricciones a la exportación'],
        confidence: 'medium',
      },
      limitations: 'Cifras del modelo, pueden estar desactualizadas.',
      sources: ['Informes anuales'],
    },
    ...overrides,
  }
}

const ttm = (end: string, form = '10-Q') => ({ kind: 'ttm' as const, end, form })
const price = { kind: 'price' as const, end: '2026-10-14', form: null }
const none = (reason: FundamentalReason) => ({ value: null, period: null, reason })

function fundamentals(overrides: Partial<Fundamentals> = {}): Fundamentals {
  return {
    schema_version: 'fundamentals-v1',
    source: 'sec_edgar',
    currency: 'USD',
    is_financial: false,
    period_end: '2026-07-27',
    filing: { form: '10-Q', filed_at: '2026-08-27', accession: '0001045810-26-000100' },
    pending_filing: null,
    price: { close: 180, date: '2026-10-14' },
    fetched_at: '2026-10-15T11:00:00Z',
    metrics: {
      pe_ttm: { value: 45.2, period: price, reason: null },
      eps_diluted_ttm: { value: 3.98, period: ttm('2026-07-27'), reason: null },
      market_cap_millions: { value: 4380000, period: price, reason: null },
      revenue_ttm_millions: { value: 186000.5, period: ttm('2026-07-27'), reason: null },
      revenue_growth_yoy_percent: { value: 78.4, period: ttm('2026-07-27'), reason: null },
      ebitda_millions: { value: 86000, period: { kind: 'fy', end: '2026-01-25', form: '10-K' }, reason: null },
      ebitda_margin_percent: { value: 62.1, period: ttm('2026-07-27'), reason: null },
      net_debt_to_ebitda: { value: -0.4, period: ttm('2026-07-27'), reason: null },
      roic_percent: none('missing_data'),
      dividend_yield_percent: { value: 0.03, period: price, reason: null },
    },
    ...overrides,
  }
}

const READY_EMPTY = { card: null, available: true, fundamentals: null }

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => { cleanup(); vi.clearAllMocks(); window.localStorage.clear() })

describe('CompanyCardPanel', () => {
  it('renders nothing for a Visitor: no section, no sign-in prompt, no request', () => {
    const { container } = show(false)
    expect(container.innerHTML).toBe('')
    expect(document.getElementById('ai-company-card')).toBeNull()
    expect(screen.queryByRole('link')).toBeNull()
    expect(companyCardApi.get).not.toHaveBeenCalled()
    expect(companyCardApi.generate).not.toHaveBeenCalled()
  })

  it('renders nothing while auth is loading (no flash) and makes no request', () => {
    const { container } = show(false, 'loading')
    expect(container.innerHTML).toBe('')
    expect(companyCardApi.get).not.toHaveBeenCalled()
  })

  it('shows the labelled panel for a signed-in user and hides it on sign-out without a reload', async () => {
    vi.mocked(companyCardApi.get).mockResolvedValue(READY_EMPTY)
    const { container, rerender } = show(true)
    expect(screen.getByTestId('company-card-warning').textContent).toContain('Generado por IA · no verificado · no es asesoramiento de inversión')
    await waitFor(() => expect(screen.getByRole('button', { name: 'Generar previsión' })).toBeTruthy())
    rerender(tree(false))
    expect(container.innerHTML).toBe('')
    rerender(tree(true))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Generar previsión' })).toBeTruthy())
  })

  it('shows loading, then a clear not-available state without a generate button', async () => {
    let resolve: (value: { card: null; available: boolean; fundamentals: null }) => void = () => {}
    vi.mocked(companyCardApi.get).mockImplementation(() => new Promise((done) => { resolve = done }))
    show(true)
    expect(screen.getByRole('status').textContent).toBe('Cargando ficha…')
    resolve({ card: null, available: false, fundamentals: null })
    await waitFor(() => expect(screen.getByText('La previsión con IA aún no está disponible.')).toBeTruthy())
    expect(screen.queryByRole('button', { name: 'Generar previsión' })).toBeNull()
    expect(companyCardApi.generate).not.toHaveBeenCalled()
  })

  it('reads on open, generates only on request and renders the card with dashes for unknown values', async () => {
    vi.mocked(companyCardApi.get).mockResolvedValue(READY_EMPTY)
    let finish: (value: { card: CompanyCard; reused: boolean; fundamentals: Fundamentals | null }) => void = () => {}
    vi.mocked(companyCardApi.generate).mockImplementation(() => new Promise((done) => { finish = done }))
    show(true)

    await waitFor(() => expect(screen.getByText('Todavía no hay previsión de NVDA en este idioma.')).toBeTruthy())
    expect(companyCardApi.get).toHaveBeenCalledWith('NVDA')
    expect(companyCardApi.generate).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Generar previsión' }))
    expect(companyCardApi.generate).toHaveBeenCalledWith('NVDA')
    expect((screen.getByRole('button', { name: 'Generar previsión' }) as HTMLButtonElement).disabled).toBe(true)
    expect(screen.getByText('Generando… puede tardar hasta un minuto.')).toBeTruthy()

    finish({ card: card(), reused: false, fundamentals: fundamentals() })
    await waitFor(() => expect(screen.getByText('NVIDIA Corporation')).toBeTruthy())
    expect(screen.queryByRole('button', { name: 'Generar previsión' })).toBeNull()
    expect(screen.getByText('Semiconductors')).toBeTruthy()
    expect(screen.getByText('Information Technology')).toBeTruthy()

    const pe = within(screen.getByTestId('company-card-metric-pe_ttm'))
    expect(pe.getByText('PER (últimos 12 meses)')).toBeTruthy()
    expect(pe.getByText('45,2x')).toBeTruthy()
    expect(pe.getByText('Precio del 14 oct 2026')).toBeTruthy()
    expect(screen.getByTestId('company-card-metric-eps_diluted_ttm').textContent).toContain('3,98 USD')
    expect(screen.getByTestId('company-card-metric-eps_diluted_ttm').textContent).toContain('TTM a 27 jul 2026 · 10-Q')
    expect(screen.getByTestId('company-card-metric-revenue_ttm_millions').textContent).toContain('186.000,5 M USD')
    expect(screen.getByTestId('company-card-metric-revenue_growth_yoy_percent').textContent).toContain('+78,40%')
    expect(screen.getByTestId('company-card-metric-ebitda_millions').textContent).toContain('86.000 M USD')
    expect(screen.getByTestId('company-card-metric-ebitda_millions').textContent).toContain('Ejercicio a 25 ene 2026 · 10-K')
    expect(screen.getByTestId('company-card-metric-roic_percent').textContent).toBe('ROIC (aprox.)—sin datos en los informes')
    expect(screen.getByTestId('company-card-metric-net_debt_to_ebitda').textContent).toContain('-0,4x')
    expect(screen.getByTestId('company-card-source').textContent).toBe('Fuente: SEC (10-K/10-Q)')
    expect(screen.queryByText('PER estimado')).toBeNull()

    expect(screen.getByTestId('company-card-stance').textContent).toBe('Escenario base: alcista')
    expect(screen.getByText('Confianza del modelo: media')).toBeTruthy()
    expect(screen.getByText('Centros de datos')).toBeTruthy()
    expect(screen.getByText('Restricciones a la exportación')).toBeTruthy()
    expect(screen.getByText('Cifras del modelo, pueden estar desactualizadas.')).toBeTruthy()
    expect(screen.getByText('Cifras SEC enviadas a la IA: periodo a 27 jul 2026.')).toBeTruthy()
    expect(screen.getByText(/^Generada el .* · modelo fake-model$/)).toBeTruthy()
    expect(screen.getByText('Contexto enviado a la IA: identidad de la empresa.')).toBeTruthy()
    // Plain text, never links.
    expect(screen.queryAllByRole('link')).toHaveLength(0)
  })

  it('shows a stale card with an outdated note and a refresh button', async () => {
    vi.mocked(companyCardApi.get).mockResolvedValue({ card: card({ fresh: false }), available: true, fundamentals: fundamentals() })
    show(true)
    await waitFor(() => expect(screen.getByText('Esta previsión está desactualizada (hay datos o un modelo más recientes).')).toBeTruthy())
    expect(screen.getByText('NVIDIA Corporation')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Actualizar previsión' })).toBeTruthy()
  })

  it('shows a generation error as an alert, keeps the old card and never retries on its own', async () => {
    vi.mocked(companyCardApi.get).mockResolvedValue({ card: card({ fresh: false }), available: true, fundamentals: fundamentals() })
    vi.mocked(companyCardApi.generate).mockRejectedValue(new ApiError(503, 'El proveedor de IA devolvió una ficha no válida.', {}, 'invalid_provider_response'))
    show(true)
    fireEvent.click(await screen.findByRole('button', { name: 'Actualizar previsión' }))
    await waitFor(() => expect(screen.getByRole('alert').textContent).toBe('El proveedor de IA devolvió una ficha no válida.'))
    expect(screen.getByText('NVIDIA Corporation')).toBeTruthy()
    expect(companyCardApi.generate).toHaveBeenCalledTimes(1)
  })

  it('switches to the not-available state when generation reports provider_not_configured', async () => {
    vi.mocked(companyCardApi.get).mockResolvedValue(READY_EMPTY)
    vi.mocked(companyCardApi.generate).mockRejectedValue(new ApiError(503, 'La previsión con IA aún no está disponible.', {}, 'provider_not_configured'))
    show(true)
    fireEvent.click(await screen.findByRole('button', { name: 'Generar previsión' }))
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Generar previsión' })).toBeNull())
    expect(screen.getAllByText('La previsión con IA aún no está disponible.').length).toBeGreaterThan(0)
  })

  it('shows a read error with a manual retry', async () => {
    vi.mocked(companyCardApi.get).mockRejectedValueOnce(new TypeError('offline')).mockResolvedValueOnce(READY_EMPTY)
    show(true)
    await waitFor(() => expect(screen.getByRole('alert').textContent).toBe('No se pudo contactar con el servidor. Inténtalo de nuevo.'))
    expect(companyCardApi.get).toHaveBeenCalledTimes(1)
    fireEvent.click(screen.getByRole('button', { name: 'Reintentar' }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Generar previsión' })).toBeTruthy())
    expect(companyCardApi.get).toHaveBeenCalledTimes(2)
  })

  it('renders English labels and the English scenario wording', async () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    vi.mocked(companyCardApi.get).mockResolvedValue({ card: card({ locale: 'en', context_mode: 'identity+technical', context_as_of_date: '2026-10-14' }), available: true, fundamentals: fundamentals() })
    show(true)
    await waitFor(() => expect(screen.getByText('Company card')).toBeTruthy())
    expect(screen.getByText('3-month outlook')).toBeTruthy()
    expect(screen.getByTestId('company-card-warning').textContent).toBe('Generated by AI · unverified · not investment advice · may contain errors')
    expect(await screen.findByText('Base case: bullish')).toBeTruthy()
    expect(screen.getByTestId('company-card-metric-ebitda_millions').textContent).toContain('86,000M USD')
    expect(screen.getByTestId('company-card-metric-ebitda_millions').textContent).toContain('Fiscal year to Jan 25, 2026 · 10-K')
    expect(screen.getByTestId('company-card-metric-pe_ttm').textContent).toContain('Price of Oct 14, 2026')
    expect(screen.getByTestId('company-card-metric-roic_percent').textContent).toContain('not in the filings')
    expect(screen.getByTestId('company-card-source').textContent).toBe('Source: SEC (10-K/10-Q)')
    expect(screen.getByText(/Data from filings submitted to the SEC \(EDGAR\), computed by Chartiko · closing price of Oct 14, 2026/)).toBeTruthy()
    expect(screen.getByText('P/E (trailing 12 months)')).toBeTruthy()
    expect(screen.getByText(/Chartiko technical data from Oct 14, 2026/)).toBeTruthy()
  })
})

describe('CompanyCardPanel SEC fundamentals', () => {
  it('puts the AI 3-month outlook above the SEC metrics', async () => {
    vi.mocked(companyCardApi.get).mockResolvedValue({ card: card(), available: true, fundamentals: fundamentals() })
    show(true)
    await waitFor(() => expect(screen.getByTestId('company-card-stance')).toBeTruthy())
    const outlook = screen.getByTestId('company-card-outlook')
    const metrics = screen.getByTestId('company-card-metrics')
    expect(outlook.compareDocumentPosition(metrics) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    const heading = within(outlook).getByRole('heading', { level: 3 })
    expect(heading.textContent).toBe('Previsión a 3 meses')
    expect(within(outlook).getByText('IA')).toBeTruthy()
    expect(within(outlook).getByTestId('company-card-warning')).toBeTruthy()
    // The heading precedes the stance chip, the summary and the confidence.
    expect(heading.compareDocumentPosition(screen.getByTestId('company-card-stance')) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    expect(within(metrics).getByText('Métricas clave')).toBeTruthy()
    expect(within(metrics).getByText(/Datos de informes presentados ante la SEC \(EDGAR\), calculados por Chartiko · precio de cierre del 14 oct 2026 · actualizado/)).toBeTruthy()
  })

  it('shows the metrics without any AI card and with the provider disabled', async () => {
    vi.mocked(companyCardApi.get).mockResolvedValue({ card: null, available: false, fundamentals: fundamentals() })
    show(true)
    await waitFor(() => expect(screen.getByTestId('company-card-metric-pe_ttm')).toBeTruthy())
    expect(screen.getByText('La previsión con IA aún no está disponible.')).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Generar previsión' })).toBeNull()
    expect(screen.getByTestId('company-card-source').textContent).toBe('Fuente: SEC (10-K/10-Q)')
    expect(screen.getAllByTestId(/^company-card-metric-/)).toHaveLength(10)
  })

  it('explains not-applicable bank metrics, negative earnings and missing data with a dash', async () => {
    const base = fundamentals()
    vi.mocked(companyCardApi.get).mockResolvedValue({
      card: null,
      available: true,
      fundamentals: fundamentals({
        is_financial: true,
        metrics: {
          ...base.metrics,
          pe_ttm: none('negative_earnings'),
          ebitda_millions: none('financial_sector'),
          ebitda_margin_percent: none('financial_sector'),
          net_debt_to_ebitda: none('financial_sector'),
          roic_percent: none('financial_sector'),
          dividend_yield_percent: none('not_reported'),
          market_cap_millions: none('no_price'),
        },
      }),
    })
    show(true)
    await waitFor(() => expect(screen.getByTestId('company-card-metric-ebitda_millions')).toBeTruthy())
    for (const key of ['ebitda_millions', 'ebitda_margin_percent', 'net_debt_to_ebitda', 'roic_percent']) {
      const tile = screen.getByTestId(`company-card-metric-${key}`)
      expect(tile.textContent).toContain('—')
      expect(tile.textContent).toContain('no aplica a entidades financieras')
    }
    expect(screen.getByTestId('company-card-metric-pe_ttm').textContent).toContain('beneficio negativo')
    expect(screen.getByTestId('company-card-metric-dividend_yield_percent').textContent).toContain('no informado')
    expect(screen.getByTestId('company-card-metric-market_cap_millions').textContent).toContain('sin precio reciente')
    expect(screen.getByTestId('company-card-metric-eps_diluted_ttm').textContent).toContain('3,98 USD')
  })

  it('notes a newer filing that SEC data does not include yet', async () => {
    vi.mocked(companyCardApi.get).mockResolvedValue({
      card: null,
      available: true,
      fundamentals: fundamentals({ pending_filing: { form: '10-Q', filed_at: '2026-08-03' } }),
    })
    show(true)
    await waitFor(() => expect(screen.getByTestId('company-card-pending')).toBeTruthy())
    expect(screen.getByTestId('company-card-pending').textContent).toBe('Hay un informe más reciente (10-Q del 3 ago 2026) pendiente de publicar en datos SEC.')
  })

  it('says when SEC metrics are unavailable or the company has no filing data', async () => {
    vi.mocked(companyCardApi.get).mockResolvedValueOnce(READY_EMPTY)
    const { unmount } = show(true)
    await waitFor(() => expect(screen.getByText('Las métricas de informes SEC aún no están disponibles.')).toBeTruthy())
    expect(screen.queryByTestId('company-card-source')).toBeNull()
    unmount()

    vi.mocked(companyCardApi.get).mockResolvedValueOnce({ card: null, available: true, fundamentals: fundamentals({ period_end: null, filing: null }) })
    show(true)
    await waitFor(() => expect(screen.getByText('No hay datos de informes SEC para esta empresa.')).toBeTruthy())
    expect(screen.queryAllByTestId(/^company-card-metric-/)).toHaveLength(0)
  })

  it('takes fresh fundamentals from the generate response', async () => {
    vi.mocked(companyCardApi.get).mockResolvedValue(READY_EMPTY)
    vi.mocked(companyCardApi.generate).mockResolvedValue({ card: card(), reused: false, fundamentals: fundamentals() })
    show(true)
    fireEvent.click(await screen.findByRole('button', { name: 'Generar previsión' }))
    await waitFor(() => expect(screen.getByTestId('company-card-metric-pe_ttm')).toBeTruthy())
    expect(screen.getByText('La demanda sigue fuerte.')).toBeTruthy()
  })
})

describe('CompanyCardPanel deep link', () => {
  const scroll = vi.fn()

  beforeEach(() => {
    scroll.mockClear()
    Element.prototype.scrollIntoView = scroll
    vi.mocked(companyCardApi.get).mockResolvedValue(READY_EMPTY)
  })

  it('scrolls to the panel once for a signed-in user opening #ai-company-card', async () => {
    const { rerender } = show(true, 'ready', DEEP_LINK)
    expect(scroll).toHaveBeenCalledTimes(1)
    expect(scroll).toHaveBeenCalledWith({ block: 'start' })
    expect(scroll.mock.contexts[0]).toBe(document.getElementById('ai-company-card'))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Generar previsión' })).toBeTruthy())
    rerender(tree(true, 'ready', DEEP_LINK))
    expect(scroll).toHaveBeenCalledTimes(1)
  })

  it('scrolls when the session resolves after the panel mounted', async () => {
    const { rerender } = show(false, 'loading', DEEP_LINK)
    expect(scroll).not.toHaveBeenCalled()
    rerender(tree(true, 'ready', DEEP_LINK))
    expect(scroll).toHaveBeenCalledTimes(1)
    await waitFor(() => expect(companyCardApi.get).toHaveBeenCalledWith('NVDA'))
  })

  it('never scrolls or renders for a Visitor with the hash', () => {
    const { container } = show(false, 'ready', DEEP_LINK)
    expect(container.innerHTML).toBe('')
    expect(scroll).not.toHaveBeenCalled()
  })

  it('does not scroll without the hash', () => {
    show(true)
    expect(scroll).not.toHaveBeenCalled()
  })
})

describe('companyCard catalog', () => {
  it('has no price target, rating or buy/sell wording in any language', () => {
    for (const messages of Object.values(MESSAGES)) {
      const text = JSON.stringify(messages.companyCard)
      expect(text).not.toMatch(/target|objetivo|precio justo|fair value|\bbuy\b|\bsell\b|comprar|vender|recomend|recommend/i)
    }
  })
})

describe('CandidateTable AI card link', () => {
  const candidate: ScreenerCandidate = {
    ticker: 'NVDA', company: 'NVIDIA', sector: 'Tech', exchange: 'NASDAQ', active: true,
    date: '2026-10-02', close: 100, change_percent: 1, rvol: 1.2, rsi14: 55, signals: [], patterns: [],
  }

  function results(value: AuthContextValue | null) {
    const content = (
      <I18nProvider>
        <MemoryRouter>
          <CandidateResults status="ready" candidates={[candidate]} hasFilters={false} error={null} onRetry={() => {}} onClearFilters={() => {}} />
        </MemoryRouter>
      </I18nProvider>
    )
    return render(value === null ? content : <AuthContext.Provider value={value}>{content}</AuthContext.Provider>)
  }

  it('hides the link by default (presentational table, Visitors)', () => {
    render(
      <I18nProvider>
        <MemoryRouter>
          <CandidateTable candidates={[candidate]} />
        </MemoryRouter>
      </I18nProvider>,
    )
    expect(screen.getByRole('link', { name: 'NVDA' })).toBeTruthy()
    expect(screen.getByText('NASDAQ')).toBeTruthy()
    expect(screen.getByText('NVIDIA')).toBeTruthy()
    expect(screen.queryByText('Ficha IA')).toBeNull()
  })

  it.each([
    ['no AuthProvider', null],
    ['auth loading', { ...auth, status: 'loading' as const, user: null }],
    ['Visitor', { ...auth, user: null }],
  ])('CandidateResults shows no AI card link for %s', (_label, value) => {
    results(value)
    expect(screen.getByRole('link', { name: 'NVDA' })).toBeTruthy()
    expect(screen.queryByText('Ficha IA')).toBeNull()
    expect(screen.queryByText(/inicia sesión/i)).toBeNull()
  })

  it('CandidateResults links each Candidate to its chart Company Card anchor for a signed-in user', () => {
    results(auth)
    const link = screen.getByRole('link', { name: 'Ficha IA de NVDA' })
    expect(link.getAttribute('href')).toBe('/instruments/NVDA#ai-company-card')
    expect(link.textContent).toBe('Ficha IA')
    expect(companyCardApi.get).not.toHaveBeenCalled()
  })
})
