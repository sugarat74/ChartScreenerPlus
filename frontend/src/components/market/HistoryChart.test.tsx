// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import type { MarketHistory } from '../../lib/api.ts'
import HistoryChart from './HistoryChart.tsx'

function history(overrides: Partial<MarketHistory> = {}): MarketHistory {
  return {
    metric: 'regime_score',
    range: '1y',
    disabled: false,
    from: '2025-10-22',
    to: '2026-10-22',
    first_date: '2025-10-22',
    points: [],
    ...overrides,
  }
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => {
  cleanup()
  window.localStorage.clear()
})

describe('HistoryChart', () => {
  function renderChart(data: MarketHistory, reference: number[] = []) {
    return render(
      <I18nProvider>
        <HistoryChart history={data} metricLabel="Puntuación del entorno" reference={reference} formatValue={(value) => String(value)} />
      </I18nProvider>,
    )
  }

  it('breaks the line at null values and draws labelled reference lines', () => {
    renderChart(
      history({
        from: '2025-10-22',
        to: '2026-10-22',
        points: [
          { date: '2025-10-22', value: 1 },
          { date: '2025-12-01', value: 2 },
          { date: '2026-01-05', value: null },
          { date: '2026-04-01', value: 3 },
          { date: '2026-10-22', value: -1 },
        ],
      }),
      [-3, 0, 3],
    )

    const svg = screen.getByRole('img')
    expect(svg.getAttribute('viewBox')).toBe('0 0 640 180')
    expect(svg.getAttribute('aria-label')).toBe(
      'Evolución de Puntuación del entorno del 22 oct 2025 al 22 oct 2026: mínimo -1, máximo 3, último -1',
    )
    const path = screen.getByTestId('history-line').getAttribute('d')!
    // Two segments: a new "M" after the gap.
    expect(path.match(/M/g)).toHaveLength(2)
    expect(path.match(/L/g)).toHaveLength(2)
    const references = svg.querySelectorAll('[data-reference]')
    expect([...references].map((node) => node.getAttribute('data-reference'))).toEqual(['-3', '0', '3'])
    expect([...references].every((node) => node.querySelector('line')?.getAttribute('stroke-dasharray') === '4 4')).toBe(true)
    expect(svg.querySelector('circle')).not.toBeNull()
  })

  it('renders nothing without values', () => {
    const { container } = renderChart(history({ points: [{ date: '2026-01-05', value: null }] }))
    expect(container.querySelector('svg')).toBeNull()
  })
})

describe('HistoryChart labels stay inside the viewBox', () => {
  it('draws long net-liquidity min/max labels inside the plot, start-anchored', () => {
    const billions = (value: number) => `${new Intl.NumberFormat('es-ES', { maximumFractionDigits: 0 }).format(value)} mil M USD`
    render(
      <I18nProvider>
        <HistoryChart
          history={history({
            metric: 'net_liquidity',
            points: [
              { date: '2025-10-22', value: 5800 },
              { date: '2026-04-22', value: 6300 },
              { date: '2026-10-21', value: 6130 },
            ],
          })}
          metricLabel="Liquidez neta (mil M USD)"
          formatValue={billions}
        />
      </I18nProvider>,
    )

    const svg = screen.getByRole('img')
    const [, , width] = svg.getAttribute('viewBox')!.split(' ').map(Number)
    for (const name of ['max', 'min']) {
      const label = svg.querySelector(`[data-label="${name}"]`)!
      // Start-anchored inside the plot: the text grows to the right, never past x = 0.
      expect(label.getAttribute('text-anchor')).toBe('start')
      expect(Number(label.getAttribute('x'))).toBeGreaterThan(0)
      // ~7 px per mono character at font-size 11 still fits in the viewBox.
      expect(Number(label.getAttribute('x')) + label.textContent!.length * 7).toBeLessThan(width)
    }
    expect(svg.querySelector('[data-label="max"]')!.textContent).toBe('6300 mil M USD')
    // The summary is unchanged.
    expect(svg.getAttribute('aria-label')).toBe(
      'Evolución de Liquidez neta (mil M USD) del 22 oct 2025 al 21 oct 2026: mínimo 5800 mil M USD, máximo 6300 mil M USD, último 6130 mil M USD',
    )
  })
})
