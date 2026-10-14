/**
 * "My pattern alerts": the single `watchlist_pattern` Alert of the caller
 * (docs/specs/alerts-patterns.md). Choose chartist pattern types and the
 * events to be told about (appears / confirmed by a breakout; confirmed is
 * pre-ticked on a new alert) and save; an existing alert can be paused/resumed
 * or deleted. Save is disabled while no type or no event is selected or
 * nothing changed; API messages (e.g. the alert limit) show inline.
 */

import { useState } from 'react'
import type { FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { useI18n } from '../../i18n/useI18n.ts'
import { ALERT_PATTERN_EVENTS, ApiError, alertsApi, apiErrorMessage } from '../../lib/api.ts'
import type { Alert, AlertPatternEvent, ScreenerPatternType } from '../../lib/api.ts'
import { SCREENER_PATTERN_TYPES, patternLabel } from '../../lib/screenerFilters.ts'
import { LOGIN_ROUTE } from '../../nav.ts'
import AlertStatusChip from './AlertStatusChip.tsx'

const PRIMARY_BUTTON_CLASS =
  'rounded-md border-2 border-outline bg-primary-container px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60'

const SECONDARY_BUTTON_CLASS =
  'rounded-md border-2 border-outline bg-surface-bright px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60'

const LEGEND_CLASS = 'mb-2 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant'

const DEFAULT_EVENTS: AlertPatternEvent[] = ['confirmed']

type Props = {
  /** The caller's existing Watchlist pattern alert, if any. */
  alert: Alert | undefined
  /** True while the Portal alert list is loading or failed to load. */
  disabled: boolean
  onSaved: (alert: Alert) => void
  onRemoved: (alertId: number) => void
}

function sameSet(a: readonly string[], b: readonly string[]): boolean {
  return a.length === b.length && a.every((value) => b.includes(value))
}

/** Toggle `value` in `current`, keeping the order of `order`. */
function toggled<T extends string>(order: readonly T[], current: readonly T[], value: T): T[] {
  return current.includes(value)
    ? current.filter((item) => item !== value)
    : order.filter((item) => item === value || current.includes(item))
}

export default function WatchlistPatternAlertForm({ alert, disabled, onSaved, onRemoved }: Props) {
  const { t } = useI18n()
  const navigate = useNavigate()
  const savedTypes = alert?.pattern_types ?? []
  const savedEvents = alert?.pattern_events ?? DEFAULT_EVENTS
  const [types, setTypes] = useState<ScreenerPatternType[]>(savedTypes)
  const [events, setEvents] = useState<AlertPatternEvent[]>(savedEvents)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const incomplete = types.length === 0 || events.length === 0
  const unchanged = alert !== undefined && sameSet(types, savedTypes) && sameSet(events, savedEvents)
  const saveDisabled = disabled || busy || incomplete || unchanged

  function toggleType(type: ScreenerPatternType) {
    setNotice(null)
    setTypes((current) => toggled(SCREENER_PATTERN_TYPES, current, type))
  }

  function toggleEvent(event: AlertPatternEvent) {
    setNotice(null)
    setEvents((current) => toggled(ALERT_PATTERN_EVENTS, current, event))
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
          ? await alertsApi.create({ kind: 'watchlist_pattern', pattern_types: types, pattern_events: events })
          : await alertsApi.update(alert.id, { pattern_types: types, pattern_events: events })
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
      setTypes([])
      setEvents(DEFAULT_EVENTS)
      return t('alerts.removed')
    })
  }

  return (
    <section
      aria-labelledby="pattern-alert-heading"
      className="flex flex-col gap-3 rounded-md border-2 border-outline bg-surface-bright p-4 shadow-[2px_2px_0px_#1a1a1a]"
    >
      <header className="flex flex-wrap items-start justify-between gap-2">
        <div className="min-w-0">
          <h3 id="pattern-alert-heading" className="font-headline text-base font-black uppercase tracking-wide text-on-surface">
            {t('alerts.patternTitle')}
          </h3>
          <p className="mt-1 text-sm text-on-surface-variant">{t('alerts.patternIntro')}</p>
          <p className="mt-1 font-mono text-[11px] text-on-surface-variant">{t('alerts.patternHint')}</p>
        </div>
        {disabled && alert === undefined ? null : <AlertStatusChip alert={alert} />}
      </header>

      <form onSubmit={save} className="flex flex-col gap-3">
        <fieldset disabled={disabled || busy} className="flex flex-col gap-2">
          <legend className={LEGEND_CLASS}>{t('alerts.patternTypesLegend')}</legend>
          <div className="grid gap-x-4 gap-y-1.5 sm:grid-cols-2">
            {SCREENER_PATTERN_TYPES.map((type) => (
              <label key={type} className="flex items-center gap-2 text-sm text-on-surface">
                <input
                  type="checkbox"
                  checked={types.includes(type)}
                  onChange={() => toggleType(type)}
                  className="h-4 w-4 accent-[#1a1a1a]"
                />
                {patternLabel(type, t)}
              </label>
            ))}
          </div>
        </fieldset>

        <fieldset disabled={disabled || busy} className="flex flex-col gap-2">
          <legend className={LEGEND_CLASS}>{t('alerts.patternEventsLegend')}</legend>
          <div className="grid gap-x-4 gap-y-1.5 sm:grid-cols-2">
            {ALERT_PATTERN_EVENTS.map((event) => (
              <label key={event} className="flex items-center gap-2 text-sm text-on-surface">
                <input
                  type="checkbox"
                  checked={events.includes(event)}
                  onChange={() => toggleEvent(event)}
                  className="h-4 w-4 accent-[#1a1a1a]"
                />
                {t(`alerts.patternEvents.${event}` as const)}
              </label>
            ))}
          </div>
        </fieldset>

        {incomplete ? (
          <p className="font-mono text-[11px] text-on-surface-variant">{t('alerts.patternNoneSelected')}</p>
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
