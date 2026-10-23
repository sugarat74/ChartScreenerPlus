// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, RouterProvider, createMemoryRouter, useLocation } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Mock } from 'vitest'
import { AuthProvider } from '../../auth/AuthContext.tsx'
import { AuthContext } from '../../auth/context.ts'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import { MESSAGES } from '../../i18n/messages/index.ts'
import { ApiError, screenerApi } from '../../lib/api.ts'
import type { SavedScreenerFilters, ScreenerFilters, ScreenerInterpretation } from '../../lib/api.ts'
import { interpretedFiltersToPatch, patchScreenerFilters } from '../../lib/screenerFilters.ts'
import ScreenerPage from '../../pages/ScreenerPage.tsx'
import ScreenerAiQuery from './ScreenerAiQuery.tsx'

const es = MESSAGES.es.aiQuery
const en = MESSAGES.en.aiQuery

const FILTERS: SavedScreenerFilters = {
  signal: [],
  rsi_min: null,
  rsi_max: 30,
  min_rvol: 1.5,
  price_above_sma200: true,
  ma_cross: null,
  pattern: [],
  pattern_status: 'any',
  sort: 'rvol_desc',
}

function interpretation(overrides: Partial<ScreenerInterpretation> = {}): ScreenerInterpretation {
  return { filters: FILTERS, unsupported: [], explanation: 'RSI ≤ 30, precio sobre SMA200, RVOL ≥ 1,5.', ...overrides }
}

const auth = {
  user: { id: 1, name: 'Test', email: 'test@example.com', role: 'user' as const },
  status: 'ready' as const,
  login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn(),
}

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

describe('interpretedFiltersToPatch', () => {
  it('maps API names to a complete SPA patch', () => {
    expect(interpretedFiltersToPatch({ ...FILTERS, signal: ['golden_cross'], pattern: ['bull_flag'], pattern_status: 'confirmed', sort: 'rsi_asc' })).toEqual({
      signals: ['golden_cross'],
      rsiMin: null,
      rsiMax: 30,
      minRvol: 1.5,
      priceAboveSma200: true,
      maCross: null,
      patterns: ['bull_flag'],
      patternStatus: 'confirmed',
      sectorTrend: null,
      sectorRsRankMax: null,
      sort: 'rsi_asc',
    })
  })

  it('applies the sector context keys (market-context-patterns)', () => {
    const patch = interpretedFiltersToPatch({ ...FILTERS, sector_trend: 'uptrend', sector_rs_rank_max: 3 })
    expect(patch.sectorTrend).toBe('uptrend')
    expect(patch.sectorRsRankMax).toBe(3)
    const after = patchScreenerFilters(new URLSearchParams('sector_trend=mixed&utm=x'), patch)
    expect(after.toString()).toBe('sector_trend=uptrend&utm=x&rsi_max=30&min_rvol=1.5&price_above_sma200=1&sector_rs_rank_max=3')
    // A denied user gets nulls from the server: both keys leave the URL.
    expect(patchScreenerFilters(after, interpretedFiltersToPatch(FILTERS)).toString()).toBe('utm=x&rsi_max=30&min_rvol=1.5&price_above_sma200=1')
  })

  it('replaces every owned key in the URL and keeps unrelated params', () => {
    const before = new URLSearchParams('signal=death_cross&rsi_min=60&ma_cross=bearish&sort=change_desc&utm=x')
    const after = patchScreenerFilters(before, interpretedFiltersToPatch(FILTERS))
    expect(after.toString()).toBe('utm=x&rsi_max=30&min_rvol=1.5&price_above_sma200=1')
  })
})

