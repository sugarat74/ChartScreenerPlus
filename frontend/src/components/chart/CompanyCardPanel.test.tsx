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
import type { CompanyCard, ScreenerCandidate } from '../../lib/api.ts'
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
  login: vi.fn(), register: vi.fn(), logout: vi.fn(),
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
  const metric = (value: number | null, period: string | null, currency: string | null = null) => ({ value, currency, period })
  return {
    ticker: 'NVDA',
    locale: 'es',
    schema_version: 'company-card-v1',
    model: 'fake-model',
    generated_at: '2026-10-15T10:00:00Z',
    fresh: true,
    context_mode: 'identity',
    context_as_of_date: null,
    content: {
      company: { name: 'NVIDIA Corporation', ticker: 'NVDA', sector: 'Information Technology', industry: 'Semiconductors', description: 'Diseña GPUs.' },
      metrics: {
        pe_ttm: metric(45.2, 'TTM T2 2026'),
        pe_forward: metric(null, null),
        roic_percent: metric(38.5, 'FY2025'),
        ebitda_millions: metric(86000, 'FY2025', 'USD'),
        ebitda_margin_percent: metric(62.1, 'FY2025'),
        revenue_growth_yoy_percent: metric(78, null),
        net_debt_to_ebitda: metric(-0.4, 'FY2025'),
        market_cap_millions: metric(null, null),
        dividend_yield_percent: metric(0.03, 'TTM'),
      },
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
      data_as_of: 'FY2025',
      limitations: 'Cifras del modelo, pueden estar desactualizadas.',
      sources: ['Informes anuales'],
    },
    ...overrides,
  }
}

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
    vi.mocked(companyCardApi.get).mockResolvedValue({ card: null, available: true })
    const { container, rerender } = show(true)
    expect(screen.getByTestId('company-card-warning').textContent).toContain('Generado por IA · no verificado · no es asesoramiento de inversión')
    await waitFor(() => expect(screen.getByRole('button', { name: 'Generar ficha' })).toBeTruthy())
    rerender(tree(false))
    expect(container.innerHTML).toBe('')
    rerender(tree(true))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Generar ficha' })).toBeTruthy())
  })

  it('shows loading, then a clear not-available state without a generate button', async () => {
    let resolve: (value: { card: null; available: boolean }) => void = () => {}
    vi.mocked(companyCardApi.get).mockImplementation(() => new Promise((done) => { resolve = done }))
    show(true)
    expect(screen.getByRole('status').textContent).toBe('Cargando ficha…')
    resolve({ card: null, available: false })
    await waitFor(() => expect(screen.getByText('Las fichas de empresa con IA aún no están disponibles.')).toBeTruthy())
    expect(screen.queryByRole('button', { name: 'Generar ficha' })).toBeNull()
    expect(companyCardApi.generate).not.toHaveBeenCalled()
  })

  it('reads on open, generates only on request and renders the card with dashes for unknown values', async () => {
    vi.mocked(companyCardApi.get).mockResolvedValue({ card: null, available: true })
    let finish: (value: { card: CompanyCard; reused: boolean }) => void = () => {}
    vi.mocked(companyCardApi.generate).mockImplementation(() => new Promise((done) => { finish = done }))
    show(true)

    await waitFor(() => expect(screen.getByText('Todavía no hay ficha de NVDA en este idioma.')).toBeTruthy())
    expect(companyCardApi.get).toHaveBeenCalledWith('NVDA')
    expect(companyCardApi.generate).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Generar ficha' }))
    expect(companyCardApi.generate).toHaveBeenCalledWith('NVDA')
    expect((screen.getByRole('button', { name: 'Generar ficha' }) as HTMLButtonElement).disabled).toBe(true)
    expect(screen.getByText('Generando… puede tardar hasta un minuto.')).toBeTruthy()

    finish({ card: card(), reused: false })
    await waitFor(() => expect(screen.getByText('NVIDIA Corporation')).toBeTruthy())
    expect(screen.queryByRole('button', { name: 'Generar ficha' })).toBeNull()
    expect(screen.getByText('Semiconductors')).toBeTruthy()
    expect(screen.getByText('Information Technology')).toBeTruthy()

    const pe = within(screen.getByTestId('company-card-metric-pe_ttm'))
    expect(pe.getByText('PER (últimos 12 meses)')).toBeTruthy()
    expect(pe.getByText('45,2x')).toBeTruthy()
    expect(pe.getByText('TTM T2 2026')).toBeTruthy()
    expect(screen.getByTestId('company-card-metric-pe_forward').textContent).toContain('—')
    expect(screen.getByTestId('company-card-metric-market_cap_millions').textContent).toContain('—')
    expect(screen.getByTestId('company-card-metric-ebitda_millions').textContent).toContain('86.000 M USD')
    expect(screen.getByTestId('company-card-metric-roic_percent').textContent).toContain('38,5%')
    expect(screen.getByTestId('company-card-metric-revenue_growth_yoy_percent').textContent).toContain('periodo no indicado')

    expect(screen.getByTestId('company-card-stance').textContent).toBe('Escenario base: alcista')
    expect(screen.getByText('Confianza del modelo: media')).toBeTruthy()
    expect(screen.getByText('Centros de datos')).toBeTruthy()
    expect(screen.getByText('Restricciones a la exportación')).toBeTruthy()
    expect(screen.getByText('Cifras del modelo, pueden estar desactualizadas.')).toBeTruthy()
    expect(screen.getByText('Datos hasta: FY2025')).toBeTruthy()
    expect(screen.getByText(/^Generada el .* · modelo fake-model$/)).toBeTruthy()
    expect(screen.getByText('Contexto enviado: solo la identidad de la empresa.')).toBeTruthy()
    // Plain text, never links.
    expect(screen.queryAllByRole('link')).toHaveLength(0)
  })

  it('shows a stale card with an outdated note and a refresh button', async () => {
    vi.mocked(companyCardApi.get).mockResolvedValue({ card: card({ fresh: false }), available: true })
    show(true)
    await waitFor(() => expect(screen.getByText('Esta ficha está desactualizada.')).toBeTruthy())
    expect(screen.getByText('NVIDIA Corporation')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Actualizar ficha' })).toBeTruthy()
  })

  it('shows a generation error as an alert, keeps the old card and never retries on its own', async () => {
    vi.mocked(companyCardApi.get).mockResolvedValue({ card: card({ fresh: false }), available: true })
    vi.mocked(companyCardApi.generate).mockRejectedValue(new ApiError(503, 'El proveedor de IA devolvió una ficha no válida.', {}, 'invalid_provider_response'))
    show(true)
    fireEvent.click(await screen.findByRole('button', { name: 'Actualizar ficha' }))
    await waitFor(() => expect(screen.getByRole('alert').textContent).toBe('El proveedor de IA devolvió una ficha no válida.'))
    expect(screen.getByText('NVIDIA Corporation')).toBeTruthy()
    expect(companyCardApi.generate).toHaveBeenCalledTimes(1)
  })

  it('switches to the not-available state when generation reports provider_not_configured', async () => {
    vi.mocked(companyCardApi.get).mockResolvedValue({ card: null, available: true })
    vi.mocked(companyCardApi.generate).mockRejectedValue(new ApiError(503, 'Las fichas de empresa con IA aún no están disponibles.', {}, 'provider_not_configured'))
    show(true)
    fireEvent.click(await screen.findByRole('button', { name: 'Generar ficha' }))
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Generar ficha' })).toBeNull())
    expect(screen.getAllByText('Las fichas de empresa con IA aún no están disponibles.').length).toBeGreaterThan(0)
  })

  it('shows a read error with a manual retry', async () => {
    vi.mocked(companyCardApi.get).mockRejectedValueOnce(new TypeError('offline')).mockResolvedValueOnce({ card: null, available: true })
    show(true)
    await waitFor(() => expect(screen.getByRole('alert').textContent).toBe('No se pudo contactar con el servidor. Inténtalo de nuevo.'))
    expect(companyCardApi.get).toHaveBeenCalledTimes(1)
    fireEvent.click(screen.getByRole('button', { name: 'Reintentar' }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Generar ficha' })).toBeTruthy())
    expect(companyCardApi.get).toHaveBeenCalledTimes(2)
  })

  it('renders English labels and the English scenario wording', async () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    vi.mocked(companyCardApi.get).mockResolvedValue({ card: card({ locale: 'en', context_mode: 'identity+technical', context_as_of_date: '2026-10-14' }), available: true })
    show(true)
    await waitFor(() => expect(screen.getByText('Company card (AI)')).toBeTruthy())
    expect(screen.getByTestId('company-card-warning').textContent).toBe('Generated by AI · unverified · not investment advice · may contain errors and outdated figures')
    expect(await screen.findByText('Base case: bullish')).toBeTruthy()
    expect(screen.getByTestId('company-card-metric-ebitda_millions').textContent).toContain('86,000M USD')
    expect(screen.getByText('P/E (trailing 12 months)')).toBeTruthy()
    expect(screen.getByText(/Chartiko technical data from Oct 14, 2026/)).toBeTruthy()
  })
})

describe('CompanyCardPanel deep link', () => {
  const scroll = vi.fn()

  beforeEach(() => {
    scroll.mockClear()
    Element.prototype.scrollIntoView = scroll
    vi.mocked(companyCardApi.get).mockResolvedValue({ card: null, available: true })
  })

  it('scrolls to the panel once for a signed-in user opening #ai-company-card', async () => {
    const { rerender } = show(true, 'ready', DEEP_LINK)
    expect(scroll).toHaveBeenCalledTimes(1)
    expect(scroll).toHaveBeenCalledWith({ block: 'start' })
    expect(scroll.mock.contexts[0]).toBe(document.getElementById('ai-company-card'))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Generar ficha' })).toBeTruthy())
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
