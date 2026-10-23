import { useState } from 'react'
import { Link } from 'react-router'
import { useAuth } from '../../auth/useAuth.ts'
import Interpolate from '../../i18n/Interpolate.tsx'
import { useI18n } from '../../i18n/useI18n.ts'
import { authApi } from '../../lib/api.ts'
import { ACCOUNT_ROUTE } from '../../nav.ts'
import ResendVerificationButton from './ResendVerificationButton.tsx'

const SECONDARY_BUTTON =
  'rounded-md border-2 border-outline bg-surface-bright px-3 py-2 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px disabled:cursor-not-allowed disabled:opacity-70'

/**
 * "Confirma tu correo" (auth-email-verification, D7): what a signed-in account
 * that still has to confirm its email sees instead of any page except the
 * legal pages, the Account page and the verify page. A UI affordance only:
 * every account-only API answers 403 `email_verification.required` itself.
 */
export default function VerifyEmailNotice() {
  const { user, logout, setCurrentUser } = useAuth()
  const { t } = useI18n()
  const [checking, setChecking] = useState(false)
  const [stillPending, setStillPending] = useState(false)
  const [checkError, setCheckError] = useState(false)
  const [signingOut, setSigningOut] = useState(false)

  async function handleCheckAgain() {
    setChecking(true)
    setStillPending(false)
    setCheckError(false)
    try {
      const current = await authApi.currentUser()
      setCurrentUser(current)
      setStillPending(current?.email_verification_pending === true)
    } catch {
      setCheckError(true)
    } finally {
      setChecking(false)
    }
  }

  async function handleSignOut() {
    setSigningOut(true)
    try {
      await logout()
    } catch {
      // Keep the session visible; the user can retry.
    } finally {
      setSigningOut(false)
    }
  }

  return (
    <section aria-labelledby="verify-email-title" className="mx-auto flex w-full max-w-lg flex-col gap-5">
      <div>
        <span className="w-fit rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_var(--color-shadow)]">
          {t('verifyEmail.badge')}
        </span>
        <h2 id="verify-email-title" className="mt-3 font-headline text-3xl font-black tracking-tight uppercase text-on-surface">
          {t('verifyEmail.title')}
        </h2>
      </div>

      <div className="flex flex-col gap-4 rounded-md border-2 border-outline bg-surface-bright p-6 shadow-[2px_2px_0px_var(--color-shadow)]">
        <p className="text-sm leading-6 text-on-surface">
          <Interpolate
            template={t('verifyEmail.body')}
            values={{ email: <strong className="break-all font-bold">{user?.email ?? ''}</strong> }}
          />
        </p>
        <p className="text-sm leading-6 text-on-surface-variant">{t('verifyEmail.spamHint')}</p>

        <ResendVerificationButton />

        <div className="flex flex-col gap-2 border-t border-outline-variant pt-4">
          <button type="button" onClick={() => void handleCheckAgain()} disabled={checking} className={`w-fit ${SECONDARY_BUTTON}`}>
            {t('verifyEmail.checkAgain')}
          </button>
          <p role="status" aria-live="polite" className="font-mono text-xs text-on-surface-variant">
            {stillPending ? t('verifyEmail.stillPending') : null}
            {checkError ? t('auth.serverUnreachable') : null}
          </p>
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <Link to={ACCOUNT_ROUTE} className="text-sm font-bold text-on-surface underline">
          {t('verifyEmail.manageAccount')}
        </Link>
        <button type="button" onClick={() => void handleSignOut()} disabled={signingOut} className={SECONDARY_BUTTON}>
          {signingOut ? t('header.signingOut') : t('header.signOut')}
        </button>
      </div>
    </section>
  )
}
