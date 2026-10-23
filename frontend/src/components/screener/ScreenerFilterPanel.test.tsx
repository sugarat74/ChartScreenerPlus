// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
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

const sectorFieldset = () => screen.queryByRole('group', { name: /Contexto sectorial/ })

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => {
  cleanup()
  window.localStorage.clear()
})

describe('ScreenerFilterPanel sector context (market-context-patterns)', () => {
  it('offers the trend segments and the top-N select to an allowed session', () => {
    const onChange = show({ status: 'allowed', preview: false }, { ...EMPTY_SCREENER_FILTERS, sectorTrend: 'mixed' })

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
    expect(within(sectorFieldset()!).getByText('Vista previa')).toBeTruthy()
  })

  it('shows a dashed sign-in box to a Visitor once the flag is on', () => {
    show({ status: 'signIn', preview: false })

    const fieldset = sectorFieldset()!
    expect(within(fieldset).getByRole('link', { name: 'Iniciar sesión' }).getAttribute('href')).toBe('/login')
    expect(within(fieldset).queryByRole('button')).toBeNull()
    expect(within(fieldset).queryByRole('combobox')).toBeNull()
  })

  it('renders no fieldset while loading, when hidden or without access info', () => {
    for (const access of [{ status: 'loading', preview: false } as const, { status: 'hidden', preview: false } as const, undefined]) {
      show(access)
      expect(sectorFieldset()).toBeNull()
      expect(screen.queryByText('Fuerza relativa del sector')).toBeNull()
      cleanup()
    }
  })

  it('renders in English', () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    show({ status: 'allowed', preview: false })

    const fieldset = screen.getByRole('group', { name: /Sector context/ })
    expect(within(fieldset).getByLabelText('Sector relative strength')).toBeTruthy()
    expect(within(fieldset).getByRole('option', { name: 'Top 3 of 11' })).toBeTruthy()
    expect(within(fieldset).getByRole('button', { name: 'Uptrend' })).toBeTruthy()
  })
})
