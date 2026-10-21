// @vitest-environment jsdom
import { cleanup, render } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import TechnicalExample, { type ExampleKind } from './TechnicalExample.tsx'

afterEach(cleanup)

function drawing(kind: ExampleKind) {
  const { container } = render(<TechnicalExample kind={kind} label={kind} />)
  const svg = container.querySelector('svg')!
  const pivotY = Number(svg.querySelector('path[stroke-dasharray]')!.getAttribute('d')!.match(/^M\S+ (\S+) H/)![1])
  const price = svg.querySelector('path[fill="none"][stroke="currentColor"]')!.getAttribute('d')!
  const vertices = [...price.matchAll(/[ML](\S+) (\S+)/g)].map(([, x, y]) => ({ x: Number(x), y: Number(y) }))
  const marker = svg.querySelector('circle')!
  const bars = [...svg.querySelectorAll('rect')].map((rect) => ({
    centre: Number(rect.getAttribute('x')) + Number(rect.getAttribute('width')) / 2,
    height: Number(rect.getAttribute('height')),
    // Themed hues are set through `style`; the ink may also be an attribute.
    fill: (rect.style.getPropertyValue('fill') || rect.getAttribute('fill') || '').toLowerCase(),
  }))
  return { pivotY, vertices, marker: { x: Number(marker.getAttribute('cx')), y: Number(marker.getAttribute('cy')) }, bars }
}

// Smaller y means higher price. The engine compares the latest close with the highest high of earlier bars.
describe('TechnicalExample pivot breakout diagrams', () => {
  for (const kind of ['pivot', 'pivotBreakoutRvol'] as const) {
    it(`${kind}: only the last close is above the pivot and it carries the marker`, () => {
      const { pivotY, vertices, marker } = drawing(kind)
      const last = vertices.at(-1)!
      expect(vertices.length).toBeGreaterThan(5)
      expect(vertices.slice(0, -1).every((point) => point.y > pivotY), JSON.stringify(vertices)).toBe(true)
      expect(last.y).toBeLessThan(pivotY)
      expect(marker).toEqual(last)
    })
  }

  it('pivotBreakoutRvol: the highlighted volume bar sits under the breakout close with at least 2x average volume', () => {
    const { vertices, bars } = drawing('pivotBreakoutRvol')
    expect(bars.map((bar) => bar.centre)).toEqual(vertices.map((point) => point.x))
    const highlighted = bars.filter((bar) => bar.fill !== 'currentcolor')
    expect(highlighted).toHaveLength(1)
    expect(highlighted[0]).toBe(bars.at(-1))
    const earlier = bars.slice(0, -1)
    const mean = earlier.reduce((sum, bar) => sum + bar.height, 0) / earlier.length
    expect(highlighted[0].height).toBeGreaterThanOrEqual(2 * mean)
  })
})
