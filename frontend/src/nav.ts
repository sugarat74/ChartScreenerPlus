/**
 * Single source of truth for the shell surfaces.
 *
 * Tab labels, paths and the default route are derived from this list so the
 * header navigation and the router cannot drift apart.
 */
export type NavItem = {
  readonly path: string
  readonly label: string
}

export const NAV_ITEMS = [
  { path: '/screener', label: 'Screener' },
  { path: '/chart', label: 'Chart' },
  { path: '/admin', label: 'Admin' },
  { path: '/portal', label: 'Portal' },
] satisfies readonly NavItem[]

export const DEFAULT_ROUTE: string = NAV_ITEMS[0].path
