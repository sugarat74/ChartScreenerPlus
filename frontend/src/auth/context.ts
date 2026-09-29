import { createContext } from 'react'
import type { AuthUser, RegisterPayload } from '../lib/api.ts'

export interface AuthContextValue {
  /** The signed-in user, or `null` for a Visitor. */
  user: AuthUser | null
  /** `loading` while the session is bootstrapped from `GET /api/user`. */
  status: 'loading' | 'ready'
  login: (email: string, password: string) => Promise<void>
  register: (payload: RegisterPayload) => Promise<void>
  logout: () => Promise<void>
}

/**
 * Auth context object lives outside `AuthContext.tsx` so that file only
 * exports its React component (keeps Fast Refresh lint clean).
 */
export const AuthContext = createContext<AuthContextValue | null>(null)
