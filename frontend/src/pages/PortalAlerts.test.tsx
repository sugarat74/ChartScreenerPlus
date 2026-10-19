// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { AuthContext } from '../auth/context.ts'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { ApiError, alertsApi, savedScreenersApi, watchlistApi } from '../lib/api.ts'
import type { Alert, SavedScreener } from '../lib/api.ts'
import PortalPage from './PortalPage.tsx'

vi.mock('../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api.ts')>()
  return {
    ...actual,
    alertsApi: { list: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn() },
    savedScreenersApi: { list: vi.fn(), create: vi.fn(), remove: vi.fn() },
    watchlistApi: { list: vi.fn(), add: vi.fn(), remove: vi.fn() },
  }
})

const user = { id: 1, name: 'Test', email: 'test@example.com', role: 'user' as const }

const breakouts: SavedScreener = {
  id: 7,
  name: 'Breakouts',
  filters: { signal: [], rsi_min: null, rsi_max: null, min_rvol: 1.5, price_above_sma200: false, ma_cross: null, sort: 'rvol_desc' },
}

const screenerAlert: Alert = {
  id: 3, kind: 'screener_new_candidates', saved_screener: { id: 7, name: 'Breakouts' }, signal_types: null, pattern_types: null, pattern_events: null,
  active: true, last_evaluated_as_of: null, created_at: null,
}

function show() {
  render(
    <AuthContext.Provider value={{ user, status: 'ready', login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn() }}>
      <I18nProvider>
        <MemoryRouter initialEntries={['/portal']}>
          <PortalPage />
        </MemoryRouter>
      </I18nProvider>
    </AuthContext.Provider>,
  )
}

beforeEach(() => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
  vi.mocked(savedScreenersApi.list).mockResolvedValue([breakouts])
  vi.mocked(watchlistApi.list).mockResolvedValue([])
})
afterEach(() => { cleanup(); vi.clearAllMocks(); window.localStorage.clear() })

it('creates, pauses and resumes the alert of a Saved Screener', async () => {
  vi.mocked(alertsApi.list).mockResolvedValue([])
  vi.mocked(alertsApi.create).mockResolvedValue(screenerAlert)
  vi.mocked(alertsApi.update).mockImplementation(async (id, data) => ({ ...screenerAlert, id, ...data }))
  show()

  const toggle = await screen.findByRole('switch', { name: 'Avisarme de nuevos candidatos en «Breakouts»' })
  await waitFor(() => expect((toggle as HTMLButtonElement).disabled).toBe(false))
  expect(toggle.getAttribute('aria-checked')).toBe('false')
  expect(screen.getAllByText('Sin alerta')).toHaveLength(3)

  fireEvent.click(toggle)
  await waitFor(() => expect(toggle.getAttribute('aria-checked')).toBe('true'))
  expect(alertsApi.create).toHaveBeenCalledWith({ kind: 'screener_new_candidates', saved_screener_id: 7 })
  expect(screen.getAllByText('Activa').length).toBeGreaterThan(0)

  fireEvent.click(toggle)
  await waitFor(() => expect(toggle.getAttribute('aria-checked')).toBe('false'))
  expect(alertsApi.update).toHaveBeenLastCalledWith(3, { active: false })
  expect(screen.getByText('Pausada')).toBeTruthy()

  fireEvent.click(toggle)
  await waitFor(() => expect(toggle.getAttribute('aria-checked')).toBe('true'))
  expect(alertsApi.update).toHaveBeenLastCalledWith(3, { active: true })
})

it('shows the API 422 message inline when the alert limit is reached', async () => {
  vi.mocked(alertsApi.list).mockResolvedValue([])
  vi.mocked(alertsApi.create).mockRejectedValue(new ApiError(422, 'Invalid', { kind: ['Puedes tener como máximo 10 alertas.'] }))
  show()
  const toggle = await screen.findByRole('switch', { name: /Breakouts/ })
  await waitFor(() => expect((toggle as HTMLButtonElement).disabled).toBe(false))
  fireEvent.click(toggle)
  expect((await screen.findByRole('alert')).textContent).toBe('Puedes tener como máximo 10 alertas.')
  expect(toggle.getAttribute('aria-checked')).toBe('false')
})

it('removes the alert and its toggle with the Saved Screener', async () => {
  vi.mocked(alertsApi.list).mockResolvedValue([screenerAlert])
  vi.mocked(savedScreenersApi.remove).mockResolvedValue(undefined)
  show()
  await waitFor(() => expect(screen.getByRole('switch', { name: /Breakouts/ }).getAttribute('aria-checked')).toBe('true'))
  vi.mocked(savedScreenersApi.list).mockResolvedValue([])
  fireEvent.click(screen.getByRole('button', { name: 'Eliminar' }))
  await waitFor(() => expect(screen.queryByRole('switch')).toBeNull())
})

it('deletes only the alert with "Quitar alerta"', async () => {
  vi.mocked(alertsApi.list).mockResolvedValue([screenerAlert])
  vi.mocked(alertsApi.remove).mockResolvedValue(undefined)
  show()
  fireEvent.click(await screen.findByRole('button', { name: 'Quitar la alerta de «Breakouts»' }))
  await waitFor(() => expect(screen.getByRole('switch', { name: /Breakouts/ }).getAttribute('aria-checked')).toBe('false'))
  expect(alertsApi.remove).toHaveBeenCalledWith(3)
  expect(screen.getAllByText('Sin alerta')).toHaveLength(3)
})

it('shows a retry when alerts cannot be loaded', async () => {
  vi.mocked(alertsApi.list).mockRejectedValueOnce(new TypeError('offline')).mockResolvedValue([])
  show()
  expect(await screen.findByText('No se pudieron cargar tus alertas')).toBeTruthy()
  expect((screen.getByRole('switch', { name: /Breakouts/ }) as HTMLButtonElement).disabled).toBe(true)
  fireEvent.click(screen.getByRole('button', { name: 'Reintentar' }))
  await waitFor(() => expect((screen.getByRole('switch', { name: /Breakouts/ }) as HTMLButtonElement).disabled).toBe(false))
  expect(screen.getByRole('link', { name: 'Ver notificaciones' }).getAttribute('href')).toBe('/portal/notificaciones')
})

it('renders both Watchlist alert cards and prefills the pattern alert', async () => {
  const patternAlert: Alert = {
    id: 4, kind: 'watchlist_pattern', saved_screener: null, signal_types: null,
    pattern_types: ['double_bottom', 'bull_flag'], pattern_events: ['appears'],
    active: false, last_evaluated_as_of: null, created_at: null,
  }
  vi.mocked(alertsApi.list).mockResolvedValue([patternAlert])
  show()
  expect(await screen.findByRole('heading', { name: 'Alertas de mi Watchlist' })).toBeTruthy()
  const card = screen.getByRole('region', { name: 'Mis alertas de patrones' })
  await waitFor(() => expect((within(card).getByRole('checkbox', { name: 'Doble suelo' }) as HTMLInputElement).checked).toBe(true))
  expect((within(card).getByRole('checkbox', { name: 'Bandera alcista' }) as HTMLInputElement).checked).toBe(true)
  expect((within(card).getByRole('checkbox', { name: 'Cuando aparece' }) as HTMLInputElement).checked).toBe(true)
  expect((within(card).getByRole('checkbox', { name: 'Cuando se confirma (ruptura)' }) as HTMLInputElement).checked).toBe(false)
  expect(within(card).getByText('Pausada')).toBeTruthy()
  expect(within(card).getByRole('button', { name: 'Reanudar' })).toBeTruthy()
})
