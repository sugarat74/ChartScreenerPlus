// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { MarketContextAccess } from '../../auth/useMarketContextAccess.ts'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import { instrumentMarketContextApi } from '../../lib/api.ts'
import type { InstrumentMarketContext } from '../../lib/api.ts'
import { AAPL_CONTEXT } from './instrumentContext.fixture.ts'
import MarketContextStrip from './MarketContextStrip.tsx'
import type { InstrumentMarketContextState } from './useInstrumentMarketContext.ts'

const ADVICE = /compra|comprar|venta|vender|recomend|objetivo|probabilidad|previsi[oó]n|pron[oó]stico|\bbuy\b|\bsell\b|target|recommend|probabilit|forecast|predict/i
const EOD = /\bEOD\b|end[- ]of[- ]day|fin de d[ií]a/i
const FRED = 'This product uses the FRED® API but is not endorsed or certified by the Federal Reserve Bank of St. Louis.'

const ALLOWED: MarketContextAccess = { status: 'allowed', preview: false }

function show(state: InstrumentMarketContextState, access: MarketContextAccess = ALLOWED, onRetry = vi.fn()) {
  return render(
    <I18nProvider>
      <MemoryRouter>
        <MarketContextStrip access={access} state={state} onRetry={onRetry} />
      </MemoryRouter>
    </I18nProvider>,
  )
}

function ready(data: InstrumentMarketContext): InstrumentMarketContextState {
  return { status: 'ready', data }
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => {
  cleanup()
  window.localStorage.clear()
})

describe('MarketContextStrip (Scenario 1)', () => {
  it('shows the sector, trend, rank and regime in Spanish with links and the legal lines', () => {
    show(ready(AAPL_CONTEXT))

    const strip = screen.getByRole('region', { name: 'Contexto de mercado' })
    expect(within(strip).getByText('Datos del 22 oct 2026')).toBeTruthy()
    const items = within(strip).getByRole('list', { name: 'Sector y entorno de mercado de este valor' })
    const text = items.textContent ?? ''
    expect(text).toContain('Sector: Tecnología de la información (XLK)')
    expect(text).toContain('Tendencia del sector▲Alcista')
    expect(text).toContain('Fuerza relativa 1/10')
    expect(text).toContain('Entorno: ● Neutral')
    expect(within(strip).getByRole('link', { name: 'Ver el gráfico de XLK' }).getAttribute('href')).toBe('/instruments/XLK')
    expect(within(strip).getByRole('link', { name: 'Ver contexto de mercado' }).getAttribute('href')).toBe('/mercado')
    expect(within(strip).getByText('Información descriptiva, no es asesoramiento de inversión.')).toBeTruthy()
    const notice = within(strip).getByTestId('strip-fred-notice')
    expect(notice.textContent).toBe(FRED)
    expect(notice.getAttribute('lang')).toBe('en')
    expect(within(strip).queryByText('Vista previa')).toBeNull()
    expect(within(strip).queryByText('Desactualizado')).toBeNull()

    expect(strip.textContent).not.toMatch(ADVICE)
    expect(strip.textContent).not.toMatch(EOD)
  })

  it('renders in English', () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    show(ready(AAPL_CONTEXT))

    const strip = screen.getByRole('region', { name: 'Market context' })
    const text = strip.textContent ?? ''
    expect(text).toContain('Data as of Oct 22, 2026')
    expect(text).toContain('Sector: Information technology (XLK)')
    expect(text).toContain('Sector trend▲Uptrend')
    expect(text).toContain('Relative strength 1/10')
    expect(text).toContain('Regime: ● Neutral')
    expect(within(strip).getByRole('link', { name: 'View market context' })).toBeTruthy()
    expect(within(strip).getByTestId('strip-fred-notice').textContent).toBe(FRED)
    expect(text).not.toMatch(ADVICE)
    expect(text).not.toMatch(EOD)
  })
})