describe('ScreenerAiQuery', () => {
  let onApply: Mock<(patch: Partial<ScreenerFilters>) => void>
  let onRestore: Mock<(params: URLSearchParams) => void>

  beforeEach(() => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
    onApply = vi.fn<(patch: Partial<ScreenerFilters>) => void>()
    onRestore = vi.fn<(params: URLSearchParams) => void>()
  })

  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
    window.localStorage.clear()
  })

  function show(signedIn: boolean, status: 'loading' | 'ready' = 'ready', params = 'rsi_min=60&page=2') {
    return render(
      <AuthContext.Provider value={{ ...auth, status, user: signedIn ? auth.user : null }}>
        <I18nProvider>
          <MemoryRouter>
            <ScreenerAiQuery searchParams={new URLSearchParams(params)} onApply={onApply} onRestore={onRestore} />
          </MemoryRouter>
        </I18nProvider>
      </AuthContext.Provider>,
    )
  }

  function submit(text: string) {
    fireEvent.change(screen.getByLabelText(es.label), { target: { value: text } })
    fireEvent.click(screen.getByRole('button', { name: es.submit }))
  }

  it('keeps its space while auth is loading, with no link or input', () => {
    const interpret = vi.spyOn(screenerApi, 'interpret')
    show(true, 'loading')
    expect(screen.getByRole('heading', { name: new RegExp(es.title) }).closest('section')?.getAttribute('aria-busy')).toBe('true')
    expect(screen.queryByRole('link')).toBeNull()
    expect(screen.queryByRole('textbox')).toBeNull()
    expect(interpret).not.toHaveBeenCalled()
  })

  it('shows a Visitor a sign-in prompt instead of the input', () => {
    const interpret = vi.spyOn(screenerApi, 'interpret')
    show(false)
    expect(screen.getByRole('link', { name: es.signIn }).getAttribute('href')).toBe('/login')
    expect(screen.getByText(es.guestIntro)).toBeTruthy()
    expect(screen.queryByRole('textbox')).toBeNull()
    expect(interpret).not.toHaveBeenCalled()
  })

  it('shows examples and the privacy hint, and an example fills the input without a request', () => {
    const interpret = vi.spyOn(screenerApi, 'interpret')
    show(true)
    expect(screen.getByText(es.hint)).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: es.examples.second }))
    expect((screen.getByLabelText(es.label) as HTMLInputElement).value).toBe(es.examples.second)
    expect(interpret).not.toHaveBeenCalled()
  })

  it('applies the filters, shows the explanation and undoes to the previous URL', async () => {
    const pending = deferred<ScreenerInterpretation>()
    const interpret = vi.spyOn(screenerApi, 'interpret').mockReturnValue(pending.promise)
    show(true)

    submit('  sobrevendidas por encima de la SMA200 con volumen alto ')
    expect(interpret).toHaveBeenCalledWith('sobrevendidas por encima de la SMA200 con volumen alto', 'es')
    const busyButton = screen.getByRole('button', { name: es.submitting })
    expect(busyButton.hasAttribute('disabled')).toBe(true)
    expect((screen.getByLabelText(es.label) as HTMLInputElement).disabled).toBe(true)
    // A second submit while busy sends nothing.
    fireEvent.submit(busyButton.closest('form')!)
    expect(interpret).toHaveBeenCalledTimes(1)

    pending.resolve(interpretation({ unsupported: ['sector tecnológico', '<b>precio</b>'] }))
    const status = await screen.findByRole('status')
    expect(onApply).toHaveBeenCalledWith(interpretedFiltersToPatch(FILTERS))
    expect(within(status).getByText('RSI ≤ 30, precio sobre SMA200, RVOL ≥ 1,5.')).toBeTruthy()
    expect(within(status).getByText(es.unsupportedTitle)).toBeTruthy()
    expect(within(status).getByText('sector tecnológico')).toBeTruthy()
    // Plain text, never HTML.
    expect(within(status).getByText('<b>precio</b>')).toBeTruthy()
    expect(status.querySelector('b')).toBeNull()
    expect(screen.getByRole('button', { name: es.submit }).hasAttribute('disabled')).toBe(false)

    fireEvent.click(screen.getByRole('button', { name: es.undo }))
    expect(onRestore).toHaveBeenCalledTimes(1)
    expect(onRestore.mock.calls[0][0].toString()).toBe('rsi_min=60&page=2')
    expect(screen.queryByRole('status')).toBeNull()
  })

  it('does not send a request shorter than 3 characters', () => {
    const interpret = vi.spyOn(screenerApi, 'interpret')
    show(true)
    submit('  ab ')
    expect(screen.getByRole('alert').textContent).toBe(es.tooShort)
    expect(interpret).not.toHaveBeenCalled()
  })

  it('shows an error and changes no filters when the AI answer is invalid', async () => {
    vi.spyOn(screenerApi, 'interpret').mockRejectedValue(
      new ApiError(503, 'La IA no ha podido convertir tu petición en filtros.', {}, 'invalid_provider_response'),
    )
    show(true)
    submit('sobrevendidas')
    expect((await screen.findByRole('alert')).textContent).toBe('La IA no ha podido convertir tu petición en filtros.')
    expect(onApply).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: es.submit }).hasAttribute('disabled')).toBe(false)
  })

  it('shows the daily-limit message from the server', async () => {
    vi.spyOn(screenerApi, 'interpret').mockRejectedValue(new ApiError(429, 'Has alcanzado tu límite diario.', {}, 'user_daily_limit'))
    show(true)
    submit('sobrevendidas')
    expect((await screen.findByRole('alert')).textContent).toBe('Has alcanzado tu límite diario.')
    expect(onApply).not.toHaveBeenCalled()
  })

  it('hides the input with a notice when the feature is not configured', async () => {
    vi.spyOn(screenerApi, 'interpret').mockRejectedValue(new ApiError(503, 'No disponible.', {}, 'provider_not_configured'))
    show(true)
    submit('sobrevendidas')
    expect((await screen.findByRole('status')).textContent).toBe(es.unavailable)
    expect(screen.queryByRole('textbox')).toBeNull()
    expect(onApply).not.toHaveBeenCalled()
  })

  it('works in English and sends the active locale', async () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    const interpret = vi.spyOn(screenerApi, 'interpret').mockResolvedValue(interpretation({ explanation: 'RSI at most 30.' }))
    show(true)
    expect(screen.getByRole('button', { name: en.examples.first })).toBeTruthy()
    fireEvent.change(screen.getByLabelText(en.label), { target: { value: 'oversold stocks' } })
    fireEvent.click(screen.getByRole('button', { name: en.submit }))
    expect(await screen.findByText('RSI at most 30.')).toBeTruthy()
    expect(interpret).toHaveBeenCalledWith('oversold stocks', 'en')
    expect(screen.getByRole('button', { name: en.undo })).toBeTruthy()
  })
})

