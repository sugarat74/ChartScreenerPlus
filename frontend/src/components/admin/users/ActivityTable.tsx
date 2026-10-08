import { Link } from 'react-router'
import { useI18n } from '../../../i18n/useI18n.ts'
import type { AdminActivityEvent } from '../../../lib/api.ts'
import { deviceLabel } from './adminFormat.ts'

const EVENT_CHIP: Record<AdminActivityEvent['event'], string> = {
  login: 'border-gain bg-gain text-primary',
  failed: 'border-secondary bg-secondary-container text-on-secondary-container',
  logout: 'border-outline-variant bg-surface-dim text-on-surface-variant',
  session_revoked: 'border-secondary bg-primary-container text-on-primary-container',
}

interface ActivityTableProps {
  events: AdminActivityEvent[]
  /** Show the account column (global log); the user detail view hides it. */
  showUser?: boolean
  /** Text when there are no rows (e.g. a page beyond the last one). */
  emptyMessage?: string
}

/** Sign-in activity rows; the event chip always carries a text label. */
export default function ActivityTable({ events, showUser = false, emptyMessage }: ActivityTableProps) {
  const { t, formatDateTime, formatInteger } = useI18n()

  if (events.length === 0) {
    return (
      <p className="rounded-[4px] border-2 border-dashed border-outline-variant bg-surface-container px-3 py-4 text-center font-mono text-xs text-on-surface-variant">
        {emptyMessage ?? t('adminUsers.activityEmpty')}
      </p>
    )
  }

  return (
    <div className="overflow-x-auto rounded-md border-2 border-outline bg-surface-bright shadow-[2px_2px_0px_#1a1a1a]">
      <table className="w-full border-collapse text-left">
        <thead>
          <tr className="border-b-2 border-outline bg-surface-container font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
            <th scope="col" className="px-4 py-2">{t('adminUsers.colWhen')}</th>
            <th scope="col" className="px-4 py-2">{t('adminUsers.colEvent')}</th>
            {showUser ? <th scope="col" className="px-4 py-2">{t('adminUsers.colAccount')}</th> : null}
            <th scope="col" className="px-4 py-2">{t('adminUsers.colIp')}</th>
            <th scope="col" className="px-4 py-2">{t('adminUsers.colDevice')}</th>
            <th scope="col" className="px-4 py-2">{t('adminUsers.colDetail')}</th>
          </tr>
        </thead>
        <tbody>
          {events.map((event) => (
            <tr key={event.id} className="border-b border-outline-variant font-mono text-xs align-top">
              <td className="whitespace-nowrap px-4 py-2 text-on-surface-variant">{formatDateTime(event.created_at)}</td>
              <td className="px-4 py-2">
                <span
                  className={`inline-flex rounded-[4px] border-2 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider ${EVENT_CHIP[event.event]}`}
                >
                  {t(`adminUsers.events.${event.event}` as const)}
                </span>
              </td>
              {showUser ? (
                <td className="px-4 py-2 text-on-surface">
                  {event.user ? (
                    <Link
                      to={`/admin/users/${event.user.id}`}
                      className="font-bold underline-offset-2 hover:underline focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
                    >
                      {event.user.email}
                    </Link>
                  ) : (
                    <span className="text-on-surface-variant">{event.email ?? t('adminUsers.unknown')}</span>
                  )}
                </td>
              ) : null}
              <td className="px-4 py-2 text-on-surface-variant">{event.ip_address ?? '—'}</td>
              <td className="px-4 py-2 text-on-surface-variant">{deviceLabel(event.device, t)}</td>
              <td className="px-4 py-2 text-on-surface-variant">
                {event.event === 'session_revoked'
                  ? t('adminUsers.revokedDetail', {
                      count: formatInteger(event.sessions_revoked ?? 0),
                      actor: event.actor?.name ?? t('adminUsers.unknown'),
                    })
                  : event.event === 'failed' && !showUser
                    ? (event.email ?? '—')
                    : '—'}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
