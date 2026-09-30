import type { ReactNode } from 'react'
import { Navigate } from 'react-router'
import { LOGIN_ROUTE } from '../nav.ts'
import { useAuth } from './useAuth.ts'

/**
 * Component-level route guard.
 *
 * The router is element-based (no loaders) and auth state lives in
 * `AuthContext`, so guarding is a component concern (the same pattern
 * `AdminPage` established). While the session bootstraps it renders a
 * "Comprobando sesión" panel; a Visitor is redirected to sign-in with
 * `replace` so the protected surface does not pollute history. The API remains
 * the enforcement point — this is only a UI affordance.
 */
export default function RequireAuth({ children }: { children: ReactNode }) {
  const { user, status } = useAuth()

  if (status === 'loading') {
    return (
      <section className="mx-auto flex w-full max-w-lg flex-col gap-4">
        <span className="w-fit rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a]">
          Portal
        </span>
        <h2 className="font-headline text-3xl font-black tracking-tight uppercase text-on-surface">
          Comprobando sesión
        </h2>
        <p
          role="status"
          aria-busy="true"
          className="text-sm text-on-surface-variant"
        >
          Verificando tu sesión…
        </p>
      </section>
    )
  }

  if (user === null) {
    return <Navigate to={LOGIN_ROUTE} replace />
  }

  return <>{children}</>
}
