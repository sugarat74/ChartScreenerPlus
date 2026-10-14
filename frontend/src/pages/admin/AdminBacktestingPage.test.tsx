// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import { forecastBacktestApi } from '../../lib/api.ts'
import AdminBacktestingPage from './AdminBacktestingPage.tsx'

vi.mock('../../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api.ts')>()
  return { ...actual, forecastBacktestApi: { list: vi.fn(), get: vi.fn(), start: vi.fn() } }
})

beforeEach(() => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
  vi.mocked(forecastBacktestApi.list).mockResolvedValue([])
})
afterEach(() => { cleanup(); vi.restoreAllMocks(); window.localStorage.clear() })

it('requires explicit confirmation and sends selected tickers and bar count', async () => {
  vi.spyOn(window, 'confirm').mockReturnValue(true)
  vi.mocked(forecastBacktestApi.start).mockResolvedValue({
    id: 1, tickers: ['AAPL', 'MSFT'], bars_requested: 30, status: 'queued',
    total: 60, processed: 0, correct: 0, skipped: 0, accuracy: null, created_at: '2026-10-14',
  })
  vi.mocked(forecastBacktestApi.get).mockResolvedValue({
    id: 1, tickers: ['AAPL', 'MSFT'], bars_requested: 30, status: 'queued',
    total: 60, processed: 0, correct: 0, skipped: 0, accuracy: null, created_at: '2026-10-14', cases: [],
  })
  render(<I18nProvider><AdminBacktestingPage /></I18nProvider>)
  fireEvent.click(screen.getByRole('button', { name: 'Iniciar prueba' }))
  await waitFor(() => expect(forecastBacktestApi.start).toHaveBeenCalledWith(['AAPL', 'MSFT'], 30))
  expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('60 llamadas'))
  expect(await screen.findByText('Procesados: 0/60')).toBeTruthy()
})

it('rejects duplicate ticker selection before calling the API', async () => {
  render(<I18nProvider><AdminBacktestingPage /></I18nProvider>)
  fireEvent.change(screen.getByLabelText('Tickers separados por coma'), { target: { value: 'AAPL, aapl' } })
  fireEvent.click(screen.getByRole('button', { name: 'Iniciar prueba' }))
  expect(await screen.findByRole('alert')).toBeTruthy()
  expect(forecastBacktestApi.start).not.toHaveBeenCalled()
})
