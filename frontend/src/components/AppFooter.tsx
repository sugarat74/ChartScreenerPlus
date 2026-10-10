import { Link } from 'react-router'
import { useI18n } from '../i18n/useI18n.ts'
import { useLegalInfo } from '../legal/useLegalInfo.ts'
import { COOKIES_ROUTE, LEGAL_NOTICE_ROUTE, PRIVACY_ROUTE } from '../nav.ts'

const LINK_CLASS = 'underline-offset-2 hover:underline focus:outline-2 focus:outline-offset-2 focus:outline-outline'

/**
 * Site footer on every page: the investment-information disclaimer and the
 * legal links. Privacy and legal notice are linked only once the owner data
 * is configured (docs/specs/legal-compliance-eu.md); cookies always.
 */
export default function AppFooter() {
  const { t } = useI18n()
  const legal = useLegalInfo()
  const published = legal.status === 'ready' && legal.info.published

  return (
    <footer className="border-t-2 border-outline bg-surface-container">
      <div className="mx-auto flex w-full max-w-7xl flex-col gap-3 px-4 py-5 text-xs leading-5 text-on-surface-variant sm:flex-row sm:items-start sm:justify-between">
        <p className="max-w-3xl">{t('footer.disclaimer')}</p>
        <nav aria-label={t('footer.navAria')} className="flex shrink-0 flex-wrap gap-x-4 gap-y-1 font-mono font-bold uppercase">
          {published ? (
            <>
              <Link to={PRIVACY_ROUTE} className={LINK_CLASS}>{t('footer.privacy')}</Link>
              <Link to={LEGAL_NOTICE_ROUTE} className={LINK_CLASS}>{t('footer.legalNotice')}</Link>
            </>
          ) : null}
          <Link to={COOKIES_ROUTE} className={LINK_CLASS}>{t('footer.cookies')}</Link>
        </nav>
      </div>
    </footer>
  )
}
