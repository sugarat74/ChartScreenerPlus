import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router'
import AuthField from '../components/AuthField.tsx'
import { useI18n } from '../i18n/useI18n.ts'
import { apiErrorMessage, passwordResetApi } from '../lib/api.ts'
import { FORGOT_PASSWORD_ROUTE, LOGIN_ROUTE } from '../nav.ts'

export default function ResetPasswordPage() {
  const { t } = useI18n()
  const [params, setParams] = useSearchParams()
  const email = params.get('email') ?? ''
  const token = params.get('token') ?? ''
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    setError(null)
    try {
      setMessage(await passwordResetApi.reset(email, token, password, confirmation))
      setParams({}, { replace: true })
    } catch (cause) {
      setError(apiErrorMessage(cause, t('auth.serverUnreachable')))
    } finally {
      setSaving(false)
    }
  }

  return <section className="mx-auto w-full max-w-md space-y-5">
    <h2 className="font-headline text-3xl font-black uppercase">{t('auth.resetTitle')}</h2>
    {message ? (
      <p role="status">{message} <Link to={LOGIN_ROUTE} className="font-bold underline">{t('auth.signIn')}</Link></p>
    ) : !token || !email ? <p role="alert">{t('auth.resetInvalid')}</p> : <>
      <p className="text-sm text-on-surface-variant">{t('auth.resetIntro')}</p>
      <form onSubmit={submit} className="space-y-4 rounded-md border-2 border-outline bg-surface-bright p-6 shadow-[2px_2px_0px_var(--color-shadow)]">
        <AuthField id="password" label={t('auth.password')} type="password" autoComplete="new-password" value={password} onChange={setPassword} />
        <AuthField id="password_confirmation" label={t('auth.passwordConfirmation')} type="password" autoComplete="new-password" value={confirmation} onChange={setConfirmation} />
        {error && <p role="alert" className="text-sm text-secondary">{error}</p>}
        <button type="submit" disabled={saving} className="rounded-md border-2 border-outline bg-primary-container px-4 py-2 font-headline font-bold uppercase shadow-[2px_2px_0px_var(--color-shadow)] disabled:opacity-70">
          {saving ? t('auth.resetSaving') : t('auth.resetSubmit')}
        </button>
      </form>
    </>}
    <Link to={FORGOT_PASSWORD_ROUTE} className="text-sm font-bold underline">{t('auth.resetNewLink')}</Link>
  </section>
}
