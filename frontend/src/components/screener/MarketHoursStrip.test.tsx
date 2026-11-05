// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { StrictMode } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import type { MarketStatus } from '../../lib/api.ts'
import MarketHoursStrip from './MarketHoursStrip.tsx'

const US: MarketStatus = {
  code: 'US',
  timezone: 'America/New_York',
  regular_hours: { open: '09:30', close: '16:00' },
  is_open: false,
  opens_at: '2026-07-06T13:30:00Z',
  closes_at: '2026-07-06T20:00:00Z',
  early_close: false,
  next_open_at: '2026-07-07T13:30:00Z',
  next_close_at: '2026-07-07T20:00:00Z',
  calendar_year: 2026,
  calendar_covered: true,
  upcoming: [
    { date: '2026-09-07', type: 'holiday', close: null },
    { date: '2026-11-27', type: 'early_close', close: '13:00' },
  ],
}
const XETR: MarketStatus = {
  code: 'XETR',
  timezone: 'Europe/Berlin',
  regular_hours: { open: '09:00', close: '17:30' },
  is_open: false,
  opens_at: '2026-07-06T07:00:00Z',
  closes_at: '2026-07-06T15:30:00Z',
  early_close: false,
  next_open_at: '2026-07-07T07:00:00Z',
  next_close_at: '2026-07-07T15:30:00Z',
  calendar_year: 2030,
  calendar_covered: false,
  upcoming: [],
}

let markets: MarketStatus[] = []
let status = 200
const fetchMock = vi.fn()

function json(body: unknown, code = 200): Response {
  return new Response(JSON.stringify(body), { status: code, headers: { 'Content-Type': 'application/json' } })
}

function setClock(iso: string) {
  vi.setSystemTime(new Date(iso))
}

async function flush() {
  await act(async () => {
    await Promise.resolve()
    await Promise.resolve()
    await Promise.resolve()
  })
}

async function renderAt(iso: string) {
  setClock(iso)
  const view = render(
    <I18nProvider>
      <MarketHoursStrip timeZone="Europe/Madrid" />
    </I18nProvider>,
  )
  await flush()
  return view
}

function card(code: string): HTMLElement {
  return document.querySelector(`[data-market="${code}"]`) as HTMLElement
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
  markets = [US, XETR]
  status = 200
  fetchMock.mockReset()
  fetchMock.mockImplementation(async () => json({ markets }, status))
  vi.stubGlobal('fetch', fetchMock)
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
})
afterEach(() => {
  cleanup()
  vi.useRealTimers()
  vi.unstubAllGlobals()
  window.localStorage.clear()
})

describe('MarketHoursStrip (market-hours-status)', () => {
  it('shows each market open or closed with the next boundary in the viewer timezone', async () => {
    await renderAt('2026-07-06T14:00:00Z')

    expect(within(card('US')).getByText('NYSE/Nasdaq')).toBeTruthy()
    expect(within(card('US')).getByText('Abierto')).toBeTruthy()
    // 20:00Z is 22:00 in Madrid.
    expect(within(card('US')).getByText('Cierra 22:00')).toBeTruthy()
    expect(within(card('XETR')).getByText('Abierto')).toBeTruthy()
    expect(within(card('XETR')).getByText('Cierra 17:30')).toBeTruthy()
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(fetchMock.mock.calls[0][0]).toBe('/api/markets/status')
  })

  it('flips the status when the clock crosses a boundary, without refetching', async () => {
    await renderAt('2026-07-06T13:29:30Z')
    expect(within(card('US')).getByText('Cerrado')).toBeTruthy()
    expect(within(card('US')).getByText('Abre 15:30')).toBeTruthy()

    setClock('2026-07-06T13:30:30Z')
    await act(async () => {
      vi.advanceTimersByTime(60_000)
    })
    expect(within(card('US')).getByText('Abierto')).toBeTruthy()
    expect(within(card('US')).getByText('Cierra 22:00')).toBeTruthy()

    setClock('2026-07-06T20:00:30Z')
    await act(async () => {
      vi.advanceTimersByTime(60_000)
    })
    expect(within(card('US')).getByText('Cerrado')).toBeTruthy()
    expect(within(card('US')).getByText(/^Abre mar,? 15:30$/)).toBeTruthy()
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('refetches once when the payload no longer covers the moment', async () => {
    const weekend: MarketStatus = { ...US, opens_at: null, closes_at: null, next_open_at: '2026-07-06T13:30:00Z' }
    markets = [weekend]
    await renderAt('2026-07-05T12:00:00Z')
    expect(fetchMock).toHaveBeenCalledTimes(1)

    markets = [US]
    setClock('2026-07-06T13:31:00Z')
    await act(async () => {
      vi.advanceTimersByTime(60_000)
    })
    await flush()
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(within(card('US')).getByText('Abierto')).toBeTruthy()
  })

  it('renders in English and lists hours, holidays and early closes in the details', async () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    await renderAt('2026-07-06T06:00:00Z')

    expect(screen.getAllByText('Closed')).toHaveLength(2)
    expect(screen.getByText('Opens 09:00')).toBeTruthy()

    const toggle = screen.getByRole('button', { name: 'Hours and holidays' })
    expect(toggle.getAttribute('aria-expanded')).toBe('false')
    fireEvent.click(toggle)
    expect(toggle.getAttribute('aria-expanded')).toBe('true')
    expect(screen.getByText('Continuous session 09:30–16:00 (America/New_York time)')).toBeTruthy()
    expect(screen.getByText('Holiday')).toBeTruthy()
    expect(screen.getByText('Early close at 13:00')).toBeTruthy()
    // A market without a committed holiday list for the year says so.
    expect(screen.getByText('Holiday calendar for 2030 not available: the status may not reflect holidays.')).toBeTruthy()
    expect(screen.getAllByText('No published holidays or early closes.')).toHaveLength(1)
  })

  it('does not rely on colour alone and uses no end-of-day wording', async () => {
    await renderAt('2026-07-06T14:00:00Z')
    fireEvent.click(screen.getByRole('button', { name: 'Horarios y festivos' }))

    const text = document.body.textContent ?? ''
    expect(text).toContain('Abierto')
    expect(text).not.toMatch(/\bEOD\b|end[- ]of[- ]day|fin de d[ií]a/i)
    // The marker is decorative: the state is in the text.
    const markers = document.querySelectorAll('[data-market] [aria-hidden="true"]')
    expect(markers.length).toBeGreaterThan(0)
    for (const marker of markers) {
      expect(['●', '○']).toContain(marker.textContent)
    }
  })

  it('still loads under StrictMode (the first mount is aborted and remounted)', async () => {
    setClock('2026-07-06T14:00:00Z')
    render(
      <StrictMode>
        <I18nProvider>
          <MarketHoursStrip timeZone="Europe/Madrid" />
        </I18nProvider>
      </StrictMode>,
    )
    await flush()
    expect(within(card('US')).getByText('Abierto')).toBeTruthy()
  })

  it('renders nothing when the request fails or no market is public', async () => {
    status = 500
    const failed = await renderAt('2026-07-06T14:00:00Z')
    expect(failed.container.innerHTML).toBe('')
    cleanup()

    status = 200
    markets = []
    const empty = await renderAt('2026-07-06T14:00:00Z')
    expect(empty.container.innerHTML).toBe('')
  })
})
