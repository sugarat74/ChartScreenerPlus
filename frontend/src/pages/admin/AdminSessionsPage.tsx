import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import AdminPagination from '../../components/admin/users/AdminPagination.tsx'
import {
  ADMIN_BUTTON_CLASS,
  ADMIN_TABLE_HEAD_CLASS,
  AdminError,
  AdminLoading,
  AdminNoticeLine,
} from '../../components/admin/users/AdminStatus.tsx'
import { deviceLabel, pageParam } from '../../components/admin/users/adminFormat.ts'
import { useAdminResource } from '../../components/admin/users/useAdminResource.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import { adminUsersApi, apiErrorMessage } from '../../lib/api.ts'

/** Admin -> Sessions: every active signed-in session; any but your own can be ended. */
export default function AdminSessionsPage() {
  const { t, formatDateTime, formatInteger } = useI18n()
  const [params, setParams] = useSearchParams()
  const page = pageParam(params.get('page'))
  const sessions = useAdminResource(`sessions|${page}`, () => adminUsersApi.sessions({ page }))
  const [busy, setBusy] = useState<string | null>(null)
  const [notice, setNotice] = useState<{ tone: 'info' | 'error'; message: string } | null>(null)

  async function end(ref: string) {
    if (!window.confirm(t('adminUsers.confirmEndOne'))) {
      return
    }
    setBusy(ref)
    setNotice(null)
    try {
      const revoked = await adminUsersApi.revokeSession(ref)
      setNotice({ tone: 'info', message: t('adminUsers.revokedNotice', { count: formatInteger(revoked) }) })
      sessions.reload()
    } catch (caught) {
      setNotice({ tone: 'error', message: apiErrorMessage(caught, t('common.networkError')) })
    } finally {
      setBusy(null)
    }
  }

  return (
    <section className="flex flex-col gap-6">
      <header>
        <h1 className="font-headline text-3xl font-black tracking-tight uppercase text-on-surface">
          {t('adminUsers.sessionsTitle')}
        </h1>
        <p className="mt-1 max-w-2xl text-sm text-on-surface-variant">{t('adminUsers.sessionsIntro')}</p>
      </header>

      {notice ? <AdminNoticeLine tone={notice.tone} message={notice.message} /> : null}
      {sessions.error ? <AdminError message={sessions.error} onRetry={sessions.reload} /> : null}
      {sessions.loading && sessions.data === null ? <AdminLoading /> : null}

      {sessions.data !== null ? (
        <div aria-busy={sessions.loading} className="flex flex-col gap-3">
          {sessions.data.data.length === 0 ? (
            <p className="rounded-[4px] border-2 border-dashed border-outline-variant bg-surface-container px-3 py-4 text-center font-mono text-xs text-on-surface-variant">
              {t('adminUsers.sessionsEmpty')}
            </p>
          ) : (
            <div className="overflow-x-auto rounded-md border-2 border-outline bg-surface-bright shadow-[2px_2px_0px_#1a1a1a]">
              <table className="w-full border-collapse text-left">
                <thead>
                  <tr className={ADMIN_TABLE_HEAD_CLASS}>
                    <th scope="col" className="px-4 py-2">{t('adminUsers.colUser')}</th>
                    <th scope="col" className="px-4 py-2">{t('adminUsers.colDevice')}</th>
                    <th scope="col" className="px-4 py-2">{t('adminUsers.colIp')}</th>
                    <th scope="col" className="px-4 py-2">{t('adminUsers.colLastActivity')}</th>
                    <th scope="col" className="px-4 py-2 text-right">{t('adminUsers.colActions')}</th>
                  </tr>
                </thead>
                <tbody>
                  {sessions.data.data.map((session) => (
                    <tr key={session.ref} className="border-b border-outline-variant align-top font-mono text-xs">
                      <th scope="row" className="px-4 py-2 text-left font-normal">
                        {session.user ? (
                          <Link
                            to={`/admin/users/${session.user.id}`}
                            className="font-bold text-on-surface underline-offset-2 hover:underline focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
                          >
                            {session.user.name}
                          </Link>
                        ) : (
                          t('adminUsers.unknown')
                        )}
                        <p className="text-on-surface-variant">{session.user?.email}</p>
                      </th>
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
                            onClick={() => void end(session.ref)}
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
          <AdminPagination
            page={sessions.data.meta.current_page}
            lastPage={sessions.data.meta.last_page}
            total={sessions.data.meta.total}
            onPage={(next) => setParams({ page: String(next) })}
          />
        </div>
      ) : null}
    </section>
  )
}
