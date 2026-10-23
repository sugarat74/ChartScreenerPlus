import Interpolate from '../../i18n/Interpolate.tsx'
import { useI18n } from '../../i18n/useI18n.ts'

export const FRED_TERMS_URL = 'https://fred.stlouisfed.org/docs/api/terms_of_use.html'
export const CC_BY_URL = 'https://creativecommons.org/licenses/by/4.0/'

const LINK_CLASS = 'font-bold text-on-surface underline underline-offset-2'

/**
 * Legal footer of /mercado (market-context-ui, C8): the descriptive-only
 * disclaimer, the FRED® notice verbatim in English (only while macro data is
 * shown), the binding clause with the FRED® API Terms of Use link and the OECD
 * licence. While the legal notice is withheld, this is the only place where
 * the FRED binding clause is visible.
 */
export default function MarketLegalFooter({ hasMacro }: { hasMacro: boolean }) {
  const { t } = useI18n()

  return (
    <footer className="flex flex-col gap-2 border-t-2 border-outline pt-4 text-xs leading-5 text-on-surface-variant">
      <p className="font-bold text-on-surface">{t('marketContext.legal.disclaimer')}</p>
      {hasMacro ? (
        <p lang="en" data-testid="fred-notice">
          {t('marketContext.legal.fredNotice')}
        </p>
      ) : null}
      <p>
        <Interpolate
          template={t('marketContext.legal.fredBinding')}
          values={{
            terms: (
              <a href={FRED_TERMS_URL} target="_blank" rel="noopener noreferrer" className={LINK_CLASS}>
                {t('marketContext.legal.fredTerms')}
              </a>
            ),
          }}
        />
      </p>
      <p>
        <Interpolate
          template={t('marketContext.legal.oecdLicence')}
          values={{
            licence: (
              <a href={CC_BY_URL} target="_blank" rel="noopener noreferrer" className={LINK_CLASS}>
                {t('marketContext.legal.licenceLabel')}
              </a>
            ),
          }}
        />
      </p>
    </footer>
  )
}
