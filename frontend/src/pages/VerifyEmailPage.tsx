import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import ResendVerificationButton from '../components/auth/ResendVerificationButton.tsx'
import { useAuth } from '../auth/useAuth.ts'
import { useI18n } from '../i18n/useI18n.ts'
import type { MessageKey } from '../i18n/translate.ts'
import { ApiError, authApi, emailVerificationApi } from '../lib/api.ts'
import { DEFAULT_ROUTE, LOGIN_ROUTE } from '../nav.ts'

type VerifyState = 'verifying' | 'verified' | 'already' | 'expired' | 'invalid' | 'error'

const TITLES: Record<VerifyState, MessageKey> = {
  verifying: 'verifyEmail.verifyingTitle',
  verified: 'verifyEmail.verifiedTitle',
  already: 'verifyEmail.alreadyTitle',
  expired: 'verifyEmail.expiredTitle',
  invalid: 'verifyEmail.invalidTitle',
  error: 'verifyEmail.title',
}

const BODIES: Record<VerifyState, MessageKey> = {
  verifying: 'verifyEmail.verifyingTitle',
  verified: 'verifyEmail.verifiedBody',
  already: 'verifyEmail.verifiedBody',
  expired: 'verifyEmail.expiredBody',
  invalid: 'verifyEmail.invalidBody',
  error: 'verifyEmail.errorBody',
}

const PRIMARY_BUTTON =
  'w-fit rounded-md border-2 border-outline bg-primary-container px-4 py-2.5 font-headline text-sm font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px'

/**
 * `/verificar-correo/:userId/:hash?expires=…&signature=…`, the link of the
 * confirmation email (auth-email-verification, D5). It POSTs the signed link
 * once (the signature is the credential, so it works in a private window) and
 * never signs anyone in. The session is re-read afterwards, which removes the
 * "confirm your email" gate of a signed-in account.
 */
export default function VerifyEmailPage() {
  const { userId = '', hash = '' } = useParams()
  const [searchParams] = useSearchParams()
  const expires = searchParams.get('expires') ?? ''
  const signature = searchParams.get('signature') ?? ''
  const complete = expires !== '' && signature !== ''

  const { user, setCurrentUser } = useAuth()
  const { t } = useI18n()
  const navigate = useNavigate()
  const [state, setState] = useState<VerifyState>(complete ? 'verifying' : 'invalid')
  const [errorMessage, setErrorMessage] = useState<string | null>(null)
  // One POST per visit, also under StrictMode's double effect.
  const started = useRef(false)

  const refreshSession = useCallback(async () => {
    try {
      setCurrentUser(await authApi.currentUser())
    } catch {
      // Keep the current session state; a reload refreshes it.
    }
  }, [setCurrentUser])

  const verify = useCallback(async () => {
    if (!complete) {
      return
    }
    setState('verifying')
    setErrorMessage(null)
    try {
      const status = await emailVerificationApi.verify(userId, hash, expires, signature)
      setState(status === 'verified' ? 'verified' : 'already')
      await refreshSession()
    } catch (error) {
      if (error instanceof ApiError && error.code === 'email_verification.link_expired') {
        setState('expired')
      } else if (error instanceof ApiError && error.status >= 400 && error.status < 500 && error.status !== 429) {
        setState('invalid')
      } else {
        setState('error')
        // A 429 carries the server's localized message; otherwise the generic text.
        setErrorMessage(error instanceof ApiError && error.status === 429 ? error.message : null)
      }
    }
  }, [complete, userId, hash, expires, signature, refreshSession])

  useEffect(() => {
    if (started.current) {
      return
    }
    started.current = true
    void verify()
  }, [verify])

  async function handleContinue() {
    // Re-read the session first, so a stale "pending" never shows the gate.
    await refreshSession()
    navigate(DEFAULT_ROUTE, { replace: true })
  }

  const signedIn = user !== null
  const pending = user?.email_verification_pending === true

  const signInLink = (
    <Link to={LOGIN_ROUTE} className={PRIMARY_BUTTON}>
      {t('verifyEmail.signIn')}
    </Link>
  )
  const continueButton = (
    <button type="button" onClick={() => void handleContinue()} className={PRIMARY_BUTTON}>
      {t('verifyEmail.continue')}
    </button>
  )

  let actions = null
  if (state === 'verified' || state === 'already') {
    actions = signedIn ? continueButton : signInLink
  } else if (state === 'expired' || state === 'invalid') {
    actions = pending ? <ResendVerificationButton /> : signedIn ? continueButton : signInLink
  } else if (state === 'error') {
    actions = (
      <button type="button" onClick={() => void verify()} className={PRIMARY_BUTTON}>
        {t('verifyEmail.retry')}
      </button>
    )
  }

  return (
    <section aria-labelledby="verify-page-title" className="mx-auto flex w-full max-w-lg flex-col gap-5">
      <div>
        <span className="w-fit rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_var(--color-shadow)]">
          {t('verifyEmail.badge')}
        </span>
        <h2
          id="verify-page-title"
          className="mt-3 font-headline text-3xl font-black tracking-tight uppercase text-on-surface"
        >
          {t(TITLES[state])}
        </h2>
      </div>

      <div className="flex flex-col gap-4 rounded-md border-2 border-outline bg-surface-bright p-6 shadow-[2px_2px_0px_var(--color-shadow)]">
        <p
          role="status"
          aria-live="polite"
          aria-busy={state === 'verifying'}
          className="text-sm leading-6 text-on-surface"
        >
          {errorMessage ?? t(BODIES[state])}
        </p>
        {actions}
      </div>
    </section>
  )
}
