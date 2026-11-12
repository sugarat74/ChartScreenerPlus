// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { MarketContextAccess } from '../../auth/useMarketContextAccess.ts'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import type { ScreenerFilters } from '../../lib/api.ts'
import { EMPTY_SCREENER_FILTERS } from '../../lib/screenerFilters.ts'
import ScreenerFilterPanel from './ScreenerFilterPanel.tsx'

const ADVICE = /compra|comprar|venta|vender|recomend|objetivo|probabilidad|\bbuy\b|\bsell\b|target|recommend|probabilit|forecast|predict/i

function show(access: MarketContextAccess | undefined, filters: ScreenerFilters = EMPTY_SCREENER_FILTERS, onChange = vi.fn()) {
  render(
    <I18nProvider>
      <MemoryRouter>
        <ScreenerFilterPanel filters={filters} activeCount={0} access={access} onChange={onChange} onClear={vi.fn()} />
      </MemoryRouter>
    </I18nProvider>,
  )
  return onChange
}

// screener-filter-panel-compact: the sector editor lives in the inline card, so
// the existing sector tests open it from its toolbar trigger first (the only
// change to them; assertions are untouched).
function openSector() {
  fireEvent.click(screen.getByRole('button', { name: /^Sector/ }))
}

const sectorFieldset = () => screen.queryByRole('group', { name: /Contexto sectorial/ })

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => {
  cleanup()
  window.localStorage.clear()
})

describe('ScreenerFilterPanel sector context (market-context-patterns)', () => {
  it('offers the trend segments and the top-N select to an allowed session', () => {
    const onChange = show({ status: 'allowed', preview: false }, { ...EMPTY_SCREENER_FILTERS, sectorTrend: 'mixed' })
    openSector()

    const fieldset = sectorFieldset()
    expect(fieldset).not.toBeNull()
    const trend = within(fieldset!).getByRole('group', { name: 'Tendencia del ETF sectorial de la acción' })
    const buttons = within(trend).getAllByRole('button')
    expect(buttons.map((button) => button.textContent)).toEqual(['Cualquiera', '▲ Alcista', '◆ Mixta', '▼ Bajista'])
    expect(buttons.map((button) => button.getAttribute('aria-pressed'))).toEqual(['false', 'false', 'true', 'false'])
    fireEvent.click(buttons[1])
    expect(onChange).toHaveBeenLastCalledWith({ sectorTrend: 'uptrend' })
    fireEvent.click(buttons[0])
    expect(onChange).toHaveBeenLastCalledWith({ sectorTrend: null })

    const select = within(fieldset!).getByLabelText('Fuerza relativa del sector') as HTMLSelectElement
    const options = within(select).getAllByRole('option').map((option) => option.textContent)
    expect(options).toHaveLength(12)
    expect(options[0]).toBe('Todos los sectores')
    expect(options[1]).toBe('Top 1 de 11')
    expect(options[11]).toBe('Top 11 de 11')
    fireEvent.change(select, { target: { value: '3' } })
    expect(onChange).toHaveBeenLastCalledWith({ sectorRsRankMax: 3 })
    fireEvent.change(select, { target: { value: '' } })
    expect(onChange).toHaveBeenLastCalledWith({ sectorRsRankMax: null })

    expect(within(fieldset!).getByText('Según el ETF sectorial de cada acción y la última sesión.')).toBeTruthy()
    expect(within(fieldset!).queryByText('Vista previa')).toBeNull()
    expect(fieldset!.textContent).not.toMatch(ADVICE)
  })

  it('marks an Admin preview', () => {
    show({ status: 'allowed', preview: true })
    openSector()
    expect(within(sectorFieldset()!).getByText('Vista previa')).toBeTruthy()
  })

  it('shows a Visitor the dimmed Sector trigger and a read-only editor with the sign-in reason once the flag is on', () => {
    const onChange = show({ status: 'signIn', preview: false })
    const trigger = screen.getByRole('button', { name: /^Sector/ })
    expect(trigger.className).toContain('opacity-60')
    openSector()

    const fieldset = sectorFieldset()!
    expect(screen.getByTestId('registered-only-reason').textContent).toContain('Disponible al iniciar sesión')
    const link = within(screen.getByTestId('registered-only-reason')).getByRole('link', { name: 'Iniciar sesión' })
    expect(link.getAttribute('href')).toBe('/login')
    const select = within(fieldset).getByLabelText('Fuerza relativa del sector') as HTMLSelectElement
    expect(select.disabled).toBe(true)
    for (const button of within(fieldset).getAllByRole('button')) {
      expect((button as HTMLButtonElement).disabled).toBe(true)
      fireEvent.click(button)
    }
    expect(onChange).not.toHaveBeenCalled()
  })

  it('renders no sector control while loading, when hidden or without access info', () => {
    for (const access of [{ status: 'loading', preview: false } as const, { status: 'hidden', preview: false } as const, undefined]) {
      show(access)
      expect(screen.queryByRole('button', { name: /^Sector/ })).toBeNull()
      expect(sectorFieldset()).toBeNull()
      expect(screen.queryByText('Fuerza relativa del sector')).toBeNull()
      cleanup()
    }
  })

  it('renders in English', () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    show({ status: 'allowed', preview: false })
    openSector()

    const fieldset = screen.getByRole('group', { name: /Sector context/ })
    expect(within(fieldset).getByLabelText('Sector relative strength')).toBeTruthy()
    expect(within(fieldset).getByRole('option', { name: 'Top 3 of 11' })).toBeTruthy()
    expect(within(fieldset).getByRole('button', { name: 'Uptrend' })).toBeTruthy()
  })
})

