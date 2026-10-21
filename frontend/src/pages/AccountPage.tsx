/**
 * Account page (`/portal/cuenta`, account-self-service).
 *
 * Four sections for the signed-in user: profile (display name), password,
 * data download and a danger zone that deletes the account. The page is
 * guarded by `RequireAuth`; the API scopes every call to the session user and
 * enforces the password, confirmation word and Admin rules. After deletion the
 * user is cleared from the auth context, so the guard sends them home (the
 * same no-flash path as a sign-out).
 */

import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import RequireAuth from '../auth/RequireAuth.tsx'
import { useAuth } from '../auth/useAuth.ts'
import AuthField from '../components/AuthField.tsx'
import Interpolate from '../i18n/Interpolate.tsx'
import { useI18n } from '../i18n/useI18n.ts'
import { useLegalInfo } from '../legal/useLegalInfo.ts'
import { accountApi, ApiError, apiErrorMessage } from '../lib/api.ts'
import type { AuthUser, ValidationErrors } from '../lib/api.ts'
import { LOGIN_ROUTE, PRIVACY_ROUTE } from '../nav.ts'

const PRIMARY_BUTTON_CLASS =
  'w-fit rounded-md border-2 border-outline bg-primary-container px-3 py-2 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:translate-y-0'

const DANGER_BUTTON_CLASS =
  'w-fit rounded-md border-2 border-outline bg-secondary px-3 py-2 font-headline text-xs font-bold uppercase tracking-wider text-on-secondary shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0'

const CARD_CLASS =
  'flex min-w-0 flex-col gap-4 rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_var(--color-shadow)]'

/** Field-level errors from a 422, or one form-level message otherwise. */
type FormFailure = { fields: ValidationErrors; message: string | null }

const NO_FAILURE: FormFailure = { fields: {}, message: null }

function useFailureHandler() {
  const { t } = useI18n()
  const navigate = useNavigate()

  return (caught: unknown): FormFailure => {
    if (caught instanceof ApiError && caught.status === 401) {
      // Session expired: send the user back through sign-in.
      navigate(LOGIN_ROUTE, { replace: true })
      return NO_FAILURE
    }
    if (caught instanceof ApiError && Object.keys(caught.errors).length > 0) {
      return { fields: caught.errors, message: null }
    }
    return { fields: {}, message: apiErrorMessage(caught, t('common.networkError')) }
  }
}

function FormMessage({ failure, success }: { failure: string | null; success: string | null }) {
  if (failure !== null) {
    return (
      <p
        role="alert"
        className="rounded-[4px] border-2 border-secondary bg-secondary-container px-3 py-2 font-mono text-xs text-on-secondary-container"
      >
        {failure}
      </p>
    )
  }
  if (success !== null) {
    return (
      <p role="status" className="rounded-[4px] border-2 border-outline bg-surface-container px-3 py-2 font-mono text-xs text-on-surface">
        {success}
      </p>
    )
  }
  return null
}

function ProfileSection({ user }: { user: AuthUser }) {
  const { t } = useI18n()
  const { setCurrentUser } = useAuth()
  const toFailure = useFailureHandler()
  const [name, setName] = useState(user.name)
  const [saving, setSaving] = useState(false)
  const [failure, setFailure] = useState<FormFailure>(NO_FAILURE)
  const [saved, setSaved] = useState(false)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    setSaved(false)
    setFailure(NO_FAILURE)
    try {
      const updated = await accountApi.update(name)
      setCurrentUser(updated)
      setName(updated.name)
      setSaved(true)
    } catch (caught) {
      setFailure(toFailure(caught))
    } finally {
      setSaving(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} noValidate aria-labelledby="account-profile" className={CARD_CLASS}>
      <h2 id="account-profile" className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
        {t('account.profileTitle')}
      </h2>
      <div className="flex flex-col gap-1.5">
        <span className="font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant">
          {t('account.emailLabel')}
        </span>
        <span className="break-all font-mono text-sm text-on-surface">{user.email}</span>
      </div>
      <AuthField
        id="account-name"
        label={t('auth.name')}
        value={name}
        onChange={setName}
        autoComplete="name"
        error={failure.fields.name?.[0]}
      />
      <FormMessage failure={failure.message} success={saved ? t('account.nameSaved') : null} />
      <button type="submit" disabled={saving || name.trim() === ''} className={PRIMARY_BUTTON_CLASS}>
        {saving ? t('account.nameSaving') : t('account.nameSubmit')}
      </button>
    </form>
  )
}

