// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from '../auth/context.ts'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { resetLegalInfoCache } from '../legal/useLegalInfo.ts'
import { legalApi, notificationsApi } from '../lib/api.ts'
import type { AuthUser, LegalInfo } from '../lib/api.ts'
import AppLayout from './AppLayout.tsx'

vi.mock('../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api.ts')>()
  return { ...actual, legalApi: { info: vi.fn() }, notificationsApi: { list: vi.fn(), markRead: vi.fn() } }
})

const LEGAL: LegalInfo = {
  privacy_published: true,
  notice_published: false,
  owner: null,
  updated_at: '2026-10-23',
  retention: { sign_in_activity_days: 90, session_minutes: 120, backup_days: 14, server_log_days: 14, notification_days: 90 },
}

const PENDING: AuthUser = { id: 7, name: 'Ana', email: 'ana@example.test', role: 'user', email_verification_pending: true }
const VERIFIED: AuthUser = { ...PENDING, email_verification_pending: false }

function show(path: string, user: AuthUser | null) {
  const router = createMemoryRouter(
    [
      {
        path: '/',
        element: <AppLayout />,
        children: [
          { index: true, element: <p>screener page</p> },
          { path: 'portal', element: <p>portal page</p> },
          { path: 'portal/cuenta', element: <p>account page</p> },
          { path: 'privacidad', element: <p>privacy page</p> },
          { path: 'aviso-legal', element: <p>notice page</p> },
          { path: 'cookies', element: <p>cookies page</p> },
          { path: 'verificar-correo/:userId/:hash', element: <p>verify page</p> },
        ],
      },
    ],
    { initialEntries: [path] },
  )
  render(
    <I18nProvider>
      <AuthContext.Provider value={{ user, status: 'ready', login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn() }}>
        <RouterProvider router={router} />
      </AuthContext.Provider>
    </I18nProvider>,
  )
}

const notice = () => screen.queryByRole('heading', { name: 'Confirma tu correo' })
const bell = () => screen.queryByRole('link', { name: /Notificaciones/ })

beforeEach(() => {
  resetLegalInfoCache()
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
  vi.mocked(legalApi.info).mockResolvedValue(LEGAL)
  vi.mocked(notificationsApi.list).mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 }, unread_count: 0 })
})
afterEach(() => {
  cleanup()
  vi.clearAllMocks()
  window.localStorage.clear()
})

describe('AppLayout email verification gate', () => {
  it.each(['/', '/portal'])('a pending account on %s sees the notice instead of the page', (path) => {
    show(path, PENDING)

    expect(notice()).toBeTruthy()
    expect(screen.queryByText(/screener page|portal page/)).toBeNull()
  })

  it.each([
    ['/privacidad', 'privacy page'],
    ['/aviso-legal', 'notice page'],
    ['/cookies', 'cookies page'],
    ['/portal/cuenta', 'account page'],
    ['/verificar-correo/1/x', 'verify page'],
  ])('a pending account can still open %s', (path, text) => {
    show(path, PENDING)

    expect(screen.getByText(text)).toBeTruthy()
    expect(notice()).toBeNull()
  })

  it('hides the bell and never asks for notifications while pending', async () => {
    show('/', PENDING)

    // The name chip and sign-out stay.
    expect(screen.getByRole('link', { name: /Ana/ })).toBeTruthy()
    expect(screen.getAllByRole('button', { name: 'Salir' }).length).toBeGreaterThan(0)
    expect(bell()).toBeNull()
    await waitFor(() => expect(legalApi.info).toHaveBeenCalled())
    expect(notificationsApi.list).not.toHaveBeenCalled()
  })

  it('a verified account sees the page, the bell and its unread count', async () => {
    show('/portal', VERIFIED)

    expect(screen.getByText('portal page')).toBeTruthy()
    expect(notice()).toBeNull()
    expect(bell()).toBeTruthy()
    await waitFor(() => expect(notificationsApi.list).toHaveBeenCalled())
  })

  it('a Visitor sees the page', () => {
    show('/', null)

    expect(screen.getByText('screener page')).toBeTruthy()
    expect(notice()).toBeNull()
  })
})
