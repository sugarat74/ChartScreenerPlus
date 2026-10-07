import { useContext, useEffect, useRef } from 'react'
import type { RefObject } from 'react'
import { I18nContext } from './context.ts'
import type { I18nContextValue } from './context.ts'
import type { Translate } from './translate.ts'

/**
 * Read the active language, its translator and formatters. Must be used below
 * an `I18nProvider`.
 */
export function useI18n(): I18nContextValue {
  const context = useContext(I18nContext)

  if (context === null) {
    throw new Error('useI18n must be used inside an I18nProvider')
  }

  return context
}

/**
 * The latest translator for async effect callbacks. Reading `ref.current`
 * there keeps `t` out of the effect dependencies, so a language switch never
 * refetches data that does not depend on the UI language.
 */
export function useTranslateRef(): RefObject<Translate> {
  const { t } = useI18n()
  const ref = useRef(t)

  useEffect(() => {
    ref.current = t
  }, [t])

  return ref
}