function PasswordSection() {
  const { t } = useI18n()
  const toFailure = useFailureHandler()
  const [current, setCurrent] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [saving, setSaving] = useState(false)
  const [failure, setFailure] = useState<FormFailure>(NO_FAILURE)
  const [saved, setSaved] = useState(false)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSaving(true)
    setSaved(false)
    setFailure(NO_FAILURE)
    try {
      await accountApi.changePassword({
        current_password: current,
        password,
        password_confirmation: confirmation,
      })
      setCurrent('')
      setPassword('')
      setConfirmation('')
      setSaved(true)
    } catch (caught) {
      setFailure(toFailure(caught))
    } finally {
      setSaving(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} noValidate aria-labelledby="account-password" className={CARD_CLASS}>
      <div>
        <h2 id="account-password" className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
          {t('account.passwordTitle')}
        </h2>
        <p className="mt-1 text-sm text-on-surface-variant">{t('account.passwordIntro')}</p>
      </div>
      <AuthField
        id="account-current-password"
        label={t('account.currentPassword')}
        type="password"
        value={current}
        onChange={setCurrent}
        autoComplete="current-password"
        error={failure.fields.current_password?.[0]}
      />
      <AuthField
        id="account-new-password"
        label={t('account.newPassword')}
        type="password"
        value={password}
        onChange={setPassword}
        autoComplete="new-password"
        error={failure.fields.password?.[0]}
      />
      <AuthField
        id="account-new-password-confirmation"
        label={t('account.newPasswordConfirmation')}
        type="password"
        value={confirmation}
        onChange={setConfirmation}
        autoComplete="new-password"
        error={failure.fields.password_confirmation?.[0]}
      />
      <FormMessage failure={failure.message} success={saved ? t('account.passwordSaved') : null} />
      <button
        type="submit"
        disabled={saving || current === '' || password === '' || confirmation === ''}
        className={PRIMARY_BUTTON_CLASS}
      >
        {saving ? t('account.passwordSaving') : t('account.passwordSubmit')}
      </button>
    </form>
  )
}

function ExportSection() {
  const { t } = useI18n()
  const toFailure = useFailureHandler()
  const [preparing, setPreparing] = useState(false)
  const [failure, setFailure] = useState<string | null>(null)
  const [done, setDone] = useState(false)

  async function handleDownload() {
    setPreparing(true)
    setDone(false)
    setFailure(null)
    try {
      const file = await accountApi.export()
      const url = URL.createObjectURL(file.blob)
      const link = document.createElement('a')
      link.href = url
      link.download = file.filename
      document.body.append(link)
      link.click()
      link.remove()
      // Give the browser a tick to start the download before releasing it.
      window.setTimeout(() => URL.revokeObjectURL(url), 1000)
      setDone(true)
    } catch (caught) {
      const result = toFailure(caught)
      setFailure(result.message ?? Object.values(result.fields)[0]?.[0] ?? null)
    } finally {
      setPreparing(false)
    }
  }

  return (
    <section aria-labelledby="account-export" className={CARD_CLASS}>
      <div>
        <h2 id="account-export" className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
          {t('account.exportTitle')}
        </h2>
        <p className="mt-1 text-sm text-on-surface-variant">{t('account.exportIntro')}</p>
      </div>
      <FormMessage failure={failure} success={done ? t('account.exportDone') : null} />
      <button type="button" onClick={handleDownload} disabled={preparing} className={PRIMARY_BUTTON_CLASS}>
        {preparing ? t('account.exportPreparing') : t('account.exportSubmit')}
      </button>
    </section>
  )
}

