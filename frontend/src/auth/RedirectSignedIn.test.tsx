// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { authApi } from '../lib/api.ts'
import type { AuthUser } from '../lib/api.ts'
import LoginPage from '../pages/LoginPage.tsx'
import { AuthContext } from './context.ts'

const member: AuthUser = { id: 1, name: 'Test', email: 'test@example.com', role: 'user' }

/** The context still holds a user (what the SPA keeps after a session expires). */
function show() {
  function Shell({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<AuthUser | null>(member)
    return (
      <AuthContext.Provider value={{ user, status: 'ready', login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: setUser }}>
        {children}
      </AuthContext.Provider>
    )
  }

  const router = createMemoryRouter(
    [
      { path: '/', element: <p>home page</p> },
      { path: '/login', element: <LoginPage /> },
    ],
    { initialEntries: ['/login'] },
  )
  render(
    <I18nProvider>
      <Shell>
        <RouterProvider router={router} />
      </Shell>
    </I18nProvider>,
  )

  return router
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => {
  cleanup()
  window.localStorage.clear()
  vi.restoreAllMocks()
})

it('an expired session reaching sign-in clears the user and shows the form instead of bouncing home', async () => {
  vi.spyOn(authApi, 'currentUser').mockResolvedValue(null)
  const router = show()

  await screen.findByRole('heading', { name: 'Iniciar sesión' })
  expect(router.state.location.pathname).toBe('/login')
  expect(screen.queryByText('home page')).toBeNull()
})

it('a live session on the sign-in page still goes home', async () => {
  vi.spyOn(authApi, 'currentUser').mockResolvedValue(member)
  const router = show()

  await screen.findByText('home page')
  expect(router.state.location.pathname).toBe('/')
})