function candidate(ticker: string) {
  return {
    ticker, company: ticker, sector: 'Tech', exchange: 'NYSE', active: true, date: '2026-10-02',
    close: 10, change_percent: 1, rvol: 1, rsi14: 50, signals: [], patterns: [],
  }
}

function LocationProbe() {
  const location = useLocation()
  return <p data-testid="url">{location.search}</p>
}

describe('ScreenerPage with the AI input', () => {
  const requests: { url: string; init?: RequestInit }[] = []

  beforeEach(() => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
    requests.length = 0
    vi.stubGlobal('fetch', vi.fn(async (input: string, init?: RequestInit) => {
      requests.push({ url: input, init })
      const json = (body: unknown, status = 200) =>
        new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
      if (input === '/api/screener/interpret') {
        return json(interpretation())
      }
      if (input === '/api/screeners') {
        return json({ screeners: [] })
      }
      if (input.startsWith('/api/screener')) {
        const url = new URL(input, 'http://localhost')
        const limit = Number(url.searchParams.get('limit') ?? 50)
        const offset = Number(url.searchParams.get('offset') ?? 0)
        const total = 120
        const rows = Array.from({ length: Math.max(0, Math.min(limit, total - offset)) }, (_, i) => candidate(`T${offset + i + 1}`))
        return json({ universe: { slug: 'sp500', name: 'S&P 500' }, sort: 'rvol_desc', candidates: rows, meta: { limit, offset, returned: rows.length, total } })
      }
      if (input === '/api/user') {
        return json({ user: auth.user })
      }
      return new Response(null, { status: 204 })
    }))
  })

  afterEach(() => {
    cleanup()
    vi.unstubAllGlobals()
    window.localStorage.clear()
  })

  it('applies the interpreted filters to the URL at page 1, reloads the list and undoes', async () => {
    const router = createMemoryRouter([{ path: '/', element: <><ScreenerPage /><LocationProbe /></> }], {
      initialEntries: ['/?signal=death_cross&page=3&limit=25'],
    })
    render(<I18nProvider><AuthProvider><RouterProvider router={router} /></AuthProvider></I18nProvider>)

    fireEvent.change(await screen.findByLabelText(es.label), { target: { value: 'sobrevendidas por encima de la SMA200 con volumen alto' } })
    fireEvent.click(screen.getByRole('button', { name: es.submit }))

    await waitFor(() => expect(screen.getByTestId('url').textContent).toBe('?limit=25&rsi_max=30&min_rvol=1.5&price_above_sma200=1'))
    const post = requests.find((request) => request.url === '/api/screener/interpret')
    expect(post?.init?.method).toBe('POST')
    expect(JSON.parse(String(post?.init?.body))).toEqual({ query: 'sobrevendidas por encima de la SMA200 con volumen alto', locale: 'es' })
    await waitFor(() => expect(requests.some((request) => request.url.startsWith('/api/screener?') && request.url.includes('rsi_max=30'))).toBe(true))
    expect(await screen.findByText('RSI ≤ 30, precio sobre SMA200, RVOL ≥ 1,5.')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: es.undo }))
    await waitFor(() => expect(screen.getByTestId('url').textContent).toBe('?signal=death_cross&page=3&limit=25'))
  })
})
