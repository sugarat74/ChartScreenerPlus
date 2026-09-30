/**
 * Fixed-column Candidate table using the API's default ranking. The only
 * interactive element is the ticker link into the instrument chart; there are
 * no watchlist actions. Styling is DESIGN.md tokens only.
 */

import { Link } from 'react-router'
import type { ScreenerCandidate } from '../../lib/api.ts'
import {
  formatChangePercent,
  formatPrice,
  formatRsi,
  formatRvol,
  signalLabel,
} from '../../lib/screenerFilters.ts'

/** Sign + color for the change column; the sign is the non-color cue. */
function changeColorClass(value: number | null): string {
  if (value === null || value === 0) {
    return 'text-on-surface-variant'
  }
  return value > 0 ? 'text-gain' : 'text-secondary'
}

export default function CandidateTable({ candidates }: { candidates: ScreenerCandidate[] }) {
  return (
    <div className="overflow-x-auto rounded-md border-2 border-outline bg-surface-bright shadow-[2px_2px_0px_#1a1a1a]">
      <table className="w-full border-collapse text-left">
        <thead>
          <tr className="border-b-2 border-outline bg-surface-container font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
            <th scope="col" className="px-4 py-2">
              Símbolo / Empresa
            </th>
            <th scope="col" className="px-4 py-2 text-right">
              Cierre EOD
            </th>
            <th scope="col" className="px-4 py-2 text-right">
              Var %
            </th>
            <th scope="col" className="px-4 py-2 text-right">
              RVOL
            </th>
            <th scope="col" className="px-4 py-2 text-right">
              RSI (14)
            </th>
            <th scope="col" className="px-4 py-2">
              Señales
            </th>
          </tr>
        </thead>
        <tbody>
          {candidates.map((candidate) => {
            const accentRvol = candidate.rvol !== null && candidate.rvol >= 2

            return (
              <tr key={candidate.ticker} className="border-b border-outline-variant align-top">
                <th scope="row" className="px-4 py-2 text-left font-normal">
                  <div className="flex flex-wrap items-center gap-2">
                    <Link
                      to={`/instruments/${encodeURIComponent(candidate.ticker)}`}
                      className="font-headline text-sm font-black text-on-surface underline-offset-2 hover:underline focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
                    >
                      {candidate.ticker}
                    </Link>
                    <span className="rounded-[4px] border border-outline bg-surface-container px-1 py-0.5 font-mono text-[10px] font-bold text-on-surface-variant">
                      {candidate.exchange}
                    </span>
                  </div>
                  <p className="max-w-56 truncate text-xs text-on-surface-variant">
                    {candidate.company}
                  </p>
                </th>

                <td className="px-4 py-2 text-right font-mono text-xs text-on-surface">
                  {formatPrice(candidate.close)}
                </td>

                <td
                  className={`px-4 py-2 text-right font-mono text-xs font-bold ${changeColorClass(
                    candidate.change_percent,
                  )}`}
                >
                  {formatChangePercent(candidate.change_percent)}
                </td>

                <td className="px-4 py-2 text-right">
                  {accentRvol ? (
                    <span className="inline-flex rounded-[4px] border-2 border-outline bg-primary-container px-1.5 py-0.5 font-mono text-xs font-black text-on-primary-container shadow-[1px_1px_0px_#1a1a1a]">
                      {formatRvol(candidate.rvol)}
                    </span>
                  ) : (
                    <span className="font-mono text-xs text-on-surface">
                      {formatRvol(candidate.rvol)}
                    </span>
                  )}
                </td>

                <td className="px-4 py-2 text-right font-mono text-xs text-on-surface">
                  {formatRsi(candidate.rsi14)}
                </td>

                <td className="px-4 py-2">
                  {candidate.signals.length === 0 ? (
                    <span className="font-mono text-xs text-on-surface-variant">—</span>
                  ) : (
                    <div className="flex flex-wrap gap-1">
                      {candidate.signals.map((signal) => (
                        <span
                          key={signal}
                          className="rounded-[4px] border border-outline bg-surface-container px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface"
                        >
                          {signalLabel(signal)}
                        </span>
                      ))}
                    </div>
                  )}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
