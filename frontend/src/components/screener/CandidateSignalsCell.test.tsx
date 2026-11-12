// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import type { ScreenerCandidate } from '../../lib/api.ts'
import CandidateTable from './CandidateTable.tsx'

const base: ScreenerCandidate = {
  ticker: 'AAA', company: 'Alpha', sector: null, industry: null, country: null, exchange: 'NYSE', active: true,
  date: '2026-10-23', close: 100, change_percent: 1, rvol: 1.2, rsi14: 55,
  signals: [], patterns: [],
}
const none = { ...base, ticker: 'NONE' }
const one = { ...base, ticker: 'ONE', signals: ['golden_cross'] } as ScreenerCandidate
const onePattern = {
  ...base, ticker: 'ONEP',
  patterns: [{ type: 'bull_flag', status: 'forming', breakout_level: 1, end_date: '2026-10-01' }],
} as ScreenerCandidate
const many = {
  ...base, ticker: 'MANY', signals: ['golden_cross', 'rsi_oversold'],
  patterns: [{ type: 'double_top', status: 'confirmed', breakout_level: 1, end_date: '2026-10-01' }],
} as ScreenerCandidate
const many2 = { ...base, ticker: 'TWO', signals: ['golden_cross', 'death_cross'] } as ScreenerCandidate

function show(candidates = [none, one, onePattern, many, many2]) {
  return render(<I18nProvider><MemoryRouter><CandidateTable candidates={candidates} /></MemoryRouter></I18nProvider>)
}
const triggers = () => screen.getAllByTestId('candidate-signals-trigger')

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => { cleanup(); window.localStorage.clear() })

describe('Candidate signals cell (screener-signals-cell-compact)', () => {
  it('renders a dash for none, the chip for one item and a single trigger for two or more', () => {
    show()
    const cellOf = (ticker: string) => within(screen.getByRole('row', { name: new RegExp('^' + ticker + 'NYSE') })).getAllByRole('cell').at(-1)!
    expect(cellOf('NONE').textContent).toBe('—')
    expect(cellOf('ONE').textContent).toBe('Cruce dorado')
    expect(cellOf('ONE').querySelector('button')).toBeNull()
    expect(cellOf('ONEP').textContent).toBe('Bandera alcista · En formación')
    expect(screen.getAllByTestId('candidate-pattern')).toHaveLength(1)
    expect(triggers()).toHaveLength(2)
    expect(triggers()[0].textContent).toBe('3 señales▾')
    expect(triggers()[1].textContent).toBe('2 señales▾')
    expect(screen.queryByTestId('candidate-signals-panel')).toBeNull()
  })

  it('labels the trigger in English', () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    show()
    expect(triggers()[0].textContent).toBe('3 signals▾')
  })

  it('opens a panel with every signal and pattern chip, wired with aria-expanded/aria-controls', () => {
    show()
    const trigger = triggers()[0]
    expect(trigger.getAttribute('aria-expanded')).toBe('false')
    fireEvent.click(trigger)
    const panel = screen.getByTestId('candidate-signals-panel')
    expect(trigger.getAttribute('aria-expanded')).toBe('true')
    expect(trigger.getAttribute('aria-controls')).toBe(panel.id)
    expect(panel.getAttribute('role')).toBe('group')
    expect(panel.parentElement).toBe(document.body)
    expect(within(panel).getByTestId('candidate-pattern').textContent).toBe('Doble techo · Confirmado')
    expect(within(panel).getByText('Cruce dorado')).toBeTruthy()
    fireEvent.click(trigger)
    expect(screen.queryByTestId('candidate-signals-panel')).toBeNull()
  })

  it('closes on Escape and returns focus to the trigger', () => {
    show()
    fireEvent.click(triggers()[0])
    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.queryByTestId('candidate-signals-panel')).toBeNull()
    expect(document.activeElement).toBe(triggers()[0])
  })

  it('closes on outside press but not on a press inside the panel', () => {
    show()
    fireEvent.click(triggers()[0])
    fireEvent.pointerDown(within(screen.getByTestId('candidate-signals-panel')).getByText('Cruce dorado'))
    expect(screen.getByTestId('candidate-signals-panel')).toBeTruthy()
    fireEvent.pointerDown(document.body)
    expect(screen.queryByTestId('candidate-signals-panel')).toBeNull()
  })

  it('closes on page scroll and on resize', () => {
    show()
    fireEvent.click(triggers()[0])
    fireEvent.scroll(window)
    expect(screen.queryByTestId('candidate-signals-panel')).toBeNull()
    fireEvent.click(triggers()[0])
    fireEvent.resize(window)
    expect(screen.queryByTestId('candidate-signals-panel')).toBeNull()
  })

  it('keeps only one panel open at a time', () => {
    show()
    fireEvent.click(triggers()[0])
    fireEvent.click(triggers()[1])
    expect(screen.getAllByTestId('candidate-signals-panel')).toHaveLength(1)
    expect(triggers()[0].getAttribute('aria-expanded')).toBe('false')
    expect(triggers()[1].getAttribute('aria-expanded')).toBe('true')
  })
})
