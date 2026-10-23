import { useEffect, useState } from 'react'
import { useI18n, useTranslateRef } from '../../i18n/useI18n.ts'
import type { MessageKey } from '../../i18n/translate.ts'
import { MARKET_CONTEXT_COMMANDS, adminMarketContextApi, apiErrorMessage } from '../../lib/api.ts'
import type { AdminMarketContextStatus, MarketContextCommand, MarketContextRun } from '../../lib/api.ts'

/** Poll interval while a run is queued or running (same as the ingestion panel). */
export const MARKET_CONTEXT_POLL_MS = 2500

const COMMAND_LABEL: Record<MarketContextCommand, MessageKey> = {
  'market-context:compute': 'admin.marketContext.commands.compute',
  'macro:refresh': 'admin.marketContext.commands.macro',
  'market-regime:compute': 'admin.marketContext.commands.regime',
}

const CARD = 'rounded-md border-2 border-outline bg-surface-bright p-4 shadow-[2px_2px_0px_var(--color-shadow)]'
const TH = 'px-2 py-1.5 font-mono text-[10px] uppercase text-on-surface-variant'
const TD = 'px-2 py-1.5 font-mono text-xs'

function isActive(run: MarketContextRun | null): boolean {
  return run !== null && (run.status === 'queued' || run.status === 'running')
}

/**
 * Admin Market Context tab (market-context-ui, C3): the go-live and macro
 * switches, the last run of each command with a rerun button, snapshot
 * coverage and every configured macro series. Admin-only server-side.
 */
