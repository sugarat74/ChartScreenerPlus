// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import { RouterProvider, createMemoryRouter, useLocation } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import LanguageSelector from '../components/LanguageSelector.tsx'
import { screenerApi } from '../lib/api.ts'
import { EMPTY_SCREENER_FILTERS } from '../lib/screenerFilters.ts'
import { I18nProvider } from './I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from './locales.ts'
import { useI18n } from './useI18n.ts'

/** Shows translated text, the URL and component-local state that must survive a switch. */
function Probe() {
  const { t, formatPrice } = useI18n()
  const location = useLocation()
  const [draft, setDraft] = useState('')
  return (
    <div>
      <p data-testid="text">{t('header.signIn')}</p>
      <p data-testid="price">{formatPrice(1234.5)}</p>
      <p data-testid="url">{`${location.pathname}${location.search}`}</p>
      <input aria-label="draft" value={draft} onChange={(event) => setDraft(event.target.value)} />
    </div>
  )
}

function renderApp(initialEntry = '/screener?rsi_min=30&sort=rsi_desc') {
  const router = createMemoryRouter(
    [{ path: '*', element: <><LanguageSelector /><Probe /></> }],
    { initialEntries: [initialEntry] },
  )
  return render(
    <I18nProvider>
      <RouterProvider router={router} />
    </I18nProvider>,
  )
}

function setBrowserLanguages(languages: string[]) {
  Object.defineProperty(window.navigator, 'languages', { value: languages, configurable: true })
}

beforeEach(() => {
  window.localStorage.clear()
  setBrowserLanguages(['en-US', 'en'])
})

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

describe('I18nProvider + LanguageSelector', () => {
  it('starts from a supported browser language and sets <html lang>', () => {
    renderApp()
    expect(screen.getByTestId('text').textContent).toBe('Sign in')
    expect(screen.getByTestId('price').textContent).toBe('1,234.50')
    expect(document.documentElement.lang).toBe('en')
    expect(screen.getByRole('button', { name: 'English' }).getAttribute('aria-pressed')).toBe('true')
  })

  it('falls back to Spanish for an unsupported browser language', () => {
    setBrowserLanguages(['fr-FR'])
    renderApp()
    expect(screen.getByTestId('text').textContent).toBe('Iniciar sesión')
    expect(document.documentElement.lang).toBe('es')
  })

  it('switches in place without losing the URL or component state, and persists the choice', () => {
    renderApp()
    fireEvent.change(screen.getByLabelText('draft'), { target: { value: 'keep me' } })

    fireEvent.click(screen.getByRole('button', { name: 'Español' }))

    expect(screen.getByTestId('text').textContent).toBe('Iniciar sesión')
    expect(screen.getByTestId('price').textContent).toBe('1234,50')
    expect(screen.getByTestId('url').textContent).toBe('/screener?rsi_min=30&sort=rsi_desc')
    expect((screen.getByLabelText('draft') as HTMLInputElement).value).toBe('keep me')
    expect(document.documentElement.lang).toBe('es')
    expect(window.localStorage.getItem(LOCALE_STORAGE_KEY)).toBe('es')
    expect(screen.getByRole('button', { name: 'Español' }).getAttribute('aria-pressed')).toBe('true')
  })

  it('restores the saved choice over the browser language on reload', () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
    renderApp()
    expect(screen.getByTestId('text').textContent).toBe('Iniciar sesión')
  })

  it('keeps working when storage throws', () => {
    vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError')
    })
    vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new DOMException('blocked', 'SecurityError')
    })
    renderApp()
    expect(screen.getByTestId('text').textContent).toBe('Sign in')
    fireEvent.click(screen.getByRole('button', { name: 'Español' }))
    expect(screen.getByTestId('text').textContent).toBe('Iniciar sesión')
  })

  it('sends the selected language to the API as Accept-Language', async () => {
    const fetchMock = vi.fn(async () =>
      new Response(
        JSON.stringify({
          universe: { slug: 'sp500', name: 'S&P 500' },
          sort: 'rvol_desc',
          candidates: [],
          meta: { limit: 50, returned: 0, total: 0 },
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    )
    vi.stubGlobal('fetch', fetchMock)

    renderApp()
    await act(async () => {
      await screenerApi.search(EMPTY_SCREENER_FILTERS)
    })
    fireEvent.click(screen.getByRole('button', { name: 'Español' }))
    await act(async () => {
      await screenerApi.search(EMPTY_SCREENER_FILTERS)
    })

    const languages = fetchMock.mock.calls.map((call) => {
      const init = (call as unknown as [string, RequestInit])[1]
      return new Headers(init.headers).get('Accept-Language')
    })
    expect(languages).toEqual(['en', 'es'])
    vi.unstubAllGlobals()
  })
})
