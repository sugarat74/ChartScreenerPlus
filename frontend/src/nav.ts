/**
 * Single source of truth for the shell surfaces.
 *
 * Tab labels, paths and the default route are derived from this list so the
 * header navigation and the router cannot drift apart.
 */
import type { MessageKey } from './i18n/translate.ts'

export type NavItem = {
  readonly path: string
  /** Catalog key; the header translates it in the active language. */
  readonly labelKey: MessageKey
  /** Admin-only surfaces are hidden from non-admin sessions (UI affordance). */
  readonly adminOnly?: boolean
  /** Keep the tab active on nested routes (e.g. `/admin/users`). */
  readonly nested?: boolean
  /**
   * Market Context: always shown in the header, but linked only when
   * `useMarketContextAccess` is `allowed`. UI affordance only.
   */
  readonly marketContext?: boolean
}

export const NAV_ITEMS = [
  { path: '/', labelKey: 'nav.screener' },
  { path: '/mercado', labelKey: 'nav.market', marketContext: true },
  { path: '/como-funciona', labelKey: 'nav.howItWorks' },
  { path: '/admin', labelKey: 'nav.admin', adminOnly: true, nested: true },
  { path: '/portal', labelKey: 'nav.portal', nested: true },
] satisfies readonly NavItem[]

export const DEFAULT_ROUTE: string = NAV_ITEMS[0].path

/**
 * Auth surfaces. They are routes but not tabs: the header links to them and
 * the router mounts them inside the shell.
 */
export const LOGIN_ROUTE = '/login'
export const REGISTER_ROUTE = '/register'
export const FORGOT_PASSWORD_ROUTE = '/olvide-contrasena'
export const RESET_PASSWORD_ROUTE = '/restablecer-contrasena'

/** Market Context page (market-context-ui). */
export const MARKET_ROUTE = '/mercado'

/** Public legal pages (docs/specs/legal-compliance-eu.md), linked from the footer. */
export const PRIVACY_ROUTE = '/privacidad'
export const LEGAL_NOTICE_ROUTE = '/aviso-legal'
export const COOKIES_ROUTE = '/cookies'

/** Private alert-notification inbox (alerts-ui); reached from the header bell. */
export const NOTIFICATIONS_ROUTE = '/portal/notificaciones'

/**
 * Account self-service (account-self-service): name, password, data download
 * and deletion. One path for both locales; linked from the header and Portal.
 */
export const ACCOUNT_ROUTE = '/portal/cuenta'

/**
 * The page the confirmation email links to (auth-email-verification):
 * `/verificar-correo/:userId/:hash?expires=…&signature=…`. It POSTs the link
 * to the API; a GET of the API never verifies anything.
 */
export const VERIFY_EMAIL_ROUTE = '/verificar-correo'

/**
 * Paths a signed-in account that still has to confirm its email can open
 * (auth-email-verification, D7): the legal pages, the Account page (export or
 * delete the account) and the verify page. Every other path shows the
 * "confirm your email" notice instead (a UI affordance; the API enforces it).
 */
export const EMAIL_VERIFICATION_EXEMPT_ROUTES: readonly string[] = [
  PRIVACY_ROUTE,
  LEGAL_NOTICE_ROUTE,
  COOKIES_ROUTE,
  ACCOUNT_ROUTE,
  FORGOT_PASSWORD_ROUTE,
  RESET_PASSWORD_ROUTE,
]

/** Whether `pathname` stays reachable while the account is pending verification. */
export function isEmailVerificationExempt(pathname: string): boolean {
  const path = pathname.length > 1 ? pathname.replace(/\/+$/, '') : pathname
  return EMAIL_VERIFICATION_EXEMPT_ROUTES.includes(path) || path.startsWith(`${VERIFY_EMAIL_ROUTE}/`)
}

/**
 * react-router nested routes must be relative; `NAV_ITEMS`/auth routes store
 * absolute paths for links. Strip the leading slash when declaring a child.
 */
export function routeSegment(path: string): string {
  return path.startsWith('/') ? path.slice(1) : path
}
