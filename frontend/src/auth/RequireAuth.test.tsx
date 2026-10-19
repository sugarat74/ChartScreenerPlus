// @vitest-environment jsdom
import { act, cleanup, render, screen } from '@testing-library/react'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import type { AuthUser } from '../lib/api.ts'
import AdminLayout from '../pages/admin/AdminLayout.tsx'
import { AuthContext } from './context.ts'
import RequireAuth from './RequireAuth.tsx'

const admin: AuthUser = { id: 1, name: 'Test', email: 'test@example.com', role: 'admin' }

/**
 * Auth state that can really go from a user to `null` (what `logout()` does),
 * around a router that starts at `path`.
 */
function show(path: string, initialUser: AuthUser | null) {
  let signOut: () => void = () => {}
  const loginRenders = vi.fn()

  function Shell({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<AuthUser | null>(initialUser)
    signOut = () => setUser(null)
    return (
      <AuthContext.Provider value={{ user, status: 'ready', login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn() }}>
        {children}
      </AuthContext.Provider>
    )
  }
  function Login() {
    loginRenders()
    return <p>login page</p>
  }

  const router = createMemoryRouter(
    [
      { path: '/', element: <p>home page</p> },
      { path: '/login', element: <Login /> },
      { path: '/portal', element: <RequireAuth><p>portal page</p></RequireAuth> },
      { path: '/admin', element: <AdminLayout />, children: [{ path: 'users', element: <p>admin users</p> }] },
    ],
    { initialEntries: [path] },
  )
  render(
    <I18nProvider>
      <Shell>
        <RouterProvider router={router} />
      </Shell>
    </I18nProvider>,
  )

  return { router, loginRenders, signOut: () => act(() => signOut()) }
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => { cleanup(); window.localStorage.clear() })

it('signing out on a guarded page goes home without showing the sign-in page', async () => {
  const { router, loginRenders, signOut } = show('/portal', admin)
  expect(screen.getByText('portal page')).toBeTruthy()

  signOut()

  await screen.findByText('home page')
  expect(router.state.location.pathname).toBe('/')
  expect(loginRenders).not.toHaveBeenCalled()
})

it('signing out on an Admin page goes home instead of the sign-in notice', async () => {
  const { router, loginRenders, signOut } = show('/admin/users', admin)
  expect(screen.getByText('admin users')).toBeTruthy()

  signOut()

  await screen.findByText('home page')
  expect(router.state.location.pathname).toBe('/')
  expect(loginRenders).not.toHaveBeenCalled()
})

it('a Visitor opening a guarded page is still sent to sign-in', async () => {
  const { router } = show('/portal', null)

  await screen.findByText('login page')
  expect(router.state.location.pathname).toBe('/login')
})

it('a Visitor opening Admin still sees the sign-in notice', () => {
  show('/admin/users', null)

  expect(screen.queryByText('home page')).toBeNull()
  expect(screen.queryByText('admin users')).toBeNull()
  expect(screen.getByRole('link', { name: /inicia sesión|iniciar sesión|entrar/i })).toBeTruthy()
})
