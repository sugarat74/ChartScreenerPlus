import { Link } from 'react-router'
import { useAuthLinks } from '../../auth/useAuthLinks.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import { ACCOUNT_ROUTE } from '../../nav.ts'

const LINK_CLASS =
  'font-mono text-xs font-bold text-on-surface underline underline-offset-4 focus:outline-2 focus:outline-offset-2 focus:outline-outline'

/**
 * The way out of a disabled registered-only option: sign-in and create-account
 * links that carry the current location as `next` (a Visitor), or a link to
 * the Account page, where the email confirmation is resent (a pending account).
 */
export default function RegisteredOnlyActions({ state, showRegister = true }: { state: 'guest' | 'pending'; showRegister?: boolean }) {
  const { t } = useI18n()
  const links = useAuthLinks()

  if (state === 'pending') {
    return (
      <Link to={ACCOUNT_ROUTE} className={LINK_CLASS}>
        {t('registeredOnly.account')}
      </Link>
    )
  }

  return (
    <>
      <Link to={links.login} className={LINK_CLASS}>
        {t('registeredOnly.signIn')}
      </Link>
      {showRegister ? (
        <Link to={links.register} className={LINK_CLASS}>
          {t('registeredOnly.createAccount')}
        </Link>
      ) : null}
    </>
  )
}
