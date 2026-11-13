// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from '../../auth/context.ts'
import type { AuthContextValue } from '../../auth/context.ts'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import { watchlistApi } from '../../lib/api.ts'
import type { AuthUser } from '../../lib/api.ts'
import WatchlistButton from './WatchlistButton.tsx'

vi.mock('../../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api.ts')>()
  return { ...actual, watchlistApi: { list: vi.fn(), add: vi.fn(), remove: vi.fn() } }
})

const member: AuthUser = { id: 1, name: 'Test', email: 'test@example.com', role: 'user' }
const base: AuthContextValue = {
  user: null,
  status: 'ready',
  login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn(),
}

function tree(auth: AuthContextValue) {
  return (
    <AuthContext.Provider value={auth}>
      <I18nProvider>
        <MemoryRouter initialEntries={['/instruments/NVDA']}>
          <WatchlistButton ticker="NVDA" />
        </MemoryRouter>
      </I18nProvider>
    </AuthContext.Provider>
  )
}

beforeEach(() => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
  vi.mocked(watchlistApi.list).mockResolvedValue([])
})
afterEach(() => { cleanup(); vi.clearAllMocks(); window.localStorage.clear() })

describe('WatchlistButton registered-only states', () => {
  it('a Visitor gets the same button dimmed, not a link; a click opens the sign-in popover and sends nothing', async () => {
    render(tree(base))
    const button = screen.getByRole('button', { name: 'Seguir' })
    expect(button.getAttribute('aria-disabled')).toBe('true')
    expect(button.hasAttribute('disabled')).toBe(false)
    expect(screen.queryByRole('link')).toBeNull()
    fireEvent.click(button)
    const link = await screen.findByRole('link', { name: 'Iniciar sesión' })
    expect(link.getAttribute('href')).toBe('/login?next=%2Finstruments%2FNVDA')
    expect(watchlistApi.list).not.toHaveBeenCalled()
    expect(watchlistApi.add).not.toHaveBeenCalled()
  })

  it('while auth loads it is dimmed with no reason and no request', () => {
    render(tree({ ...base, status: 'loading' }))
    const button = screen.getByRole('button', { name: 'Seguir' })
    expect(button.getAttribute('aria-disabled')).toBe('true')
    fireEvent.click(button)
    expect(screen.queryByRole('link')).toBeNull()
    expect(watchlistApi.list).not.toHaveBeenCalled()
  })

  it('a pending account is dimmed with the confirm-email reason and no request', () => {
    render(tree({ ...base, user: { ...member, email_verification_pending: true } }))
    const button = screen.getByRole('button', { name: 'Seguir' })
    expect(button.getAttribute('aria-disabled')).toBe('true')
    fireEvent.click(button)
    expect(screen.getByRole('status').textContent).toContain('Confirma tu email para usarlo')
    expect(watchlistApi.list).not.toHaveBeenCalled()
  })

  it('a signed-in user gets the live toggle: membership is read, then follow adds', async () => {
    vi.mocked(watchlistApi.add).mockResolvedValue(undefined as never)
    render(tree({ ...base, user: member }))
    const button = await screen.findByRole('button', { name: 'Seguir' })
    await waitFor(() => expect(button.hasAttribute('disabled')).toBe(false))
    expect(button.getAttribute('aria-disabled')).toBeNull()
    expect(watchlistApi.list).toHaveBeenCalledTimes(1)
    fireEvent.click(button)
    await waitFor(() => expect(watchlistApi.add).toHaveBeenCalledWith('NVDA'))
    expect(await screen.findByRole('button', { name: 'Siguiendo' })).toBeTruthy()
  })

  it('flips at login and back at logout in place', async () => {
    const { rerender } = render(tree(base))
    expect(watchlistApi.list).not.toHaveBeenCalled()
    rerender(tree({ ...base, user: member }))
    await waitFor(() => expect(watchlistApi.list).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Seguir' }).hasAttribute('disabled')).toBe(false))
    rerender(tree(base))
    expect(screen.getByRole('button', { name: 'Seguir' }).getAttribute('aria-disabled')).toBe('true')
  })
})