describe('MarketContextStrip partial and stale states (Scenarios 3 and 5)', () => {
  it('a stock without a sector ETF still shows the regime', () => {
    show(ready({ ...AAPL_CONTEXT, ticker: 'ZZZ', sector: null }))

    const strip = screen.getByRole('region', { name: 'Contexto de mercado' })
    expect(strip.textContent).toContain('Sector: sin ETF sectorial asignado')
    expect(strip.textContent).toContain('Entorno: ● Neutral')
    expect(strip.textContent).not.toContain('Fuerza relativa')
    expect(within(strip).getByText('Datos del 22 oct 2026')).toBeTruthy()
  })

  it('a mixed sector without a rank', () => {
    show(ready({ ...AAPL_CONTEXT, sector: { ...AAPL_CONTEXT.sector!, etf: 'XLU', sector_key: 'utilities', trend: 'mixed', rs_rank: null } }))

    const text = screen.getByRole('region', { name: 'Contexto de mercado' }).textContent ?? ''
    expect(text).toContain('Sector: Servicios públicos (XLU)')
    expect(text).toContain('◆Mixta')
    expect(text).toContain('Fuerza relativa: sin datos')
  })

  it('a null trend and no regime: no FRED notice', () => {
    show(ready({ ...AAPL_CONTEXT, sector: { ...AAPL_CONTEXT.sector!, trend: null }, regime: null }))

    const strip = screen.getByRole('region', { name: 'Contexto de mercado' })
    expect(strip.textContent).toContain('Tendencia del sectorSin datos')
    expect(strip.textContent).toContain('Entorno: sin datos')
    expect(within(strip).queryByTestId('strip-fred-notice')).toBeNull()
    expect(within(strip).getByText('Información descriptiva, no es asesoramiento de inversión.')).toBeTruthy()
  })

  it('stale items carry the badge and a regime on another date shows its own date', () => {
    show(ready({
      ...AAPL_CONTEXT,
      sector: { ...AAPL_CONTEXT.sector!, date: '2026-10-14', stale: true },
      regime: { date: '2026-10-21', stale: false, regime: 'adverse' },
    }))

    const strip = screen.getByRole('region', { name: 'Contexto de mercado' })
    expect(within(strip).getByText('Datos del 14 oct 2026')).toBeTruthy()
    expect(within(strip).getAllByText('Desactualizado')).toHaveLength(1)
    expect(strip.textContent).toContain('Entorno: ▼ Adverso(datos del 21 oct 2026)')
  })

  it('an insufficient regime is shown as such', () => {
    show(ready({ ...AAPL_CONTEXT, regime: { ...AAPL_CONTEXT.regime!, regime: 'insufficient', stale: true } }))

    const strip = screen.getByRole('region', { name: 'Contexto de mercado' })
    expect(strip.textContent).toContain('Entorno: ? Sin datos suficientes')
    expect(within(strip).getAllByText('Desactualizado')).toHaveLength(1)
  })
})

