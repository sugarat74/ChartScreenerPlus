// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { AuthContext } from '../auth/context.ts'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { alertEmailApi, alertsApi, savedScreenersApi, watchlistApi } from '../lib/api.ts'
import PortalPage from './PortalPage.tsx'

vi.mock('../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api.ts')>()
  return {
    ...actual,
    alertsApi: { list: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn() },
    alertEmailApi: { get: vi.fn(), update: vi.fn() },
    savedScreenersApi: { list: vi.fn(), create: vi.fn(), remove: vi.fn() },
    watchlistApi: { list: vi.fn(), add: vi.fn(), remove: vi.fn() },
  }
})

const user = { id: 1, name: 'Test', email: 'test@example.com', role: 'user' as const }

function Where() {
  const location = useLocation()
  return <output data-testid="where">{location.pathname + location.search}</output>
}

function show(entry = '/portal', signedIn = true) {
  render(
    <AuthContext.Provider
      value={{
        user: signedIn ? user : null,
        status: 'ready',
        login: vi.fn(),
        register: vi.fn(),
        logout: vi.fn(),
        setCurrentUser: vi.fn(),
      }}
    >
      <I18nProvider>
        <MemoryRouter initialEntries={[entry]}>
          <Where />
          <Routes>
            <Route path="/portal" element={<PortalPage />} />
            <Route path="/login" element={<p>login page</p>} />
          </Routes>
        </MemoryRouter>
      </I18nProvider>
    </AuthContext.Provider>,
  )
}

beforeEach(() => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
  vi.mocked(alertsApi.list).mockResolvedValue([])
  vi.mocked(alertEmailApi.get).mockResolvedValue({ available: false } as never)
  vi.mocked(savedScreenersApi.list).mockResolvedValue([])
  vi.mocked(watchlistApi.list).mockResolvedValue([])
})
afterEach(() => {
  cleanup()
  vi.clearAllMocks()
  window.localStorage.clear()
})

const selected = (name: string) =>
  screen.getByRole('tab', { name }).getAttribute('aria-selected')

it('defaults to Saved Screeners with one visible panel', async () => {
  show()
  expect(selected('Screeners guardados')).toBe('true')
  expect(selected('Watchlist')).toBe('false')
  const panel = screen.getByRole('tabpanel', { name: 'Screeners guardados' })
  expect(panel.hasAttribute('hidden')).toBe(false)
  expect(screen.getAllByRole('tabpanel')).toHaveLength(1)
  expect(screen.getByRole('tab', { name: 'Screeners guardados' }).getAttribute('aria-controls')).toBe(panel.id)
  await waitFor(() => expect(watchlistApi.list).toHaveBeenCalled())
})

it('switches tabs, syncs ?tab= with replace and keeps panels mounted', async () => {
  show()
  fireEvent.click(screen.getByRole('tab', { name: 'Watchlist' }))
  expect(selected('Watchlist')).toBe('true')
  expect(screen.getByTestId('where').textContent).toBe('/portal?tab=watchlist')
  expect(screen.getByRole('tabpanel', { name: 'Watchlist' })).toBeTruthy()
  fireEvent.click(screen.getByRole('tab', { name: 'Screeners guardados' }))
  expect(screen.getByTestId('where').textContent).toBe('/portal?tab=screeners')
  expect(watchlistApi.list).toHaveBeenCalledTimes(1)
  expect(savedScreenersApi.list).toHaveBeenCalledTimes(1)
})

it('opens the tab named in the URL and falls back on invalid values', () => {
  show('/portal?tab=watchlist')
  expect(selected('Watchlist')).toBe('true')
  cleanup()
  show('/portal?tab=bogus')
  expect(selected('Screeners guardados')).toBe('true')
})

it('supports roving tabindex and Arrow/Home/End keys', () => {
  show()
  const first = screen.getByRole('tab', { name: 'Screeners guardados' })
  const second = screen.getByRole('tab', { name: 'Watchlist' })
  expect(first.getAttribute('tabindex')).toBe('0')
  expect(second.getAttribute('tabindex')).toBe('-1')
  fireEvent.keyDown(first, { key: 'ArrowRight' })
  expect(selected('Watchlist')).toBe('true')
  expect(document.activeElement).toBe(second)
  expect(second.getAttribute('tabindex')).toBe('0')
  fireEvent.keyDown(second, { key: 'ArrowRight' })
  expect(selected('Screeners guardados')).toBe('true')
  fireEvent.keyDown(first, { key: 'ArrowLeft' })
  expect(selected('Watchlist')).toBe('true')
  fireEvent.keyDown(second, { key: 'Home' })
  expect(selected('Screeners guardados')).toBe('true')
  fireEvent.keyDown(first, { key: 'End' })
  expect(selected('Watchlist')).toBe('true')
})

it('keeps the email opt-in above the tabs for every tab', async () => {
  vi.mocked(alertEmailApi.get).mockResolvedValue({ available: true, opt_in: false } as never)
  show('/portal?tab=watchlist')
  const tablist = screen.getByRole('tablist')
  await waitFor(() => expect(alertEmailApi.get).toHaveBeenCalled())
  const checkbox = await screen.findByRole('checkbox', { name: /correo|email/i })
  expect(checkbox.compareDocumentPosition(tablist) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
})

it('redirects a guest to sign-in without rendering tabs', () => {
  show('/portal?tab=watchlist', false)
  expect(screen.queryByRole('tablist')).toBeNull()
  expect(screen.getByText('login page')).toBeTruthy()
  expect(watchlistApi.list).not.toHaveBeenCalled()
})
