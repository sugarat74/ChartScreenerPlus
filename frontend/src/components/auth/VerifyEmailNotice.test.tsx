// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from '../../auth/context.ts'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import { ApiError, authApi, emailVerificationApi } from '../../lib/api.ts'
import type { AuthUser } from '../../lib/api.ts'
import VerifyEmailNotice from './VerifyEmailNotice.tsx'

vi.mock('../../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api.ts')>()
  return {
    ...actual,
    authApi: { ...actual.authApi, currentUser: vi.fn() },
    emailVerificationApi: { verify: vi.fn(), resend: vi.fn() },
  }
})

const PENDING: AuthUser = { id: 7, name: 'Ana', email: 'ana@example.test', role: 'user', email_verification_pending: true }
const VERIFIED: AuthUser = { ...PENDING, email_verification_pending: false }

function show(locale: 'es' | 'en' = 'es') {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, locale)
  const logout = vi.fn().mockResolvedValue(undefined)

  function Shell({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<AuthUser | null>(PENDING)
    return (
      <AuthContext.Provider value={{ user, status: 'ready', login: vi.fn(), register: vi.fn(), logout, setCurrentUser: setUser }}>
        <p data-testid="pending">{String(user?.email_verification_pending)}</p>
        {children}
      </AuthContext.Provider>
    )
  }

  render(
    <I18nProvider>
      <MemoryRouter>
        <Shell>
          <VerifyEmailNotice />
        </Shell>
      </MemoryRouter>
    </I18nProvider>,
  )

  return { logout }
}

const resendButton = () => screen.getByRole('button', { name: /Reenviar el email|Enviando…/ })

beforeEach(() => {
  vi.mocked(authApi.currentUser).mockResolvedValue(PENDING)
})
afterEach(() => {
  cleanup()
  vi.useRealTimers()
  vi.clearAllMocks()
  window.localStorage.clear()
})

describe('VerifyEmailNotice', () => {
  it('shows the address, the hint, the account link and sign-out in Spanish', async () => {
    const { logout } = show('es')

    expect(screen.getByRole('heading', { name: 'Confirma tu correo' })).toBeTruthy()
    expect(screen.getByText('ana@example.test').tagName).toBe('STRONG')
    expect(document.body.textContent).toMatch(/Te hemos enviado un email a ana@example\.test con un enlace/)
    expect(document.body.textContent).toMatch(/revisa la carpeta de spam/)
    expect(screen.getByRole('link', { name: 'Gestionar o eliminar la cuenta' }).getAttribute('href')).toBe('/portal/cuenta')

    fireEvent.click(screen.getByRole('button', { name: 'Salir' }))
    await waitFor(() => expect(logout).toHaveBeenCalledTimes(1))
  })

  it('speaks English', () => {
    show('en')

    expect(screen.getByRole('heading', { name: 'Confirm your email' })).toBeTruthy()
    expect(document.body.textContent).toMatch(/We sent an email to ana@example\.test/)
    expect(screen.getByRole('button', { name: 'Resend the email' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'I have confirmed it' })).toBeTruthy()
    expect(screen.getByRole('link', { name: 'Manage or delete the account' })).toBeTruthy()
  })

  it('disables resend while sending, then confirms and counts down 60 s', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    let resolve: (status: 'sent') => void = () => {}
    vi.mocked(emailVerificationApi.resend).mockReturnValue(new Promise((done) => { resolve = done }))
    show()

    fireEvent.click(resendButton())
    expect(resendButton().textContent).toBe('Enviando…')
    expect((resendButton() as HTMLButtonElement).disabled).toBe(true)

    await act(async () => resolve('sent'))
    const status = await screen.findByText(/Te hemos enviado un nuevo email\./)
    expect(status.getAttribute('role')).toBe('status')
    expect(status.textContent).toMatch(/Podrás pedir otro en 60 s/)
    expect((resendButton() as HTMLButtonElement).disabled).toBe(true)

    await act(async () => { vi.advanceTimersByTime(1000) })
    expect(status.textContent).toMatch(/Podrás pedir otro en 59 s/)

    for (let second = 0; second < 59; second++) {
      await act(async () => { vi.advanceTimersByTime(1000) })
    }
    expect(status.textContent).not.toMatch(/Podrás pedir otro/)
    expect((resendButton() as HTMLButtonElement).disabled).toBe(false)
    expect(emailVerificationApi.resend).toHaveBeenCalledTimes(1)
  })

  it('shows the localized 429 from the server', async () => {
    vi.mocked(emailVerificationApi.resend).mockRejectedValue(
      new ApiError(429, 'Demasiados intentos. Espera un momento e inténtalo de nuevo.'),
    )
    show()

    fireEvent.click(resendButton())
    expect((await screen.findByRole('alert')).textContent).toBe('Demasiados intentos. Espera un momento e inténtalo de nuevo.')
    expect((resendButton() as HTMLButtonElement).disabled).toBe(false)
  })

  it('shows the network message when the server cannot be reached', async () => {
    vi.mocked(emailVerificationApi.resend).mockRejectedValue(new TypeError('Failed to fetch'))
    show()

    fireEvent.click(resendButton())
    expect((await screen.findByRole('alert')).textContent).toBe('No se pudo contactar al servidor. Inténtalo de nuevo.')
  })

  it('refreshes the session when the server says verification is off (409)', async () => {
    vi.mocked(emailVerificationApi.resend).mockRejectedValue(
      new ApiError(409, 'La confirmación por email no está disponible.', {}, 'email_verification.unavailable'),
    )
    vi.mocked(authApi.currentUser).mockResolvedValue(VERIFIED)
    show()

    fireEvent.click(resendButton())
    await waitFor(() => expect(screen.getByTestId('pending').textContent).toBe('false'))
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('"Ya lo he confirmado" re-reads the session and says when it is still pending', async () => {
    show()

    fireEvent.click(screen.getByRole('button', { name: 'Ya lo he confirmado' }))
    expect(await screen.findByText(/Tu email todavía no está confirmado/)).toBeTruthy()
    expect(authApi.currentUser).toHaveBeenCalledTimes(1)

    vi.mocked(authApi.currentUser).mockResolvedValue(VERIFIED)
    fireEvent.click(screen.getByRole('button', { name: 'Ya lo he confirmado' }))
    await waitFor(() => expect(screen.getByTestId('pending').textContent).toBe('false'))
    expect(screen.queryByText(/Tu email todavía no está confirmado/)).toBeNull()
  })
})
