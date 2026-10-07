/**
 * Portal-only Saved Screeners list. The protected API remains the ownership
 * boundary; this component only presents the caller-scoped collection and
 * replays a stored definition through the Screener's canonical URL helpers.
 */

import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router'
import { useAuth } from '../../auth/useAuth.ts'
import { useI18n, useTranslateRef } from '../../i18n/useI18n.ts'
import { ApiError, apiErrorMessage, savedScreenersApi } from '../../lib/api.ts'
import type { SavedScreener } from '../../lib/api.ts'
import {
  describeSavedFilters,
  deserializeScreenerFilters,
  patchScreenerFilters,
} from '../../lib/screenerFilters.ts'
import { LOGIN_ROUTE } from '../../nav.ts'


const PRIMARY_BUTTON_CLASS =
  'rounded-md border-2 border-outline bg-primary-container px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60'

const SECONDARY_BUTTON_CLASS =
  'rounded-md border-2 border-outline bg-surface-bright px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60'

export default function PortalSavedScreeners() {
  const { user } = useAuth()
  const { t, formatDecimal } = useI18n()
  const tRef = useTranslateRef()
  const navigate = useNavigate()
  const [items, setItems] = useState<SavedScreener[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [reloadToken, setReloadToken] = useState(0)
  const [removingId, setRemovingId] = useState<number | null>(null)
  const [removeError, setRemoveError] = useState<string | null>(null)

  useEffect(() => {
    if (user === null) {
      return
    }

    let active = true

    void (async () => {
      try {
        const list = await savedScreenersApi.list()
        if (active) {
          setItems(list)
          setError(null)
          setLoading(false)
        }
      } catch (caught) {
        if (!active) {
          return
        }
        if (caught instanceof ApiError && caught.status === 401) {
          navigate(LOGIN_ROUTE, { replace: true })
          return
        }
        setError(apiErrorMessage(caught, tRef.current('common.networkError')))
        setLoading(false)
      }
    })()

    return () => {
      active = false
    }
  }, [user, reloadToken, navigate, tRef])

  function reload() {
    setLoading(true)
    setError(null)
    setReloadToken((token) => token + 1)
  }

  function apply(screener: SavedScreener) {
    const patch = deserializeScreenerFilters(screener.filters)
    const params = patchScreenerFilters(new URLSearchParams(), patch)
    navigate(`/screener${params.size > 0 ? `?${params.toString()}` : ''}`)
  }

  async function remove(id: number) {
    setRemovingId(id)
    setRemoveError(null)

    try {
      await savedScreenersApi.remove(id)
      // Reload the caller-scoped API list after the protected mutation rather
      // than maintaining a shared cache or assuming ownership client-side.
      reload()
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 404) {
        // The row is already absent for this caller; refresh the authoritative list.
        reload()
      } else if (caught instanceof ApiError && caught.status === 401) {
        navigate(LOGIN_ROUTE, { replace: true })
      } else {
        setRemoveError(apiErrorMessage(caught, t('common.networkError')))
      }
    } finally {
      setRemovingId(null)
    }
  }

  return (
    <section className="flex flex-col gap-4" aria-labelledby="portal-screeners-heading">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 id="portal-screeners-heading" className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
            {t('saved.title')}
          </h2>
          <p className="mt-1 text-sm text-on-surface-variant">
            {t('saved.portalIntro')}
          </p>
        </div>
        <span className="rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a]">
          {items.length === 1
            ? t('saved.countOne', { count: items.length })
            : t('saved.countOther', { count: items.length })}
        </span>
      </header>

      {loading ? (
        <div role="status" aria-busy="true" className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]">
          <p className="font-mono text-xs uppercase tracking-wider text-on-surface-variant">{t('saved.loading')}</p>
          <div className="mt-4 flex flex-col gap-2" aria-hidden="true">
            <div className="h-9 rounded-[2px] bg-surface-container" />
            <div className="h-9 rounded-[2px] bg-surface-container" />
          </div>
        </div>
      ) : null}

      {!loading && error !== null ? (
        <div role="alert" className="flex flex-col gap-3 rounded-md border-2 border-secondary bg-secondary-container p-5 shadow-[2px_2px_0px_#1a1a1a]">
          <div>
            <p className="font-headline text-sm font-black uppercase tracking-wide text-on-secondary-container">{t('saved.loadError')}</p>
            <p className="mt-1 font-mono text-xs text-on-secondary-container">{error}</p>
          </div>
          <button type="button" onClick={reload} className={PRIMARY_BUTTON_CLASS}>{t('common.retry')}</button>
        </div>
      ) : null}

      {!loading && error === null && items.length === 0 ? (
        <div className="rounded-md border-2 border-outline bg-surface-bright p-8 text-center shadow-[2px_2px_0px_#1a1a1a]">
          <p className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">{t('saved.portalEmptyTitle')}</p>
          <p className="mt-2 text-sm text-on-surface-variant">{t('saved.portalEmptyBody')}</p>
        </div>
      ) : null}

      {!loading && error === null && items.length > 0 ? (
        <>
          {removeError !== null ? <p role="alert" className="rounded-[4px] border-2 border-secondary bg-secondary-container px-3 py-2 font-mono text-xs text-on-secondary-container">{removeError}</p> : null}
          <div className="overflow-x-auto rounded-md border-2 border-outline bg-surface-bright shadow-[2px_2px_0px_#1a1a1a]">
            <table className="w-full border-collapse text-left">
              <thead>
                <tr className="border-b-2 border-outline bg-surface-container font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
                  <th scope="col" className="px-4 py-2">{t('saved.colNameCriteria')}</th>
                  <th scope="col" className="px-4 py-2 text-right">{t('saved.colActions')}</th>
                </tr>
              </thead>
              <tbody>
                {items.map((screener) => (
                  <tr key={screener.id} className="border-b border-outline-variant align-top">
                    <th scope="row" className="px-4 py-3 text-left font-normal">
                      <p className="font-mono text-sm font-bold text-on-surface">{screener.name}</p>
                      <p className="mt-0.5 max-w-2xl text-xs text-on-surface-variant">{describeSavedFilters(screener.filters, t, formatDecimal)}</p>
                    </th>
                    <td className="px-4 py-3 text-right">
                      <div className="flex flex-wrap justify-end gap-2">
                        <button type="button" onClick={() => apply(screener)} className={PRIMARY_BUTTON_CLASS}>{t('saved.apply')}</button>
                        <button type="button" onClick={() => void remove(screener.id)} disabled={removingId === screener.id} className={SECONDARY_BUTTON_CLASS}>
                          {removingId === screener.id ? t('saved.removing') : t('saved.remove')}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      ) : null}
    </section>
  )
}
