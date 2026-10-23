import type { InstrumentMarketContext } from '../../lib/api.ts'

/**
 * Scenario 1 of docs/specs/market-context-patterns.md: AAPL on 2026-10-23
 * (New York), sector and regime rows on 2026-10-22.
 */
export const AAPL_CONTEXT: InstrumentMarketContext = {
  ticker: 'AAPL',
  as_of: '2026-10-23',
  sector: {
    date: '2026-10-22',
    stale: false,
    etf: 'XLK',
    sector_key: 'information_technology',
    trend: 'uptrend',
    rs_rank: 1,
    rs_rank_of: 10,
  },
  regime: { date: '2026-10-22', stale: false, regime: 'neutral' },
}
