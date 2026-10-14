/**
 * "My Watchlist alerts": the single `watchlist_signal` Alert of the caller.
 * Choose Signal types (canonical order, existing Signal labels) and save; an
 * existing alert can be paused/resumed or deleted. Save is disabled while
 * nothing is selected; API messages (e.g. the alert limit) show inline.
 */

import { useState } from 'react'
import type { FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { useI18n } from '../../i18n/useI18n.ts'
import { ApiError, alertsApi, apiErrorMessage } from '../../lib/api.ts'
import type { Alert, ScreenerSignalType } from '../../lib/api.ts'
import { SCREENER_SIGNAL_TYPES, signalLabel } from '../../lib/screenerFilters.ts'
import { LOGIN_ROUTE } from '../../nav.ts'
import AlertStatusChip from './AlertStatusChip.tsx'

const PRIMARY_BUTTON_CLASS =
  'rounded-md border-2 border-outline bg-primary-container px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60'

const SECONDARY_BUTTON_CLASS =
  'rounded-md border-2 border-outline bg-surface-bright px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60'

type Props = {
  /** The caller's existing Watchlist alert, if any. */
  alert: Alert | undefined
  /** True while the Portal alert list is loading or failed to load. */
  disabled: boolean
  onSaved: (alert: Alert) => void
  onRemoved: (alertId: number) => void
}

function sameTypes(a: readonly string[], b: readonly string[]): boolean {
  return a.length === b.length && a.every((type) => b.includes(type))
}

export default function WatchlistAlertForm({ alert, disabled, onSaved, onRemoved }: Props) {
  const { t } = useI18n()
  const navigate = useNavigate()
  const savedTypes = alert?.signal_types ?? []
  const [selected, setSelected] = useState<ScreenerSignalType[]>(savedTypes)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const unchanged = alert !== undefined && sameTypes(selected, savedTypes)
  const saveDisabled = disabled || busy || selected.length === 0 || unchanged

  function toggleType(type: ScreenerSignalType) {
    setNotice(null)
    setSelected((current) =>
      current.includes(type)
        ? current.filter((item) => item !== type)
        : SCREENER_SIGNAL_TYPES.filter((item) => item === type || current.includes(item)),
    )
  }

  async function run(action: () => Promise<string>) {
    setBusy(true)
    setError(null)
    setNotice(null)
    try {
      setNotice(await action())
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 401) {
        navigate(LOGIN_ROUTE, { replace: true })
        return
      }
      if (caught instanceof ApiError && caught.status === 404 && alert !== undefined) {
        onRemoved(alert.id)
        return
      }
      setError(apiErrorMessage(caught, t('common.networkError')))
    } finally {
      setBusy(false)
    }
  }

  function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (saveDisabled) {
      return
    }
    void run(async () => {
      const saved =
        alert === undefined
          ? await alertsApi.create({ kind: 'watchlist_signal', signal_types: selected })
          : await alertsApi.update(alert.id, { signal_types: selected })
      onSaved(saved)
      return t('alerts.saved')
    })
  }

  function togglePaused() {
    if (alert === undefined) {
      return
    }
    void run(async () => {
      onSaved(await alertsApi.update(alert.id, { active: !alert.active }))
      return t('alerts.saved')
    })
  }

  function remove() {
    if (alert === undefined) {
      return
    }
    void run(async () => {
      await alertsApi.remove(alert.id)
      onRemoved(alert.id)
      setSelected([])
      return t('alerts.removed')
    })
  }

  return (
    <section
      aria-labelledby="watchlist-alert-heading"
      className="flex flex-col gap-3 rounded-md border-2 border-outline bg-surface-bright p-4 shadow-[2px_2px_0px_#1a1a1a]"
    >
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h3 id="watchlist-alert-heading" className="font-headline text-base font-black uppercase tracking-wide text-on-surface">
            {t('alerts.watchlistTitle')}
          </h3>
          <p className="mt-1 text-sm text-on-surface-variant">{t('alerts.watchlistIntro')}</p>
        </div>
        {disabled && alert === undefined ? null : <AlertStatusChip alert={alert} />}
      </header>

      <form onSubmit={save} className="flex flex-col gap-3">
        <fieldset disabled={disabled || busy} className="flex flex-col gap-2">
          <legend className="mb-2 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
            {t('alerts.typesLegend')}
          </legend>
          <div className="grid gap-x-4 gap-y-1.5 sm:grid-cols-2">
            {SCREENER_SIGNAL_TYPES.map((type) => (
              <label key={type} className="flex items-center gap-2 text-sm text-on-surface">
                <input
                  type="checkbox"
                  checked={selected.includes(type)}
                  onChange={() => toggleType(type)}
                  className="h-4 w-4 accent-[#1a1a1a]"
                />
                {signalLabel(type, t)}
              </label>
            ))}
          </div>
        </fieldset>

        {selected.length === 0 ? (
          <p className="font-mono text-[11px] text-on-surface-variant">{t('alerts.noneSelected')}</p>
        ) : null}

        <div className="flex flex-wrap items-center gap-2">
          <button type="submit" disabled={saveDisabled} className={PRIMARY_BUTTON_CLASS}>
            {busy ? t('alerts.saving') : alert === undefined ? t('alerts.save') : t('alerts.saveChanges')}
          </button>
          {alert !== undefined ? (
            <>
              <button type="button" onClick={togglePaused} disabled={disabled || busy} className={SECONDARY_BUTTON_CLASS}>
                {alert.active ? t('alerts.pause') : t('alerts.resume')}
              </button>
              <button type="button" onClick={remove} disabled={disabled || busy} className={SECONDARY_BUTTON_CLASS}>
                {t('alerts.remove')}
              </button>
            </>
          ) : null}
        </div>
      </form>

      {error !== null ? (
        <p role="alert" className="rounded-[4px] border-2 border-secondary bg-secondary-container px-3 py-2 font-mono text-xs text-on-secondary-container">
          {error}
        </p>
      ) : null}
      {notice !== null ? (
        <p role="status" className="font-mono text-xs text-on-surface">
          {notice}
        </p>
      ) : null}
    </section>
  )
}