const ALL_FILTERS: ScreenerFilters = {
  ...EMPTY_SCREENER_FILTERS,
  signals: ['golden_cross', 'rsi_oversold'],
  rsiMin: 30,
  rsiMax: 70,
  minRvol: 1.5,
  priceAboveSma200: true,
  maCross: 'bullish',
  patterns: ['double_bottom', 'bull_flag'],
  patternStatus: 'confirmed',
  sectorTrend: 'uptrend',
  sectorRsRankMax: 3,
}

function showWith(
  filters: ScreenerFilters,
  extra: { activeCount?: number; onClear?: () => void; access?: MarketContextAccess } = {},
) {
  const onChange = vi.fn()
  render(
    <I18nProvider>
      <MemoryRouter>
        <ScreenerFilterPanel
          filters={filters}
          activeCount={extra.activeCount ?? 0}
          access={extra.access}
          onChange={onChange}
          onClear={extra.onClear ?? vi.fn()}
        />
      </MemoryRouter>
    </I18nProvider>,
  )
  return onChange
}

const trigger = (name: RegExp) => screen.getByRole('button', { name })
const patternChips = () =>
  screen.getAllByRole('button').filter((button) => button.closest('[role=group][aria-labelledby^=pattern-group]'))

describe('ScreenerFilterPanel compact toolbar (screener-filter-panel-compact)', () => {
  it('shows every value without opening a card', () => {
    showWith(ALL_FILTERS, { access: { status: 'allowed', preview: false }, activeCount: 9 })

    expect(trigger(/^Señales · 2/)).toBeTruthy()
    expect(trigger(/^RSI · 30-70/)).toBeTruthy()
    expect(trigger(/^Patrones · 2 · Confirmado/)).toBeTruthy()
    expect(trigger(/^Sector · ▲ Alcista · Top 3/)).toBeTruthy()
    expect((screen.getByLabelText('RVOL mínimo') as HTMLSelectElement).value).toBe('1.5')
    expect((screen.getByLabelText(/Cruce de medias/) as HTMLSelectElement).value).toBe('bullish')
    expect(screen.getByRole('button', { name: 'Precio > SMA 200' }).getAttribute('aria-pressed')).toBe('true')
    expect(document.getElementById('screener-criteria-card')).toBeNull()
    expect(screen.getByText('9 filtro(s) activo(s)')).toBeTruthy()
  })

  it('summarises RSI with only a minimum, only a maximum or none', () => {
    showWith({ ...EMPTY_SCREENER_FILTERS, rsiMin: 30 })
    expect(trigger(/^RSI · ≥ 30/)).toBeTruthy()
    cleanup()
    showWith({ ...EMPTY_SCREENER_FILTERS, rsiMax: 70 })
    expect(trigger(/^RSI · ≤ 70/)).toBeTruthy()
    cleanup()
    showWith(EMPTY_SCREENER_FILTERS)
    expect(trigger(/^RSI$/)).toBeTruthy()
    expect(trigger(/^Señales$/)).toBeTruthy()
    expect(trigger(/^Patrones$/)).toBeTruthy()
  })

  it('wires aria-expanded/aria-controls and keeps one card open at a time', () => {
    showWith(EMPTY_SCREENER_FILTERS)
    const signals = trigger(/^Señales/)
    const patterns = trigger(/^Patrones/)
    expect(signals.getAttribute('aria-expanded')).toBe('false')
    expect(signals.getAttribute('aria-controls')).toBe('screener-criteria-card')

    fireEvent.click(signals)
    expect(signals.getAttribute('aria-expanded')).toBe('true')
    expect(document.getElementById('screener-criteria-card')?.getAttribute('aria-labelledby')).toBe(signals.id)
    expect(screen.getByRole('group', { name: /Señales técnicas/ })).toBeTruthy()

    fireEvent.click(patterns)
    expect(signals.getAttribute('aria-expanded')).toBe('false')
    expect(patterns.getAttribute('aria-expanded')).toBe('true')
    expect(document.querySelectorAll('#screener-criteria-card')).toHaveLength(1)
    expect(screen.queryByRole('group', { name: /Señales técnicas/ })).toBeNull()

    fireEvent.click(patterns)
    expect(document.getElementById('screener-criteria-card')).toBeNull()
  })

  it('toggles signals with the same patch as before and Esc returns focus to the trigger', () => {
    const onChange = showWith({ ...EMPTY_SCREENER_FILTERS, signals: ['golden_cross'] })
    const signals = trigger(/^Señales/)
    fireEvent.click(signals)
    const card = screen.getByRole('group', { name: /Señales técnicas/ })
    const chips = within(card).getAllByRole('button')
    expect(chips).toHaveLength(15)
    const pressed = chips.find((chip) => chip.getAttribute('aria-pressed') === 'true')!
    fireEvent.click(pressed)
    expect(onChange).toHaveBeenLastCalledWith({ signals: [] })
    const unpressed = chips.find((chip) => chip.getAttribute('aria-pressed') === 'false')!
    fireEvent.click(unpressed)
    expect(onChange.mock.lastCall![0].signals).toHaveLength(2)

    unpressed.focus()
    fireEvent.keyDown(unpressed, { key: 'Escape' })
    expect(document.getElementById('screener-criteria-card')).toBeNull()
    expect(document.activeElement).toBe(signals)
  })

  it('commits the RSI draft on Enter, on Esc and when another group opens', () => {
    const onChange = showWith(EMPTY_SCREENER_FILTERS)
    const rsi = trigger(/^RSI/)
    fireEvent.click(rsi)
    const min = screen.getByLabelText('Mín') as HTMLInputElement
    min.focus()
    fireEvent.change(min, { target: { value: '35' } })
    expect(onChange).not.toHaveBeenCalled()
    fireEvent.keyDown(min, { key: 'Enter' })
    expect(onChange).toHaveBeenCalledTimes(1)
    expect(onChange).toHaveBeenLastCalledWith({ rsiMin: 35 })

    const max = screen.getByLabelText('Máx') as HTMLInputElement
    max.focus()
    fireEvent.change(max, { target: { value: '65' } })
    fireEvent.keyDown(max, { key: 'Escape' })
    expect(onChange).toHaveBeenCalledTimes(2)
    expect(onChange).toHaveBeenLastCalledWith({ rsiMax: 65 })
    expect(document.activeElement).toBe(rsi)

    fireEvent.click(rsi)
    const min2 = screen.getByLabelText('Mín') as HTMLInputElement
    min2.focus()
    fireEvent.change(min2, { target: { value: '20' } })
    fireEvent.click(trigger(/^Patrones/))
    expect(onChange).toHaveBeenCalledTimes(3)
    expect(onChange).toHaveBeenLastCalledWith({ rsiMin: 20 })
  })

  it('commits an RSI draft once when its own trigger closes the card', () => {
    const onChange = showWith(EMPTY_SCREENER_FILTERS)
    const rsi = trigger(/^RSI/)
    fireEvent.click(rsi)
    const max = screen.getByLabelText('Máx') as HTMLInputElement
    max.focus()
    fireEvent.change(max, { target: { value: '80' } })
    act(() => {
      fireEvent.click(rsi)
    })
    expect(onChange).toHaveBeenCalledTimes(1)
    expect(onChange).toHaveBeenLastCalledWith({ rsiMax: 80 })
    expect(document.getElementById('screener-criteria-card')).toBeNull()
  })

  it('RVOL and MA cross selects send the same patches (Todos and Cualquiera -> null)', () => {
    const onChange = showWith({ ...EMPTY_SCREENER_FILTERS, minRvol: 2 })
    const rvol = screen.getByLabelText('RVOL mínimo') as HTMLSelectElement
    expect(within(rvol).getAllByRole('option').map((option) => option.textContent)).toEqual([
      'RVOL: Todos',
      'RVOL ≥ 1,0x',
      'RVOL ≥ 1,5x',
      'RVOL ≥ 2,0x',
      'RVOL ≥ 3,0x',
    ])
    fireEvent.change(rvol, { target: { value: '3' } })
    expect(onChange).toHaveBeenLastCalledWith({ minRvol: 3 })
    fireEvent.change(rvol, { target: { value: '' } })
    expect(onChange).toHaveBeenLastCalledWith({ minRvol: null })

    const cross = screen.getByLabelText(/Cruce de medias/) as HTMLSelectElement
    expect(within(cross).getAllByRole('option').map((option) => option.textContent)).toEqual([
      'Cruce: Cualquiera',
      'Cruce: Alcista',
      'Cruce: Bajista',
    ])
    fireEvent.change(cross, { target: { value: 'bullish' } })
    expect(onChange).toHaveBeenLastCalledWith({ maCross: 'bullish' })
    fireEvent.change(cross, { target: { value: 'bearish' } })
    expect(onChange).toHaveBeenLastCalledWith({ maCross: 'bearish' })
    fireEvent.change(cross, { target: { value: '' } })
    expect(onChange).toHaveBeenLastCalledWith({ maCross: null })
  })

  it('keeps a non-preset RVOL from the URL selected', () => {
    showWith({ ...EMPTY_SCREENER_FILTERS, minRvol: 2.5 })
    expect((screen.getByLabelText('RVOL mínimo') as HTMLSelectElement).value).toBe('2.5')
  })

  it('toggles Price > SMA 200 both ways', () => {
    const on = showWith(EMPTY_SCREENER_FILTERS)
    fireEvent.click(screen.getByRole('button', { name: 'Precio > SMA 200' }))
    expect(on).toHaveBeenLastCalledWith({ priceAboveSma200: true })
    cleanup()
    const off = showWith({ ...EMPTY_SCREENER_FILTERS, priceAboveSma200: true })
    fireEvent.click(screen.getByRole('button', { name: 'Precio > SMA 200' }))
    expect(off).toHaveBeenLastCalledWith({ priceAboveSma200: false })
  })

  it('patterns: status disabled with none, toggle adds, last removal resets the status', () => {
    const none = showWith(EMPTY_SCREENER_FILTERS)
    fireEvent.click(trigger(/^Patrones/))
    const status = screen.getByRole('group', { name: 'Estado del patrón' })
    expect(within(status).getAllByRole('button').every((button) => (button as HTMLButtonElement).disabled)).toBe(true)
    fireEvent.click(patternChips()[0])
    expect(none.mock.lastCall![0].patterns).toHaveLength(1)
    cleanup()

    const some = showWith({ ...EMPTY_SCREENER_FILTERS, patterns: ['double_bottom'], patternStatus: 'forming' })
    fireEvent.click(trigger(/^Patrones/))
    fireEvent.click(patternChips().find((chip) => chip.getAttribute('aria-pressed') === 'true')!)
    expect(some).toHaveBeenLastCalledWith({ patterns: [], patternStatus: 'any' })
  })

  it('header count, Clear filters and its disabled state', () => {
    const onClear = vi.fn()
    showWith(EMPTY_SCREENER_FILTERS, { onClear })
    expect((screen.getByRole('button', { name: 'Limpiar filtros' }) as HTMLButtonElement).disabled).toBe(true)
    expect(screen.getByText('Sin filtros activos')).toBeTruthy()
    cleanup()
    showWith(ALL_FILTERS, { onClear, activeCount: 3 })
    fireEvent.click(screen.getByRole('button', { name: 'Limpiar filtros' }))
    expect(onClear).toHaveBeenCalledTimes(1)
    expect(screen.getByText('3 filtro(s) activo(s)')).toBeTruthy()
  })

  it('renders the toolbar in English without advice wording', () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    const { container } = render(
      <I18nProvider>
        <MemoryRouter>
          <ScreenerFilterPanel
            filters={ALL_FILTERS}
            activeCount={2}
            access={{ status: 'allowed', preview: false }}
            onChange={vi.fn()}
            onClear={vi.fn()}
          />
        </MemoryRouter>
      </I18nProvider>,
    )
    expect(screen.getByRole('button', { name: /^Signals · 2/ })).toBeTruthy()
    expect(screen.getByRole('button', { name: /^Patterns · 2 · Confirmed/ })).toBeTruthy()
    expect(screen.getByRole('button', { name: /^Sector · ▲ Uptrend · Top 3/ })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Clear filters' })).toBeTruthy()
    expect(container.textContent).not.toMatch(ADVICE)
  })
})
