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
}

export const NAV_ITEMS = [
  { path: '/screener', labelKey: 'nav.screener' },
  { path: '/chart', labelKey: 'nav.chart' },
  { path: '/admin', labelKey: 'nav.admin', adminOnly: true },
  { path: '/portal', labelKey: 'nav.portal' },
] satisfies readonly NavItem[]

export const DEFAULT_ROUTE: string = NAV_ITEMS[0].path

/**
 * Auth surfaces. They are routes but not tabs: the header links to them and
 * the router mounts them inside the shell.
 */
export const LOGIN_ROUTE = '/login'
export const REGISTER_ROUTE = '/register'

/**
 * react-router nested routes must be relative; `NAV_ITEMS`/auth routes store
 * absolute paths for links. Strip the leading slash when declaring a child.
 */
export function routeSegment(path: string): string {
  return path.startsWith('/') ? path.slice(1) : path
}
