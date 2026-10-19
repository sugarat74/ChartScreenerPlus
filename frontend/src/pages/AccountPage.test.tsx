// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { AuthContext } from '../auth/context.ts'
import { useAuth } from '../auth/useAuth.ts'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { resetLegalInfoCache } from '../legal/useLegalInfo.ts'
import { accountApi, ApiError, legalApi } from '../lib/api.ts'
import type { AuthUser, LegalInfo } from '../lib/api.ts'
import AccountPage from './AccountPage.tsx'

vi.mock('../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api.ts')>()
  return {
    ...actual,
    accountApi: { update: vi.fn(), changePassword: vi.fn(), export: vi.fn(), remove: vi.fn() },
    legalApi: { info: vi.fn() },
  }
})

const ana: AuthUser = { id: 1, name: 'Ana', email: 'ana@example.com', role: 'user' }

const legal: LegalInfo = {
  privacy_published: true,
  notice_published: false,
  owner: null,
  updated_at: '2026-10-19',
  retention: { sign_in_activity_days: 90, session_minutes: 120, backup_days: 14, server_log_days: 14, notification_days: 90 },
}

/** Shows the signed-in name from the context, like the header chip. */
function HeaderName() {
  const { user } = useAuth()
  return <p data-testid="header-name">{user?.name ?? 'signed out'}</p>
}

function show(initialUser: AuthUser = ana) {
  const loginRenders = vi.fn()

  function Shell({ children }: { children: ReactNode }) {
    const [user, setUser] = useState<AuthUser | null>(initialUser)
    return (
      <AuthContext.Provider
        value={{ user, status: 'ready', login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: setUser }}
      >
        <HeaderName />
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
      { path: '/privacidad', element: <p>privacy page</p> },
      { path: '/portal/cuenta', element: <AccountPage /> },
    ],
    { initialEntries: ['/portal/cuenta'] },
  )
  render(
    <I18nProvider>
      <Shell>
        <RouterProvider router={router} />
      </Shell>
    </I18nProvider>,
  )
  return { router, loginRenders }
}

beforeEach(() => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
  resetLegalInfoCache()
  vi.mocked(legalApi.info).mockResolvedValue(legal)
})
afterEach(() => {
  cleanup()
  vi.clearAllMocks()
  window.localStorage.clear()
})

it('shows the profile, password, download and danger zone sections', async () => {
  show()
  expect(screen.getByRole('heading', { level: 1, name: 'Cuenta' })).toBeTruthy()
  expect(screen.getByRole('heading', { name: 'Perfil' })).toBeTruthy()
  expect(screen.getByRole('heading', { name: 'Contraseña' })).toBeTruthy()
  expect(screen.getByRole('heading', { name: 'Descargar mis datos' })).toBeTruthy()
  expect(screen.getByRole('heading', { name: 'Eliminar cuenta' })).toBeTruthy()
  expect(screen.getByText('ana@example.com')).toBeTruthy()
  expect(screen.getByRole('link', { name: 'política de privacidad' }).getAttribute('href')).toBe('/privacidad')
  // Retention wording comes from GET /api/legal.
  expect(await screen.findByText(/hasta 14 días más .* a los 90 días/)).toBeTruthy()
})

it('keeps the delete button disabled until the word and the password are filled', () => {
  show()
  const button = screen.getByRole('button', { name: 'Eliminar mi cuenta' }) as HTMLButtonElement
  expect(button.disabled).toBe(true)

  fireEvent.change(screen.getByLabelText('Escribe ELIMINAR para confirmar'), { target: { value: 'ELIMINAR' } })
  expect(button.disabled).toBe(true)

  fireEvent.change(screen.getByLabelText('Tu contraseña'), { target: { value: 'secret-password' } })
  expect(button.disabled).toBe(false)

  fireEvent.change(screen.getByLabelText('Escribe ELIMINAR para confirmar'), { target: { value: '  ' } })
  expect(button.disabled).toBe(true)
})

it('asks for the English confirmation word in English', () => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
  show()
  expect(screen.getByLabelText('Type DELETE to confirm')).toBeTruthy()
  expect(screen.getByRole('button', { name: 'Delete my account' })).toBeTruthy()
})

it('deletes the account and lands home signed out without the sign-in page', async () => {
  vi.mocked(accountApi.remove).mockResolvedValue(undefined)
  const { router, loginRenders } = show()

  fireEvent.change(screen.getByLabelText('Escribe ELIMINAR para confirmar'), { target: { value: 'ELIMINAR' } })
  fireEvent.change(screen.getByLabelText('Tu contraseña'), { target: { value: 'secret-password' } })
  fireEvent.click(screen.getByRole('button', { name: 'Eliminar mi cuenta' }))

  await screen.findByText('home page')
  expect(accountApi.remove).toHaveBeenCalledWith('secret-password', 'ELIMINAR')
  expect(router.state.location.pathname).toBe('/')
  expect(screen.getByTestId('header-name').textContent).toBe('signed out')
  expect(loginRenders).not.toHaveBeenCalled()
})

