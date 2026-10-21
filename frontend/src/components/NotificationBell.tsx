import { NavLink } from 'react-router'
import { useI18n } from '../i18n/useI18n.ts'
import { NOTIFICATIONS_ROUTE } from '../nav.ts'
import { useUnreadNotifications } from '../notifications/useUnreadNotifications.ts'

/** Badge text: the exact count up to 9, then "9+". */
function unreadBadgeText(count: number): string {
  return count > 9 ? '9+' : String(count)
}

/**
 * Header bell for a signed-in user: an icon-only link to the inbox whose
 * accessible name carries the unread count (the visual badge is capped at
 * "9+"). The header renders it only for signed-in users; the API is the gate.
 */
export default function NotificationBell() {
  const { t } = useI18n()
  const { count } = useUnreadNotifications()
  const label = count > 0 ? t('notifications.bellAria', { count }) : t('notifications.bellAriaNone')

  return (
    <NavLink
      to={NOTIFICATIONS_ROUTE}
      aria-label={label}
      title={label}
      className={({ isActive }) =>
        [
          'relative flex h-9 w-9 shrink-0 items-center justify-center rounded-md border-2 border-outline text-on-surface shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none',
          isActive ? 'bg-surface-container' : 'bg-surface-bright',
        ].join(' ')
      }
    >
      <svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2.25" strokeLinecap="round" strokeLinejoin="round">
        <path d="M6 9a6 6 0 1 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9Z" />
        <path d="M10 20a2 2 0 0 0 4 0" />
      </svg>
      {count > 0 ? (
        <span
          aria-hidden="true"
          data-testid="notification-badge"
          className="absolute -top-2 -right-2 min-w-5 rounded-[4px] border-2 border-outline bg-secondary px-1 text-center font-mono text-[10px] font-bold leading-4 text-on-secondary"
        >
          {unreadBadgeText(count)}
        </span>
      ) : null}
    </NavLink>
  )
}
