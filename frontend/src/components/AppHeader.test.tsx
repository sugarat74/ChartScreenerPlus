// @vitest-environment jsdom
import { act, cleanup, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from '../auth/context.ts'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { resetLegalInfoCache } from '../legal/useLegalInfo.ts'
import { legalApi, notificationsApi } from '../lib/api.ts'
import type { AuthUser, LegalInfo } from '../lib/api.ts'
import { UnreadNotificationsProvider } from '../notifications/UnreadNotificationsProvider.tsx'
import AppHeader from './AppHeader.tsx'

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

// The capability comes from the server's `view-market-context` ability (market-context-patterns).
const USER: AuthUser = { id: 1, name: 'Ana', email: 'ana@example.test', role: 'user', capabilities: { market_context: false } }
const ALLOWED_USER: AuthUser = { ...USER, capabilities: { market_context: true } }
const ADMIN: AuthUser = { ...USER, role: 'admin', capabilities: { market_context: true } }

function show(user: AuthUser | null) {
  return render(
    <AuthContext.Provider value={{ user, status: 'ready', login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn() }}>
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

/** Let the resolved legal info reach the header. */
const settle = () => act(() => new Promise((resolve) => setTimeout(resolve, 0)))

const marketLink = () => screen.queryByRole('link', { name: 'Mercado' })
const disabledMarket = () => screen.getByText('Mercado')

beforeEach(() => {
  resetLegalInfoCache()
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
  vi.mocked(notificationsApi.list).mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 }, unread_count: 0 })
})
afterEach(() => {
  cleanup()
  vi.clearAllMocks()
  window.localStorage.clear()
})

describe('AppHeader Market link (market-context-ui, market-context-patterns)', () => {
  it('is visible but disabled while access loads', () => {
    vi.mocked(legalApi.info).mockReturnValue(new Promise(() => {}))
    show(null)
    expect(screen.getByRole('link', { name: 'Screener' })).toBeTruthy()
    expect(marketLink()).toBeNull()
    expect(disabledMarket().getAttribute('aria-disabled')).toBe('true')
  })

  it('is disabled for Visitors and Registered Users while the page is not public', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: false })
    show(null)
    await waitFor(() => expect(legalApi.info).toHaveBeenCalled())
    await settle()
    expect(marketLink()).toBeNull()
    expect(disabledMarket().getAttribute('aria-disabled')).toBe('true')
    expect(disabledMarket().getAttribute('title')).toBe('Inicia sesión para acceder a Mercado')
    cleanup()

    show(USER)
    await settle()
    expect(marketLink()).toBeNull()
    expect(disabledMarket().getAttribute('aria-disabled')).toBe('true')
    expect(disabledMarket().getAttribute('title')).toBe('Mercado aún no está disponible')
  })

  it('stays disabled for a Visitor after the page is available to registered users', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    show(null)
    await settle()
    expect(marketLink()).toBeNull()
    expect(disabledMarket().getAttribute('aria-disabled')).toBe('true')
    const tabs = screen.getByRole('navigation', { name: 'Secciones principales' }).querySelectorAll('a')
    expect([...tabs].map((tab) => tab.textContent)).toEqual(['Screener', 'Cómo funciona', 'Portal'])
    expect([...screen.getByRole('navigation', { name: 'Secciones principales' }).children].map((tab) => tab.textContent)).toEqual(['Screener', 'Mercado', 'Cómo funciona', 'Portal'])
  })

  it('is shown to Admins as a preview while the page is not public', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: false })
    show(ADMIN)
    expect(await screen.findByRole('link', { name: 'Mercado' })).toBeTruthy()
  })

  it('follows the capability for a signed-in user, never the role', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    show(ALLOWED_USER)
    expect(await screen.findByRole('link', { name: 'Mercado' })).toBeTruthy()
    cleanup()

    // An Admin payload without the capability (an older deployment) is hidden.
    show({ ...ADMIN, capabilities: undefined })
    await settle()
    expect(marketLink()).toBeNull()
    cleanup()

    show(USER)
    await settle()
    expect(marketLink()).toBeNull()
  })
})
