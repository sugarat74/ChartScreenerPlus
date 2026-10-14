// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import { ApiError, alertsApi } from '../../lib/api.ts'
import type { Alert } from '../../lib/api.ts'
import WatchlistAlertForm from './WatchlistAlertForm.tsx'

vi.mock('../../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api.ts')>()
  return { ...actual, alertsApi: { list: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn() } }
})

const existing: Alert = {
  id: 5, kind: 'watchlist_signal', saved_screener: null, signal_types: ['golden_cross', 'rsi_oversold'],
  active: true, last_evaluated_as_of: null, created_at: null,
}

function show(alert: Alert | undefined, handlers = { onSaved: vi.fn(), onRemoved: vi.fn() }) {
  render(
    <I18nProvider>
      <MemoryRouter>
        <WatchlistAlertForm alert={alert} disabled={false} {...handlers} />
      </MemoryRouter>
    </I18nProvider>,
  )
  return handlers
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => { cleanup(); vi.clearAllMocks(); window.localStorage.clear() })

it('disables save until a Signal type is selected, then creates one alert with those types', async () => {
  vi.mocked(alertsApi.create).mockImplementation(async (data) => ({ ...existing, id: 9, signal_types: 'signal_types' in data ? data.signal_types : null }))
  const { onSaved } = show(undefined)
  const save = screen.getByRole('button', { name: 'Guardar alerta' }) as HTMLButtonElement
  expect(save.disabled).toBe(true)
  expect(screen.getByText('Selecciona al menos un tipo de señal para guardar.')).toBeTruthy()
  expect(screen.getAllByRole('checkbox')).toHaveLength(9)

  fireEvent.click(screen.getByRole('checkbox', { name: 'Ruptura pivote + RVOL' }))
  fireEvent.click(screen.getByRole('checkbox', { name: 'Cruce dorado' }))
  expect(save.disabled).toBe(false)
  fireEvent.click(save)

  await waitFor(() => expect(screen.getByRole('status').textContent).toBe('Alerta guardada.'))
  expect(alertsApi.create).toHaveBeenCalledWith({ kind: 'watchlist_signal', signal_types: ['golden_cross', 'pivot_breakout_rvol'] })
  expect(onSaved).toHaveBeenCalledWith(expect.objectContaining({ id: 9 }))
})

it('prefills an existing alert, saves changes, pauses and deletes it', async () => {
  vi.mocked(alertsApi.update).mockImplementation(async (id, data) => ({ ...existing, id, ...data }))
  vi.mocked(alertsApi.remove).mockResolvedValue(undefined)
  const { onSaved, onRemoved } = show(existing)
  expect((screen.getByRole('checkbox', { name: 'Cruce dorado' }) as HTMLInputElement).checked).toBe(true)
  expect(screen.getByText('Activa')).toBeTruthy()
  const save = screen.getByRole('button', { name: 'Guardar cambios' }) as HTMLButtonElement
  expect(save.disabled).toBe(true)

  fireEvent.click(screen.getByRole('checkbox', { name: 'MACD alcista' }))
  fireEvent.click(save)
  await waitFor(() => expect(alertsApi.update).toHaveBeenCalledWith(5, { signal_types: ['golden_cross', 'rsi_oversold', 'macd_bullish_cross'] }))

  fireEvent.click(screen.getByRole('button', { name: 'Pausar' }))
  await waitFor(() => expect(alertsApi.update).toHaveBeenCalledWith(5, { active: false }))
  expect(onSaved).toHaveBeenCalledTimes(2)

  fireEvent.click(screen.getByRole('button', { name: 'Eliminar alerta' }))
  await waitFor(() => expect(onRemoved).toHaveBeenCalledWith(5))
  expect(alertsApi.remove).toHaveBeenCalledWith(5)
})

it('shows the API limit message inline', async () => {
  vi.mocked(alertsApi.create).mockRejectedValue(new ApiError(422, 'Invalid', { kind: ['Puedes tener como máximo 10 alertas.'] }))
  show(undefined)
  fireEvent.click(screen.getByRole('checkbox', { name: 'Cruce dorado' }))
  fireEvent.click(screen.getByRole('button', { name: 'Guardar alerta' }))
  expect((await screen.findByRole('alert')).textContent).toBe('Puedes tener como máximo 10 alertas.')
})

it('renders English labels', () => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
  show(undefined)
  expect(screen.getByRole('heading', { name: 'My Watchlist alerts' })).toBeTruthy()
  expect(screen.getByRole('checkbox', { name: 'Golden cross' })).toBeTruthy()
  expect(screen.getByRole('button', { name: 'Save alert' })).toBeTruthy()
})
