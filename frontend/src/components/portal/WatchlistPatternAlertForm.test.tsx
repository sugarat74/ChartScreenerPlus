// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import { ApiError, alertsApi } from '../../lib/api.ts'
import type { Alert } from '../../lib/api.ts'
import WatchlistPatternAlertForm from './WatchlistPatternAlertForm.tsx'

vi.mock('../../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api.ts')>()
  return { ...actual, alertsApi: { list: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn() } }
})

const existing: Alert = {
  id: 6, kind: 'watchlist_pattern', saved_screener: null, signal_types: null,
  pattern_types: ['double_bottom'], pattern_events: ['confirmed'],
  active: true, last_evaluated_as_of: null, created_at: null,
}

function show(alert: Alert | undefined, handlers = { onSaved: vi.fn(), onRemoved: vi.fn() }) {
  render(
    <I18nProvider>
      <MemoryRouter>
        <WatchlistPatternAlertForm alert={alert} disabled={false} {...handlers} />
      </MemoryRouter>
    </I18nProvider>,
  )
  return handlers
}

function checkbox(name: string): HTMLInputElement {
  return screen.getByRole('checkbox', { name }) as HTMLInputElement
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => { cleanup(); vi.clearAllMocks(); window.localStorage.clear() })

it('pre-ticks "confirmed", disables save until a type and an event are chosen, then creates the alert', async () => {
  vi.mocked(alertsApi.create).mockImplementation(async () => ({ ...existing, id: 9 }))
  const { onSaved } = show(undefined)
  expect(screen.getByRole('heading', { name: 'Mis alertas de patrones' })).toBeTruthy()
  expect(screen.getByText('Sin alerta')).toBeTruthy()
  expect(screen.getAllByRole('checkbox')).toHaveLength(12) // 10 types + 2 events
  expect(checkbox('Cuando se confirma (ruptura)').checked).toBe(true)
  expect(checkbox('Cuando aparece').checked).toBe(false)

  const save = screen.getByRole('button', { name: 'Guardar alerta' }) as HTMLButtonElement
  expect(save.disabled).toBe(true)
  expect(screen.getByText('Selecciona al menos un patrón y un aviso para guardar.')).toBeTruthy()

  fireEvent.click(checkbox('Doble suelo'))
  expect(save.disabled).toBe(false)
  fireEvent.click(checkbox('Cuando se confirma (ruptura)'))
  expect(save.disabled).toBe(true)
  fireEvent.click(checkbox('Cuando se confirma (ruptura)'))
  fireEvent.click(checkbox('Bandera alcista'))
  fireEvent.click(save)

  await waitFor(() => expect(screen.getByRole('status').textContent).toBe('Alerta guardada.'))
  expect(alertsApi.create).toHaveBeenCalledWith({
    kind: 'watchlist_pattern',
    pattern_types: ['double_bottom', 'bull_flag'],
    pattern_events: ['confirmed'],
  })
  expect(onSaved).toHaveBeenCalledWith(expect.objectContaining({ id: 9 }))
})

it('prefills an existing alert, saves changes, pauses, resumes and deletes it', async () => {
  vi.mocked(alertsApi.update).mockImplementation(async (id, data) => ({ ...existing, id, ...data }))
  vi.mocked(alertsApi.remove).mockResolvedValue(undefined)
  const { onSaved, onRemoved } = show(existing)
  expect(checkbox('Doble suelo').checked).toBe(true)
  expect(checkbox('Doble techo').checked).toBe(false)
  expect(screen.getByText('Activa')).toBeTruthy()
  const save = screen.getByRole('button', { name: 'Guardar cambios' }) as HTMLButtonElement
  expect(save.disabled).toBe(true)

  fireEvent.click(checkbox('Cuando aparece'))
  expect(save.disabled).toBe(false)
  fireEvent.click(save)
  await waitFor(() =>
    expect(alertsApi.update).toHaveBeenCalledWith(6, { pattern_types: ['double_bottom'], pattern_events: ['appears', 'confirmed'] }),
  )

  fireEvent.click(screen.getByRole('button', { name: 'Pausar' }))
  await waitFor(() => expect(alertsApi.update).toHaveBeenCalledWith(6, { active: false }))
  expect(onSaved).toHaveBeenCalledTimes(2)

  fireEvent.click(screen.getByRole('button', { name: 'Eliminar alerta' }))
  await waitFor(() => expect(onRemoved).toHaveBeenCalledWith(6))
  expect(alertsApi.remove).toHaveBeenCalledWith(6)
})

it('offers resume on a paused alert', async () => {
  vi.mocked(alertsApi.update).mockImplementation(async (id, data) => ({ ...existing, id, ...data }))
  show({ ...existing, active: false })
  expect(screen.getByText('Pausada')).toBeTruthy()
  fireEvent.click(screen.getByRole('button', { name: 'Reanudar' }))
  await waitFor(() => expect(alertsApi.update).toHaveBeenCalledWith(6, { active: true }))
})

it('shows a 422 message inline', async () => {
  vi.mocked(alertsApi.create).mockRejectedValue(new ApiError(422, 'Invalid', { kind: ['Ya tienes esta alerta.'] }))
  show(undefined)
  fireEvent.click(checkbox('Doble techo'))
  fireEvent.click(screen.getByRole('button', { name: 'Guardar alerta' }))
  expect((await screen.findByRole('alert')).textContent).toBe('Ya tienes esta alerta.')
})

it('renders English labels', () => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
  show(undefined)
  expect(screen.getByRole('heading', { name: 'My pattern alerts' })).toBeTruthy()
  expect(screen.getByRole('checkbox', { name: 'Double bottom' })).toBeTruthy()
  expect(screen.getByRole('checkbox', { name: 'When it is confirmed (breakout)' })).toBeTruthy()
  expect(screen.getByRole('button', { name: 'Save alert' })).toBeTruthy()
})

it('groups the pattern types as named bullish and bearish groups (chart-patterns-extended)', () => {
  show(undefined)
  const bullish = screen.getByRole('group', { name: 'Alcistas' })
  const bearish = screen.getByRole('group', { name: 'Bajistas' })
  expect(within(bullish).getAllByRole('checkbox')).toHaveLength(5)
  expect(within(bearish).getByRole('checkbox', { name: 'Taza con asa invertida' })).toBeTruthy()
  expect(within(bearish).getByRole('checkbox', { name: 'Banderín bajista' })).toBeTruthy()
})
