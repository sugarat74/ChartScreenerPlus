/**
 * Notification inbox (`/portal/notificaciones`, alerts-ui).
 *
 * Lists the caller's alert notifications newest first (20 per page from the
 * API), links each ticker to its chart and marks one or all as read. The page
 * is guarded by `RequireAuth`; the API scopes every call to the session user.
 * Notification text is rendered here from the stored language-neutral data.
 */

import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import RequireAuth from '../auth/RequireAuth.tsx'
import { useAuth } from '../auth/useAuth.ts'
import { useI18n, useTranslateRef } from '../i18n/useI18n.ts'
import type { Translate } from '../i18n/translate.ts'
import { ApiError, apiErrorMessage, notificationsApi } from '../lib/api.ts'
import type { AppNotification, NotificationsPage as NotificationsPayload } from '../lib/api.ts'
import { signalLabel } from '../lib/screenerFilters.ts'
import { LOGIN_ROUTE } from '../nav.ts'
import { useUnreadNotifications } from '../notifications/useUnreadNotifications.ts'

const PRIMARY_BUTTON_CLASS =
  'w-fit rounded-md border-2 border-outline bg-primary-container px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60'

const SECONDARY_BUTTON_CLASS =
  'w-fit rounded-md border-2 border-outline bg-surface-bright px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60'

function notificationTitle(notification: AppNotification, t: Translate): string {
  if (notification.kind === 'watchlist_signal') {
    return t('notifications.kindWatchlist')
  }
  const name = notification.saved_screener?.name
  return name ? t('notifications.kindScreener', { name }) : t('notifications.kindScreenerUnnamed')
}

function reasonLabel(reason: string, t: Translate): string {
  return reason === 'new_candidate' ? t('notifications.reasonNewCandidate') : signalLabel(reason, t)
}

export default function NotificationsPage() {
  return (
    <RequireAuth>
      <NotificationsInbox />
    </RequireAuth>
  )
}

