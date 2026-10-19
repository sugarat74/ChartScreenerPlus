// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { AuthContext } from '../auth/context.ts'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { ApiError, notificationsApi } from '../lib/api.ts'
import type { AppNotification, NotificationsPage as Payload } from '../lib/api.ts'
import { UnreadNotificationsProvider } from '../notifications/UnreadNotificationsProvider.tsx'
import NotificationsPage from './NotificationsPage.tsx'

vi.mock('../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api.ts')>()
  return { ...actual, notificationsApi: { list: vi.fn(), markRead: vi.fn() } }
})

const user = { id: 1, name: 'Test', email: 'test@example.com', role: 'user' as const }

const screenerNotification: AppNotification = {
  id: '11111111-1111-4111-8111-111111111111', kind: 'screener_new_candidates', as_of: '2026-10-14',
  saved_screener: { id: 7, name: 'Breakouts' },
  items: [{ ticker: 'NVDA', name: 'NVIDIA', reason: 'new_candidate' }], more: 3,
  read_at: null, created_at: '2026-10-14T22:00:00+00:00',
}
const watchlistNotification: AppNotification = {
  id: '22222222-2222-4222-8222-222222222222', kind: 'watchlist_signal', as_of: '2026-10-13',
  saved_screener: null,
  items: [{ ticker: 'AAPL', name: 'Apple', reason: 'golden_cross' }], more: 0,
  read_at: null, created_at: '2026-10-13T22:00:00+00:00',
}

function payload(data: AppNotification[], unread: number, meta: Partial<Payload['meta']> = {}): Payload {
  return { data, meta: { current_page: 1, last_page: 1, per_page: 20, total: data.length, ...meta }, unread_count: unread }
}

function show(signedIn = true) {
  return render(
    <AuthContext.Provider value={{ user: signedIn ? user : null, status: 'ready', login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn() }}>
      <I18nProvider>
        <MemoryRouter initialEntries={['/portal/notificaciones']}>
          <UnreadNotificationsProvider>
            <Routes>
              <Route path="/portal/notificaciones" element={<NotificationsPage />} />
              <Route path="/instruments/:ticker" element={<p>chart page</p>} />
              <Route path="/login" element={<p>login page</p>} />
            </Routes>
          </UnreadNotificationsProvider>
        </MemoryRouter>
      </I18nProvider>
    </AuthContext.Provider>,
  )
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => { cleanup(); vi.clearAllMocks(); window.localStorage.clear() })

it('lists notifications newest first with date, kind, screener name and ticker links', async () => {
  vi.mocked(notificationsApi.list).mockResolvedValue(payload([screenerNotification, watchlistNotification], 2))
  show()
  const cards = await screen.findAllByRole('heading', { level: 2 })
  expect(cards.map((card) => card.textContent)).toEqual([
    'Nuevos candidatos en «Breakouts»',
    'Nuevas señales en tu Watchlist',
  ])
  expect(screen.getByText('y 3 más')).toBeTruthy()
  expect(screen.getByText('2 sin leer')).toBeTruthy()
  const link = screen.getByRole('link', { name: 'Abrir el gráfico de AAPL: Cruce dorado' })
  expect(link.getAttribute('href')).toBe('/instruments/AAPL')
  expect(screen.getByRole('link', { name: 'Abrir el gráfico de NVDA: Nuevo candidato' })).toBeTruthy()
  fireEvent.click(link)
  expect(await screen.findByText('chart page')).toBeTruthy()
})

it('marks all as read and clears the count', async () => {
  vi.mocked(notificationsApi.list).mockResolvedValue(payload([screenerNotification, watchlistNotification], 2))
  vi.mocked(notificationsApi.markRead).mockResolvedValue(undefined)
  show()
  const button = await screen.findByRole('button', { name: 'Marcar todas como leídas' })
  fireEvent.click(button)
  await waitFor(() => expect(screen.getByText('Todo leído')).toBeTruthy())
  expect(notificationsApi.markRead).toHaveBeenCalledWith({ all: true })
  expect(screen.queryByText('Nueva')).toBeNull()
  expect(screen.getAllByText('Leída')).toHaveLength(2)
  expect((screen.getByRole('button', { name: 'Marcar todas como leídas' }) as HTMLButtonElement).disabled).toBe(true)
})

it('marks one notification as read', async () => {
  vi.mocked(notificationsApi.list).mockResolvedValue(payload([screenerNotification, watchlistNotification], 2))
  vi.mocked(notificationsApi.markRead).mockResolvedValue(undefined)
  show()
  const [first] = await screen.findAllByRole('button', { name: 'Marcar como leída' })
  fireEvent.click(first)
  await waitFor(() => expect(screen.getByText('1 sin leer')).toBeTruthy())
  expect(notificationsApi.markRead).toHaveBeenCalledWith({ ids: [screenerNotification.id] })
})

