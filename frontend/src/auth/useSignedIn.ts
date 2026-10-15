import { useContext } from 'react'
import { AuthContext } from './context.ts'

/**
 * `true` only when the already bootstrapped session is resolved and has a
 * user. Read-only way for anonymous surfaces (Candidate rows, chart panels) to
 * hide signed-in affordances: it never fetches, never navigates and never
 * throws, returning `false` while auth is loading and outside an
 * `AuthProvider` (docs/specs/premium-panels-auth-only.md).
 */
export function useSignedIn(): boolean {
  const context = useContext(AuthContext)

  return context !== null && context.status === 'ready' && context.user !== null
}
