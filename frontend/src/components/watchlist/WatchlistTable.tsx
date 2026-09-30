/**
 * Owned watchlist table: ticker (link to its chart), company/sector and a
 * remove action. Entries are already ordered by ticker server-side; the SPA
 * renders that order as-is. Styling is DESIGN.md tokens only.
 */

import { Link } from 'react-router'
import type { WatchlistEntry } from '../../lib/api.ts'

interface WatchlistTableProps {
  items: WatchlistEntry[]
  /** Ticker currently being removed, or `null` when idle (per-row busy state). */
  removingTicker: string | null
  onRemove: (ticker: string) => void
}

export default function WatchlistTable({ items, removingTicker, onRemove }: WatchlistTableProps) {
  return (
    <div className="overflow-x-auto rounded-md border-2 border-outline bg-surface-bright shadow-[2px_2px_0px_#1a1a1a]">
      <table className="w-full border-collapse text-left">
        <thead>
          <tr className="border-b-2 border-outline bg-surface-container font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
            <th scope="col" className="px-4 py-2">
              Símbolo
            </th>
            <th scope="col" className="px-4 py-2">
              Empresa
            </th>
            <th scope="col" className="px-4 py-2">
              Sector
            </th>
            <th scope="col" className="px-4 py-2 text-right">
              Acciones
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => {
            const removing = removingTicker === item.ticker

            return (
              <tr key={item.ticker} className="border-b border-outline-variant align-top">
                <th scope="row" className="px-4 py-2 text-left font-normal">
                  <Link
                    to={`/instruments/${encodeURIComponent(item.ticker)}`}
                    className="font-headline text-sm font-black text-on-surface underline-offset-2 hover:underline focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
                  >
                    {item.ticker}
                  </Link>
                  <span className="ml-2 rounded-[4px] border border-outline bg-surface-container px-1 py-0.5 font-mono text-[10px] font-bold text-on-surface-variant">
                    {item.exchange}
                  </span>
                </th>

                <td className="px-4 py-2 text-xs text-on-surface">{item.company}</td>

                <td className="px-4 py-2 text-xs text-on-surface-variant">{item.sector}</td>

                <td className="px-4 py-2 text-right">
                  <button
                    type="button"
                    onClick={() => onRemove(item.ticker)}
                    disabled={removing}
                    aria-label={`Quitar ${item.ticker} de la watchlist`}
                    className="rounded-md border-2 border-outline bg-surface-bright px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-70"
                  >
                    {removing ? 'Quitando…' : 'Quitar'}
                  </button>
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