export default function AdminMarketContextPage() {
  const { t, formatDateTime, formatMarketDate, formatInteger } = useI18n()
  const [status, setStatus] = useState<AdminMarketContextStatus | null>(null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [pending, setPending] = useState<MarketContextCommand | null>(null)
  const polling = status !== null && MARKET_CONTEXT_COMMANDS.some((command) => isActive(status.runs[command]))

  const translate = useTranslateRef()

  useEffect(() => {
    let active = true
    const load = () =>
      adminMarketContextApi.status().then(
        (next) => {
          if (!active) return
          setStatus(next)
          setLoadError(null)
        },
        (error: unknown) => {
          if (active) setLoadError(apiErrorMessage(error, translate.current('admin.marketContext.loadError')))
        },
      )
    void load()
    // Poll while a run is queued or running (same cadence as the ingestion panel).
    const timer = polling ? window.setInterval(() => void load(), MARKET_CONTEXT_POLL_MS) : undefined
    return () => {
      active = false
      if (timer !== undefined) window.clearInterval(timer)
    }
  }, [polling, translate])

  async function rerun(command: MarketContextCommand) {
    setPending(command)
    setActionError(null)
    try {
      const result = await adminMarketContextApi.rerun(command)
      setStatus((current) => (current ? { ...current, runs: { ...current.runs, [command]: result.run } } : current))
    } catch (error) {
      setActionError(apiErrorMessage(error, t('admin.marketContext.rerunError')))
    } finally {
      setPending(null)
    }
  }

  const statusLabel = (run: MarketContextRun | null) =>
    run === null ? t('admin.marketContext.status.never') : t(`admin.marketContext.status.${run.status}`)

  return (
    <section className="flex flex-col gap-5">
      <header className="flex flex-col gap-1">
        <h2 className="font-headline text-2xl font-black uppercase tracking-tight text-on-surface">{t('admin.marketContext.title')}</h2>
        <p className="text-sm text-on-surface-variant">{t('admin.marketContext.intro')}</p>
      </header>

      {loadError ? (
        <p role="alert" className="rounded-md border-2 border-secondary bg-secondary-container p-3 text-sm text-on-secondary-container">
          {loadError}
        </p>
      ) : null}

      {status === null ? (
        loadError ? null : <p className="font-mono text-sm text-on-surface-variant">{t('common.loading')}</p>
      ) : (
        <>
          <div className={`${CARD} flex flex-col gap-1 font-mono text-xs text-on-surface`}>
            <p>
              <span className="font-bold">{t('admin.marketContext.public')}: </span>
              {status.public ? t('admin.marketContext.publicOn') : t('admin.marketContext.publicOff')}
            </p>
            <p>{status.macro_enabled ? t('admin.marketContext.macroOn') : t('admin.marketContext.macroOff')}</p>
          </div>

          <div className={CARD}>
            <h3 className="mb-2 font-headline text-sm font-bold uppercase tracking-wide">{t('admin.marketContext.runs')}</h3>
            {actionError ? (
              <p role="alert" className="mb-2 font-mono text-xs text-secondary">
                {actionError}
              </p>
            ) : null}
            <div role="region" aria-label={t('admin.marketContext.runs')} tabIndex={0} className="overflow-x-auto">
              <table className="w-full min-w-[44rem] text-left">
                <thead className="border-b-2 border-outline bg-surface-container">
                  <tr>
                    <th scope="col" className={TH}>{t('admin.marketContext.columns.command')}</th>
                    <th scope="col" className={TH}>{t('admin.marketContext.columns.status')}</th>
                    <th scope="col" className={TH}>{t('admin.marketContext.columns.queued')}</th>
                    <th scope="col" className={TH}>{t('admin.marketContext.columns.started')}</th>
                    <th scope="col" className={TH}>{t('admin.marketContext.columns.finished')}</th>
                    <th scope="col" className={`${TH} text-right`}>{t('admin.marketContext.columns.exit')}</th>
                    <th scope="col" className={TH}><span className="sr-only">{t('admin.marketContext.rerun')}</span></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-outline-variant">
                  {MARKET_CONTEXT_COMMANDS.map((command) => {
                    const run = status.runs[command]
                    const active = isActive(run)
                    const macroOff = command === 'macro:refresh' && !status.macro_enabled
                    return (
                      <tr key={command} data-command={command}>
                        <th scope="row" className="px-2 py-1.5 text-left">
                          <span className="block text-xs font-bold text-on-surface">{t(COMMAND_LABEL[command])}</span>
                          <code className="font-mono text-[10px] text-on-surface-variant">{command}</code>
                        </th>
                        <td className={`${TD} font-bold ${run?.status === 'failed' || run?.status === 'interrupted' ? 'text-secondary' : run?.status === 'succeeded' ? 'text-gain' : ''}`}>
                          {statusLabel(run)}
                        </td>
                        <td className={TD}>{formatDateTime(run?.queued_at ?? null)}</td>
                        <td className={TD}>{formatDateTime(run?.started_at ?? null)}</td>
                        <td className={TD}>{formatDateTime(run?.finished_at ?? null)}</td>
                        <td className={`${TD} text-right`}>{run?.exit_code ?? t('common.empty')}</td>
                        <td className="px-2 py-1.5 text-right">
                          <button
                            type="button"
                            disabled={active || macroOff || pending !== null}
                            onClick={() => void rerun(command)}
                            aria-label={`${t('admin.marketContext.rerun')} ${command}`}
                            className="rounded-md border-2 border-outline bg-primary-container px-2.5 py-1 font-headline text-[11px] font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_var(--color-shadow)] disabled:cursor-not-allowed disabled:opacity-60"
                          >
                            {active || pending === command ? t('admin.marketContext.rerunActive') : t('admin.marketContext.rerun')}
                          </button>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
            <p className="mt-2 font-mono text-[11px] text-on-surface-variant">{t('admin.marketContext.regimeHint')}</p>
            {!status.macro_enabled ? (
              <p className="mt-1 font-mono text-[11px] text-on-surface-variant">{t('admin.marketContext.macroDisabledNote')}</p>
            ) : null}
          </div>

          <div className={CARD}>
            <h3 className="mb-2 font-headline text-sm font-bold uppercase tracking-wide">{t('admin.marketContext.snapshots')}</h3>
            <dl className="grid grid-cols-1 gap-3 font-mono text-xs sm:grid-cols-3">
              <div>
                <dt className="font-bold">{t('marketContext.sectors.title')}</dt>
                {status.snapshots.sectors ? (
                  <dd>
                    {t('admin.marketContext.firstDate')}: {formatMarketDate(status.snapshots.sectors.first_date)}
                    <br />
                    {t('admin.marketContext.latestDate')}: {formatMarketDate(status.snapshots.sectors.latest_date)}
                    <br />
                    {t('admin.marketContext.rows')}: {formatInteger(status.snapshots.sectors.latest_rows)}
                  </dd>
                ) : (
                  <dd>{t('admin.marketContext.empty')}</dd>
                )}
              </div>
              <div>
                <dt className="font-bold">{t('marketContext.breadth.title')}</dt>
                {status.snapshots.breadth ? (
                  <dd>
                    {t('admin.marketContext.firstDate')}: {formatMarketDate(status.snapshots.breadth.first_date)}
                    <br />
                    {t('admin.marketContext.latestDate')}: {formatMarketDate(status.snapshots.breadth.latest_date)}
                  </dd>
                ) : (
                  <dd>{t('admin.marketContext.empty')}</dd>
                )}
              </div>
              <div>
                <dt className="font-bold">{t('marketContext.regime.title')}</dt>
                {status.snapshots.regime ? (
                  <dd>
                    {t('admin.marketContext.firstDate')}: {formatMarketDate(status.snapshots.regime.first_date)}
                    <br />
                    {t('admin.marketContext.latestDate')}: {formatMarketDate(status.snapshots.regime.latest_date)}
                    <br />
                    {t('admin.marketContext.count')}: {formatInteger(status.snapshots.regime.count)} · v{status.snapshots.regime.rules_version}
                    <br />
                    {t('admin.marketContext.latestRegime')}: {t(`marketContext.regime.labels.${status.snapshots.regime.latest.regime}`)}
                    {status.snapshots.regime.latest.score !== null ? ` (${status.snapshots.regime.latest.score})` : ''} · {status.snapshots.regime.latest.available}
                    <br />
                    <span data-testid="regime-distribution">
                      {t('admin.marketContext.distribution')}:{' '}
                      {(['favourable', 'neutral', 'adverse', 'insufficient'] as const)
                        .map((label) => `${t(`marketContext.regime.labels.${label}`)} ${status.snapshots.regime!.distribution[label]}`)
                        .join(' · ')}
                    </span>
                  </dd>
                ) : (
                  <dd>{t('admin.marketContext.empty')}</dd>
                )}
              </div>
            </dl>
          </div>

          <div className={CARD}>
            <h3 className="mb-2 font-headline text-sm font-bold uppercase tracking-wide">{t('admin.marketContext.series')}</h3>
            <div role="region" aria-label={t('admin.marketContext.series')} tabIndex={0} className="overflow-x-auto">
              <table className="w-full min-w-[60rem] text-left">
                <thead className="border-b-2 border-outline bg-surface-container">
                  <tr>
                    <th scope="col" className={TH}>{t('admin.marketContext.columns.code')}</th>
                    <th scope="col" className={TH}>{t('admin.marketContext.columns.category')}</th>
                    <th scope="col" className={TH}>{t('admin.marketContext.columns.frequency')}</th>
                    <th scope="col" className={TH}>{t('admin.marketContext.columns.licence')}</th>
                    <th scope="col" className={TH}>{t('admin.marketContext.columns.enabled')}</th>
                    <th scope="col" className={TH}>{t('admin.marketContext.columns.lastObservation')}</th>
                    <th scope="col" className={TH}>{t('admin.marketContext.columns.lastRefresh')}</th>
                    <th scope="col" className={TH}>{t('admin.marketContext.columns.lastAttempt')}</th>
                    <th scope="col" className={TH}>{t('admin.marketContext.columns.lastError')}</th>
                    <th scope="col" className={TH}>{t('admin.marketContext.columns.stale')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-outline-variant">
                  {status.series.map((series) => (
                    <tr key={series.code} data-series={series.code}>
                      <th scope="row" className={`${TD} text-left font-bold`}>{series.code}</th>
                      <td className={TD}>{series.category}</td>
                      <td className={TD}>{series.frequency}</td>
                      <td className={TD}>{series.license}</td>
                      <td className={TD}>{series.enabled ? t('admin.marketContext.yes') : t('admin.marketContext.no')}</td>
                      <td className={TD}>{formatMarketDate(series.last_observation_date)}</td>
                      <td className={TD}>{formatDateTime(series.last_refreshed_at)}</td>
                      <td className={TD}>{formatDateTime(series.last_attempted_at)}</td>
                      <td className={`${TD} ${series.last_error ? 'font-bold text-secondary' : ''}`}>{series.last_error ?? t('common.empty')}</td>
                      <td className={`${TD} ${series.stale ? 'font-bold text-secondary' : ''}`}>
                        {series.stale ? t('admin.marketContext.yes') : t('admin.marketContext.no')}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </section>
  )
}
