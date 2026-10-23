/**
 * Display helpers for the Market Context page (market-context-ui): signs,
 * units, date lines and the regime reason sentences. Display only: the API
 * values stay raw numbers and ISO dates.
 */
import type { I18nContextValue } from '../../i18n/context.ts'
import type { MarketReading, MarketTrend, RegimeComponent, RegimeComponentKey } from '../../lib/api.ts'

type Fmt = Pick<I18nContextValue, 't' | 'formatNumber' | 'formatMarketDate' | 'formatMarketMonth'>

/** Explicit sign so a direction never depends on colour alone (`+0,31`, `-1,50`). */
export function signed(fmt: Fmt, value: number, digits = 2): string {
  const sign = value > 0 ? '+' : value < 0 ? '-' : ''
  return `${sign}${fmt.formatNumber(Math.abs(value), digits)}`
}

/** Signed percentage points: `+0,31 pp`. */
export function signedPp(fmt: Fmt, value: number): string {
  return fmt.t('marketContext.units.pp', { value: signed(fmt, value) })
}

/** A level in percent: `4,11 %`. */
export function percent(fmt: Fmt, value: number, digits = 2): string {
  return fmt.t('marketContext.units.percent', { value: fmt.formatNumber(value, digits) })
}

/** Signed percent change: `+2,17 %`. */
export function signedPercent(fmt: Fmt, value: number): string {
  return fmt.t('marketContext.units.percent', { value: signed(fmt, value) })
}

/** "Datos del 22 oct 2026", or "Datos de septiembre de 2026" for monthly data. */
export function dateLine(fmt: Fmt, isoDate: string, monthly = false): string {
  return monthly
    ? fmt.t('marketContext.dataMonth', { month: fmt.formatMarketMonth(isoDate) })
    : fmt.t('marketContext.dataAsOf', { date: fmt.formatMarketDate(isoDate) })
}

/** A reading carries data only when its series is enabled and has a value. */
export function hasValue(reading: MarketReading | null | undefined): reading is MarketReading & { value: number } {
  return reading !== null && reading !== undefined && reading.value !== null
}

/** Icon shown before a trend label (always paired with its text). */
export const TREND_ICON: Record<MarketTrend, string> = {
  uptrend: '▲',
  mixed: '◆',
  downtrend: '▼',
}

export function trendClass(trend: MarketTrend | null): string {
  if (trend === 'uptrend') return 'text-gain'
  if (trend === 'downtrend') return 'text-secondary'
  return 'text-on-surface'
}

/** The descriptive sentence of one regime component (C7). */
export function reasonSentence(fmt: Fmt, key: RegimeComponentKey, component: RegimeComponent): string {
  const { t } = fmt
  if (component.status === 'disabled') {
    return t('marketContext.regime.reasonDisabled')
  }
  if (component.status === 'missing' || component.value === null) {
    return t('marketContext.regime.reasonMissing')
  }
  const raw = component.value
  const value = typeof raw === 'number' ? raw : Number.NaN

  switch (key) {
    case 'breadth':
      return t('marketContext.regime.components.breadth.reason', { value: fmt.formatNumber(value, 1) })
    case 'index_trend':
      return raw === 'uptrend'
        ? t('marketContext.regime.components.index_trend.reasonUp')
        : raw === 'downtrend'
          ? t('marketContext.regime.components.index_trend.reasonDown')
          : t('marketContext.regime.components.index_trend.reasonMixed')
    case 'sector_participation':
      return t('marketContext.regime.components.sector_participation.reason', { value: fmt.formatNumber(value, 0) })
    case 'yield_curve':
      return value < 0
        ? t('marketContext.regime.components.yield_curve.reasonInverted', { value: signed(fmt, value) })
        : t('marketContext.regime.components.yield_curve.reason', { value: signed(fmt, value) })
    case 'financial_conditions':
      return t('marketContext.regime.components.financial_conditions.reason', { value: signed(fmt, value) })
    case 'liquidity':
      return t('marketContext.regime.components.liquidity.reason', { value: signed(fmt, value) })
    case 'volatility':
      return t('marketContext.regime.components.volatility.reason', { value: fmt.formatNumber(value, 2) })
  }
}