function DangerZone({ user }: { user: AuthUser }) {
  const { t } = useI18n()
  const { setCurrentUser } = useAuth()
  const legal = useLegalInfo()
  const toFailure = useFailureHandler()
  const [confirmation, setConfirmation] = useState('')
  const [password, setPassword] = useState('')
  const [deleting, setDeleting] = useState(false)
  const [failure, setFailure] = useState<FormFailure>(NO_FAILURE)

  const word = t('account.confirmationWord')
  const ready = confirmation.trim() !== '' && password !== ''

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!ready) {
      return
    }
    setDeleting(true)
    setFailure(NO_FAILURE)
    try {
      await accountApi.remove(password, confirmation.trim())
      // The session is gone: clearing the user lets RequireAuth send the
      // visitor home (not to sign-in), like a sign-out.
      setCurrentUser(null)
    } catch (caught) {
      setFailure(toFailure(caught))
      setDeleting(false)
    }
  }

  const retention =
    legal.status === 'ready'
      ? t('account.deleteRetention', {
          backupDays: legal.info.retention.backup_days,
          activityDays: legal.info.retention.sign_in_activity_days,
        })
      : t('account.deleteRetentionGeneric')

  return (
    <section
      aria-labelledby="account-delete"
      className="flex min-w-0 flex-col gap-4 rounded-md border-2 border-secondary bg-secondary-container p-5 shadow-[2px_2px_0px_var(--color-shadow)]"
    >
      <div>
        <h2 id="account-delete" className="font-headline text-lg font-black uppercase tracking-wide text-on-secondary-container">
          {t('account.deleteTitle')}
        </h2>
        <p className="mt-1 text-sm text-on-secondary-container">{t('account.deleteIntro')}</p>
        <p className="mt-2 text-xs text-on-secondary-container">{retention}</p>
      </div>

      {user.role === 'admin' ? (
        <p role="note" className="rounded-[4px] border-2 border-outline bg-surface-bright px-3 py-2 font-mono text-xs text-on-surface">
          {t('account.adminCannotDelete')}
        </p>
      ) : (
        <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
          <AuthField
            id="account-delete-confirmation"
            label={t('account.confirmationLabel', { word })}
            value={confirmation}
            onChange={setConfirmation}
            autoComplete="off"
            error={failure.fields.confirmation?.[0]}
          />
          <AuthField
            id="account-delete-password"
            label={t('account.deletePassword')}
            type="password"
            value={password}
            onChange={setPassword}
            autoComplete="current-password"
            error={failure.fields.password?.[0] ?? failure.fields.account?.[0]}
          />
          <FormMessage failure={failure.message} success={null} />
          <button type="submit" disabled={!ready || deleting} className={DANGER_BUTTON_CLASS}>
            {deleting ? t('account.deleting') : t('account.deleteSubmit')}
          </button>
        </form>
      )}
    </section>
  )
}

function AccountContent() {
  const { user } = useAuth()
  const { t } = useI18n()

  if (user === null) {
    return null
  }

  return (
    <section className="flex flex-col gap-6">
      <header className="flex flex-col gap-2">
        <span className="w-fit rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_var(--color-shadow)]">
          {t('account.badge')}
        </span>
        <h1 className="font-headline text-3xl font-black tracking-tight uppercase text-on-surface">{t('account.title')}</h1>
        <p className="max-w-2xl text-sm text-on-surface-variant">{t('account.intro')}</p>
        <p className="max-w-2xl text-xs text-on-surface-variant">
          <Interpolate
            template={t('account.otherRequests')}
            values={{
              privacy: (
                <Link to={PRIVACY_ROUTE} className="font-bold text-on-surface underline">
                  {t('account.privacyLink')}
                </Link>
              ),
            }}
          />
        </p>
      </header>

      <div className="grid gap-6 lg:grid-cols-2 lg:items-start">
        <ProfileSection user={user} />
        <PasswordSection />
        <ExportSection />
        <DangerZone user={user} />
      </div>
    </section>
  )
}

export default function AccountPage() {
  return (
    <RequireAuth>
      <AccountContent />
    </RequireAuth>
  )
}
