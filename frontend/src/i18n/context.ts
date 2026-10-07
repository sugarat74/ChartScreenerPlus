import { createContext } from 'react'
import type { Locale } from './locales.ts'
import type { Translate } from './translate.ts'

export interface I18nContextValue {
  locale: Locale
  /** Switch language in place: route, filters and session are untouched. */
  setLocale: (locale: Locale) => void
  t: Translate
  formatPrice: (value: number | null) => string
  formatChangePercent: (value: number | null) => string
  formatMultiple: (value: number | null, fractionDigits?: number) => string
  formatNumber: (value: number | null, fractionDigits: number) => string
  formatInteger: (value: number | null) => string
  formatCompact: (value: number | null) => string
  formatDateTime: (iso: string | null) => string
  formatMarketDate: (isoDate: string | null) => string
}

/**
 * Context object lives outside `I18nProvider.tsx` so that file only exports
 * its React component (keeps Fast Refresh lint clean).
 */
export const I18nContext = createContext<I18nContextValue | null>(null)
