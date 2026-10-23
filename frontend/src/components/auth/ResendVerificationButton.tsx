import { useEffect, useState } from 'react'
import { useAuth } from '../../auth/useAuth.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import { ApiError, apiErrorMessage, authApi, emailVerificationApi } from '../../lib/api.ts'

/** Seconds the button stays disabled after a confirmation email was sent. */
const RESEND_COOLDOWN_SECONDS = 60

type ResendState = 'idle' | 'sending' | 'sent' | 'error'

/**
 * "Resend the email" for an account that still has to confirm its address
 * (auth-email-verification). After a send it waits 60 s; the server also
 * throttles it (6 per hour) and its localized 429 message is shown as is.
 * When the server says there is nothing to confirm (already verified, or 409
 * because verification is off) the session user is refreshed, which removes
 * the gate.
 */
export default function ResendVerificationButton() {
  const { t } = useI18n()
  const { setCurrentUser } = useAuth()
  const [state, setState] = useState<ResendState>('idle')
  const [error, setError] = useState<string | null>(null)
  const [cooldown, setCooldown] = useState(0)

  useEffect(() => {
    if (cooldown <= 0) {
      return
    }
    const timer = window.setTimeout(() => setCooldown((seconds) => seconds - 1), 1000)
    return () => window.clearTimeout(timer)
  }, [cooldown])

  async function refreshUser() {
    try {
      setCurrentUser(await authApi.currentUser())
    } catch {
      // Keep the current state; the user can retry.
    }
  }

  async function handleResend() {
    setState('sending')
    setError(null)
    try {
      const status = await emailVerificationApi.resend()
      if (status === 'already_verified') {
        setState('idle')
        await refreshUser()
        return
      }
      setState('sent')
      setCooldown(RESEND_COOLDOWN_SECONDS)
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 409) {
        setState('idle')
        await refreshUser()
        return
      }
      setState('error')
      setError(apiErrorMessage(caught, t('auth.serverUnreachable')))
    }
  }

  return (
    <div className="flex flex-col gap-2">
      <button
        type="button"
        onClick={() => void handleResend()}
        disabled={state === 'sending' || cooldown > 0}
        className="w-fit rounded-md border-2 border-outline bg-primary-container px-4 py-2.5 font-headline text-sm font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px disabled:cursor-not-allowed disabled:opacity-70"
      >
        {state === 'sending' ? t('verifyEmail.resending') : t('verifyEmail.resend')}
      </button>
      <p role="status" aria-live="polite" className="font-mono text-xs text-on-surface-variant">
        {state === 'sent' ? t('verifyEmail.resent') : null}
        {state === 'sent' && cooldown > 0 ? ' ' : null}
        {cooldown > 0 ? t('verifyEmail.cooldown', { seconds: cooldown }) : null}
      </p>
      {state === 'error' && error ? (
        <p
          role="alert"
          className="rounded-[4px] border-2 border-secondary bg-secondary-container px-3 py-2 font-mono text-xs text-on-secondary-container"
        >
          {error}
        </p>
      ) : null}
    </div>
  )
}