it('explains when alerts are evaluated in the empty state, without EOD wording', async () => {
  vi.mocked(notificationsApi.list).mockResolvedValue(payload([], 0))
  show()
  const title = await screen.findByText('Todavía no tienes notificaciones')
  const card = title.parentElement as HTMLElement
  expect(within(card).getByText(/después de la actualización diaria de datos/)).toBeTruthy()
  expect(document.body.textContent).not.toMatch(/EOD|end-of-day|fin de día/i)
})

it('shows the retry pattern on a network error', async () => {
  vi.mocked(notificationsApi.list)
    .mockRejectedValueOnce(new TypeError('offline'))
    .mockRejectedValueOnce(new TypeError('offline'))
    .mockResolvedValue(payload([watchlistNotification], 1))
  show()
  expect(await screen.findByText('No se pudieron cargar tus notificaciones')).toBeTruthy()
  fireEvent.click(screen.getByRole('button', { name: 'Reintentar' }))
  expect(await screen.findByText('Nuevas señales en tu Watchlist')).toBeTruthy()
})

it('paginates through the API pages', async () => {
  vi.mocked(notificationsApi.list).mockImplementation(async (page = 1) =>
    page === 2
      ? payload([watchlistNotification], 1, { current_page: 2, last_page: 2, total: 21 })
      : payload([screenerNotification], 1, { current_page: 1, last_page: 2, total: 21 }),
  )
  show()
  expect(await screen.findByText('Página 1 de 2')).toBeTruthy()
  fireEvent.click(screen.getByRole('button', { name: 'Siguiente' }))
  expect(await screen.findByText('Página 2 de 2')).toBeTruthy()
  expect(notificationsApi.list).toHaveBeenCalledWith(2)
})

it('renders English labels', async () => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
  vi.mocked(notificationsApi.list).mockResolvedValue(payload([screenerNotification], 1))
  show()
  expect(await screen.findByText('New candidates in “Breakouts”')).toBeTruthy()
  expect(screen.getByRole('button', { name: 'Mark all as read' })).toBeTruthy()
  expect(screen.getByRole('link', { name: 'Open the NVDA chart: New candidate' })).toBeTruthy()
})

const patternNotification: AppNotification = {
  id: '33333333-3333-4333-8333-333333333333', kind: 'watchlist_pattern', as_of: '2026-10-14',
  saved_screener: null,
  items: [
    { ticker: 'NVDA', name: 'NVIDIA', reason: 'pattern:double_bottom:confirmed', status: 'confirmed', breakout_level: 123.45 },
    { ticker: 'AAPL', name: 'Apple', reason: 'pattern:bull_flag:appears', status: 'confirmed', breakout_level: 1234.5 },
    { ticker: 'MSFT', name: 'Microsoft', reason: 'pattern:triangle:appears', status: 'forming', breakout_level: 10 },
  ],
  more: 0, read_at: null, created_at: '2026-10-14T22:00:00+00:00',
}

it('renders a pattern notification with its title, pattern, event and breakout level linking to the chart', async () => {
  vi.mocked(notificationsApi.list).mockResolvedValue(payload([patternNotification], 1))
  show()
  expect(await screen.findByRole('heading', { level: 2, name: 'Patrones en tu Watchlist' })).toBeTruthy()
  const link = screen.getByRole('link', { name: 'Abrir el gráfico de NVDA: Doble suelo · confirmado · Ruptura 123,45' })
  expect(link.getAttribute('href')).toBe('/instruments/NVDA')
  expect(within(link).getByText('Doble suelo · confirmado · Ruptura 123,45')).toBeTruthy()
  // An `appears` item already confirmed says so; unknown types fall back to the raw value.
  expect(screen.getByRole('link', { name: 'Abrir el gráfico de AAPL: Bandera alcista · aparece · ya confirmado · Ruptura 1234,50' })).toBeTruthy()
  expect(screen.getByRole('link', { name: 'Abrir el gráfico de MSFT: triangle · aparece · Ruptura 10,00' })).toBeTruthy()
  expect(document.body.textContent).not.toMatch(/EOD|end-of-day|fin de día|\$/i)
  fireEvent.click(link)
  expect(await screen.findByText('chart page')).toBeTruthy()
})

it('renders a pattern notification in English', async () => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
  vi.mocked(notificationsApi.list).mockResolvedValue(payload([patternNotification], 1))
  show()
  expect(await screen.findByText('Patterns on your Watchlist')).toBeTruthy()
  expect(screen.getByRole('link', { name: 'Open the NVDA chart: Double bottom · confirmed · Breakout 123.45' })).toBeTruthy()
  expect(screen.getByRole('link', { name: 'Open the AAPL chart: Bull flag · appears · already confirmed · Breakout 1,234.50' })).toBeTruthy()
})

it('sends a Visitor to sign in without requesting notifications', async () => {
  show(false)
  expect(await screen.findByText('login page')).toBeTruthy()
  expect(notificationsApi.list).not.toHaveBeenCalled()
})

it('sends an expired session to sign in', async () => {
  vi.mocked(notificationsApi.list).mockRejectedValue(new ApiError(401, 'Unauthenticated.'))
  show()
  expect(await screen.findByText('login page')).toBeTruthy()
})
