/**
 * Fixed-column Candidate table using the API's default ranking. The
 * interactive elements are the ticker link into the instrument chart and,
 * only when `showCompanyCardLink` is true (signed-in users, decided by
 * `CandidateResults`), a small "AI card" link to the same chart's Company Card
 * panel (`#ai-company-card`). Both only navigate; the table itself stays
 * presentational (no auth read) and nothing is generated from here. There are
 * no watchlist actions. Styling is DESIGN.md tokens only.
 */

import { Link } from 'react-router'
import { COMPANY_CARD_ANCHOR } from '../chart/CompanyCardPanel.tsx'
import { useI18n } from '../../i18n/useI18n.ts'
import type { ScreenerCandidate } from '../../lib/api.ts'
import { patternLabel, signalLabel } from '../../lib/screenerFilters.ts'

/** Sign + color for the change column; the sign is the non-color cue. */
function changeColorClass(value: number | null): string {
  if (value === null || value === 0) {
    return 'text-on-surface-variant'
  }
  return value > 0 ? 'text-gain' : 'text-secondary'
}

interface CandidateTableProps {
  candidates: ScreenerCandidate[]
  /** Show the per-row "AI card" link; default `false` (Visitors). */
  showCompanyCardLink?: boolean
}

export default function CandidateTable({ candidates, showCompanyCardLink = false }: CandidateTableProps) {
  const { t, formatPrice, formatChangePercent, formatMultiple, formatNumber } = useI18n()

  return (
    <div className="overflow-x-auto rounded-md border-2 border-outline bg-surface-bright shadow-[2px_2px_0px_#1a1a1a]">
      <table className="w-full border-collapse text-left">
        <thead>
          <tr className="border-b-2 border-outline bg-surface-container font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
            <th scope="col" className="px-4 py-2">
              {t('screener.colSymbol')}
            </th>
            <th scope="col" className="px-4 py-2 text-right">
              {t('screener.colClose')}
            </th>
            <th scope="col" className="px-4 py-2 text-right">
              {t('screener.colChange')}
            </th>
            <th scope="col" className="px-4 py-2 text-right">
              {t('screener.colRvol')}
            </th>
            <th scope="col" className="px-4 py-2 text-right">
              {t('screener.colRsi')}
            </th>
            <th scope="col" className="px-4 py-2">
              {t('screener.colSignals')}
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
                  {showCompanyCardLink ? (
                    <Link
                      to={`/instruments/${encodeURIComponent(candidate.ticker)}#${COMPANY_CARD_ANCHOR}`}
                      aria-label={t('companyCard.candidateLinkAria', { ticker: candidate.ticker })}
                      className="font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant underline underline-offset-2 hover:text-on-surface focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
                    >
                      {t('companyCard.candidateLink')}
                    </Link>
                  ) : null}
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
                      {formatMultiple(candidate.rvol)}
                    </span>
                  ) : (
                    <span className="font-mono text-xs text-on-surface">
                      {formatMultiple(candidate.rvol)}
                    </span>
                  )}
                </td>

                <td className="px-4 py-2 text-right font-mono text-xs text-on-surface">
                  {formatNumber(candidate.rsi14, 1)}
                </td>

                <td className="px-4 py-2">
                  {candidate.signals.length === 0 && (candidate.patterns ?? []).length === 0 ? (
                    <span className="font-mono text-xs text-on-surface-variant">—</span>
                  ) : (
                    <div className="flex flex-wrap gap-1">
                      {candidate.signals.map((signal) => (
                        <span
                          key={signal}
                          className="rounded-[4px] border border-outline bg-surface-container px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface"
                        >
                          {signalLabel(signal, t)}
                        </span>
                      ))}
                      {(candidate.patterns ?? []).map((pattern) => (
                        <span
                          key={`pattern-${pattern.type}`}
                          data-testid="candidate-pattern"
                          className={`rounded-[4px] border-2 border-dashed border-outline px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface ${
                            pattern.status === 'confirmed' ? 'bg-primary-container' : 'bg-surface-bright'
                          }`}
                        >
                          {patternLabel(pattern.type, t)} · {t(`patterns.status.${pattern.status}`)}
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
