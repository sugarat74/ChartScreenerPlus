import { useI18n } from '../i18n/useI18n.ts'
import { THEMES } from '../theme/theme.ts'
import type { Theme } from '../theme/theme.ts'
import { useTheme } from '../theme/useTheme.ts'

/** Sun and moon outlines drawn with the text color, so they follow the theme. */
function ThemeIcon({ theme }: { theme: Theme }) {
  return (
    <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round">
      {theme === 'light' ? (
        <>
          <circle cx="8" cy="8" r="3" />
          <path d="M8 1.5v1.5M8 13v1.5M1.5 8H3M13 8h1.5M3.4 3.4l1 1M11.6 11.6l1 1M3.4 12.6l1-1M11.6 4.4l1-1" />
        </>
      ) : (
        <path d="M13.5 9.5A5.5 5.5 0 0 1 6.5 2.5a5.5 5.5 0 1 0 7 7Z" strokeLinejoin="round" />
      )}
    </svg>
  )
}

/**
 * Segmented light/dark control, styled like `LanguageSelector`. Native buttons
 * keep it keyboard-operable and `aria-pressed` exposes the active theme.
 */
export default function ThemeSelector() {
  const { theme, setTheme } = useTheme()
  const { t } = useI18n()

  return (
    <div
      role="group"
      aria-label={t('theme.selectorAria')}
      className="inline-flex overflow-hidden rounded-[4px] border-2 border-outline shadow-[2px_2px_0px_var(--color-shadow)]"
    >
      {THEMES.map((option, index) => {
        const selected = option === theme
        const label = t(option === 'light' ? 'theme.light' : 'theme.dark')
        return (
          <button
            key={option}
            type="button"
            aria-pressed={selected}
            aria-label={label}
            title={label}
            onClick={() => setTheme(option)}
            className={[
              'flex items-center px-2 py-1 transition-colors focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none',
              index > 0 ? 'border-l-2 border-outline' : '',
              selected
                ? 'bg-primary text-on-primary'
                : 'bg-surface-bright text-on-surface-variant hover:bg-surface-container',
            ].join(' ')}
          >
            <ThemeIcon theme={option} />
          </button>
        )
      })}
    </div>
  )
}
