// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import type { WatchlistEntry } from '../../lib/api.ts'
import WatchlistTable from './WatchlistTable.tsx'

afterEach(cleanup)

function show(items: WatchlistEntry[]) {
  render(
    <I18nProvider>
      <MemoryRouter>
        <WatchlistTable items={items} removingTicker={null} onRemove={vi.fn()} />
      </MemoryRouter>
    </I18nProvider>,
  )
}

describe('WatchlistTable currency', () => {
  it('shows the exchange with the currency code and keeps older payloads working', () => {
    show([
      { ticker: 'SAN.MC', company: 'Banco Santander', sector: 'Financials', exchange: 'BME', active: true, market: 'XMAD', currency: 'EUR' },
      { ticker: 'AAPL', company: 'Apple', sector: 'Information Technology', exchange: 'NASDAQ', active: true },
    ])

    expect(screen.getByText('BME · EUR')).toBeTruthy()
    expect(screen.getByText('NASDAQ')).toBeTruthy()
    expect(screen.getByRole('link', { name: 'SAN.MC' }).getAttribute('href')).toBe('/instruments/SAN.MC')
  })
})
