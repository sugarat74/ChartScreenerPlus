// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import type { UniverseFreshness } from '../../lib/api.ts'
import UniverseFreshnessPanel from './UniverseFreshnessPanel.tsx'

const ROWS: UniverseFreshness[] = [
  { slug: 'sp500', name: 'S&P 500', market: 'US', currency: 'USD', members: 503, latest_date: '2026-10-23', expected_date: '2026-10-23', with_bar: 503, without_bar: 0, status: 'fresh' },
  { slug: 'ibex35', name: 'IBEX 35', market: 'XMAD', currency: 'EUR', members: 35, latest_date: '2026-10-23', expected_date: '2026-10-23', with_bar: 30, without_bar: 5, status: 'partial' },
  { slug: 'dax', name: 'DAX', market: 'XETR', currency: 'EUR', members: 40, latest_date: '2026-10-21', expected_date: '2026-10-23', with_bar: 0, without_bar: 40, status: 'stale' },
]

let response: () => Response

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

beforeEach(() => {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
  response = () => json({ universes: ROWS })
  vi.stubGlobal('fetch', vi.fn(async () => response()))
})
afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  window.localStorage.clear()
})

function show() {
  render(
    <I18nProvider>
      <UniverseFreshnessPanel />
    </I18nProvider>,
  )
}

describe('UniverseFreshnessPanel (Admin)', () => {
  it('lists every universe with market, currency, dates, counts and a textual status', async () => {
    show()

    const table = await screen.findByRole('table')
    const rows = within(table).getAllByRole('row')
    expect(rows).toHaveLength(4)
    expect(within(rows[1]).getByRole('rowheader').textContent).toBe('S&P 500')
    expect(rows[1].textContent).toContain('US · USD')
    expect(rows[1].textContent).toContain('Up to date')
    expect(rows[2].textContent).toContain('XMAD · EUR')
    expect(rows[2].textContent).toContain('Partial')
    expect(rows[3].textContent).toContain('Stale')
    expect(within(table).getAllByRole('columnheader').map((header) => header.textContent)).toEqual([
      'Universe', 'Market', 'Members', 'Latest bar', 'Expected', 'With bar', 'Without bar', 'Status',
    ])
    expect(vi.mocked(fetch).mock.calls[0][0]).toBe('/api/admin/universes/freshness')
  })

  it('shows a localized error and retries on refresh', async () => {
    response = () => json({ message: 'Forbidden.' }, 403)
    show()

    expect((await screen.findByRole('alert')).textContent).toContain('Universe freshness could not be loaded.')

    response = () => json({ universes: ROWS })
    fireEvent.click(screen.getByRole('button', { name: 'Refresh' }))
    await waitFor(() => expect(screen.getByRole('table')).toBeTruthy())
    expect(screen.queryByRole('alert')).toBeNull()
  })

  it('says so when there are no universes', async () => {
    response = () => json({ universes: [] })
    show()

    expect(await screen.findByText('There are no Screener universes.')).toBeTruthy()
  })
})
