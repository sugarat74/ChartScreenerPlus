/**
 * Admin "Universos" freshness table (universe-selector-ui): the latest Daily
 * Bar date of every Screener universe against the last session its Listing
 * Market should already have published, from
 * `GET /api/admin/universes/freshness` (Admin only, enforced server-side).
 * Admin text may say what the data is; the status is always a word, never
 * color alone.
 */

import { useEffect, useState } from 'react'
import { useI18n } from '../../i18n/useI18n.ts'
import { adminUniversesApi, apiErrorMessage } from '../../lib/api.ts'
import type { UniverseFreshness, UniverseFreshnessStatus } from '../../lib/api.ts'

const STATUS_CLASSES: Record<UniverseFreshnessStatus, string> = {
  fresh: 'border-gain bg-gain text-on-gain',
  partial: 'border-secondary bg-primary-container text-on-primary-container',
  stale: 'border-secondary bg-secondary-container text-on-secondary-container',
}

export default function UniverseFreshnessPanel() {
  const { t, formatMarketDate, formatInteger } = useI18n()
  const [rows, setRows] = useState<UniverseFreshness[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [token, setToken] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    adminUniversesApi
      .freshness(controller.signal)
      .then((result) => {
        setRows(result)
        setError(null)
      })
      .catch((caught: unknown) => {
        if (!(caught instanceof Error && caught.name === 'AbortError')) {
          setError(apiErrorMessage(caught, t('common.networkError')))
        }
      })
    return () => controller.abort()
  }, [token, t])

  function refresh() {
    setRows(null)
    setError(null)
    setToken((value) => value + 1)
  }

  return (
    <section
      aria-labelledby="admin-universes-title"
      className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_var(--color-shadow)]"
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 id="admin-universes-title" className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
            {t('admin.universes.title')}
          </h2>
          <p className="mt-1 max-w-2xl font-mono text-[11px] text-on-surface-variant">{t('admin.universes.intro')}</p>
        </div>
        <button
          type="button"
          onClick={refresh}
          className="rounded-md border-2 border-outline bg-surface-bright px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_var(--color-shadow)] focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
        >
          {t('admin.universes.refresh')}
        </button>
      </div>

      {error !== null ? (
        <p
          role="alert"
          className="mt-3 rounded-[4px] border-2 border-secondary bg-secondary-container px-3 py-2 font-mono text-xs text-on-secondary-container"
        >
          {t('admin.universes.loadError')} {error}
        </p>
      ) : null}

      {error === null && rows === null ? (
        <p role="status" className="mt-3 font-mono text-xs uppercase tracking-wider text-on-surface-variant">
          {t('admin.universes.loading')}
        </p>
      ) : null}

      {rows !== null && rows.length === 0 ? (
        <p className="mt-3 font-mono text-xs text-on-surface-variant">{t('admin.universes.empty')}</p>
      ) : null}

      {rows !== null && rows.length > 0 ? (
        <div className="mt-3 overflow-x-auto">
          <table className="w-full border-collapse text-left">
            <thead>
              <tr className="border-b-2 border-outline font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
                <th scope="col" className="px-2 py-2">{t('admin.universes.colUniverse')}</th>
                <th scope="col" className="px-2 py-2">{t('admin.universes.colMarket')}</th>
                <th scope="col" className="px-2 py-2 text-right">{t('admin.universes.colMembers')}</th>
                <th scope="col" className="px-2 py-2">{t('admin.universes.colLatest')}</th>
                <th scope="col" className="px-2 py-2">{t('admin.universes.colExpected')}</th>
                <th scope="col" className="px-2 py-2 text-right">{t('admin.universes.colWithBar')}</th>
                <th scope="col" className="px-2 py-2 text-right">{t('admin.universes.colWithoutBar')}</th>
                <th scope="col" className="px-2 py-2">{t('admin.universes.colStatus')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.slug} className="border-b border-outline-variant align-top">
                  <th scope="row" className="px-2 py-2 text-left font-headline text-sm font-black text-on-surface">
                    {row.name}
                  </th>
                  <td className="px-2 py-2 font-mono text-xs text-on-surface-variant">
                    {row.market} · {row.currency}
                  </td>
                  <td className="px-2 py-2 text-right font-mono text-xs text-on-surface">{formatInteger(row.members)}</td>
                  <td className="px-2 py-2 font-mono text-xs text-on-surface">{formatMarketDate(row.latest_date)}</td>
                  <td className="px-2 py-2 font-mono text-xs text-on-surface">{formatMarketDate(row.expected_date)}</td>
                  <td className="px-2 py-2 text-right font-mono text-xs text-on-surface">{formatInteger(row.with_bar)}</td>
                  <td className="px-2 py-2 text-right font-mono text-xs text-on-surface">{formatInteger(row.without_bar)}</td>
                  <td className="px-2 py-2">
                    <span
                      className={`inline-flex rounded-[4px] border-2 px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider ${STATUS_CLASSES[row.status]}`}
                    >
                      {t(`admin.universes.status.${row.status}`)}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </section>
  )
}
