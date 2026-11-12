import { DEFAULT_ROUTE, FORGOT_PASSWORD_ROUTE, LOGIN_ROUTE, REGISTER_ROUTE, RESET_PASSWORD_ROUTE } from '../nav.ts'

/** Routes a return path must never point at (a loop back into sign-in). */
const AUTH_ROUTES: readonly string[] = [LOGIN_ROUTE, REGISTER_ROUTE, FORGOT_PASSWORD_ROUTE, RESET_PASSWORD_ROUTE]

/** Name of the query parameter that carries the return path. */
export const NEXT_PARAM = 'next'

/**
 * Validate a `next` return path (docs/specs/guest-disabled-registered-options.md,
 * domain decision 5). Accepted only when it is a relative path that starts with
 * a single `/`: no `//host`, no scheme, no backslash or control character and
 * not an auth route. Anything else (or an absent value) is the home route, so
 * the parameter can never become an open redirect.
 */
export function nextPath(raw: string | null | undefined): string {
  if (typeof raw !== 'string' || raw === '') {
    return DEFAULT_ROUTE
  }
  if (!raw.startsWith('/') || raw.startsWith('//')) {
    return DEFAULT_ROUTE
  }
  // eslint-disable-next-line no-control-regex
  if (raw.includes('\\') ||/[\u0000-\u001f\u007f]/.test(raw)) {
    return DEFAULT_ROUTE
  }

  const pathname = (raw.split(/[?#]/, 1)[0] ?? '').toLowerCase().replace(/\/+$/, '')
  if (AUTH_ROUTES.includes(pathname)) {
    return DEFAULT_ROUTE
  }

  return raw
}

/** `route` (an auth route) carrying `next` when it is worth preserving (not home). */
export function withNext(route: string, next: string | null | undefined): string {
  const safe = nextPath(next)

  return safe === DEFAULT_ROUTE ? route : `${route}?${NEXT_PARAM}=${encodeURIComponent(safe)}`
}

/** `/login?next=<path>` for a sign-in link; the path is validated here too. */
export function loginHref(next: string | null | undefined): string {
  return withNext(LOGIN_ROUTE, next)
}

/** `/register?next=<path>`. */
export function registerHref(next: string | null | undefined): string {
  return withNext(REGISTER_ROUTE, next)
}
