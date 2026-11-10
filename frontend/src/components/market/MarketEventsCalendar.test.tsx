// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import { marketEventsApi } from '../../lib/api.ts'
import type { MarketEvent, MarketEventsResponse } from '../../lib/api.ts'
import MarketEventsCalendar from './MarketEventsCalendar.tsx'

vi.mock('../../lib/api.ts', async (original) => ({ ...await original<object>(), marketEventsApi: { list: vi.fn() } }))

const event = (patch: Partial<MarketEvent>): MarketEvent => ({
  id: 'cpi:2026-11-10', code: 'cpi', date: '2026-11-10', kind: 'macro_release', region: 'US',
  status: 'tentative', source: 'BLS via FRED', source_url: 'https://fred.stlouisfed.org/release?rid=10', checked_at: '2026-11-10T12:00:00Z', stale: false, ...patch,
})
const FIXTURE: MarketEventsResponse = {
  as_of: '2026-11-10', from: '2026-11-03', to: '2026-11-24', macro_enabled: true, macro_configured: true,
  sources: { cpi: { checked_at: '2026-11-10T12:00:00Z', stale: false } },
  events: [
    event({ id: 'employment:2026-11-04', code: 'employment', date: '2026-11-04', status: 'past' }),
    event({}),
    event({ id: 'ecb:2026-11-12', code: 'ecb', date: '2026-11-12', region: 'EU', kind: 'central_bank', status: 'scheduled', source: 'ECB', source_url: 'https://www.ecb.europa.eu/', stale: true }),
  ],
}

beforeEach(() => {
  vi.resetAllMocks()
  localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
  vi.mocked(marketEventsApi.list).mockResolvedValue(FIXTURE)
})
afterEach(() => { cleanup(); localStorage.clear() })
function show() { return render(<I18nProvider><MarketEventsCalendar /></I18nProvider>) }

it('separates today/upcoming and recent dates, displays sources and tentative/stale text', async () => {
  show()
  expect(await screen.findByText('Índice de precios al consumo (IPC)')).toBeTruthy()
  const upcoming = screen.getByRole('heading', { name: 'Próximos eventos' }).parentElement!
  const recent = screen.getByRole('heading', { name: 'Recientes · últimos 7 días' }).parentElement!
  expect(within(upcoming).getByText('Índice de precios al consumo (IPC)')).toBeTruthy()
  expect(within(recent).getByText('Informe de empleo')).toBeTruthy()
  expect(within(upcoming).getByText('Tentativa')).toBeTruthy()
  expect(screen.getByText('Desactualizado')).toBeTruthy()
  expect(screen.getAllByText(/Última revisión:/)).toHaveLength(3)
  expect(screen.getByRole('link', { name: 'Fuente: ECB' }).getAttribute('href')).toBe('https://www.ecb.europa.eu/')
  expect(document.querySelector('time[datetime="2026-11-10"]')).toBeTruthy()
  expect(document.body.textContent).not.toMatch(/EOD|end.of.day|consenso|objetivo de precio/i)
})

it('filters by region and kind without extra requests and shows an explicit empty state', async () => {
  show()
  await screen.findByText('Informe de empleo')
  fireEvent.change(screen.getByRole('combobox', { name: 'Región' }), { target: { value: 'EU' } })
  expect(screen.queryByText('Informe de empleo')).toBeNull()
  expect(screen.getByText('BCE · reunión de política monetaria')).toBeTruthy()
  fireEvent.change(screen.getByRole('combobox', { name: 'Tipo de evento' }), { target: { value: 'macro_release' } })
  expect(screen.queryByText('BCE · reunión de política monetaria')).toBeNull()
  expect(screen.getAllByText('No hay eventos en este periodo con estos filtros.')).toHaveLength(2)
  expect(marketEventsApi.list).toHaveBeenCalledTimes(1)
})

it('refetches when the horizon changes and aborts the old request', async () => {
  show()
  await screen.findByText('Informe de empleo')
  const firstSignal = vi.mocked(marketEventsApi.list).mock.calls[0][1]!
  fireEvent.change(screen.getByRole('combobox', { name: 'Ver próximos' }), { target: { value: '90' } })
  await waitFor(() => expect(marketEventsApi.list).toHaveBeenLastCalledWith(90, expect.any(AbortSignal)))
  expect(firstSignal.aborted).toBe(true)
})

it('retries a failed request and keeps the card title and controls available', async () => {
  vi.mocked(marketEventsApi.list).mockRejectedValueOnce(new Error('network'))
  show()
  expect(await screen.findByRole('alert')).toBeTruthy()
  expect(screen.getByRole('heading', { name: 'Calendario de eventos de mercado' })).toBeTruthy()
  fireEvent.click(screen.getByRole('button', { name: 'Reintentar' }))
  expect(await screen.findByText('Informe de empleo')).toBeTruthy()
  expect(marketEventsApi.list).toHaveBeenCalledTimes(2)
})

it('renders English, disabled macro and empty calendars without inventing dates', async () => {
  localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
  vi.mocked(marketEventsApi.list).mockResolvedValue({ ...FIXTURE, macro_enabled: false, macro_configured: false, sources: {}, events: [] })
  show()
  expect(await screen.findByText('Economic release dates are not enabled. Central bank meetings remain available.')).toBeTruthy()
  expect(screen.getByRole('heading', { name: 'Market events calendar' })).toBeTruthy()
  expect(document.querySelector('time')).toBeNull()
  expect(screen.getAllByText('No events in this period matching these filters.')).toHaveLength(2)
})

it('identifies missing refreshes and reports FRED usage for footer attribution', async () => {
  const usage = vi.fn()
  vi.mocked(marketEventsApi.list).mockResolvedValue({ ...FIXTURE, sources: { cpi: { checked_at: null, stale: true } } })
  render(<I18nProvider><MarketEventsCalendar onFredUsage={usage} /></I18nProvider>)
  expect(await screen.findByText('Algunos calendarios de publicaciones aún no se han actualizado. Se muestran las fechas disponibles.')).toBeTruthy()
  await waitFor(() => expect(usage).toHaveBeenLastCalledWith(true))
})
