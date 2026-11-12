// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import { CHART_INDICATOR_IDS } from '../../lib/chartIndicators.ts'
import type { ChartIndicatorId } from '../../lib/chartIndicators.ts'
import ChartIndicatorPicker from './ChartIndicatorPicker.tsx'

const NAMES: Record<ChartIndicatorId, string> = {
  bollinger: 'Bandas de Bollinger',
  supertrend: 'Supertrend (10, 3)',
  rsi: 'RSI (14)',
  macd: 'MACD (12, 26, 9)',
  obv: 'OBV',
  adx: 'ADX (+DI / -DI)',
  atr: 'ATR',
  stochastic: 'Estocástico',
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => {
  cleanup()
  window.localStorage.clear()
})

function show(selection: ChartIndicatorId[], onToggle = vi.fn()) {
  render(
    <I18nProvider>
      <ChartIndicatorPicker selection={selection} onToggle={onToggle} />
    </I18nProvider>,
  )
  return onToggle
}

it('renders one button per id, all unpressed with an empty selection', () => {
  show([])
  expect(screen.getAllByRole('button')).toHaveLength(CHART_INDICATOR_IDS.length)
  for (const id of CHART_INDICATOR_IDS) {
    expect(screen.getByRole('button', { name: NAMES[id] }).getAttribute('aria-pressed')).toBe('false')
  }
})

it('reflects the selection in aria-pressed and reports the clicked id', () => {
  const onToggle = show(['rsi', 'obv'])
  expect(screen.getByRole('button', { name: 'RSI (14)' }).getAttribute('aria-pressed')).toBe('true')
  expect(screen.getByRole('button', { name: 'OBV' }).getAttribute('aria-pressed')).toBe('true')
  expect(screen.getByRole('button', { name: 'MACD (12, 26, 9)' }).getAttribute('aria-pressed')).toBe('false')
  expect(screen.getByRole('button', { name: 'Supertrend (10, 3)' }).getAttribute('aria-pressed')).toBe('false')

  fireEvent.click(screen.getByRole('button', { name: 'MACD (12, 26, 9)' }))
  fireEvent.click(screen.getByRole('button', { name: 'Supertrend (10, 3)' }))
  expect(onToggle.mock.calls).toEqual([['macd'], ['supertrend']])
})
