/**
 * Portal: the caller-owned Saved Screeners and Watchlist for a Registered User.
 *
 * The page is guarded by `RequireAuth` (a Visitor is redirected to sign-in),
 * but the API is the enforcement point — every request runs through
 * `auth:sanctum` and is scoped server-side to the session user. Only listing
 * and removal live here; adding Watchlist entries happens from the chart page.
 */

import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import RequireAuth from '../auth/RequireAuth.tsx'
import { useAuth } from '../auth/useAuth.ts'
import PortalSavedScreeners from '../components/portal/PortalSavedScreeners.tsx'
import WatchlistTable from '../components/watchlist/WatchlistTable.tsx'
import { useI18n, useTranslateRef } from '../i18n/useI18n.ts'
import { ApiError, apiErrorMessage, watchlistApi } from '../lib/api.ts'
import type { WatchlistEntry } from '../lib/api.ts'
import { LOGIN_ROUTE } from '../nav.ts'

const ACTION_LINK_CLASS =
  'w-fit rounded-md border-2 border-outline bg-primary-container px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none'

function WatchlistPanel() {
  const { user } = useAuth()
  const { t } = useI18n()
  const tRef = useTranslateRef()
  const navigate = useNavigate()

  const [items, setItems] = useState<WatchlistEntry[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [reloadToken, setReloadToken] = useState(0)

  const [removingTicker, setRemovingTicker] = useState<string | null>(null)
  const [removeError, setRemoveError] = useState<string | null>(null)

  // Fetch the current entries on mount and after a manual reload. The keys
  // (`user`, `reloadToken`) make a login/logout or retry re-run the request.
  useEffect(() => {
    if (user === null) {
      return
    }

    let active = true

    void (async () => {
      try {
        const list = await watchlistApi.list()
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
          // Session expired: send the user back through sign-in.
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

  async function handleRemove(ticker: string) {
    setRemovingTicker(ticker)
    setRemoveError(null)

    try {
      await watchlistApi.remove(ticker)
      setItems((current) => current.filter((item) => item.ticker !== ticker))
    } catch (caught) {
      // A 404 means the row was already gone: drop it locally too.
      if (caught instanceof ApiError && caught.status === 404) {
        setItems((current) => current.filter((item) => item.ticker !== ticker))
      } else if (caught instanceof ApiError && caught.status === 401) {
        navigate(LOGIN_ROUTE, { replace: true })
      } else {
        setRemoveError(apiErrorMessage(caught, t('common.networkError')))
      }
    } finally {
      setRemovingTicker(null)
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h3 className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
            {t('watchlist.title')}
          </h3>
          <p className="mt-1 text-sm text-on-surface-variant">
            {t('watchlist.intro')}
          </p>
        </div>
        <span className="rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a]">
          {items.length === 1
            ? t('watchlist.countOne', { count: items.length })
            : t('watchlist.countOther', { count: items.length })}
        </span>
      </div>

      {loading ? (
        <div
          role="status"
          aria-busy="true"
          className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]"
        >
          <p className="font-mono text-xs uppercase tracking-wider text-on-surface-variant">
            {t('watchlist.loading')}
          </p>
          <div className="mt-4 flex flex-col gap-2" aria-hidden="true">
            <div className="h-9 rounded-[2px] bg-surface-container" />
            <div className="h-9 rounded-[2px] bg-surface-container" />
            <div className="h-9 rounded-[2px] bg-surface-container" />
          </div>
        </div>
      ) : null}

      {!loading && error !== null ? (
        <div
          role="alert"
          className="flex flex-col gap-3 rounded-md border-2 border-secondary bg-secondary-container p-5 shadow-[2px_2px_0px_#1a1a1a]"
        >
          <div>
            <p className="font-headline text-sm font-black uppercase tracking-wide text-on-secondary-container">
              {t('watchlist.loadError')}
            </p>
            <p className="mt-1 font-mono text-xs text-on-secondary-container">{error}</p>
          </div>
          <button type="button" onClick={reload} className={ACTION_LINK_CLASS}>
            {t('common.retry')}
          </button>
        </div>
      ) : null}

      {!loading && error === null && items.length === 0 ? (
        <div className="rounded-md border-2 border-outline bg-surface-bright p-8 text-center shadow-[2px_2px_0px_#1a1a1a]">
          <p className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
            {t('watchlist.emptyTitle')}
          </p>
          <p className="mt-2 text-sm text-on-surface-variant">
            {t('watchlist.emptyBody')}
          </p>
          <div className="mt-4 flex justify-center">
            <Link to="/screener" className={ACTION_LINK_CLASS}>
              {t('watchlist.goToScreener')}
            </Link>
          </div>
        </div>
      ) : null}

      {!loading && error === null && items.length > 0 ? (
        <>
          {removeError !== null ? (
            <p
              role="alert"
              className="rounded-[4px] border-2 border-secondary bg-secondary-container px-3 py-2 font-mono text-xs text-on-secondary-container"
            >
              {removeError}
            </p>
          ) : null}
          <WatchlistTable
            items={items}
            removingTicker={removingTicker}
            onRemove={handleRemove}
          />
        </>
      ) : null}
    </div>
  )
}

export default function PortalPage() {
  const { t } = useI18n()

  return (
    <RequireAuth>
      <section className="flex flex-col gap-6">
        <header className="flex flex-col gap-2">
          <div className="flex flex-wrap items-center gap-3">
            <span className="rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a]">
              {t('portal.badge')}
            </span>
            <span className="font-mono text-[11px] uppercase tracking-wider text-on-surface-variant">
              {t('portal.session')}
            </span>
          </div>

          <div>
            <h1 className="font-headline text-3xl font-black tracking-tight uppercase text-on-surface">
              {t('portal.title')}
            </h1>
            <p className="mt-1 max-w-2xl text-sm text-on-surface-variant">
              {t('portal.intro')}
            </p>
          </div>
        </header>

        <div className="grid gap-8 xl:grid-cols-2 xl:items-start">
          <PortalSavedScreeners />
          <WatchlistPanel />
        </div>
      </section>
    </RequireAuth>
  )
}
