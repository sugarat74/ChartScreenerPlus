// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { StrictMode, useState } from 'react'
import type { ReactNode } from 'react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from '../auth/context.ts'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { ApiError, authApi, emailVerificationApi } from '../lib/api.ts'
import type { AuthUser } from '../lib/api.ts'
import VerifyEmailPage from './VerifyEmailPage.tsx'

vi.mock('../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api.ts')>()
  return {
    ...actual,
    authApi: { ...actual.authApi, currentUser: vi.fn() },
    emailVerificationApi: { verify: vi.fn(), resend: vi.fn() },
  }
})

const HASH = 'a'.repeat(40)
const LINK = `/verificar-correo/7/${HASH}?expires=1790000000&signature=abc123`
const PENDING: AuthUser = { id: 7, name: 'Ana', email: 'ana@example.test', role: 'user', email_verification_pending: true }
const VERIFIED: AuthUser = { ...PENDING, email_verification_pending: false }

function show(path: string, initialUser: AuthUser | null) {
  function Shell({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<AuthUser | null>(initialUser)
    return (
      <AuthContext.Provider value={{ user, status: 'ready', login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: setUser }}>
        <p data-testid="pending">{String(user?.email_verification_pending)}</p>
        {children}
      </AuthContext.Provider>
    )
  }

  const router = createMemoryRouter(
    [
      { path: '/', element: <p>home page</p> },
      { path: '/login', element: <p>login page</p> },
      { path: '/verificar-correo/:userId/:hash', element: <VerifyEmailPage /> },
    ],
    { initialEntries: [path] },
  )
  render(
    <StrictMode>
      <I18nProvider>
        <Shell>
          <RouterProvider router={router} />
        </Shell>
      </I18nProvider>
    </StrictMode>,
  )
  return router
}

const heading = () => screen.findByRole('heading', { level: 2 })

beforeEach(() => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
  vi.mocked(authApi.currentUser).mockResolvedValue(null)
})
afterEach(() => {
  cleanup()
  vi.clearAllMocks()
  window.localStorage.clear()
})

describe('VerifyEmailPage', () => {
  it('is invalid without expires or signature and makes no request', async () => {
    show(`/verificar-correo/7/${HASH}?expires=1790000000`, null)

    expect((await heading()).textContent).toBe('Enlace no válido')
    expect(screen.getByRole('link', { name: 'Iniciar sesión' }).getAttribute('href')).toBe('/login')
    expect(emailVerificationApi.verify).not.toHaveBeenCalled()
  })

  it('posts the link once under StrictMode and confirms it for a Visitor with a sign-in link', async () => {
    vi.mocked(emailVerificationApi.verify).mockResolvedValue('verified')
    show(LINK, null)

    await waitFor(() => expect(screen.getByRole('heading', { level: 2 }).textContent).toBe('Correo confirmado'))
    expect(emailVerificationApi.verify).toHaveBeenCalledTimes(1)
    expect(emailVerificationApi.verify).toHaveBeenCalledWith('7', HASH, '1790000000', 'abc123')
    expect(document.body.textContent).toMatch(/Tu cuenta ya puede usar todas las funciones/)
    expect(screen.getByRole('link', { name: 'Iniciar sesión' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Continuar' })).toBeNull()
  })

  it('refreshes a signed-in session and continues home', async () => {
    vi.mocked(emailVerificationApi.verify).mockResolvedValue('verified')
    vi.mocked(authApi.currentUser).mockResolvedValue(VERIFIED)
    const router = show(LINK, PENDING)

    await waitFor(() => expect(screen.getByTestId('pending').textContent).toBe('false'))
    fireEvent.click(await screen.findByRole('button', { name: 'Continuar' }))
    await screen.findByText('home page')
    expect(router.state.location.pathname).toBe('/')
  })

  it('says when the address was already confirmed', async () => {
    vi.mocked(emailVerificationApi.verify).mockResolvedValue('already_verified')
    show(LINK, null)

    await waitFor(() => expect(screen.getByRole('heading', { level: 2 }).textContent).toBe('Tu correo ya estaba confirmado'))
  })

  it('maps an expired link and offers a resend to a pending session', async () => {
    vi.mocked(emailVerificationApi.verify).mockRejectedValue(
      new ApiError(403, 'El enlace de confirmación ha caducado. Pide otro.', {}, 'email_verification.link_expired'),
    )
    show(LINK, PENDING)

    await waitFor(() => expect(screen.getByRole('heading', { level: 2 }).textContent).toBe('Enlace caducado'))
    expect(document.body.textContent).toMatch(/solo dura 24 horas/)
    expect(screen.getByRole('button', { name: 'Reenviar el email' })).toBeTruthy()
  })

  it('maps a tampered link to invalid with a sign-in link for a Visitor', async () => {
    vi.mocked(emailVerificationApi.verify).mockRejectedValue(
      new ApiError(403, 'El enlace de confirmación no es válido.', {}, 'email_verification.invalid_link'),
    )
    show(LINK, null)

    await waitFor(() => expect(screen.getByRole('heading', { level: 2 }).textContent).toBe('Enlace no válido'))
    expect(screen.getByRole('link', { name: 'Iniciar sesión' })).toBeTruthy()
    expect(screen.queryByRole('button', { name: 'Reenviar el email' })).toBeNull()
  })

  it('offers a retry after a network error', async () => {
    vi.mocked(emailVerificationApi.verify).mockRejectedValueOnce(new TypeError('Failed to fetch')).mockResolvedValueOnce('verified')
    show(LINK, null)

    expect(await screen.findByText(/No se pudo confirmar tu email ahora mismo/)).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Reintentar' }))

    await waitFor(() => expect(screen.getByRole('heading', { level: 2 }).textContent).toBe('Correo confirmado'))
    expect(emailVerificationApi.verify).toHaveBeenCalledTimes(2)
  })

  it('speaks English', async () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    vi.mocked(emailVerificationApi.verify).mockResolvedValue('verified')
    show(LINK, null)

    await waitFor(() => expect(screen.getByRole('heading', { level: 2 }).textContent).toBe('Email confirmed'))
    expect(screen.getByRole('link', { name: 'Sign in' })).toBeTruthy()
  })
})
