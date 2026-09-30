import { useState } from 'react'
import { Link, NavLink } from 'react-router'
import { useAuth } from '../auth/useAuth.ts'
import { LOGIN_ROUTE, NAV_ITEMS } from '../nav.ts'

/**
 * Sticky product chrome: brand block, EOD status, inert primary action, user
 * pill and the route-backed tab bar. Styling follows DESIGN.md tokens only.
 */
export default function AppHeader() {
  const { user, status, logout } = useAuth()
  const [signingOut, setSigningOut] = useState(false)

  // Admin-only tabs are hidden for everyone else; the API is the real gate.
  const visibleNavItems = NAV_ITEMS.filter(
    (item) => item.adminOnly !== true || user?.role === 'admin',
  )

  async function handleSignOut() {
    setSigningOut(true)
    try {
      await logout()
    } catch {
      // Keep the session visible; the user can retry.
    } finally {
      setSigningOut(false)
    }
  }

  return (
    <header className="sticky top-0 z-40 w-full border-b-2 border-outline bg-surface">
      <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-2.5">
        <div className="flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-md border-2 border-outline bg-primary font-headline text-xl font-black text-on-primary shadow-[2px_2px_0px_#ffcc00]">
            αP
          </div>
          <div>
            <div className="flex items-center gap-2">
              <span className="font-headline text-xl font-black tracking-tight text-on-surface">
                ALPHAPULSE
              </span>
              <span className="rounded-[4px] border border-outline bg-primary-container px-1.5 py-0.5 font-mono text-[10px] font-bold text-on-primary-container shadow-[1px_1px_0px_#1a1a1a]">
                EOD
              </span>
            </div>
            <p className="hidden font-mono text-xs text-on-surface-variant sm:block">
              Screener cuantitativo EOD
            </p>
          </div>
        </div>

        <div className="flex items-center gap-3">
          <div className="hidden items-center gap-2 rounded border border-outline bg-surface-container px-2.5 py-1 font-mono text-xs md:flex">
            <span className="h-2 w-2 rounded-full border border-outline bg-surface-dim" />
            <span className="text-on-surface">Mercado Cerrado (EOD)</span>
          </div>

          <button
            type="button"
            disabled
            aria-disabled="true"
            title="La ingesta EOD todavía no está conectada"
            className="rounded-md border-2 border-outline bg-primary-container px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a] disabled:cursor-not-allowed disabled:opacity-70"
          >
            Actualizar EOD
          </button>

          {status === 'loading' ? (
            <div className="flex items-center gap-2 rounded-full border-2 border-outline bg-surface-bright py-1 pr-2.5 pl-1 shadow-[2px_2px_0px_#1a1a1a]">
              <span className="flex h-6 w-6 items-center justify-center rounded-full bg-surface-dim font-headline text-xs font-bold text-on-surface-variant">
                ··
              </span>
              <span className="hidden font-mono text-[11px] text-on-surface-variant sm:inline">
                Cargando…
              </span>
            </div>
          ) : user ? (
            <>
              <div className="flex items-center gap-2 rounded-full border-2 border-outline bg-surface-bright py-1 pr-2.5 pl-1 shadow-[2px_2px_0px_#1a1a1a]">
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-primary font-headline text-xs font-bold uppercase text-on-primary">
                  {user.name.charAt(0)}
                </span>
                <span className="hidden max-w-40 truncate font-headline text-xs font-bold text-on-surface sm:inline">
                  {user.name}
                </span>
              </div>
              <button
                type="button"
                onClick={handleSignOut}
                disabled={signingOut}
                className="rounded-md border-2 border-outline bg-surface-bright px-2.5 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_#1a1a1a] disabled:cursor-not-allowed disabled:opacity-70"
              >
                {signingOut ? 'Saliendo…' : 'Salir'}
              </button>
            </>
          ) : (
            <Link
              to={LOGIN_ROUTE}
              className="rounded-md border-2 border-outline bg-primary-container px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px"
            >
              Iniciar sesión
            </Link>
          )}
        </div>
      </div>

      <nav
        aria-label="Secciones principales"
        className="mx-auto flex max-w-7xl items-center gap-1 overflow-x-auto border-t border-outline-variant px-4"
      >
        {visibleNavItems.map((item) => (
          <NavLink
            key={item.path}
            to={item.path}
            end
            className={({ isActive }) =>
              [
                'whitespace-nowrap border-b-2 px-4 py-2.5 font-headline text-xs font-bold uppercase tracking-wider transition-colors',
                isActive
                  ? 'border-outline bg-surface-container text-on-surface'
                  : 'border-transparent text-on-surface-variant hover:bg-surface-bright hover:text-on-surface',
              ].join(' ')
            }
          >
            {item.label}
          </NavLink>
        ))}
      </nav>
    </header>
  )
}
