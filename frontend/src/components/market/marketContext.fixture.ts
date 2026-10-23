/**
 * Test fixture: the "production-like" Market Context payload of
 * docs/specs/market-context-ui.md (Acceptance Scenarios), the same data as
 * tests/Concerns/BuildsMarketContextFixture.php. Used by Vitest only.
 */
import type { MarketContext, MarketHistory, MarketRegime, MarketSectorRow } from '../../lib/api.ts'

export const FIXTURE_REGIME: MarketRegime = {
  date: '2026-10-22',
  stale: false,
  regime: 'neutral',
  score: 1,
  available: 5,
  rules_version: 1,
  rules: { min_available: 4, favourable_min_score: 3, adverse_max_score: -3 },
  components: {
    breadth: { status: 'ok', score: 0, value: 55.2, as_of: '2026-10-22', stale: false },
    index_trend: { status: 'ok', score: 1, value: 'uptrend', as_of: '2026-10-22', stale: false },
    sector_participation: { status: 'ok', score: 0, value: 6, as_of: '2026-10-22', stale: false },
    yield_curve: { status: 'ok', score: 0, value: 0.31, as_of: '2026-10-21', stale: false },
    financial_conditions: { status: 'disabled', score: null, value: null, as_of: null, stale: false },
    liquidity: { status: 'ok', score: 0, value: -0.85, as_of: '2026-10-15', stale: false },
    volatility: { status: 'disabled', score: null, value: null, as_of: null, stale: false },
  },
}

const sector = (
  ticker: string,
  key: MarketSectorRow['sector_key'],
  rank: number,
  values: [number, number, number, number],
  trend: MarketSectorRow['trend'],
  breadth: number,
): MarketSectorRow => ({
  ticker,
  sector_key: key,
  ret_5: values[0],
  ret_20: values[1],
  ret_60: values[2],
  rs_60: values[3],
  rs_rank: rank,
  trend,
  breadth_200: breadth,
})

export const FIXTURE_SECTORS: MarketSectorRow[] = [
  sector('XLK', 'information_technology', 1, [1.23, 4.5, 9.87, 3.21], 'uptrend', 71.43),
  sector('XLC', 'communication_services', 2, [0.9, 3.1, 8.2, 2.5], 'uptrend', 65),
  sector('XLY', 'consumer_discretionary', 3, [0.4, 2.2, 6.1, 1.4], 'uptrend', 60),
  sector('XLI', 'industrials', 4, [0.3, 1.9, 5.5, 0.8], 'uptrend', 58),
  sector('XLF', 'financials', 5, [0.2, 1.5, 4.9, 0.2], 'uptrend', 55),
  sector('XLV', 'health_care', 6, [0.1, 1.1, 4.0, -0.7], 'uptrend', 52),
  sector('XLB', 'materials', 7, [-0.1, 0.5, 3.2, -1.5], 'mixed', 48),
  sector('XLRE', 'real_estate', 8, [-0.4, -0.2, 2.1, -2.6], 'mixed', 45),
  sector('XLP', 'consumer_staples', 9, [-0.6, -0.9, 1.0, -3.7], 'mixed', 40),
  sector('XLU', 'utilities', 10, [-0.8, -1.4, 0.3, -4.4], 'downtrend', 35),
  sector('XLE', 'energy', 11, [-1.5, -3.0, -2.2, -6.9], 'downtrend', 20),
]

const reading = (value: number, asOf: string, stale = false) => ({ value, as_of: asOf, stale })

export const FIXTURE_CONTEXT: MarketContext = {
  as_of: '2026-10-23',
  public: true,
  regime: FIXTURE_REGIME,
  sectors: { date: '2026-10-22', stale: false, rows: FIXTURE_SECTORS },
  breadth: {
    date: '2026-10-22',
    stale: false,
    members: 503,
    pct_above_sma50: 58.1,
    pct_above_sma200: 55.2,
    new_highs: 21,
    new_lows: 4,
    advancers: 301,
    decliners: 195,
    spy_trend: 'uptrend',
  },
  macro: {
    rates: {
      dgs3mo: reading(3.8, '2026-10-21'),
      dgs2: reading(3.6, '2026-10-21'),
      dgs10: reading(4.11, '2026-10-21'),
      dgs10_change_20: reading(0.1, '2026-10-21'),
    },
    curve: { spread_10y2y: reading(0.51, '2026-10-21'), spread_10y3m: reading(0.31, '2026-10-21'), inverted: false },
    liquidity: {
      net_liquidity: reading(6130, '2026-10-14'),
      change_4w_pct: reading(1.0717, '2026-10-14'),
      change_13w_pct: reading(2.1667, '2026-10-14'),
    },
    leading: {
      icsa: reading(235000, '2026-10-17'),
      icsa_4w: reading(227500, '2026-10-17'),
      icsa_4w_vs_52w_min_pct: { value: null, as_of: null, stale: false },
      permit: reading(1450, '2026-09-01'),
      permit_change_3m_pct: reading(3.5714, '2026-09-01'),
      nfci: null,
      oecd_cli: reading(100.3, '2026-09-01'),
      oecd_cli_change_1m: reading(0.2, '2026-09-01'),
    },
    central_banks: {
      fed: {
        lower: reading(3.75, '2026-10-22'),
        upper: reading(4, '2026-10-22'),
        last_change: { direction: 'cut', from: { lower: 4, upper: 4.25 }, to: { lower: 3.75, upper: 4 }, effective_date: '2026-09-17' },
        last_meeting: { start: '2026-09-15', decision: '2026-09-16' },
        next_meeting: { start: '2026-10-27', decision: '2026-10-28' },
        calendar: { source_url: 'https://www.federalreserve.gov/monetarypolicy/fomccalendars.htm', checked_at: '2026-10-22' },
      },
      ecb: {
        deposit_rate: reading(2.5, '2026-10-22'),
        last_change: { direction: 'cut', from: 2.75, to: 2.5, effective_date: '2026-06-11' },
        last_meeting: { start: '2026-09-09', decision: '2026-09-10' },
        next_meeting: { start: '2026-10-28', decision: '2026-10-29' },
        calendar: { source_url: 'https://www.ecb.europa.eu/press/calendars/mgcgc/html/index.en.html', checked_at: '2026-10-22' },
      },
    },
    sources: {
      rates: ['Board of Governors of the Federal Reserve System (US), H.15 Selected Interest Rates, via FRED®'],
      liquidity: ['Board of Governors of the Federal Reserve System (US), H.4.1 Factors Affecting Reserve Balances, via FRED®'],
      central_banks: [
        'Board of Governors of the Federal Reserve System (US), FOMC Press Release, via FRED®',
        'Source: ECB statistics, via FRED®',
      ],
      leading: [
        'U.S. Employment and Training Administration, via FRED®',
        'U.S. Census Bureau and U.S. Department of Housing and Urban Development, via FRED®',
        'OECD, Composite Leading Indicators (CC BY 4.0), via FRED®',
      ],
    },
  },
}

/** Scenario 2: the regime history starts after the 3-year window. */
export function regimeHistory(range: '1y' | '3y'): MarketHistory {
  return {
    metric: 'regime_score',
    range,
    disabled: false,
    from: range === '1y' ? '2025-10-22' : '2023-10-22',
    to: '2026-10-22',
    first_date: '2025-10-22',
    points: [
      { date: '2025-10-22', value: 1 },
      { date: '2026-01-05', value: null },
      { date: '2026-04-01', value: 3 },
      { date: '2026-10-22', value: 1 },
    ],
  }
}