it('shows the server errors when the deletion is rejected and keeps the account', async () => {
  vi.mocked(accountApi.remove).mockRejectedValue(
    new ApiError(422, 'Invalid', { password: ['La contraseña es incorrecta.'], confirmation: ['Escribe ELIMINAR para confirmar.'] }),
  )
  show()

  fireEvent.change(screen.getByLabelText('Escribe ELIMINAR para confirmar'), { target: { value: 'BORRAR' } })
  fireEvent.change(screen.getByLabelText('Tu contraseña'), { target: { value: 'wrong' } })
  fireEvent.click(screen.getByRole('button', { name: 'Eliminar mi cuenta' }))

  expect(await screen.findByText('La contraseña es incorrecta.')).toBeTruthy()
  expect(screen.getByText('Escribe ELIMINAR para confirmar.')).toBeTruthy()
  expect(screen.getByTestId('header-name').textContent).toBe('Ana')
  expect((screen.getByRole('button', { name: 'Eliminar mi cuenta' }) as HTMLButtonElement).disabled).toBe(false)
})

it('does not offer deletion to an Admin', () => {
  show({ ...ana, role: 'admin' })
  expect(screen.getByText('Una cuenta de administración no puede eliminarse desde aquí.')).toBeTruthy()
  expect(screen.queryByRole('button', { name: 'Eliminar mi cuenta' })).toBeNull()
})

it('saves the name and updates the signed-in user without a reload', async () => {
  vi.mocked(accountApi.update).mockResolvedValue({ ...ana, name: 'Ana María' })
  show()

  fireEvent.change(screen.getByLabelText('Nombre'), { target: { value: 'Ana María' } })
  fireEvent.click(screen.getByRole('button', { name: 'Guardar nombre' }))

  expect(await screen.findByText('Nombre actualizado.')).toBeTruthy()
  expect(accountApi.update).toHaveBeenCalledWith('Ana María')
  expect(screen.getByTestId('header-name').textContent).toBe('Ana María')
})

it('shows a field error for an invalid name', async () => {
  vi.mocked(accountApi.update).mockRejectedValue(new ApiError(422, 'Invalid', { name: ['El campo nombre es obligatorio.'] }))
  show()

  fireEvent.change(screen.getByLabelText('Nombre'), { target: { value: 'x' } })
  fireEvent.click(screen.getByRole('button', { name: 'Guardar nombre' }))

  expect(await screen.findByText('El campo nombre es obligatorio.')).toBeTruthy()
  expect(screen.getByTestId('header-name').textContent).toBe('Ana')
})

it('changes the password and clears the form', async () => {
  vi.mocked(accountApi.changePassword).mockResolvedValue(undefined)
  show()

  fireEvent.change(screen.getByLabelText('Contraseña actual'), { target: { value: 'old-password' } })
  fireEvent.change(screen.getByLabelText('Nueva contraseña'), { target: { value: 'brand-new-password' } })
  fireEvent.change(screen.getByLabelText('Repetir nueva contraseña'), { target: { value: 'brand-new-password' } })
  fireEvent.click(screen.getByRole('button', { name: 'Cambiar contraseña' }))

  expect(await screen.findByText('Contraseña cambiada. Las demás sesiones se han cerrado.')).toBeTruthy()
  expect(accountApi.changePassword).toHaveBeenCalledWith({
    current_password: 'old-password',
    password: 'brand-new-password',
    password_confirmation: 'brand-new-password',
  })
  expect((screen.getByLabelText('Contraseña actual') as HTMLInputElement).value).toBe('')
})

it('shows the error for a wrong current password', async () => {
  vi.mocked(accountApi.changePassword).mockRejectedValue(
    new ApiError(422, 'Invalid', { current_password: ['La contraseña es incorrecta.'] }),
  )
  show()

  fireEvent.change(screen.getByLabelText('Contraseña actual'), { target: { value: 'wrong' } })
  fireEvent.change(screen.getByLabelText('Nueva contraseña'), { target: { value: 'brand-new-password' } })
  fireEvent.change(screen.getByLabelText('Repetir nueva contraseña'), { target: { value: 'brand-new-password' } })
  fireEvent.click(screen.getByRole('button', { name: 'Cambiar contraseña' }))

  expect(await screen.findByText('La contraseña es incorrecta.')).toBeTruthy()
})

it('downloads the export as a file', async () => {
  const blob = new Blob(['{}'], { type: 'application/json' })
  vi.mocked(accountApi.export).mockResolvedValue({ blob, filename: 'chartiko-account-2026-10-19.json' })
  const createObjectURL = vi.fn(() => 'blob:export')
  const revokeObjectURL = vi.fn()
  Object.assign(URL, { createObjectURL, revokeObjectURL })
  const clicked: string[] = []
  const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
    clicked.push(this.download)
  })
  show()

  fireEvent.click(screen.getByRole('button', { name: 'Descargar JSON' }))

  expect(await screen.findByText('Descarga iniciada.')).toBeTruthy()
  expect(createObjectURL).toHaveBeenCalledWith(blob)
  expect(clicked).toEqual(['chartiko-account-2026-10-19.json'])
  click.mockRestore()
})

it('sends an expired session back through sign-in', async () => {
  vi.mocked(accountApi.export).mockRejectedValue(new ApiError(401, 'Unauthenticated.'))
  const { router } = show()

  fireEvent.click(screen.getByRole('button', { name: 'Descargar JSON' }))

  await waitFor(() => expect(router.state.location.pathname).toBe('/login'))
})
