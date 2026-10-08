import { useSearchParams } from 'react-router'
import ActivityTable from '../../components/admin/users/ActivityTable.tsx'
import AdminPagination from '../../components/admin/users/AdminPagination.tsx'
import { AdminError, AdminLoading } from '../../components/admin/users/AdminStatus.tsx'
import { pageParam } from '../../components/admin/users/adminFormat.ts'
import { useAdminResource } from '../../components/admin/users/useAdminResource.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import { adminUsersApi } from '../../lib/api.ts'
import type { AdminActivityEventType } from '../../lib/api.ts'

const EVENT_TYPES: readonly AdminActivityEventType[] = ['login', 'failed', 'logout', 'session_revoked']

function eventParam(value: string | null): AdminActivityEventType | null {
  return EVENT_TYPES.find((type) => type === value) ?? null
}

/** Admin -> Activity: the sign-in log, filterable by event type, newest first. */
export default function AdminActivityPage() {
  const { t } = useI18n()
  const [params, setParams] = useSearchParams()
  const event = eventParam(params.get('event'))
  const page = pageParam(params.get('page'))
  const activity = useAdminResource(`activity|${event ?? ''}|${page}`, () => adminUsersApi.activity({ event, page }))

  function update(nextEvent: AdminActivityEventType | null, nextPage = 1) {
    const next: Record<string, string> = {}
    if (nextEvent !== null) {
      next.event = nextEvent
    }
    if (nextPage > 1) {
      next.page = String(nextPage)
    }
    setParams(next)
  }

  return (
    <section className="flex flex-col gap-6">
      <header>
        <h1 className="font-headline text-3xl font-black tracking-tight uppercase text-on-surface">
          {t('adminUsers.activityTitle')}
        </h1>
        <p className="mt-1 max-w-2xl text-sm text-on-surface-variant">{t('adminUsers.activityIntro')}</p>
      </header>

      <div className="flex flex-wrap items-center gap-2">
        <label htmlFor="admin-activity-event" className="font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant">
          {t('adminUsers.eventFilter')}
        </label>
        <select
          id="admin-activity-event"
          value={event ?? ''}
          onChange={(change) => update(eventParam(change.target.value))}
          className="rounded-md border-2 border-outline bg-surface-bright px-3 py-2 font-mono text-xs text-on-surface shadow-[2px_2px_0px_#1a1a1a] focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
        >
          <option value="">{t('adminUsers.eventAll')}</option>
          {EVENT_TYPES.map((type) => (
            <option key={type} value={type}>
              {t(`adminUsers.events.${type}` as const)}
            </option>
          ))}
        </select>
      </div>

      {activity.error ? <AdminError message={activity.error} onRetry={activity.reload} /> : null}
      {activity.loading && activity.data === null ? <AdminLoading /> : null}

      {activity.data !== null ? (
        <div aria-busy={activity.loading} className="flex flex-col gap-3">
          <ActivityTable
            events={activity.data.data}
            showUser
            emptyMessage={activity.data.meta.total > 0 ? t('adminUsers.pageEmpty') : undefined}
          />
          <AdminPagination
            page={activity.data.meta.current_page}
            lastPage={activity.data.meta.last_page}
            total={activity.data.meta.total}
            onPage={(next) => update(event, next)}
          />
        </div>
      ) : null}
    </section>
  )
}
