// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import { ApiError, adminMarketContextApi } from '../../lib/api.ts'
import type { AdminMarketContextStatus, MarketContextRun } from '../../lib/api.ts'
import AdminMarketContextPage, { MARKET_CONTEXT_POLL_MS } from './AdminMarketContextPage.tsx'

vi.mock('../../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/api.ts')>()
  return { ...actual, adminMarketContextApi: { status: vi.fn(), rerun: vi.fn() } }
})

const SUCCEEDED: MarketContextRun = {
  status: 'succeeded',
  queued_at: null,
  started_at: '2026-10-22T21:05:00Z',
  finished_at: '2026-10-22T21:05:03Z',
  exit_code: 0,
}

function status(overrides: Partial<AdminMarketContextStatus> = {}): AdminMarketContextStatus {
  return {
    public: false,
    macro_enabled: true,
    runs: { 'market-context:compute': SUCCEEDED, 'macro:refresh': null, 'market-regime:compute': SUCCEEDED },
    snapshots: {
      sectors: { first_date: '2025-10-22', latest_date: '2026-10-22', latest_rows: 11 },
      breadth: { first_date: '2025-10-22', latest_date: '2026-10-22' },
      regime: {
        first_date: '2025-10-22',
        latest_date: '2026-10-22',
        count: 252,
        rules_version: 1,
        latest: { regime: 'neutral', score: 1, available: 5 },
        distribution: { favourable: 119, neutral: 133, adverse: 0, insufficient: 0 },
      },
    },
    series: [
      {
        code: 'DGS10', category: 'rates', frequency: 'daily', license: 'public_domain', enabled: true,
        last_observation_date: '2026-10-21', last_refreshed_at: '2026-10-23T14:00:00Z', last_attempted_at: '2026-10-23T14:00:00Z',
        last_error: null, stale: false,
      },
      {
        code: 'NFCI', category: 'leading', frequency: 'weekly', license: 'restricted', enabled: false,
        last_observation_date: null, last_refreshed_at: null, last_attempted_at: null, last_error: null, stale: false,
      },
      {
        code: 'ICSA', category: 'leading', frequency: 'weekly', license: 'public_domain', enabled: true,
        last_observation_date: '2026-09-26', last_refreshed_at: null, last_attempted_at: '2026-10-23T14:00:00Z',
        last_error: 'unavailable', stale: true,
      },
    ],
    ...overrides,
  }
}

function renderPage() {
  return render(
    <I18nProvider>
      <AdminMarketContextPage />
    </I18nProvider>,
  )
}

const row = (command: string) => document.querySelector<HTMLElement>(`[data-command="${command}"]`)!

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => {
  cleanup()
  vi.clearAllMocks()
  vi.useRealTimers()
  window.localStorage.clear()
})

it('renders the switches, runs, snapshot coverage and every series', async () => {
  vi.mocked(adminMarketContextApi.status).mockResolvedValue(status())
  renderPage()

  expect(await screen.findByText('Solo vista previa para administradores (MARKET_CONTEXT_PUBLIC=false)')).toBeTruthy()
  expect(screen.getByText('Series macro activadas (MACRO_ENABLED=true)')).toBeTruthy()
  expect(within(row('market-regime:compute')).getByText('Correcta')).toBeTruthy()
  expect(within(row('macro:refresh')).getByText('Sin ejecuciones registradas')).toBeTruthy()
  expect(screen.getByTestId('regime-distribution').textContent).toBe(
    'Distribución: Favorable 119 · Neutral 133 · Adverso 0 · Sin datos suficientes 0',
  )
  const icsa = document.querySelector<HTMLElement>('[data-series="ICSA"]')!
  expect(icsa.textContent).toContain('unavailable')
  expect(icsa.textContent).toContain('Sí')
  const nfci = document.querySelector<HTMLElement>('[data-series="NFCI"]')!
  expect(nfci.textContent).toContain('restricted')
  expect(nfci.textContent).toContain('No')
})

it('reruns a command and polls until it finishes', async () => {
  vi.useFakeTimers({ shouldAdvanceTime: true })
  const queued: MarketContextRun = { status: 'queued', queued_at: '2026-10-23T16:00:00Z', started_at: null, finished_at: null, exit_code: null }
  vi.mocked(adminMarketContextApi.status)
    .mockResolvedValueOnce(status())
    .mockResolvedValueOnce(status({ runs: { ...status().runs, 'market-regime:compute': { ...queued, status: 'running', started_at: '2026-10-23T16:00:01Z' } } }))
    .mockResolvedValue(status({ runs: { ...status().runs, 'market-regime:compute': { ...SUCCEEDED, queued_at: queued.queued_at } } }))
  vi.mocked(adminMarketContextApi.rerun).mockResolvedValue({ command: 'market-regime:compute', run: queued })
  renderPage()

  await screen.findByText('Solo vista previa para administradores (MARKET_CONTEXT_PUBLIC=false)')
  fireEvent.click(within(row('market-regime:compute')).getByRole('button', { name: 'Recalcular market-regime:compute' }))
  await waitFor(() => expect(adminMarketContextApi.rerun).toHaveBeenCalledWith('market-regime:compute'))
  expect(await within(row('market-regime:compute')).findByText('En cola')).toBeTruthy()
  expect(within(row('market-regime:compute')).getByRole('button', { name: 'Recalcular market-regime:compute' }).hasAttribute('disabled')).toBe(true)

  await act(async () => {
    await vi.advanceTimersByTimeAsync(MARKET_CONTEXT_POLL_MS * 3)
  })
  expect(await within(row('market-regime:compute')).findByText('Correcta')).toBeTruthy()
  const calls = vi.mocked(adminMarketContextApi.status).mock.calls.length
  await act(async () => {
    await vi.advanceTimersByTimeAsync(MARKET_CONTEXT_POLL_MS * 3)
  })
  // Polling stops at a terminal status (one extra read when it stops at most).
  expect(vi.mocked(adminMarketContextApi.status).mock.calls.length).toBeLessThanOrEqual(calls + 1)
})

it('shows the 409 message when the command is already active', async () => {
  vi.mocked(adminMarketContextApi.status).mockResolvedValue(status())
  vi.mocked(adminMarketContextApi.rerun).mockRejectedValue(new ApiError(409, 'Este comando ya está en cola o en curso.'))
  renderPage()

  await screen.findByText('Solo vista previa para administradores (MARKET_CONTEXT_PUBLIC=false)')
  fireEvent.click(within(row('market-context:compute')).getByRole('button', { name: 'Recalcular market-context:compute' }))
  expect((await screen.findByRole('alert')).textContent).toBe('Este comando ya está en cola o en curso.')
})

it('disables macro:refresh with a note while macro series are off', async () => {
  vi.mocked(adminMarketContextApi.status).mockResolvedValue(status({ macro_enabled: false }))
  renderPage()

  expect(await screen.findByText('Las series macro están desactivadas: macro:refresh no se puede lanzar.')).toBeTruthy()
  expect(within(row('macro:refresh')).getByRole('button', { name: 'Recalcular macro:refresh' }).hasAttribute('disabled')).toBe(true)
  expect(within(row('market-context:compute')).getByRole('button', { name: 'Recalcular market-context:compute' }).hasAttribute('disabled')).toBe(false)
})
