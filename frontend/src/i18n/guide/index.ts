import type { Locale } from '../locales.ts'
import { guideEn } from './en.ts'
import { guideEs } from './es.ts'
import type { GuideTexts } from './types.ts'

/** One complete user manual per supported locale. */
export const GUIDE_TEXTS: Record<Locale, GuideTexts> = { es: guideEs, en: guideEn }
