import type { Locale } from '../locales.ts'
import { en } from './en.ts'
import { es } from './es.ts'
import type { Messages } from './es.ts'

/** One complete catalog per supported locale (`en` is typed as `Messages`). */
export const MESSAGES: Record<Locale, Messages> = { es, en }
