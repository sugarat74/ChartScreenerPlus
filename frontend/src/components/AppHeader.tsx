import { Fragment, useState } from 'react'
import { Link, NavLink } from 'react-router'
import { useAuth } from '../auth/useAuth.ts'
import { useEmailVerificationPending } from '../auth/useEmailVerificationPending.ts'
import { useMarketContextAccess } from '../auth/useMarketContextAccess.ts'
import type { MarketContextAccess } from '../auth/useMarketContextAccess.ts'
import { useRegisteredOnly } from '../auth/useRegisteredOnly.ts'
import { useI18n } from '../i18n/useI18n.ts'
import { useLegalInfo } from '../legal/useLegalInfo.ts'
import { ACCOUNT_ROUTE, DEFAULT_ROUTE, LOGIN_ROUTE, NAV_ITEMS } from '../nav.ts'
import { ReturnTo, TAB_ACTIVE_CLASS, TAB_CLASS, TAB_IDLE_CLASS, UnreleasedNavTab } from './HeaderNavTab.tsx'
import LanguageSelector from './LanguageSelector.tsx'
import NotificationBell from './NotificationBell.tsx'
import LockIcon from './registered-only/LockIcon.tsx'
import RegisteredOnly from './registered-only/RegisteredOnly.tsx'
import ThemeSelector from './ThemeSelector.tsx'

const LOADING_ACCESS: MarketContextAccess = { status: 'loading', preview: false }

/**
 * How the «Mercado» tab renders (guest-disabled-nav, inventory row 9):
 * a link with access; dimmed with the sign-in popover for a Visitor while the
 * page is public; dimmed with "confirm your email" for a pending account that
 * confirming would unlock; otherwise "not available yet" with no sign-in link
 * (login would not unlock it).
 */
type MarketTab = 'enabled' | 'loading' | 'guest' | 'pending' | 'unreleased'

