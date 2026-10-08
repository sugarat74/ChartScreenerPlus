import type { FormEvent, ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router'
import AdminPagination from '../../components/admin/users/AdminPagination.tsx'
import { ADMIN_BUTTON_CLASS, ADMIN_TABLE_HEAD_CLASS, AdminError, AdminLoading } from '../../components/admin/users/AdminStatus.tsx'
import { pageParam } from '../../components/admin/users/adminFormat.ts'
import { useAdminResource } from '../../components/admin/users/useAdminResource.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import { adminUsersApi } from '../../lib/api.ts'

function Tile({ label, value, hint }: { label: string; value: ReactNode; hint?: string }) {
  return (
    <div className="rounded-md border-2 border-outline bg-surface-bright p-4 shadow-[2px_2px_0px_#1a1a1a]">
      <p className="font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">{label}</p>
      <p className="mt-2 font-headline text-2xl font-black text-on-surface">{value}</p>
      {hint ? <p className="mt-1 font-mono text-[11px] text-on-surface-variant">{hint}</p> : null}
    </div>
  )
}

/**
 * Admin -> Users: summary tiles plus the searchable, paginated account list.
 * Search and page live in the URL so a view is shareable and survives reload.
 */
export default function AdminUsersPage() {
  const { t, formatInteger, formatDateTime } = useI18n()
  const [params, setParams] = useSearchParams()
  const search = params.get('q') ?? ''
  const page = pageParam(params.get('page'))

  const summary = useAdminResource('summary', () => adminUsersApi.summary())
  const users = useAdminResource(`users|${search}|${page}`, () => adminUsersApi.list({ search, page }))

  function handleSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const value = String(new FormData(event.currentTarget).get('q') ?? '').trim()
    setParams(value === '' ? {} : { q: value })
  }

  function goTo(nextPage: number) {
    const next = new URLSearchParams(params)
    next.set('page', String(nextPage))
    setParams(next)
  }

  const s = summary.data

  return (
    <section className="flex flex-col gap-6">
      <header>
        <h1 className="font-headline text-3xl font-black tracking-tight uppercase text-on-surface">
          {t('adminUsers.usersTitle')}
        </h1>
        <p className="mt-1 max-w-2xl text-sm text-on-surface-variant">{t('adminUsers.usersIntro')}</p>
      </header>

      {summary.error ? <AdminError message={summary.error} onRetry={summary.reload} /> : null}
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Tile
          label={t('adminUsers.tileUsers')}
          value={s ? formatInteger(s.users_total) : '—'}
          hint={s ? t('adminUsers.tileAdmins', { count: formatInteger(s.admins_total) }) : undefined}
        />
        <Tile
          label={t('adminUsers.tileNewUsers')}
          value={s ? formatInteger(s.users_new_7d) : '—'}
          hint={s ? t('adminUsers.tileNewUsersHint', { count: formatInteger(s.users_new_30d) }) : undefined}
        />
        <Tile
          label={t('adminUsers.tileActive')}
          value={s ? formatInteger(s.users_active_24h) : '—'}
          hint={s ? t('adminUsers.tileSessionsHint', { count: formatInteger(s.sessions_active) }) : undefined}
        />
        <Tile
          label={t('adminUsers.tileFailed')}
          value={s ? formatInteger(s.failed_logins_24h) : '—'}
          hint={s ? t('adminUsers.tileRetention', { days: formatInteger(s.activity_retention_days) }) : undefined}
        />
      </div>

      <form role="search" onSubmit={handleSearch} className="flex flex-wrap items-end gap-3">
        <div className="flex flex-col gap-1">
          <label htmlFor="admin-user-search" className="font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant">
            {t('adminUsers.searchLabel')}
          </label>
          <input
            id="admin-user-search"
            name="q"
            type="search"
            key={search}
            defaultValue={search}
            placeholder={t('adminUsers.searchPlaceholder')}
            className="w-72 rounded-md border-2 border-outline bg-surface-bright px-3 py-2 font-mono text-sm text-on-surface shadow-[2px_2px_0px_#1a1a1a] focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
          />
        </div>
        <button type="submit" className={`bg-primary-container text-on-primary-container ${ADMIN_BUTTON_CLASS}`}>
          {t('adminUsers.search')}
        </button>
      </form>

      {users.error ? <AdminError message={users.error} onRetry={users.reload} /> : null}
      {users.loading && users.data === null ? <AdminLoading /> : null}

      {users.data !== null ? (
        <div aria-busy={users.loading} className="flex flex-col gap-3">
          {users.data.data.length === 0 ? (
            <p className="rounded-[4px] border-2 border-dashed border-outline-variant bg-surface-container px-3 py-4 text-center font-mono text-xs text-on-surface-variant">
              {users.data.meta.total > 0
                ? t('adminUsers.pageEmpty')
                : search === ''
                  ? t('adminUsers.usersEmpty')
                  : t('adminUsers.usersNoMatch', { search })}
            </p>
          ) : (
            <div className="overflow-x-auto rounded-md border-2 border-outline bg-surface-bright shadow-[2px_2px_0px_#1a1a1a]">
              <table className="w-full border-collapse text-left">
                <thead>
                  <tr className={ADMIN_TABLE_HEAD_CLASS}>
                    <th scope="col" className="px-4 py-2">{t('adminUsers.colUser')}</th>
                    <th scope="col" className="px-4 py-2">{t('adminUsers.colRole')}</th>
                    <th scope="col" className="px-4 py-2">{t('adminUsers.colSignedUp')}</th>
                    <th scope="col" className="px-4 py-2">{t('adminUsers.colLastLogin')}</th>
                    <th scope="col" className="px-4 py-2">{t('adminUsers.colLastActivity')}</th>
                    <th scope="col" className="px-4 py-2 text-right">{t('adminUsers.colSessions')}</th>
                    <th scope="col" className="px-4 py-2 text-right">{t('adminUsers.colScreeners')}</th>
                    <th scope="col" className="px-4 py-2 text-right">{t('adminUsers.colWatchlist')}</th>
                  </tr>
                </thead>
                <tbody>
                  {users.data.data.map((row) => (
                    <tr key={row.id} className="border-b border-outline-variant align-top font-mono text-xs">
                      <th scope="row" className="px-4 py-2 text-left font-normal">
                        <Link
                          to={`/admin/users/${row.id}`}
                          className="font-headline text-sm font-black text-on-surface underline-offset-2 hover:underline focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
                        >
                          {row.name}
                        </Link>
                        <p className="text-on-surface-variant">{row.email}</p>
                      </th>
                      <td className="px-4 py-2">
                        <span className="rounded-[4px] border border-outline bg-surface-container px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-on-surface">
                          {t(`adminUsers.roles.${row.role}` as const)}
                        </span>
                      </td>
                      <td className="whitespace-nowrap px-4 py-2 text-on-surface-variant">{formatDateTime(row.created_at)}</td>
                      <td className="whitespace-nowrap px-4 py-2 text-on-surface-variant">{formatDateTime(row.last_login_at)}</td>
                      <td className="whitespace-nowrap px-4 py-2 text-on-surface-variant">{formatDateTime(row.last_activity_at)}</td>
                      <td className="px-4 py-2 text-right text-on-surface">{formatInteger(row.active_sessions_count)}</td>
                      <td className="px-4 py-2 text-right text-on-surface">{formatInteger(row.saved_screeners_count)}</td>
                      <td className="px-4 py-2 text-right text-on-surface">{formatInteger(row.watchlist_count)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <AdminPagination
            page={users.data.meta.current_page}
            lastPage={users.data.meta.last_page}
            total={users.data.meta.total}
            onPage={goTo}
          />
        </div>
      ) : null}
    </section>
  )
}
