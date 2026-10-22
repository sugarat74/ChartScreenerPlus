// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import { ApiError, alertEmailApi } from '../../lib/api.ts'
import AlertEmailOptIn from './AlertEmailOptIn.tsx'

vi.mock('../../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api.ts')>()
  return { ...actual, alertEmailApi: { get: vi.fn(), update: vi.fn() } }
})

const OFF = { available: true, opted_in: false, opted_in_at: null, locale: null }

function show() {
  return render(
    <I18nProvider>
      <MemoryRouter>
        <AlertEmailOptIn />
      </MemoryRouter>
    </I18nProvider>,
  )
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => { cleanup(); vi.clearAllMocks(); window.localStorage.clear() })

it('is hidden while the daily email is unavailable', async () => {
  vi.mocked(alertEmailApi.get).mockResolvedValue({ ...OFF, available: false })
  const { container } = show()
  await waitFor(() => expect(alertEmailApi.get).toHaveBeenCalled())
  expect(container.textContent).toBe('')
})

it('starts unticked and records an explicit opt-in, then an opt-out', async () => {
  vi.mocked(alertEmailApi.get).mockResolvedValue(OFF)
  vi.mocked(alertEmailApi.update)
    .mockResolvedValueOnce({ ...OFF, opted_in: true, opted_in_at: '2026-10-21T10:00:00Z', locale: 'es' })
    .mockResolvedValueOnce(OFF)
  show()

  const box = (await screen.findByRole('checkbox', { name: 'Quiero recibir el resumen diario de mis alertas por email' })) as HTMLInputElement
  expect(box.checked).toBe(false)
  expect(screen.getByRole('link', { name: 'Política de privacidad' }).getAttribute('href')).toBe('/privacidad')

  fireEvent.click(box)
  await waitFor(() => expect(box.checked).toBe(true))
  expect(alertEmailApi.update).toHaveBeenLastCalledWith(true)
  expect(screen.getByRole('status').textContent).toMatch(/Activado/)

  fireEvent.click(box)
  await waitFor(() => expect(box.checked).toBe(false))
  expect(alertEmailApi.update).toHaveBeenLastCalledWith(false)
})

it('shows the server message when the change is refused', async () => {
  vi.mocked(alertEmailApi.get).mockResolvedValue(OFF)
  vi.mocked(alertEmailApi.update).mockRejectedValue(new ApiError(409, 'El resumen diario por email todavía no está disponible.'))
  show()

  fireEvent.click(await screen.findByRole('checkbox'))
  expect((await screen.findByRole('alert')).textContent).toBe('El resumen diario por email todavía no está disponible.')
  expect((screen.getByRole('checkbox') as HTMLInputElement).checked).toBe(false)
})
