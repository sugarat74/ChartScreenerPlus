import { useRegisteredOnly } from '../../auth/useRegisteredOnly.ts'
import type { RegisteredOnlyState } from '../../auth/useRegisteredOnly.ts'
import type { MarketContextAccess } from '../../auth/useMarketContextAccess.ts'

/** `hidden` is only produced by the Market Context `access` seam: render nothing. */
export type ResolvedState = RegisteredOnlyState | 'hidden'

/**
 * The state a primitive renders. Without `access` it is the session state;
 * with a `MarketContextAccess` it follows that seam (`signIn` is a Visitor,
 * `loading` is loading, `hidden` renders nothing, `allowed` is enabled unless
 * the account still has to confirm its email).
 */
export function useResolvedState(access?: MarketContextAccess): ResolvedState {
  const { state } = useRegisteredOnly()

  if (access === undefined) {
    return state
  }
  switch (access.status) {
    case 'hidden':
      return 'hidden'
    case 'loading':
      return 'loading'
    case 'signIn':
      return 'guest'
    case 'allowed':
      return state === 'pending' ? 'pending' : 'enabled'
  }
}
