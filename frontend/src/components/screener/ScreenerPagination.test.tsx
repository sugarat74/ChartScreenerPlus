// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { RouterProvider, createMemoryRouter, useLocation } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../../auth/AuthContext.tsx'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import {
  parseScreenerPagination,
  patchScreenerPagination,
  resetScreenerPage,
  screenerOffset,
  screenerPageCount,
  screenerPageRange,
} from '../../lib/screenerPagination.ts'
import ScreenerPage from '../../pages/ScreenerPage.tsx'

describe('screener pagination helpers', () => {
  it('parses leniently and omits defaults from the URL', () => {
    expect(parseScreenerPagination(new URLSearchParams(''))).toEqual({ page: 1, limit: 50 })
    expect(parseScreenerPagination(new URLSearchParams('page=3&limit=100'))).toEqual({ page: 3, limit: 100 })
    expect(parseScreenerPagination(new URLSearchParams('page=-2&limit=37'))).toEqual({ page: 1, limit: 50 })
    expect(parseScreenerPagination(new URLSearchParams('page=abc&limit=999'))).toEqual({ page: 1, limit: 50 })

    const next = patchScreenerPagination(new URLSearchParams('signal=golden_cross&page=4'), { page: 1 })
    expect(next.toString()).toBe('signal=golden_cross')
  })

  it('restarts at page 1 when the page size changes and on reset', () => {
    const resized = patchScreenerPagination(new URLSearchParams('page=4'), { limit: 100 })
    expect(resized.get('page')).toBeNull()
    expect(resized.get('limit')).toBe('100')
    expect(resetScreenerPage(new URLSearchParams('page=2&rsi_min=30')).toString()).toBe('rsi_min=30')
  })

  it('computes offset, page count and shown range', () => {
    expect(screenerOffset({ page: 3, limit: 50 })).toBe(100)
    expect(screenerPageCount(503, 50)).toBe(11)
    expect(screenerPageCount(0, 50)).toBe(1)
    expect(screenerPageRange(100, 50)).toEqual({ from: 101, to: 150 })
    expect(screenerPageRange(500, 3)).toEqual({ from: 501, to: 503 })
    expect(screenerPageRange(0, 0)).toEqual({ from: 0, to: 0 })
  })
})

function candidate(ticker: string) {
  return {
    ticker, company: ticker, sector: 'Tech', exchange: 'NYSE', active: true, date: '2026-10-02',
    close: 10, change_percent: 1, rvol: 1, rsi14: 50, signals: [], patterns: [],
  }
}

function LocationProbe() {
  const location = useLocation()
  return <p data-testid="url">{location.search}</p>
}

describe('ScreenerPage pagination', () => {
  const requests: string[] = []

  beforeEach(() => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
    requests.length = 0
    vi.stubGlobal('fetch', vi.fn(async (input: string) => {
      if (input.startsWith('/api/screener')) {
        requests.push(input)
        const url = new URL(input, 'http://localhost')
        const limit = Number(url.searchParams.get('limit') ?? 50)
        const offset = Number(url.searchParams.get('offset') ?? 0)
        const total = url.searchParams.get('rsi_min') === '99' ? 0 : 120
        const rows = Array.from({ length: Math.max(0, Math.min(limit, total - offset)) }, (_, i) => candidate(`T${offset + i + 1}`))
        return new Response(JSON.stringify({
          universe: { slug: 'sp500', name: 'S&P 500' }, sort: 'rvol_desc', candidates: rows,
          meta: { limit, offset, returned: rows.length, total },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } })
      }
      if (input === '/api/user') {
        return new Response(JSON.stringify({ message: 'Unauthenticated.' }), { status: 401, headers: { 'Content-Type': 'application/json' } })
      }
      return new Response(JSON.stringify({}), { status: 200, headers: { 'Content-Type': 'application/json' } })
    }))
  })

  afterEach(() => {
    cleanup()
    vi.unstubAllGlobals()
    window.localStorage.clear()
  })

  function renderAt(path: string) {
    const router = createMemoryRouter([{ path: '/screener', element: <><ScreenerPage /><LocationProbe /></> }], { initialEntries: [path] })
    return render(<I18nProvider><AuthProvider><RouterProvider router={router} /></AuthProvider></I18nProvider>)
  }

  it('moves forward and back and shows the range', async () => {
    renderAt('/screener')
    await screen.findByText('Página 1 de 3')
    expect(document.body.textContent).toMatch(/Mostrando 1–50 de 120 candidatos/)
    expect(screen.getByRole('button', { name: /Anterior/ }).hasAttribute('disabled')).toBe(true)

    fireEvent.click(screen.getByRole('button', { name: /Siguiente/ }))
    await screen.findByText('Página 2 de 3')
    expect(screen.getByTestId('url').textContent).toBe('?page=2')
    expect(requests.at(-1)).toContain('offset=50')
    await waitFor(() => expect(document.body.textContent).toMatch(/Mostrando 51–100 de 120 candidatos/))

    fireEvent.click(screen.getByRole('button', { name: /Siguiente/ }))
    await screen.findByText('Página 3 de 3')
    await waitFor(() => expect(document.body.textContent).toMatch(/Mostrando 101–120 de 120 candidatos/))
    expect(screen.getByRole('button', { name: /Siguiente/ }).hasAttribute('disabled')).toBe(true)

    fireEvent.click(screen.getByRole('button', { name: /Anterior/ }))
    await screen.findByText('Página 2 de 3')
  }, 10000)

  it('changes the page size and restarts at page 1', async () => {
    renderAt('/screener?page=2')
    await screen.findByText('Página 2 de 3')

    fireEvent.change(screen.getByLabelText('Por página'), { target: { value: '100' } })
    await screen.findByText('Página 1 de 2')
    expect(screen.getByTestId('url').textContent).toBe('?limit=100')
    expect(requests.at(-1)).toContain('limit=100')
    expect(requests.at(-1)).not.toContain('offset')
  })

  it('keeps keyboard focus on the button while the next page loads', async () => {
    renderAt('/screener')
    await screen.findByText('Página 1 de 3')
    const next = screen.getByRole('button', { name: /Siguiente/ })
    next.focus()
    fireEvent.click(next)
    expect(next.hasAttribute('disabled')).toBe(false)
    expect(document.activeElement).toBe(next)
    await screen.findByText('Página 2 de 3')
    expect(document.activeElement).toBe(next)
  })

  it('changing the ranking restarts at page 1 and keeps the page size', async () => {
    renderAt('/screener?page=2&limit=25')
    await screen.findByText('Página 2 de 5')
    fireEvent.change(screen.getByLabelText(/Ordenar|Orden/), { target: { value: 'rsi_desc' } })
    await waitFor(() => expect(screen.getByTestId('url').textContent).toBe('?limit=25&sort=rsi_desc'))
    await screen.findByText('Página 1 de 5')
  })

  it('drops the page from the URL when nothing matches', async () => {
    renderAt('/screener?page=2&rsi_min=99')
    await waitFor(() => expect(screen.getByTestId('url').textContent).toBe('?rsi_min=99'))
    expect(screen.queryByRole('navigation', { name: 'Paginación de candidatos' })).toBeNull()
  })

  it('a stale page past the end jumps to the last page', async () => {
    renderAt('/screener?page=9')
    await screen.findByText('Página 3 de 3')
    expect(screen.getByTestId('url').textContent).toBe('?page=3')
  })
})