describe('MarketContextStrip access and request states (Scenario 2)', () => {
  it('marks an Admin preview', () => {
    show(ready(AAPL_CONTEXT), { status: 'allowed', preview: true })
    expect(within(screen.getByRole('region', { name: 'Contexto de mercado' })).getByText('Vista previa')).toBeTruthy()
  })

  it('a Visitor with the flag on gets the real frame disabled with the reason and one sign-in link, no figures', () => {
    show({ status: 'off' }, { status: 'signIn', preview: false })

    const strip = screen.getByRole('region', { name: 'Contexto de mercado' })
    expect(strip.textContent).toContain('Sector: —')
    expect(strip.textContent).toContain('Entorno: —')
    expect(strip.closest('fieldset')?.hasAttribute('disabled')).toBe(true)
    expect(screen.getByText('Disponible al iniciar sesión')).toBeTruthy()
    const links = screen.getAllByRole('link')
    expect(links).toHaveLength(1)
    expect(links[0].getAttribute('href')).toMatch(/^\/login/)
    expect(screen.queryByText('Ver contexto de mercado')).toBeNull()
  })

  it('shows the dimmed frame with no reason while access loads, and nothing for a hidden user', () => {
    show({ status: 'off' }, { status: 'loading', preview: false })
    expect(screen.getByRole('region', { name: 'Contexto de mercado' })).toBeTruthy()
    expect(screen.queryByText('Disponible al iniciar sesión')).toBeNull()
    expect(screen.queryByRole('link')).toBeNull()
    cleanup()
    const hidden = show({ status: 'off' }, { status: 'hidden', preview: false })
    expect(hidden.container.textContent).toBe('')
  })

  it('a request in flight is a status line; an API 404 shows nothing; an API 401 the sign-in box', () => {
    show({ status: 'loading' })
    expect(screen.getByRole('status').textContent).toBe('Cargando el contexto de mercado…')
    // Announced: the live line sits outside the aria-busy frame.
    expect(screen.getByRole('status').closest('[aria-busy="true"]')).toBeNull()
    cleanup()

    const none = show({ status: 'none' })
    expect(none.container.textContent).toBe('')
    cleanup()

    show({ status: 'signIn' })
    expect(screen.getByRole('link', { name: 'Iniciar sesión' })).toBeTruthy()
  })

  it('keeps the same outer structure for a Visitor, a request in flight and the data (no shift at login)', () => {
    const shape = (root: HTMLElement) => {
      const slot = within(root).getByTestId('registered-only-reason')
      const strip = within(root).getByRole('region', { name: 'Contexto de mercado' })
      const wrapper = slot.parentElement as HTMLElement
      return {
        slotFirst: wrapper.firstElementChild === slot,
        slotEmpty: slot.textContent === '',
        slotClass: slot.className,
        wrapperClass: wrapper.className.replace('opacity-60', '').trim(),
        fieldset: strip.closest('fieldset') !== null,
        children: Array.from(strip.children).map((child) => (child.tagName === 'A' ? 'SPAN' : child.tagName)),
        listItems: strip.querySelectorAll('li').length,
        footer: strip.querySelector('footer') !== null,
      }
    }

    const guest = show({ status: 'off' }, { status: 'signIn', preview: false })
    const guestShape = shape(guest.container)
    expect(guestShape.slotEmpty).toBe(false)
    cleanup()

    const loading = show({ status: 'loading' })
    const loadingShape = shape(loading.container)
    cleanup()

    const data = show(ready(AAPL_CONTEXT))
    const dataShape = shape(data.container)

    expect(loadingShape.slotEmpty).toBe(true)
    expect(dataShape.slotEmpty).toBe(true)
    for (const other of [loadingShape, dataShape]) {
      expect(other.slotFirst).toBe(true)
      expect(other.slotClass).toBe(guestShape.slotClass)
      expect(other.wrapperClass).toBe(guestShape.wrapperClass)
      expect(other.fieldset).toBe(true)
      expect(other.listItems).toBe(guestShape.listItems)
      expect(other.footer).toBe(true)
    }
    // The link row is a placeholder span until the data brings the real link. Skeleton and Visitor frames share the very same tree (title row, list, link slot, footer).
    expect(loadingShape.children).toEqual(guestShape.children)
    expect(guestShape.children).toEqual(dataShape.children)
  })

  it('an error offers a retry', () => {
    const onRetry = vi.fn()
    show({ status: 'error' }, ALLOWED, onRetry)

    const alert = screen.getByRole('alert')
    expect(alert.textContent).toContain('No se pudo cargar el contexto de mercado. El gráfico no se ve afectado.')
    fireEvent.click(within(alert).getByRole('button', { name: 'Reintentar' }))
    expect(onRetry).toHaveBeenCalledTimes(1)
  })
})

describe('instrumentMarketContextApi', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('requests the stock endpoint with the session cookie', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify(AAPL_CONTEXT), { status: 200, headers: { 'Content-Type': 'application/json' } }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(instrumentMarketContextApi.get('AAPL')).resolves.toEqual(AAPL_CONTEXT)
    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit]
    expect(url).toBe('/api/instruments/AAPL/market-context')
    expect(init.credentials).toBe('include')
  })
})
