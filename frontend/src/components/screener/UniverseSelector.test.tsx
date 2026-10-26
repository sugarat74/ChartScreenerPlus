// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import type { ScreenerUniverse } from '../../lib/api.ts'
import UniverseSelector from './UniverseSelector.tsx'

const UNIVERSES: ScreenerUniverse[] = [
  { slug: 'sp500', name: 'S&P 500', market: 'US', currency: 'USD', members: 500, latest_date: '2026-10-23', is_default: true },
  { slug: 'ibex35', name: 'IBEX 35', market: 'XMAD', currency: 'EUR', members: 35, latest_date: '2026-10-22', is_default: false },
  { slug: 'dax', name: 'DAX', market: 'XETR', currency: 'EUR', members: 40, latest_date: null, is_default: false },
]

afterEach(() => {
  cleanup()
  window.localStorage.clear()
})

function show(locale: 'es' | 'en', universes = UNIVERSES, selected = 'sp500', onSelect = vi.fn()) {
  window.localStorage.setItem(LOCALE_STORAGE_KEY, locale)
  render(
    <I18nProvider>
      <UniverseSelector universes={universes} selected={selected} onSelect={onSelect} />
    </I18nProvider>,
  )
  return onSelect
}

describe('UniverseSelector', () => {
  it('renders the API list in order with market label and data date in Spanish', () => {
    show('es')

    const group = screen.getByRole('group', { name: 'Universo' })
    const buttons = within(group).getAllByRole('button')
    expect(buttons.map((button) => button.querySelector('span')?.textContent)).toEqual(['S&P 500', 'IBEX 35', 'DAX'])
    expect(buttons[0].textContent).toContain('EE. UU.')
    expect(buttons[0].textContent).toContain('Datos del')
    expect(buttons[1].textContent).toContain('Bolsa de Madrid')
    expect(buttons[2].textContent).toContain('Xetra')
    expect(buttons[2].textContent).toContain('Sin datos todavía')
    expect(buttons[0].getAttribute('aria-pressed')).toBe('true')
    expect(buttons[1].getAttribute('aria-pressed')).toBe('false')
  })

  it('uses English labels and never the old DAX 40 name', () => {
    show('en')

    const group = screen.getByRole('group', { name: 'Universe' })
    const text = group.textContent ?? ''
    expect(text).toContain('United States')
    expect(text).toContain('Madrid Stock Exchange')
    expect(text).toContain('Data as of')
    expect(text).toContain('No data yet')
    expect(document.body.textContent).not.toContain('DAX 40')
    expect(document.body.textContent).not.toMatch(/EOD|end of day/i)
  })

  it('reports the chosen universe from the segments and from the phone select', () => {
    const onSelect = show('en')

    fireEvent.click(screen.getByRole('button', { name: /IBEX 35/ }))
    expect(onSelect).toHaveBeenLastCalledWith(UNIVERSES[1])

    const select = screen.getByLabelText('Universe', { selector: 'select' }) as HTMLSelectElement
    expect(select.value).toBe('sp500')
    expect(Array.from(select.options).map((option) => option.value)).toEqual(['sp500', 'ibex35', 'dax'])
    fireEvent.change(select, { target: { value: 'dax' } })
    expect(onSelect).toHaveBeenLastCalledWith(UNIVERSES[2])
  })

  it('renders nothing for a single universe', () => {
    show('es', [UNIVERSES[0]])

    expect(screen.queryByRole('group')).toBeNull()
    expect(screen.queryByRole('combobox')).toBeNull()
  })
})
