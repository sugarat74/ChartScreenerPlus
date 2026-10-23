import { useContext } from 'react'
import { AuthContext } from './context.ts'

/**
 * The single client seam for email verification
 * (docs/specs/auth-email-verification.md, D7): `true` only when the resolved
 * session user still has to confirm their email (`email_verification_pending`,
 * computed by the server's `EmailVerification::pending()`). It returns `false`
 * outside an `AuthProvider` and while the session loads, and never fetches,
 * navigates or throws. A UI affordance only: the API enforces the same rule.
 */
export function useEmailVerificationPending(): boolean {
  const context = useContext(AuthContext)

  return context !== null && context.status === 'ready' && context.user?.email_verification_pending === true
}
