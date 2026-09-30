/**
 * Candidate ranking control for the Screener results header: a labelled native
 * `<select>` limited to the six sorts the frozen screener API supports.
 *
 * The value is URL-owned by `ScreenerPage`, so the control restores from a deep
 * link, updates instantly and never depends on the response echo. Styling uses
 * the shared token input treatment (DESIGN.md -> Components/Accessibility).
 */

import type { ScreenerSort } from '../../lib/api.ts'
import { SCREENER_SORT_OPTIONS } from '../../lib/screenerFilters.ts'

const SELECT_CLASS =
  'rounded-md border-2 border-outline bg-surface-bright px-3 py-2 font-mono text-xs text-on-surface shadow-[2px_2px_0px_#1a1a1a] focus:border-outline focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none'

interface ScreenerSortControlProps {
  value: ScreenerSort
  onChange: (sort: ScreenerSort) => void
}

export default function ScreenerSortControl({ value, onChange }: ScreenerSortControlProps) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      <label
        htmlFor="screener-sort"
        className="font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant"
      >
        Ordenar por
      </label>
      <select
        id="screener-sort"
        value={value}
        onChange={(event) => onChange(event.target.value as ScreenerSort)}
        className={SELECT_CLASS}
      >
        {SCREENER_SORT_OPTIONS.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </div>
  )
}
