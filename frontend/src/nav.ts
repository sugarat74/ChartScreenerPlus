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
}

export const NAV_ITEMS = [
  { path: '/', labelKey: 'nav.screener' },
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
 * react-router nested routes must be relative; `NAV_ITEMS`/auth routes store
 * absolute paths for links. Strip the leading slash when declaring a child.
 */
export function routeSegment(path: string): string {
  return path.startsWith('/') ? path.slice(1) : path
}
