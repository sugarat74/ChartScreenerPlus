import { useContext } from 'react'
import { useLegalInfo } from '../legal/useLegalInfo.ts'
import { AuthContext } from './context.ts'

export type MarketContextAccessStatus = 'loading' | 'allowed' | 'signIn' | 'hidden'

export interface MarketContextAccess {
  /**
   * `allowed`: the session user has the `market_context` capability.
   * `signIn`: a Visitor while the owner's go-live flag is on (offer a sign-in link).
   * `hidden`: anyone else (a user without access, a Visitor while the flag is off).
   * `loading`: the session (or, for a Visitor, the legal info) is still loading.
   */
  status: MarketContextAccessStatus
  /** Allowed only as a preview: an Admin while the go-live flag is off. */
  preview: boolean
}

/**
 * The single client seam for Market Context access
 * (docs/specs/market-context-patterns.md, SPA "Access hook"). It reads the
 * already bootstrapped session (`capabilities.market_context`, computed by the
 * server's `view-market-context` ability) and the public go-live flag of
 * `GET /api/legal`; no component reads the role or the flag for Market Context.
 *
 * It never fetches anything of its own, never redirects and never throws
 * outside an `AuthProvider` (treated as a Visitor). It is a UI affordance
 * only: every Market Context API enforces the same ability server-side.
 */
export function useMarketContextAccess(): MarketContextAccess {
  const auth = useContext(AuthContext)
  const legal = useLegalInfo()

  if (auth !== null && auth.status === 'loading') {
    return { status: 'loading', preview: false }
  }

  const user = auth?.user ?? null
  // Tolerates an empty body: an absent flag (older deployments) is off.
  const flagOn = legal.status === 'ready' && legal.info?.market_context === true

  if (user !== null) {
    if (user.capabilities?.market_context === true) {
      return { status: 'allowed', preview: legal.status === 'ready' && !flagOn }
    }
    return { status: 'hidden', preview: false }
  }

  // A Visitor: wait for the go-live flag instead of flashing "hidden".
  if (legal.status === 'loading') {
    return { status: 'loading', preview: false }
  }

  return { status: flagOn ? 'signIn' : 'hidden', preview: false }
}
