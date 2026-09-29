import { useContext } from 'react'
import { AuthContext } from './context.ts'
import type { AuthContextValue } from './context.ts'

/**
 * Read the SPA auth state. Must be used below an `AuthProvider`.
 */
export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)

  if (context === null) {
    throw new Error('useAuth must be used inside an AuthProvider')
  }

  return context
}
