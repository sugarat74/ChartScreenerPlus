import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { I18nContext } from './context.ts'
import type { I18nContextValue } from './context.ts'
import * as format from './format.ts'
import { DEFAULT_LOCALE, detectInitialLocale, setActiveLocale, writeStoredLocale } from './locales.ts'
import type { Locale } from './locales.ts'
import { MESSAGES } from './messages/index.ts'
import { translate } from './translate.ts'
import type { MessageKey, MessageParams } from './translate.ts'

function initialLocale(): Locale {
  const locale = detectInitialLocale()
  // Set before the first render so the session bootstrap request already
  // carries the resolved `Accept-Language`.
  setActiveLocale(locale)
  return locale
}

/**
 * Owns the SPA language. It sits above the router, so switching re-renders in
 * place without navigation: the URL (filters, ticker), the selected Candidate
 * and the auth session survive. Read it with `useI18n`.
 */
export function I18nProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>(initialLocale)

  useEffect(() => {
    setActiveLocale(locale)
    document.documentElement.lang = locale
  }, [locale])

  const setLocale = useCallback((next: Locale) => {
    setActiveLocale(next)
    writeStoredLocale(next)
    setLocaleState(next)
  }, [])

  const value = useMemo<I18nContextValue>(() => {
    const messages = MESSAGES[locale]
    const fallback = MESSAGES[DEFAULT_LOCALE]
    return {
      locale,
      setLocale,
      t: (key: MessageKey, params?: MessageParams) => translate(messages, key, params, fallback),
      formatPrice: (v) => format.formatPrice(locale, v),
      formatChangePercent: (v) => format.formatChangePercent(locale, v),
      formatMultiple: (v, digits) => format.formatMultiple(locale, v, digits),
      formatNumber: (v, digits) => format.formatNumber(locale, v, digits),
      formatInteger: (v) => format.formatInteger(locale, v),
      formatCompact: (v) => format.formatCompact(locale, v),
      formatDateTime: (iso) => format.formatDateTime(locale, iso),
      formatMarketDate: (iso) => format.formatMarketDate(locale, iso),
    }
  }, [locale, setLocale])

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>
}
