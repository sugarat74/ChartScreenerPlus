/**
 * Portal control for the daily alert email (docs/specs/alerts-email-digest.md).
 *
 * Off unless the user ticks it: the box is never pre-ticked, and ticking it is
 * the explicit opt-in the server records with the page language. Hidden while
 * the feature is unavailable (no sending provider configured yet).
 */
import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { useI18n } from '../../i18n/useI18n.ts'
import { alertEmailApi, apiErrorMessage } from '../../lib/api.ts'
import type { AlertEmailPreference } from '../../lib/api.ts'
import { PRIVACY_ROUTE } from '../../nav.ts'

export default function AlertEmailOptIn() {
  const { t } = useI18n()
  const [preference, setPreference] = useState<AlertEmailPreference | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    alertEmailApi
      .get()
      .then((next) => {
        if (active) setPreference(next)
      })
      .catch(() => {
        // Not offered when the preference cannot be read.
      })
    return () => {
      active = false
    }
  }, [])

  if (preference === null || !preference.available) {
    return null
  }

  async function change(optIn: boolean) {
    setBusy(true)
    setError(null)
    try {
      setPreference(await alertEmailApi.update(optIn))
    } catch (caught) {
      setError(apiErrorMessage(caught, t('common.networkError')))
    } finally {
      setBusy(false)
    }
  }

  return (
    <section
      aria-labelledby="alert-email-heading"
      className="flex flex-col gap-3 rounded-md border-2 border-outline bg-surface-bright p-4 shadow-[2px_2px_0px_var(--color-shadow)]"
    >
      <h3 id="alert-email-heading" className="font-headline text-base font-black uppercase tracking-wide text-on-surface">
        {t('alertEmail.title')}
      </h3>
      <p className="text-sm text-on-surface-variant">{t('alertEmail.intro')}</p>
      <label className="flex items-start gap-2 text-sm text-on-surface">
        <input
          type="checkbox"
          checked={preference.opted_in}
          disabled={busy}
          onChange={(event) => void change(event.target.checked)}
          className="mt-0.5 h-4 w-4 accent-primary"
        />
        <span>{t('alertEmail.label')}</span>
      </label>
      <p className="font-mono text-[11px] text-on-surface-variant">
        {t('alertEmail.hint')}{' '}
        <Link to={PRIVACY_ROUTE} className="font-bold text-on-surface underline">
          {t('alertEmail.privacy')}
        </Link>
      </p>
      {preference.opted_in ? (
        <p role="status" className="text-xs font-bold text-on-surface">
          {t('alertEmail.on')}
        </p>
      ) : null}
      {error !== null ? (
        <p role="alert" className="rounded-[4px] border-2 border-secondary bg-secondary-container px-3 py-2 font-mono text-xs text-on-secondary-container">
          {error}
        </p>
      ) : null}
    </section>
  )
}
