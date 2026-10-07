import { LOCALE_DEFINITIONS, SUPPORTED_LOCALES } from '../i18n/locales.ts'
import { useI18n } from '../i18n/useI18n.ts'

/**
 * Segmented language control (same token treatment as the Screener MA-cross
 * group). Native buttons keep it keyboard-operable; `aria-pressed` exposes the
 * active language and each option announces its own name in its own `lang`.
 * Options come from the locale registry, so a new language needs no change here.
 */
export default function LanguageSelector() {
  const { locale, setLocale, t } = useI18n()

  return (
    <div
      role="group"
      aria-label={t('language.selectorAria')}
      className="inline-flex overflow-hidden rounded-[4px] border-2 border-outline shadow-[2px_2px_0px_#1a1a1a]"
    >
      {SUPPORTED_LOCALES.map((code, index) => {
        const definition = LOCALE_DEFINITIONS[code]
        const selected = code === locale
        return (
          <button
            key={code}
            type="button"
            lang={code}
            aria-pressed={selected}
            aria-label={definition.nativeName}
            title={definition.nativeName}
            onClick={() => setLocale(code)}
            className={[
              'px-2 py-1 font-mono text-[11px] font-bold uppercase tracking-wider transition-colors focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none',
              index > 0 ? 'border-l-2 border-outline' : '',
              selected
                ? 'bg-primary text-on-primary'
                : 'bg-surface-bright text-on-surface-variant hover:bg-surface-container',
            ].join(' ')}
          >
            {definition.shortLabel}
          </button>
        )
      })}
    </div>
  )
}
