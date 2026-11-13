// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { useEffect, useState } from 'react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from '../auth/context.ts'
import type { AuthContextValue } from '../auth/context.ts'
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
// The server denies Market Context to every pending account.
const PENDING: AuthUser = { ...USER, email_verification_pending: true, capabilities: { market_context: false } }

let setAuth: (patch: Partial<AuthContextValue>) => void = () => {}

function show(user: AuthUser | null, status: AuthContextValue['status'] = 'ready', path = '/') {
  function Shell() {
    const [auth, setState] = useState<AuthContextValue>({ user, status, login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn() })
    useEffect(() => {
      setAuth = (patch) => setState((current) => ({ ...current, ...patch }))
    }, [])
    return (
      <AuthContext.Provider value={auth}>
        <I18nProvider>
          <MemoryRouter initialEntries={[path]}>
            <UnreadNotificationsProvider>
              <AppHeader />
            </UnreadNotificationsProvider>
          </MemoryRouter>
        </I18nProvider>
      </AuthContext.Provider>
    )
  }
  return render(<Shell />)
}

/** Let the resolved legal info reach the header. */
const settle = () => act(() => new Promise((resolve) => setTimeout(resolve, 0)))

const nav = () => screen.getByRole('navigation', { name: 'Secciones principales' })
const tab = (name: string) => within(nav()).getByRole('link', { name })
const reasonOf = (element: HTMLElement) => document.getElementById(element.getAttribute('aria-describedby') ?? '')?.textContent
const isDisabled = (element: HTMLElement) => element.getAttribute('aria-disabled') === 'true' && !element.hasAttribute('href')

let fetchMock: ReturnType<typeof vi.fn>

beforeEach(() => {
  resetLegalInfoCache()
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
  vi.mocked(notificationsApi.list).mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 }, unread_count: 0 })
  // Every API call this header may make goes through mocked modules; a raw fetch would be a stray request.
  fetchMock = vi.fn(() => new Promise(() => {}))
  vi.stubGlobal('fetch', fetchMock)
})
afterEach(() => {
  cleanup()
  vi.clearAllMocks()
  vi.unstubAllGlobals()
  window.localStorage.clear()
})

describe('AppHeader Market tab (market-context-ui, guest-disabled-nav)', () => {
  it('is dimmed with no reason while access loads', () => {
    vi.mocked(legalApi.info).mockReturnValue(new Promise(() => {}))
    show(null)
    expect(tab('Screener').getAttribute('href')).toBe('/')
    const market = tab('Mercado')
    expect(isDisabled(market)).toBe(true)
    expect(market.getAttribute('aria-busy')).toBe('true')
    fireEvent.click(market)
    expect(screen.queryByRole('status')).toBeNull()
  })

  it('offers sign-in returning to /mercado for a Visitor while the page is public', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    show(null, 'ready', '/instruments/NVDA')
    await settle()

    const market = tab('Mercado')
    expect(isDisabled(market)).toBe(true)
    expect(market.tabIndex).toBe(0)
    expect(market.getAttribute('title')).toBe('Inicia sesión para acceder a Mercado')
    expect(reasonOf(market)).toBe('Disponible al iniciar sesión')

    fireEvent.keyDown(market, { key: 'Enter' })
    const popover = screen.getByRole('status')
    expect(within(popover).getByRole('link', { name: 'Iniciar sesión' }).getAttribute('href')).toBe('/login?next=%2Fmercado')
    expect(within(popover).getByRole('link', { name: 'Crear cuenta' }).getAttribute('href')).toBe('/register?next=%2Fmercado')

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.queryByRole('status')).toBeNull()
    expect(document.activeElement).toBe(market)
  })

  it('says "not available yet" with no sign-in link while the page is not public', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: false })
    show(null)
    await settle()
    const market = tab('Mercado')
    expect(isDisabled(market)).toBe(true)
    expect(market.tabIndex).toBe(0)
    expect(market.getAttribute('title')).toBe('Mercado aún no está disponible')
    expect(reasonOf(market)).toBe('Aún no disponible')

    market.focus()
    fireEvent.click(market)
    const bubble = screen.getByRole('status')
    expect(bubble.textContent).toBe('Aún no disponible')
    expect(within(bubble).queryByRole('link')).toBeNull()
    fireEvent.keyDown(market, { key: 'Escape' })
    expect(screen.queryByRole('status')).toBeNull()
    expect(document.activeElement).toBe(market)
    fireEvent.keyDown(market, { key: ' ' })
    expect(screen.getByRole('status')).toBeTruthy()
  })

  it('says "not available yet" to a signed-in user without access, flag on or off', async () => {
    for (const flag of [true, false]) {
      resetLegalInfoCache()
      vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: flag })
      show(USER)
      await settle()
      expect(reasonOf(tab('Mercado'))).toBe('Aún no disponible')
      fireEvent.click(tab('Mercado'))
      expect(within(screen.getByRole('status')).queryByRole('link')).toBeNull()
      cleanup()
    }
  })

  it('asks a pending account to confirm its email when that unlocks it, else "not available yet"', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    show(PENDING)
    await settle()
    expect(reasonOf(tab('Mercado'))).toBe('Confirma tu email para usarlo')
    fireEvent.click(tab('Mercado'))
    expect(within(screen.getByRole('status')).getByRole('link', { name: 'Ir a mi cuenta' }).getAttribute('href')).toBe('/portal/cuenta')
    cleanup()

    resetLegalInfoCache()
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: false })
    show(PENDING)
    await settle()
    expect(reasonOf(tab('Mercado'))).toBe('Aún no disponible')
  })

  it('is a link for Admins as a preview and follows the capability, never the role', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: false })
    show(ADMIN)
    await waitFor(() => expect(tab('Mercado').getAttribute('href')).toBe('/mercado'))
    expect(within(nav()).getByRole('link', { name: 'Admin' }).getAttribute('href')).toBe('/admin')
    cleanup()

    resetLegalInfoCache()
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    show(ALLOWED_USER)
    await waitFor(() => expect(tab('Mercado').getAttribute('href')).toBe('/mercado'))
    cleanup()

    // An Admin payload without the capability (an older deployment) is not linked.
    show({ ...ADMIN, capabilities: undefined })
    await settle()
    expect(isDisabled(tab('Mercado'))).toBe(true)
  })
})

