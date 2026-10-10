// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import type { ScreenerCandidate, ScreenerFilters } from '../../lib/api.ts'
import { EMPTY_SCREENER_FILTERS } from '../../lib/screenerFilters.ts'
import CandidateTable from '../screener/CandidateTable.tsx'
import ScreenerFilterPanel from '../screener/ScreenerFilterPanel.tsx'
import PatternPanel from './PatternPanel.tsx'
import { MemoryRouter } from 'react-router'

const PATTERN = {
  type: 'double_bottom',
  status: 'confirmed' as const,
  start_date: '2026-01-05',
  end_date: '2026-02-20',
  breakout_level: 115,
  points: [
    { date: '2026-01-05', price: 100, role: 'left_low' },
    { date: '2026-01-30', price: 115, role: 'peak' },
    { date: '2026-02-20', price: 100.5, role: 'right_low' },
  ],
}

function withI18n(node: React.ReactNode) {
  return render(
    <I18nProvider>
      <MemoryRouter>{node}</MemoryRouter>
    </I18nProvider>,
  )
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => {
  cleanup()
  window.localStorage.clear()
})

describe('PatternPanel', () => {
  it('lists each pattern with status, breakout level and named points, and no target', () => {
    withI18n(<PatternPanel patterns={[PATTERN]} />)
    expect(screen.getByText('Doble suelo')).toBeTruthy()
    expect(screen.getByText('Confirmado')).toBeTruthy()
    expect(screen.getByText('Nivel de ruptura')).toBeTruthy()
    expect(screen.getByText('Cresta')).toBeTruthy()
    expect(document.body.textContent).not.toMatch(/objetivo:|target/i)
    expect(screen.getByText(/no una recomendación ni un objetivo de precio/)).toBeTruthy()
  })

  it('shows an empty state and English labels', () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    withI18n(<PatternPanel patterns={[]} />)
    expect(screen.getByText('No active patterns for this instrument.')).toBeTruthy()
  })
})

describe('Screener pattern controls', () => {
  it('toggles pattern chips and enables the status control only with a pattern selected', () => {
    const onChange = vi.fn()
    const view = (filters: ScreenerFilters) =>
      withI18n(<ScreenerFilterPanel filters={filters} activeCount={0} onChange={onChange} onClear={() => {}} />)

    view(EMPTY_SCREENER_FILTERS)
    const group = screen.getByRole('group', { name: 'Estado del patrón' })
    expect(within(group).getByRole('button', { name: 'Confirmado' }).hasAttribute('disabled')).toBe(true)
    fireEvent.click(screen.getByRole('button', { name: 'Taza con asa' }))
    expect(onChange).toHaveBeenCalledWith({ patterns: ['cup_with_handle'] })
    cleanup()

    view({ ...EMPTY_SCREENER_FILTERS, patterns: ['cup_with_handle'], patternStatus: 'forming' })
    const enabled = screen.getByRole('group', { name: 'Estado del patrón' })
    fireEvent.click(within(enabled).getByRole('button', { name: 'Confirmado' }))
    expect(onChange).toHaveBeenLastCalledWith({ patternStatus: 'confirmed' })
    // Removing the last pattern resets the status so it never filters alone.
    fireEvent.click(screen.getByRole('button', { name: 'Taza con asa' }))
    expect(onChange).toHaveBeenLastCalledWith({ patterns: [], patternStatus: 'any' })
  })

  it('shows pattern badges with their status as text in the candidate list', () => {
    const candidate: ScreenerCandidate = {
      ticker: 'NVDA',
      company: 'NVIDIA',
      sector: 'Tech',
      exchange: 'NASDAQ',
      active: true,
      date: '2026-10-02',
      close: 100,
      change_percent: 1,
      rvol: 1.2,
      rsi14: 55,
      signals: ['golden_cross'],
      patterns: [{ type: 'bull_flag', status: 'forming', breakout_level: 101, end_date: '2026-09-30' }],
    }
    withI18n(<CandidateTable candidates={[candidate]} />)
    expect(screen.getByTestId('candidate-pattern').textContent).toBe('Bandera alcista · En formación')
  })
})
