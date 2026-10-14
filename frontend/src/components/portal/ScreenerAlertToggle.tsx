import { useState } from 'react'
import { useNavigate } from 'react-router'
import { useI18n } from '../../i18n/useI18n.ts'
import { ApiError, alertsApi, apiErrorMessage } from '../../lib/api.ts'
import type { Alert, SavedScreener } from '../../lib/api.ts'
import { LOGIN_ROUTE } from '../../nav.ts'
import AlertStatusChip from './AlertStatusChip.tsx'

type Props = {
  screener: SavedScreener
  alert: Alert | undefined
  /** True while the Portal is still loading the alert list (or it failed). */
  disabled: boolean
  onSaved: (alert: Alert) => void
  onRemoved: (alertId: number) => void
}

/**
 * "Notify me of new candidates" for one Saved Screener: switching it on
 * creates the alert (or resumes a paused one), switching it off pauses it,
 * and "remove" deletes it. API messages (limit, duplicate) are shown inline.
 */
export default function ScreenerAlertToggle({ screener, alert, disabled, onSaved, onRemoved }: Props) {
  const { t } = useI18n()
  const navigate = useNavigate()
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const checked = alert?.active === true

  async function run(action: () => Promise<void>) {
    setSaving(true)
    setError(null)
    try {
      await action()
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 401) {
        navigate(LOGIN_ROUTE, { replace: true })
        return
      }
      if (caught instanceof ApiError && caught.status === 404 && alert !== undefined) {
        // Already gone for this caller: reflect that instead of failing.
        onRemoved(alert.id)
        return
      }
      setError(apiErrorMessage(caught, t('common.networkError')))
    } finally {
      setSaving(false)
    }
  }

  function toggle() {
    void run(async () => {
      const saved =
        alert === undefined
          ? await alertsApi.create({ kind: 'screener_new_candidates', saved_screener_id: screener.id })
          : await alertsApi.update(alert.id, { active: !alert.active })
      onSaved(saved)
    })
  }

  function remove() {
    if (alert === undefined) {
      return
    }
    void run(async () => {
      await alertsApi.remove(alert.id)
      onRemoved(alert.id)
    })
  }

  return (
    <div className="mt-2 flex flex-col gap-1.5">
      <div className="flex flex-wrap items-center gap-2">
        <button
          type="button"
          role="switch"
          aria-checked={checked}
          aria-label={t('alerts.screenerToggleAria', { name: screener.name })}
          onClick={toggle}
          disabled={disabled || saving}
          className="flex items-center gap-2 rounded-md py-0.5 pr-1 text-left font-headline text-[11px] font-bold uppercase tracking-wider text-on-surface focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
        >
          <span
            aria-hidden="true"
            className={`flex h-5 w-9 shrink-0 items-center rounded-[4px] border-2 border-outline px-0.5 transition-colors ${checked ? 'justify-end bg-primary-container' : 'justify-start bg-surface-container'}`}
          >
            <span className="h-3 w-3 rounded-[2px] bg-primary" />
          </span>
          <span>{saving ? t('alerts.saving') : t('alerts.screenerToggle')}</span>
        </button>
        {disabled && alert === undefined ? null : <AlertStatusChip alert={alert} />}
        {alert !== undefined ? (
          <button
            type="button"
            onClick={remove}
            disabled={saving}
            aria-label={t('alerts.deleteAlertAria', { name: screener.name })}
            className="font-mono text-[11px] text-on-surface-variant underline underline-offset-2 hover:text-on-surface focus:outline-2 focus:outline-offset-2 focus:outline-outline disabled:cursor-not-allowed disabled:opacity-60"
          >
            {t('alerts.deleteAlert')}
          </button>
        ) : null}
      </div>
      {error !== null ? (
        <p role="alert" className="w-fit rounded-[4px] border-2 border-secondary bg-secondary-container px-2 py-1 font-mono text-[11px] text-on-secondary-container">
          {error}
        </p>
      ) : null}
    </div>
  )
}
