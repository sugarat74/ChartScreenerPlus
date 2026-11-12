import { useLocation } from 'react-router'
import { loginHref, registerHref } from './nextPath.ts'

/** Sign-in and register links that return the user to the current path, query and hash. */
export function useAuthLinks(): { login: string; register: string } {
  const location = useLocation()
  const here = `${location.pathname}${location.search}${location.hash}`

  return { login: loginHref(here), register: registerHref(here) }
}
