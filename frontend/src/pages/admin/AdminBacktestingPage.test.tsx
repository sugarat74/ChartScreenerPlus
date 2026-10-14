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
    id: 1, tickers: ['AAPL', 'MSFT'], bars_requested: 30, model_version: 'jev-1.13.0', question_version: 'close-direction-v1', status: 'queued',
    total: 60, processed: 0, correct: 0, skipped: 0, accuracy: null, created_at: '2026-10-14',
  })
  vi.mocked(forecastBacktestApi.get).mockResolvedValue({
    id: 1, tickers: ['AAPL', 'MSFT'], bars_requested: 30, model_version: 'jev-1.13.0', question_version: 'close-direction-v1', status: 'queued',
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
  fireEvent.change(screen.getByLabelText('Tickers separados por coma o salto de línea'), { target: { value: 'AAPL, aapl' } })
  fireEvent.click(screen.getByRole('button', { name: 'Iniciar prueba' }))
  expect(await screen.findByRole('alert')).toBeTruthy()
  expect(forecastBacktestApi.start).not.toHaveBeenCalled()
})

it('allows 100 stocks and rejects 101 before making paid calls', async () => {
  vi.spyOn(window, 'confirm').mockReturnValue(false)
  render(<I18nProvider><AdminBacktestingPage /></I18nProvider>)
  const tickers = Array.from({ length: 100 }, (_, index) => `T${index + 1}`)
  const input = screen.getByLabelText('Tickers separados por coma o salto de línea')
  fireEvent.change(input, { target: { value: tickers.join('\n') } })
  expect(screen.getByText('100 de 100 acciones')).toBeTruthy()
  fireEvent.click(screen.getByRole('button', { name: 'Iniciar prueba' }))
  expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('3000 llamadas'))
  fireEvent.change(input, { target: { value: [...tickers, 'T101'].join('\n') } })
  fireEvent.click(screen.getByRole('button', { name: 'Iniciar prueba' }))
  expect(await screen.findByRole('alert')).toBeTruthy()
  expect(forecastBacktestApi.start).not.toHaveBeenCalled()
})

it('loads large run details one page at a time', async () => {
  const summary = {
    id: 7, tickers: ['AAPL'], bars_requested: 100, model_version: 'jev-1.13.0', question_version: 'close-direction-v1',
    status: 'completed' as const, total: 200, processed: 200, correct: 100, skipped: 0, accuracy: 0.5, created_at: '2026-10-14',
  }
  vi.mocked(forecastBacktestApi.list).mockResolvedValue([summary])
  vi.mocked(forecastBacktestApi.get).mockImplementation(async (_id, page) => ({
    ...summary, cases: [], case_page: { current: page ?? 1, last: 2, total: 200 },
  }))
  render(<I18nProvider><AdminBacktestingPage /></I18nProvider>)
  expect(await screen.findByText('Página 1 de 2')).toBeTruthy()
  fireEvent.click(screen.getByRole('button', { name: 'Siguiente' }))
  await waitFor(() => expect(forecastBacktestApi.get).toHaveBeenCalledWith(7, 2))
  expect(await screen.findByText('Página 2 de 2')).toBeTruthy()
})
