// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { authApi } from '../lib/api.ts'
import type { AuthUser } from '../lib/api.ts'
import LoginPage from '../pages/LoginPage.tsx'
import RegisterPage from '../pages/RegisterPage.tsx'
import { AuthContext } from './context.ts'
import RequireAuth from './RequireAuth.tsx'

const member: AuthUser = { id: 1, name: 'Test', email: 'test@example.com', role: 'user' }

function show(path: string, initialUser: AuthUser | null = null) {
  function Shell({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<AuthUser | null>(initialUser)
    return (
      <AuthContext.Provider
        value={{
          user,
          status: 'ready',
          login: async () => setUser(member),
          register: async () => setUser(member),
          logout: vi.fn(),
          setCurrentUser: setUser,
        }}
      >
        {children}
      </AuthContext.Provider>
    )
  }
  const router = createMemoryRouter(
    [
      { path: '/', element: <p>home</p> },
      { path: '/instruments/:ticker', element: <p>chart</p> },
      { path: '/login', element: <LoginPage /> },
      { path: '/register', element: <RegisterPage /> },
      { path: '/portal', element: <RequireAuth><p>portal</p></RequireAuth> },
    ],
    { initialEntries: [path] },
  )
  render(<I18nProvider><Shell><RouterProvider router={router} /></Shell></I18nProvider>)
  return router
}

async function signIn() {
  fireEvent.change(await screen.findByLabelText('Email'), { target: { value: 'a@b.co' } })
  fireEvent.change(screen.getByLabelText('Contraseña'), { target: { value: 'secret-pass' } })
  fireEvent.click(screen.getByRole('button', { name: 'Entrar' }))
}

beforeEach(() => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
  vi.spyOn(authApi, 'currentUser').mockResolvedValue(member)
})
afterEach(() => { cleanup(); window.localStorage.clear(); vi.restoreAllMocks() })

describe('next return path', () => {
  it('login returns to the encoded path, query and hash', async () => {
    const router = show('/login?next=/instruments/NVDA%23ai-company-card')

    await signIn()

    await waitFor(() => expect(router.state.location.pathname).toBe('/instruments/NVDA'))
    expect(router.state.location.hash).toBe('#ai-company-card')
  })

  it.each(['//evil.com', 'https://x.example', '/login', 'javascript:alert(1)', '/\\evil.com'])('login falls back to / for next=%s', async (next) => {
    const router = show(`/login?next=${encodeURIComponent(next)}`)

    await signIn()

    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
    expect(router.state.location.search).toBe('')
  })

  it('without next, login goes home as before', async () => {
    const router = show('/login')

    await signIn()

    await waitFor(() => expect(router.state.location.pathname).toBe('/'))
  })

  it('the login and register pages keep next in their cross links', async () => {
    const router = show('/login?next=%2Fportal')
    fireEvent.click(await screen.findByRole('link', { name: 'Crear cuenta' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/register'))
    expect(router.state.location.search).toBe('?next=%2Fportal')
    fireEvent.click(screen.getByRole('link', { name: 'Iniciar sesión' }))
    await waitFor(() => expect(router.state.location.pathname).toBe('/login'))
    expect(router.state.location.search).toBe('?next=%2Fportal')
  })

  it('register returns to next', async () => {
    const router = show('/register?next=%2Fportal')
    fireEvent.change(await screen.findByLabelText('Nombre'), { target: { value: 'N' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'a@b.co' } })
    fireEvent.change(screen.getByLabelText('Contraseña'), { target: { value: 'secret-pass-1' } })
    fireEvent.change(screen.getByLabelText('Repetir contraseña'), { target: { value: 'secret-pass-1' } })
    fireEvent.click(screen.getByRole('button', { name: 'Crear cuenta' }))

    await waitFor(() => expect(router.state.location.pathname).toBe('/portal'))
  })

  it('an already signed-in user opening /login?next= goes to next', async () => {
    const router = show('/login?next=%2Fportal', member)

    await waitFor(() => expect(router.state.location.pathname).toBe('/portal'))
  })

  it('a Visitor on /portal is sent to /login?next=/portal, and after sign-in the Portal opens', async () => {
    const router = show('/portal')

    await screen.findByLabelText('Email')
    expect(router.state.location.pathname).toBe('/login')
    expect(router.state.location.search).toBe('?next=%2Fportal')

    await signIn()

    await screen.findByText('portal')
    expect(router.state.location.pathname).toBe('/portal')
  })
})