/** Sticky product chrome: brand, language, session controls and route-backed tabs. */
export default function AppHeader() {
  const { user, status, logout } = useAuth()
  const { t } = useI18n()
  const marketAccess = useMarketContextAccess()
  const legal = useLegalInfo()
  const verificationPending = useEmailVerificationPending()
  const { state: session } = useRegisteredOnly()
  const [signingOut, setSigningOut] = useState(false)
  const isAdmin = user?.role === 'admin'
  const marketPublic = legal.status === 'ready' && legal.info.market_context === true
  let marketTab: MarketTab
  if (marketAccess.status === 'allowed') {
    marketTab = 'enabled'
  } else if (marketAccess.status === 'loading') {
    marketTab = 'loading'
  } else if (marketAccess.status === 'signIn') {
    marketTab = 'guest'
  } else if (session === 'pending' && legal.status === 'loading') {
    // A pending account: wait for the go-live flag instead of flashing a reason.
    marketTab = 'loading'
  } else if (session === 'pending' && (marketPublic || isAdmin)) {
    // The server denies Market Context to every pending account; confirming unlocks it.
    marketTab = 'pending'
  } else {
    marketTab = 'unreleased'
  }
  // Admin-only tabs are hidden for everyone else; the API is the real gate.
  const visibleNavItems = NAV_ITEMS.filter(
    (item) => item.adminOnly !== true || isAdmin,
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
        <Link
          to={DEFAULT_ROUTE}
          aria-label={t('header.homeAria')}
          className="flex items-center gap-3 rounded-md focus:outline-2 focus:outline-offset-4 focus:outline-outline"
        >
          <img src="/favicon.svg" alt="" width={40} height={40} className="h-10 w-10 shrink-0" />
          <div>
            <div className="flex items-center gap-2">
              <span className="font-headline text-xl font-black tracking-tight text-on-surface">
                CHARTIKO
              </span>
              <span className="rounded-[4px] border border-outline bg-primary-container px-1.5 py-0.5 font-mono text-[10px] font-bold text-on-primary-container shadow-[1px_1px_0px_var(--color-shadow)]">
                {t('header.badge')}
              </span>
            </div>
            <p className="hidden font-mono text-xs text-on-surface-variant sm:block">
              {t('header.tagline')}
            </p>
          </div>
        </Link>

        <div className="flex flex-wrap items-center gap-3">
          <ThemeSelector />
          <LanguageSelector />

          {status === 'loading' ? (
            <div className="flex items-center gap-2 rounded-full border-2 border-outline bg-surface-bright py-1 pr-2.5 pl-1 shadow-[2px_2px_0px_var(--color-shadow)]">
              <span className="flex h-6 w-6 items-center justify-center rounded-full bg-surface-dim font-headline text-xs font-bold text-on-surface-variant">
                ··
              </span>
              <span className="hidden font-mono text-[11px] text-on-surface-variant sm:inline">
                {t('common.loading')}
              </span>
            </div>
          ) : user ? (
            <>
              {/* Only signed-in users have notifications; Visitors never see the bell,
                  nor does an account that still has to confirm its email. */}
              {verificationPending ? null : <NotificationBell />}
              {/* The name chip opens the Account page (account-self-service). */}
              <Link
                to={ACCOUNT_ROUTE}
                aria-label={t('header.accountAria', { name: user.name })}
                title={t('header.account')}
                className="flex items-center gap-2 rounded-full border-2 border-outline bg-surface-bright py-1 pr-2.5 pl-1 shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
              >
                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-primary font-headline text-xs font-bold uppercase text-on-primary">
                  {user.name.charAt(0)}
                </span>
                <span className="hidden max-w-40 truncate font-headline text-xs font-bold text-on-surface sm:inline">
                  {user.name}
                </span>
                <span className="font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
                  {t('header.account')}
                </span>
              </Link>
              <button
                type="button"
                onClick={handleSignOut}
                disabled={signingOut}
                className="rounded-md border-2 border-outline bg-surface-bright px-2.5 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_var(--color-shadow)] disabled:cursor-not-allowed disabled:opacity-70"
              >
                {signingOut ? t('header.signingOut') : t('header.signOut')}
              </button>
            </>
          ) : (
            <Link
              to={LOGIN_ROUTE}
              className="rounded-md border-2 border-outline bg-primary-container px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px"
            >
              {t('header.signIn')}
            </Link>
          )}
        </div>
      </div>

      <nav
        aria-label={t('header.navAria')}
        className="mx-auto flex max-w-7xl items-center gap-1 overflow-x-auto border-t border-outline-variant px-4"
      >
        {visibleNavItems.map((item) => {
          const label = t(item.labelKey)
          const link = (locked: boolean, title?: string) => (
            <NavLink
              to={item.path}
              end={!('nested' in item && item.nested)}
              title={title}
              className={({ isActive }) => `${TAB_CLASS} ${isActive ? TAB_ACTIVE_CLASS : TAB_IDLE_CLASS}`}
            >
              {locked ? <LockIcon className="mr-1 inline h-3 w-3 align-[-1px]" /> : null}
              {label}
            </NavLink>
          )

          if ('marketContext' in item && item.marketContext === true) {
            switch (marketTab) {
              case 'enabled':
                return <Fragment key={item.path}>{link(false)}</Fragment>
              case 'unreleased':
                return (
                  <UnreleasedNavTab
                    key={item.path}
                    label={label}
                    reason={t('registeredOnly.reasonUnreleased')}
                    title={t('nav.marketUnavailable')}
                  />
                )
              case 'pending':
                return (
                  <RegisteredOnly key={item.path}>{link(true)}</RegisteredOnly>
                )
              case 'loading':
                return (
                  <RegisteredOnly key={item.path} access={LOADING_ACCESS}>{link(false)}</RegisteredOnly>
                )
              case 'guest':
                return (
                  <ReturnTo key={item.path} path={item.path}>
                    <RegisteredOnly access={marketAccess}>{link(true, t('nav.marketSignIn'))}</RegisteredOnly>
                  </ReturnTo>
                )
            }
          }

          if ('registeredOnly' in item && item.registeredOnly === true && session !== 'enabled') {
            // Visitor, pending account or session loading: the dimmed tab; sign-in returns to the tab's route.
            return (
              <ReturnTo key={item.path} path={item.path}>
                <RegisteredOnly>{link(session !== 'loading')}</RegisteredOnly>
              </ReturnTo>
            )
          }

          return <Fragment key={item.path}>{link(false)}</Fragment>
        })}
      </nav>
    </header>
  )
}
