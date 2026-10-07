import type { ReactNode } from 'react'
import { Link, NavLink, Outlet } from 'react-router'
import { useAuth } from '../../auth/useAuth.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import type { MessageKey } from '../../i18n/translate.ts'
import { LOGIN_ROUTE } from '../../nav.ts'

/** Admin sections; the path is relative to `/admin`. */
const ADMIN_SECTIONS: ReadonlyArray<{ to: string; labelKey: MessageKey; end: boolean }> = [
  { to: '/admin', labelKey: 'adminUsers.navIngestion', end: true },
  { to: '/admin/users', labelKey: 'adminUsers.navUsers', end: false },
  { to: '/admin/sessions', labelKey: 'adminUsers.navSessions', end: true },
  { to: '/admin/activity', labelKey: 'adminUsers.navActivity', end: true },
]

interface AdminNoticeProps {
  eyebrow: string
  title: string
  message: string
  children?: ReactNode
}

function AdminNotice({ eyebrow, title, message, children }: AdminNoticeProps) {
  return (
    <section className="mx-auto flex w-full max-w-lg flex-col gap-4">
      <span className="w-fit rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a]">
        {eyebrow}
      </span>
      <h2 className="font-headline text-3xl font-black tracking-tight uppercase text-on-surface">
        {title}
      </h2>
      <p className="text-sm text-on-surface-variant">{message}</p>
      {children ? (
        <div className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]">
          {children}
        </div>
      ) : null}
    </section>
  )
}

/**
 * Admin shell: one guard for every Admin surface plus the section tabs. The
 * guard is a UI affordance only — every Admin request is authorized
 * server-side by `auth:sanctum` + `admin` (401 guest, 403 non-admin).
 */
export default function AdminLayout() {
  const { user, status } = useAuth()
  const { t } = useI18n()

  if (status === 'loading') {
    return (
      <AdminNotice
        eyebrow={t('admin.eyebrow')}
        title={t('admin.checkingTitle')}
        message={t('admin.checkingBody')}
      />
    )
  }

  if (user === null) {
    return (
      <AdminNotice eyebrow={t('admin.eyebrow')} title={t('admin.signInTitle')} message={t('admin.signInBody')}>
        <Link
          to={LOGIN_ROUTE}
          className="inline-block rounded-md border-2 border-outline bg-primary-container px-4 py-2 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px"
        >
          {t('admin.signIn')}
        </Link>
      </AdminNotice>
    )
  }

  if (user.role !== 'admin') {
    return (
      <AdminNotice
        eyebrow={t('admin.eyebrow')}
        title={t('admin.restrictedTitle')}
        message={t('admin.restrictedBody')}
      />
    )
  }

  return (
    <div className="flex flex-col gap-6">
      <nav
        aria-label={t('adminUsers.subnavAria')}
        className="flex flex-wrap gap-2 border-b-2 border-outline pb-3"
      >
        {ADMIN_SECTIONS.map((section) => (
          <NavLink
            key={section.to}
            to={section.to}
            end={section.end}
            className={({ isActive }) =>
              [
                'rounded-md border-2 border-outline px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none',
                isActive
                  ? 'bg-primary text-on-primary shadow-[2px_2px_0px_#ffcc00]'
                  : 'bg-surface-bright text-on-surface shadow-[2px_2px_0px_#1a1a1a]',
              ].join(' ')
            }
          >
            {t(section.labelKey)}
          </NavLink>
        ))}
      </nav>
      <Outlet />
    </div>
  )
}
