import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link } from 'react-router'
import AuthField from '../components/AuthField.tsx'
import { useI18n } from '../i18n/useI18n.ts'
import { apiErrorMessage, passwordResetApi } from '../lib/api.ts'
import { LOGIN_ROUTE, PRIVACY_ROUTE } from '../nav.ts'

export default function ForgotPasswordPage() {
  const { t } = useI18n()
  const [email, setEmail] = useState('')
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [sending, setSending] = useState(false)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSending(true)
    setError(null)
    try {
      setMessage(await passwordResetApi.request(email))
    } catch (cause) {
      setError(apiErrorMessage(cause, t('auth.serverUnreachable')))
    } finally {
      setSending(false)
    }
  }

  return <section className="mx-auto w-full max-w-md space-y-5">
    <h2 className="font-headline text-3xl font-black uppercase">{t('auth.forgotTitle')}</h2>
    <p className="text-sm text-on-surface-variant">{t('auth.forgotIntro')}</p>
    <p className="text-xs text-on-surface-variant">{t('auth.forgotPrivacy')} <Link to={PRIVACY_ROUTE} className="font-bold underline">{t('auth.registerPrivacyPolicy')}</Link></p>
    <form onSubmit={submit} className="space-y-4 rounded-md border-2 border-outline bg-surface-bright p-6 shadow-[2px_2px_0px_var(--color-shadow)]">
      <AuthField id="email" label={t('auth.email')} type="email" autoComplete="email" value={email} onChange={setEmail} />
      {error && <p role="alert" className="text-sm text-secondary">{error}</p>}
      {message && <p role="status" className="text-sm text-on-surface">{message}</p>}
      <button type="submit" disabled={sending} className="rounded-md border-2 border-outline bg-primary-container px-4 py-2 font-headline font-bold uppercase shadow-[2px_2px_0px_var(--color-shadow)] disabled:opacity-70">
        {sending ? t('auth.forgotSending') : t('auth.forgotSubmit')}
      </button>
    </form>
    <Link to={LOGIN_ROUTE} className="text-sm font-bold underline">{t('auth.signIn')}</Link>
  </section>
}
