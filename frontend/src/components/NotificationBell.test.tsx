// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { AuthContext } from '../auth/context.ts'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { notificationsApi } from '../lib/api.ts'
import type { NotificationsPage } from '../lib/api.ts'
import { UnreadNotificationsProvider } from '../notifications/UnreadNotificationsProvider.tsx'
import AppHeader from './AppHeader.tsx'

vi.mock('../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api.ts')>()
  return { ...actual, notificationsApi: { list: vi.fn(), markRead: vi.fn() } }
})

const user = { id: 1, name: 'Test', email: 'test@example.com', role: 'user' as const }

function page(unread: number): NotificationsPage {
  return { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 }, unread_count: unread }
}

function show(signedIn: boolean) {
  return render(
    <AuthContext.Provider value={{ user: signedIn ? user : null, status: 'ready', login: vi.fn(), register: vi.fn(), logout: vi.fn() }}>
      <I18nProvider>
        <MemoryRouter initialEntries={['/']}>
          <UnreadNotificationsProvider>
            <AppHeader />
          </UnreadNotificationsProvider>
        </MemoryRouter>
      </I18nProvider>
    </AuthContext.Provider>,
  )
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => { cleanup(); vi.clearAllMocks(); window.localStorage.clear() })

it('shows the unread count with an accessible name linking to the inbox', async () => {
  vi.mocked(notificationsApi.list).mockResolvedValue(page(3))
  show(true)
  const bell = await screen.findByRole('link', { name: 'Notificaciones, 3 sin leer' })
  expect(bell.getAttribute('href')).toBe('/portal/notificaciones')
  expect(screen.getByTestId('notification-badge').textContent).toBe('3')
})

it('caps the badge at 9+ and keeps the exact count in the accessible name', async () => {
  vi.mocked(notificationsApi.list).mockResolvedValue(page(12))
  show(true)
  await screen.findByRole('link', { name: 'Notificaciones, 12 sin leer' })
  expect(screen.getByTestId('notification-badge').textContent).toBe('9+')
})

it('hides the badge when nothing is unread', async () => {
  vi.mocked(notificationsApi.list).mockResolvedValue(page(0))
  show(true)
  await screen.findByRole('link', { name: 'Notificaciones, ninguna sin leer' })
  expect(screen.queryByTestId('notification-badge')).toBeNull()
})

it('uses English labels', async () => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
  vi.mocked(notificationsApi.list).mockResolvedValue(page(2))
  show(true)
  expect(await screen.findByRole('link', { name: 'Notifications, 2 unread' })).toBeTruthy()
})

it('refreshes the count on navigation', async () => {
  vi.mocked(notificationsApi.list).mockResolvedValueOnce(page(1)).mockResolvedValue(page(4))
  show(true)
  await screen.findByRole('link', { name: 'Notificaciones, 1 sin leer' })
  fireEvent.click(screen.getByRole('link', { name: 'Portal' }))
  await screen.findByRole('link', { name: 'Notificaciones, 4 sin leer' })
  expect(notificationsApi.list).toHaveBeenCalledTimes(2)
})

it('shows no bell and makes no request for a Visitor', async () => {
  show(false)
  await waitFor(() => expect(screen.getByRole('link', { name: 'Iniciar sesión' })).toBeTruthy())
  expect(screen.queryByRole('link', { name: /notificaciones/i })).toBeNull()
  expect(notificationsApi.list).not.toHaveBeenCalled()
})