function NotificationsInbox() {
  const { user } = useAuth()
  const { t, formatMarketDate } = useI18n()
  const tRef = useTranslateRef()
  const navigate = useNavigate()
  const { count: unreadCount, setCount } = useUnreadNotifications()

  const [page, setPage] = useState(1)
  const [payload, setPayload] = useState<NotificationsPayload | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [reloadToken, setReloadToken] = useState(0)
  const [marking, setMarking] = useState<string | 'all' | null>(null)
  const [markError, setMarkError] = useState<string | null>(null)

  useEffect(() => {
    if (user === null) {
      return
    }

    let active = true

    void (async () => {
      try {
        const next = await notificationsApi.list(page)
        if (!active) {
          return
        }
        if (next.data.length === 0 && page > 1 && next.meta.last_page < page) {
          // The page vanished (e.g. retention pruning): go to the last one.
          setPage(Math.max(1, next.meta.last_page))
          return
        }
        setPayload(next)
        setCount(next.unread_count)
        setError(null)
        setLoading(false)
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
  }, [user, page, reloadToken, navigate, tRef, setCount])

  function reload() {
    setLoading(true)
    setError(null)
    setReloadToken((token) => token + 1)
  }

  function goTo(nextPage: number) {
    setLoading(true)
    setError(null)
    setPage(nextPage)
  }

  async function markRead(target: string | 'all') {
    setMarking(target)
    setMarkError(null)
    try {
      await notificationsApi.markRead(target === 'all' ? { all: true } : { ids: [target] })
      const now = new Date().toISOString()
      setPayload((current) =>
        current === null
          ? current
          : {
              ...current,
              data: current.data.map((item) =>
                (target === 'all' || item.id === target) && item.read_at === null ? { ...item, read_at: now } : item,
              ),
            },
      )
      setCount(target === 'all' ? 0 : unreadCount - 1)
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 401) {
        navigate(LOGIN_ROUTE, { replace: true })
        return
      }
      setMarkError(apiErrorMessage(caught, t('common.networkError')))
    } finally {
      setMarking(null)
    }
  }

  const items = payload?.data ?? []
  const meta = payload?.meta

  return (
    <section className="flex flex-col gap-6" aria-labelledby="notifications-heading">
      <header className="flex flex-col gap-2">
        <div className="flex flex-wrap items-center gap-3">
          <span className="rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a]">
            {t('portal.badge')}
          </span>
          <span className="font-mono text-[11px] uppercase tracking-wider text-on-surface-variant">
            {unreadCount > 0 ? t('notifications.unreadSummary', { count: unreadCount }) : t('notifications.allRead')}
          </span>
        </div>
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h1 id="notifications-heading" className="font-headline text-3xl font-black tracking-tight uppercase text-on-surface">
              {t('notifications.title')}
            </h1>
            <p className="mt-1 max-w-2xl text-sm text-on-surface-variant">{t('notifications.intro')}</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <button
              type="button"
              onClick={() => void markRead('all')}
              disabled={unreadCount === 0 || marking !== null}
              className={PRIMARY_BUTTON_CLASS}
            >
              {marking === 'all' ? t('notifications.marking') : t('notifications.markAllRead')}
            </button>
            <Link to="/portal" className={SECONDARY_BUTTON_CLASS}>
              {t('notifications.manageAlerts')}
            </Link>
          </div>
        </div>
      </header>

      {markError !== null ? (
        <p role="alert" className="rounded-[4px] border-2 border-secondary bg-secondary-container px-3 py-2 font-mono text-xs text-on-secondary-container">
          {markError}
        </p>
      ) : null}

      {loading ? (
        <div role="status" aria-busy="true" className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]">
          <p className="font-mono text-xs uppercase tracking-wider text-on-surface-variant">{t('notifications.loading')}</p>
          <div className="mt-4 flex flex-col gap-2" aria-hidden="true">
            <div className="h-14 rounded-[2px] bg-surface-container" />
            <div className="h-14 rounded-[2px] bg-surface-container" />
          </div>
        </div>
      ) : null}

      {!loading && error !== null ? (
        <div role="alert" className="flex flex-col gap-3 rounded-md border-2 border-secondary bg-secondary-container p-5 shadow-[2px_2px_0px_#1a1a1a]">
          <div>
            <p className="font-headline text-sm font-black uppercase tracking-wide text-on-secondary-container">{t('notifications.loadError')}</p>
            <p className="mt-1 font-mono text-xs text-on-secondary-container">{error}</p>
          </div>
          <button type="button" onClick={reload} className={PRIMARY_BUTTON_CLASS}>{t('common.retry')}</button>
        </div>
      ) : null}

      {!loading && error === null && items.length === 0 ? (
        <div className="rounded-md border-2 border-outline bg-surface-bright p-8 text-center shadow-[2px_2px_0px_#1a1a1a]">
          <p className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">{t('notifications.emptyTitle')}</p>
          <p className="mx-auto mt-2 max-w-xl text-sm text-on-surface-variant">{t('notifications.emptyBody')}</p>
          <div className="mt-4 flex justify-center">
            <Link to="/portal" className={PRIMARY_BUTTON_CLASS}>{t('notifications.manageAlerts')}</Link>
          </div>
        </div>
      ) : null}

      {!loading && error === null && items.length > 0 ? (
        <ol className="flex flex-col gap-3">
          {items.map((notification) => {
            const unread = notification.read_at === null
            return (
              <li
                key={notification.id}
                className={`flex flex-col gap-3 rounded-md border-2 border-outline p-4 shadow-[2px_2px_0px_#1a1a1a] ${unread ? 'bg-surface-bright' : 'bg-surface-container'}`}
              >
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <span
                        className={`rounded-[4px] border border-outline px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider shadow-[1px_1px_0px_#1a1a1a] ${unread ? 'bg-secondary text-on-secondary' : 'bg-surface-dim text-on-surface'}`}
                      >
                        {unread ? t('notifications.unread') : t('notifications.read')}
                      </span>
                      <span className="font-mono text-[11px] text-on-surface-variant">
                        {t('notifications.asOf', { date: formatMarketDate(notification.as_of) })}
                      </span>
                    </div>
                    <h2 className="mt-1.5 break-words font-headline text-sm font-black uppercase tracking-wide text-on-surface">
                      {notificationTitle(notification, t)}
                    </h2>
                  </div>
                  {unread ? (
                    <button
                      type="button"
                      onClick={() => void markRead(notification.id)}
                      disabled={marking !== null}
                      className="font-mono text-[11px] text-on-surface-variant underline underline-offset-2 hover:text-on-surface focus:outline-2 focus:outline-offset-2 focus:outline-outline disabled:cursor-not-allowed disabled:opacity-60"
                    >
                      {marking === notification.id ? t('notifications.marking') : t('notifications.markRead')}
                    </button>
                  ) : null}
                </div>
                <ul className="flex flex-wrap gap-2">
                  {notification.items.map((item) => (
                    <li key={`${item.ticker}-${item.reason}`}>
                      <Link
                        to={`/instruments/${encodeURIComponent(item.ticker)}`}
                        aria-label={`${t('notifications.openChartAria', { ticker: item.ticker })}: ${reasonLabel(item.reason, t)}`}
                        title={item.name ?? undefined}
                        className="flex items-center gap-1.5 rounded-[4px] border-2 border-outline bg-surface-container-lowest px-2 py-1 shadow-[1px_1px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
                      >
                        <span className="font-mono text-xs font-bold text-on-surface">{item.ticker}</span>
                        <span className="text-[11px] text-on-surface-variant">{reasonLabel(item.reason, t)}</span>
                      </Link>
                    </li>
                  ))}
                  {notification.more > 0 ? (
                    <li className="flex items-center px-1 font-mono text-[11px] text-on-surface-variant">
                      {t('notifications.more', { count: notification.more })}
                    </li>
                  ) : null}
                </ul>
              </li>
            )
          })}
        </ol>
      ) : null}

      {!loading && error === null && meta !== undefined && meta.last_page > 1 ? (
        <nav aria-label={t('notifications.title')} className="flex flex-wrap items-center justify-between gap-3">
          <button type="button" onClick={() => goTo(page - 1)} disabled={page <= 1} className={SECONDARY_BUTTON_CLASS}>
            {t('notifications.previousPage')}
          </button>
          <span className="font-mono text-xs text-on-surface-variant">
            {t('notifications.pageCount', { page: meta.current_page, last: meta.last_page })}
          </span>
          <button type="button" onClick={() => goTo(page + 1)} disabled={page >= meta.last_page} className={SECONDARY_BUTTON_CLASS}>
            {t('notifications.nextPage')}
          </button>
        </nav>
      ) : null}
    </section>
  )
}
