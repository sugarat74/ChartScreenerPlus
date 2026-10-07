import { useState } from 'react'
import { Link, useParams } from 'react-router'
import ActivityTable from '../../components/admin/users/ActivityTable.tsx'
import {
  ADMIN_BUTTON_CLASS,
  ADMIN_TABLE_HEAD_CLASS,
  AdminError,
  AdminLoading,
  AdminNoticeLine,
} from '../../components/admin/users/AdminStatus.tsx'
import { deviceLabel } from '../../components/admin/users/adminFormat.ts'
import { useAdminResource } from '../../components/admin/users/useAdminResource.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import { ApiError, adminUsersApi, apiErrorMessage } from '../../lib/api.ts'

/**
 * Admin -> Users -> one account: profile facts, active sessions (each can be
 * ended; the Admin's own current session is protected) and recent activity.
 */
export default function AdminUserDetailPage() {
  const { t, formatDateTime, formatInteger } = useI18n()
  const params = useParams<{ userId: string }>()
  const userId = Number(params.userId)
  const validId = Number.isInteger(userId) && userId > 0

  const detail = useAdminResource(`user|${params.userId}`, () =>
    validId ? adminUsersApi.detail(userId) : Promise.reject(new ApiError(404, t('adminUsers.userNotFound'))),
  )
  const [busy, setBusy] = useState<string | null>(null)
  const [notice, setNotice] = useState<{ tone: 'info' | 'error'; message: string } | null>(null)

  async function run(key: string, confirmText: string, action: () => Promise<number>) {
    if (!window.confirm(confirmText)) {
      return
    }
    setBusy(key)
    setNotice(null)
    try {
      const revoked = await action()
      setNotice({ tone: 'info', message: t('adminUsers.revokedNotice', { count: formatInteger(revoked) }) })
      detail.reload()
    } catch (caught) {
      setNotice({ tone: 'error', message: apiErrorMessage(caught, t('common.networkError')) })
    } finally {
      setBusy(null)
    }
  }

  const back = (
    <Link
      to="/admin/users"
      className="w-fit font-mono text-xs font-bold uppercase tracking-wider text-on-surface underline-offset-2 hover:underline focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
    >
      ← {t('adminUsers.backToUsers')}
    </Link>
  )

  if (detail.data === null) {
    return (
      <section className="flex flex-col gap-4">
        {back}
        {detail.error ? <AdminError message={detail.error} onRetry={detail.reload} /> : <AdminLoading />}
      </section>
    )
  }

  const { user, sessions, activity } = detail.data
  const facts: Array<[string, string]> = [
    [t('adminUsers.colRole'), t(`adminUsers.roles.${user.role}` as const)],
    [t('adminUsers.colSignedUp'), formatDateTime(user.created_at)],
    [t('adminUsers.colLastLogin'), formatDateTime(user.last_login_at)],
    [t('adminUsers.colLastActivity'), formatDateTime(user.last_activity_at)],
    [t('adminUsers.colScreeners'), formatInteger(user.saved_screeners_count)],
    [t('adminUsers.colWatchlist'), formatInteger(user.watchlist_count)],
  ]

  return (
    <section aria-busy={detail.loading} className="flex flex-col gap-6">
      {back}
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="font-headline text-3xl font-black tracking-tight text-on-surface">{user.name}</h1>
          <p className="font-mono text-sm text-on-surface-variant">{user.email}</p>
        </div>
        <button
          type="button"
          disabled={busy !== null || sessions.length === 0}
          onClick={() =>
            void run('all', t('adminUsers.confirmEndAll', { name: user.name }), () =>
              adminUsersApi.revokeUserSessions(user.id),
            )
          }
          className={`bg-primary-container text-on-primary-container ${ADMIN_BUTTON_CLASS}`}
        >
          {busy === 'all' ? t('adminUsers.ending') : t('adminUsers.endAllSessions')}
        </button>
      </header>

      {notice ? <AdminNoticeLine tone={notice.tone} message={notice.message} /> : null}
      {detail.error ? <AdminError message={detail.error} onRetry={detail.reload} /> : null}

      <dl className="grid grid-cols-2 gap-3 rounded-md border-2 border-outline bg-surface-bright p-4 shadow-[2px_2px_0px_#1a1a1a] md:grid-cols-3">
        {facts.map(([label, value]) => (
          <div key={label}>
            <dt className="font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">{label}</dt>
            <dd className="mt-1 font-mono text-sm text-on-surface">{value}</dd>
          </div>
        ))}
      </dl>

      <section aria-labelledby="user-sessions-heading" className="flex flex-col gap-3">
        <h2 id="user-sessions-heading" className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
          {t('adminUsers.activeSessionsTitle', { count: formatInteger(sessions.length) })}
        </h2>
        {sessions.length === 0 ? (
          <p className="rounded-[4px] border-2 border-dashed border-outline-variant bg-surface-container px-3 py-4 text-center font-mono text-xs text-on-surface-variant">
            {t('adminUsers.sessionsEmpty')}
          </p>
        ) : (
          <div className="overflow-x-auto rounded-md border-2 border-outline bg-surface-bright shadow-[2px_2px_0px_#1a1a1a]">
            <table className="w-full border-collapse text-left">
              <thead>
                <tr className={ADMIN_TABLE_HEAD_CLASS}>
                  <th scope="col" className="px-4 py-2">{t('adminUsers.colDevice')}</th>
                  <th scope="col" className="px-4 py-2">{t('adminUsers.colIp')}</th>
                  <th scope="col" className="px-4 py-2">{t('adminUsers.colLastActivity')}</th>
                  <th scope="col" className="px-4 py-2 text-right">{t('adminUsers.colActions')}</th>
                </tr>
              </thead>
              <tbody>
                {sessions.map((session) => (
                  <tr key={session.ref} className="border-b border-outline-variant font-mono text-xs">
                    <td className="px-4 py-2 text-on-surface">{deviceLabel(session.device, t)}</td>
                    <td className="px-4 py-2 text-on-surface-variant">{session.ip_address ?? '—'}</td>
                    <td className="whitespace-nowrap px-4 py-2 text-on-surface-variant">{formatDateTime(session.last_activity_at)}</td>
                    <td className="px-4 py-2 text-right">
                      {session.is_current ? (
                        <span className="font-bold uppercase tracking-wider text-on-surface">{t('adminUsers.thisSession')}</span>
                      ) : (
                        <button
                          type="button"
                          disabled={busy !== null}
                          onClick={() =>
                            void run(session.ref, t('adminUsers.confirmEndOne'), () => adminUsersApi.revokeSession(session.ref))
                          }
                          className={`bg-surface-bright text-on-surface ${ADMIN_BUTTON_CLASS}`}
                        >
                          {busy === session.ref ? t('adminUsers.ending') : t('adminUsers.endSession')}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section aria-labelledby="user-activity-heading" className="flex flex-col gap-3">
        <h2 id="user-activity-heading" className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
          {t('adminUsers.recentActivityTitle')}
        </h2>
        <ActivityTable events={activity} />
      </section>
    </section>
  )
}
