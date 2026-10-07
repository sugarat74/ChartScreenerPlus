/**
 * Locale-aware display formatting. Display only: stored values, URL params,
 * API payloads and calculations keep their raw numbers and ISO dates.
 */

import { LOCALE_DEFINITIONS } from './locales.ts'
import type { Locale } from './locales.ts'

const EMPTY = '—'

function intlTag(locale: Locale): string {
  return LOCALE_DEFINITIONS[locale].intl
}

export function formatNumber(
  locale: Locale,
  value: number | null,
  fractionDigits: number,
): string {
  if (value === null || !Number.isFinite(value)) {
    return EMPTY
  }
  return new Intl.NumberFormat(intlTag(locale), {
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(value)
}

/** Prices are USD values shown as plain two-decimal numbers. */
export function formatPrice(locale: Locale, value: number | null): string {
  return formatNumber(locale, value, 2)
}

/** Explicit sign so gain/loss never depends on color alone. */
export function formatChangePercent(locale: Locale, value: number | null): string {
  if (value === null || !Number.isFinite(value)) {
    return EMPTY
  }
  const sign = value > 0 ? '+' : value < 0 ? '-' : ''
  return `${sign}${formatNumber(locale, Math.abs(value), 2)}%`
}

export function formatMultiple(locale: Locale, value: number | null, fractionDigits = 1): string {
  return value === null || !Number.isFinite(value) ? EMPTY : `${formatNumber(locale, value, fractionDigits)}x`
}

export function formatCompact(locale: Locale, value: number | null): string {
  if (value === null || !Number.isFinite(value)) {
    return EMPTY
  }
  return new Intl.NumberFormat(intlTag(locale), {
    notation: 'compact',
    maximumFractionDigits: 2,
  }).format(value)
}

export function formatInteger(locale: Locale, value: number | null): string {
  return formatNumber(locale, value, 0)
}

/** Timestamps (ISO with time) in the viewer's time zone. */
export function formatDateTime(locale: Locale, iso: string | null): string {
  if (iso === null) {
    return EMPTY
  }
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) {
    return EMPTY
  }
  return new Intl.DateTimeFormat(intlTag(locale), {
    dateStyle: 'medium',
    timeStyle: 'medium',
  }).format(date)
}

/**
 * Market-session dates (`YYYY-MM-DD`) are calendar days, not instants: format
 * them in UTC so no viewer time zone shifts the session by a day.
 */
export function formatMarketDate(locale: Locale, isoDate: string | null): string {
  if (isoDate === null) {
    return EMPTY
  }
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate)
  if (!match) {
    return isoDate
  }
  const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])))
  return new Intl.DateTimeFormat(intlTag(locale), {
    dateStyle: 'medium',
    timeZone: 'UTC',
  }).format(date)
}
