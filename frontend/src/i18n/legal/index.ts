import type { Locale } from '../locales.ts'
import { legalEn } from './en.ts'
import { legalEs } from './es.ts'
import type { LegalTexts } from './types.ts'

/** One complete set of legal texts per supported locale. */
export const LEGAL_TEXTS: Record<Locale, LegalTexts> = { es: legalEs, en: legalEn }