describe('AppHeader Portal tab (guest-disabled-nav)', () => {
  it('is a focusable dimmed entry for a Visitor with sign-in returning to /portal', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    show(null, 'ready', '/como-funciona')
    await settle()

    const portal = tab('Portal')
    expect(isDisabled(portal)).toBe(true)
    expect(portal.hasAttribute('disabled')).toBe(false)
    expect(portal.tabIndex).toBe(0)
    expect(portal.className).toContain('opacity-60')
    expect(portal.getAttribute('title')).toBe('Disponible al iniciar sesión')
    expect(reasonOf(portal)).toBe('Disponible al iniciar sesión')

    fireEvent.click(portal)
    const popover = screen.getByRole('status')
    expect(within(popover).getByRole('link', { name: 'Iniciar sesión' }).getAttribute('href')).toBe('/login?next=%2Fportal')
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.queryByRole('status')).toBeNull()
    expect(document.activeElement).toBe(portal)

    // Tabs in order; the current page keeps its active state; Admin, bell and account stay hidden.
    expect(within(nav()).getAllByRole('link').map((link) => link.textContent)).toEqual(['Screener', 'Mercado', 'Cómo funciona', 'Portal'])
    expect(tab('Cómo funciona').getAttribute('aria-current')).toBe('page')
    expect(screen.queryByRole('link', { name: /Mi cuenta/ })).toBeNull()
    expect(screen.queryByRole('button', { name: /notificaciones/i })).toBeNull()
    expect(screen.queryByRole('link', { name: /notificaciones/i })).toBeNull()
  })

  it('is dimmed with no reason while the session loads', () => {
    vi.mocked(legalApi.info).mockReturnValue(new Promise(() => {}))
    show(null, 'loading')
    const portal = tab('Portal')
    expect(isDisabled(portal)).toBe(true)
    expect(portal.getAttribute('aria-busy')).toBe('true')
    fireEvent.click(portal)
    expect(screen.queryByRole('status')).toBeNull()
  })

  it('asks a pending account to confirm its email, linking to the Account page', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    show(PENDING)
    await settle()
    const portal = tab('Portal')
    expect(isDisabled(portal)).toBe(true)
    expect(reasonOf(portal)).toBe('Confirma tu email para usarlo')
    fireEvent.keyDown(portal, { key: ' ' })
    expect(within(screen.getByRole('status')).getByRole('link', { name: 'Ir a mi cuenta' }).getAttribute('href')).toBe('/portal/cuenta')
  })

  it('is a real link for a verified user, active on nested Portal routes', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    show(USER, 'ready', '/portal/cuenta')
    await settle()
    const portal = tab('Portal')
    expect(portal.getAttribute('href')).toBe('/portal')
    expect(portal.hasAttribute('aria-disabled')).toBe(false)
    expect(portal.getAttribute('aria-current')).toBe('page')
  })
})

describe('AppHeader tabs flip in place on login and logout (guest-disabled-nav)', () => {
  it('turns into links on login and back into dimmed entries on logout, without remount', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    show(null)
    await settle()
    const navBefore = nav()
    expect(isDisabled(tab('Portal'))).toBe(true)
    expect(isDisabled(tab('Mercado'))).toBe(true)

    act(() => setAuth({ user: ALLOWED_USER }))
    expect(nav()).toBe(navBefore)
    expect(tab('Portal').getAttribute('href')).toBe('/portal')
    expect(tab('Mercado').getAttribute('href')).toBe('/mercado')
    expect(tab('Portal').hasAttribute('aria-disabled')).toBe(false)

    act(() => setAuth({ user: null }))
    expect(nav()).toBe(navBefore)
    expect(isDisabled(tab('Portal'))).toBe(true)
    expect(isDisabled(tab('Mercado'))).toBe(true)
    expect(reasonOf(tab('Mercado'))).toBe('Disponible al iniciar sesión')
  })
})

describe('AppHeader makes no registered-only request for a Visitor (guest-disabled-nav)', () => {
  it('only reads the public legal info; opening the disabled tabs fetches nothing', async () => {
    vi.mocked(legalApi.info).mockResolvedValue({ ...LEGAL, market_context: true })
    show(null)
    await settle()
    fireEvent.click(tab('Portal'))
    fireEvent.keyDown(document, { key: 'Escape' })
    fireEvent.click(tab('Mercado'))
    await settle()

    expect(notificationsApi.list).not.toHaveBeenCalled()
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
