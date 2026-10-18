import { useState } from 'react'
import type { ReactNode } from 'react'
import { Navigate } from 'react-router'
import { DEFAULT_ROUTE, LOGIN_ROUTE } from '../nav.ts'
import { useI18n } from '../i18n/useI18n.ts'
import { useAuth } from './useAuth.ts'

/**
 * Component-level route guard.
 *
 * The router is element-based (no loaders) and auth state lives in
 * `AuthContext`, so guarding is a component concern (the same pattern
 * `AdminPage` established). While the session bootstraps it renders a
 * "Comprobando sesión" panel; a Visitor is redirected to sign-in with
 * `replace` so the protected surface does not pollute history. If the user
 * disappears while this surface is open, that is a sign-out (an expired
 * session keeps the user and is handled by the 401 paths), so the redirect
 * goes home instead and the sign-in screen does not flash. The API remains
 * the enforcement point — this is only a UI affordance.
 */
export default function RequireAuth({ children }: { children: ReactNode }) {
  const { user, status } = useAuth()
  const { t } = useI18n()
  // Derived state (set during render): did this visit ever have a user?
  const [hadUser, setHadUser] = useState(false)
  if (user !== null && !hadUser) {
    setHadUser(true)
  }

  if (status === 'loading') {
    return (
      <section className="mx-auto flex w-full max-w-lg flex-col gap-4">
        <span className="w-fit rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a]">
          {t('auth.checkingBadge')}
        </span>
        <h2 className="font-headline text-3xl font-black tracking-tight uppercase text-on-surface">
          {t('auth.checkingTitle')}
        </h2>
        <p
          role="status"
          aria-busy="true"
          className="text-sm text-on-surface-variant"
        >
          {t('auth.checkingBody')}
        </p>
      </section>
    )
  }

  if (user === null) {
    return <Navigate to={hadUser ? DEFAULT_ROUTE : LOGIN_ROUTE} replace />
  }

  return <>{children}</>
}
