// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { ApiError, passwordResetApi } from '../lib/api.ts'
import ForgotPasswordPage from './ForgotPasswordPage.tsx'
import ResetPasswordPage from './ResetPasswordPage.tsx'

function show(path: string) {
  const router = createMemoryRouter([
    { path: '/olvide-contrasena', element: <ForgotPasswordPage /> },
    { path: '/restablecer-contrasena', element: <ResetPasswordPage /> },
    { path: '/login', element: <p>Sign-in page</p> },
  ], { initialEntries: [path] })
  render(<I18nProvider><RouterProvider router={router} /></I18nProvider>)
  return router
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => { cleanup(); window.localStorage.clear(); vi.restoreAllMocks() })

it('requests a link and shows the generic server response', async () => {
  const request = vi.spyOn(passwordResetApi, 'request').mockResolvedValue('Si existe una cuenta, recibirás un enlace.')
  show('/olvide-contrasena')
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'ana@example.com' } })
  fireEvent.click(screen.getByRole('button', { name: 'Enviar enlace' }))
  expect(await screen.findByRole('status')).toHaveProperty('textContent', 'Si existe una cuenta, recibirás un enlace.')
  expect(request).toHaveBeenCalledWith('ana@example.com')
})

it('resets a password and clears the token from the URL', async () => {
  const reset = vi.spyOn(passwordResetApi, 'reset').mockResolvedValue('Contraseña actualizada.')
  const router = show('/restablecer-contrasena?email=ana%40example.com&token=secret')
  fireEvent.change(screen.getByLabelText('Contraseña'), { target: { value: 'NewPassword123!' } })
  fireEvent.change(screen.getByLabelText('Repetir contraseña'), { target: { value: 'NewPassword123!' } })
  fireEvent.click(screen.getByRole('button', { name: 'Guardar contraseña' }))
  expect(await screen.findByRole('status')).toHaveProperty('textContent', expect.stringContaining('Contraseña actualizada.'))
  expect(reset).toHaveBeenCalledWith('ana@example.com', 'secret', 'NewPassword123!', 'NewPassword123!')
  await waitFor(() => expect(router.state.location.search).toBe(''))
})

it('rejects a missing token and reports an expired link in English', async () => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
  show('/restablecer-contrasena?email=ana%40example.com')
  expect(screen.getByRole('alert').textContent).toMatch(/link is invalid/)
  cleanup()
  const reset = vi.spyOn(passwordResetApi, 'reset').mockRejectedValue(new ApiError(422, 'The link has expired.'))
  show('/restablecer-contrasena?email=ana%40example.com&token=expired')
  fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'NewPassword123!' } })
  fireEvent.change(screen.getByLabelText('Repeat password'), { target: { value: 'NewPassword123!' } })
  fireEvent.click(screen.getByRole('button', { name: 'Save password' }))
  expect((await screen.findByRole('alert')).textContent).toBe('The link has expired.')
  expect(reset).toHaveBeenCalledOnce()
})
