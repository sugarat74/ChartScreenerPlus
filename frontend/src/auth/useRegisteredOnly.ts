import { useContext } from 'react'
import { AuthContext } from './context.ts'
import { useEmailVerificationPending } from './useEmailVerificationPending.ts'
import { useSignedIn } from './useSignedIn.ts'

export type RegisteredOnlyState = 'loading' | 'guest' | 'pending' | 'enabled'

/**
 * The client seam for registered-only options
 * (docs/specs/guest-disabled-registered-options.md): `enabled` for a verified
 * signed-in user, `pending` for an account that still has to confirm its
 * email, `loading` while the session bootstraps, `guest` for a Visitor and
 * outside an `AuthProvider`. A pure read of the bootstrapped session: it never
 * fetches, navigates or throws, and it is a presentation affordance only (the
 * API keeps answering 401/403/409).
 */
export function useRegisteredOnly(): { state: RegisteredOnlyState } {
  const context = useContext(AuthContext)
  const signedIn = useSignedIn()
  const pending = useEmailVerificationPending()

  if (context !== null && context.status === 'loading') {
    return { state: 'loading' }
  }
  if (!signedIn) {
    return { state: 'guest' }
  }

  return { state: pending ? 'pending' : 'enabled' }
}
