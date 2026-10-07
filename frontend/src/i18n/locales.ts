/**
 * Locale registry and resolution. Pure functions with no React dependency so
 * the resolution order is auditable and unit-tested.
 *
 * To add a language: append its code to `SUPPORTED_LOCALES`, describe it in
 * `LOCALE_DEFINITIONS`, add a catalog under `messages/` typed as `Messages`
 * and register it in `messages/index.ts`, then add the same code to
 * `config/locales.php` so Laravel answers in it too.
 */

export const SUPPORTED_LOCALES = ['es', 'en'] as const

export type Locale = (typeof SUPPORTED_LOCALES)[number]

/** Used when neither a saved choice nor a supported browser language exists. */
export const DEFAULT_LOCALE: Locale = 'es'

/** Device-local preference (never synced to the account). */
export const LOCALE_STORAGE_KEY = 'chartiko.locale'

export interface LocaleDefinition {
  /** Name shown in the selector, written in the language itself. */
  readonly nativeName: string
  /** Compact code shown in the header control. */
  readonly shortLabel: string
  /** BCP 47 tag handed to `Intl` for dates and numbers. */
  readonly intl: string
}

export const LOCALE_DEFINITIONS: Record<Locale, LocaleDefinition> = {
  es: { nativeName: 'Español', shortLabel: 'ES', intl: 'es-ES' },
  en: { nativeName: 'English', shortLabel: 'EN', intl: 'en-US' },
}

export function isSupportedLocale(value: unknown): value is Locale {
  return typeof value === 'string' && (SUPPORTED_LOCALES as readonly string[]).includes(value)
}

/**
 * Map a language tag (`en`, `en-GB`, `ES_es`, ` es-419 `) to a supported
 * locale by its primary subtag, or `null` when it is unsupported or invalid.
 */
export function matchSupportedLocale(tag: string | null | undefined): Locale | null {
  if (typeof tag !== 'string') {
    return null
  }
  const primary = tag.trim().toLowerCase().replace(/_/g, '-').split('-')[0]
  return isSupportedLocale(primary) ? primary : null
}

/**
 * Resolution order: an explicit saved choice, then the first supported browser
 * language (regional variants included), then Spanish.
 */
export function resolveLocale(
  stored: string | null | undefined,
  browserLanguages: readonly string[] = [],
): Locale {
  if (isSupportedLocale(stored)) {
    return stored
  }
  for (const language of browserLanguages) {
    const match = matchSupportedLocale(language)
    if (match !== null) {
      return match
    }
  }
  return DEFAULT_LOCALE
}

/** Storage may be missing or throw (private mode, blocked site data). */
function localStorageOrNull(): Storage | null {
  try {
    return typeof window === 'undefined' ? null : window.localStorage
  } catch {
    return null
  }
}

export function readStoredLocale(storage: Pick<Storage, 'getItem'> | null = localStorageOrNull()): string | null {
  try {
    return storage?.getItem(LOCALE_STORAGE_KEY) ?? null
  } catch {
    return null
  }
}

/** Returns whether the choice was persisted; failure never breaks the app. */
export function writeStoredLocale(
  locale: Locale,
  storage: Pick<Storage, 'setItem'> | null = localStorageOrNull(),
): boolean {
  try {
    if (storage === null) {
      return false
    }
    storage.setItem(LOCALE_STORAGE_KEY, locale)
    return true
  } catch {
    return false
  }
}

function browserLanguages(): readonly string[] {
  if (typeof navigator === 'undefined') {
    return []
  }
  if (Array.isArray(navigator.languages) && navigator.languages.length > 0) {
    return navigator.languages
  }
  return navigator.language ? [navigator.language] : []
}

export function detectInitialLocale(): Locale {
  return resolveLocale(readStoredLocale(), browserLanguages())
}

/**
 * The locale the non-React API client sends as `Accept-Language`. The provider
 * keeps it in sync with the rendered language.
 */
let activeLocale: Locale = DEFAULT_LOCALE

export function getActiveLocale(): Locale {
  return activeLocale
}

export function setActiveLocale(locale: Locale): void {
  activeLocale = locale
}
