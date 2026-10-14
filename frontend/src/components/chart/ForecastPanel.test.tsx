// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { AuthContext } from '../../auth/context.ts'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import { forecastApi } from '../../lib/api.ts'
import type { Forecast } from '../../lib/api.ts'
import ForecastPanel from './ForecastPanel.tsx'

vi.mock('../../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api.ts')>()
  return { ...actual, forecastApi: { predict: vi.fn(), report: vi.fn() } }
})

const auth = {
  user: { id: 1, name: 'Test', email: 'test@example.com', role: 'user' as const },
  status: 'ready' as const,
  login: vi.fn(), register: vi.fn(), logout: vi.fn(),
}

function show(signedIn: boolean) {
  return render(<AuthContext.Provider value={{ ...auth, user: signedIn ? auth.user : null }}><I18nProvider><MemoryRouter><ForecastPanel ticker="TEST" /></MemoryRouter></I18nProvider></AuthContext.Provider>)
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => { cleanup(); vi.clearAllMocks(); window.localStorage.clear() })

it('invites a guest without calling the provider', () => {
  show(false)
  expect(screen.getByRole('link', { name: /inicia sesión/i })).toBeTruthy()
  expect(forecastApi.predict).not.toHaveBeenCalled()
})

it('shows the original three probabilities and applies the chosen minimum', async () => {
  vi.mocked(forecastApi.predict).mockResolvedValue({
    ticker: 'TEST', as_of_date: '2025-01-06', target_date: '2025-01-07', horizon: 1,
    reference_close: 100, probabilities: { up: .62, down: .28, flat: .1 }, signal: null,
    minimum_probability: 70, model_version: 'jev-1.13.0', experimental: true,
  })
  show(true)
  fireEvent.change(screen.getByLabelText(/probabilidad mínima/i), { target: { value: '70' } })
  fireEvent.click(screen.getByRole('button', { name: 'Analizar' }))
  await waitFor(() => expect(screen.getByText('Sin señal suficiente')).toBeTruthy())
  expect(screen.getByText(/62\.0%/)).toBeTruthy()
  expect(forecastApi.predict).toHaveBeenCalledWith('TEST', 1, 70)
})

it('renders English controls and experimental copy', () => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
  show(true)
  expect(screen.getByRole('button', { name: 'Analyze' })).toBeTruthy()
  expect(screen.getByText('Experimental')).toBeTruthy()
})

it('ignores responses for a previous horizon or minimum probability', async () => {
  const pending: Array<(value: Forecast) => void> = []
  vi.mocked(forecastApi.predict).mockImplementation(() => new Promise((resolve) => { pending.push(resolve) }))
  const previous: Forecast = {
    ticker: 'TEST', as_of_date: '2026-10-13', target_date: '2026-10-14', horizon: 1,
    reference_close: 100, probabilities: { up: .62, down: .28, flat: .10 }, signal: 'up',
    minimum_probability: 60, model_version: 'jev-1.13.0', experimental: true,
  }
  show(true)
  fireEvent.click(screen.getByRole('button', { name: 'Analizar' }))
  fireEvent.change(screen.getByLabelText('Plazo'), { target: { value: '5' } })
  await act(async () => pending[0](previous))
  expect(screen.queryByText(/62\.0%/)).toBeNull()
  fireEvent.click(screen.getByRole('button', { name: 'Analizar' }))
  expect(forecastApi.predict).toHaveBeenLastCalledWith('TEST', 5, 60)
  fireEvent.change(screen.getByLabelText(/probabilidad mínima/i), { target: { value: '70' } })
  await act(async () => pending[1]({ ...previous, horizon: 5, minimum_probability: 60 }))
  expect(screen.queryByText(/62\.0%/)).toBeNull()
})
