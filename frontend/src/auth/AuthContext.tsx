import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { authApi } from '../lib/api.ts'
import type { AuthUser, RegisterPayload } from '../lib/api.ts'
import { AuthContext } from './context.ts'
import type { AuthContextValue } from './context.ts'

/**
 * Owns the SPA auth state and bootstraps it from `GET /api/user` so a page
 * reload keeps the signed-in user visible. Read it with `useAuth`.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [status, setStatus] = useState<'loading' | 'ready'>('loading')

  useEffect(() => {
    let active = true

    void (async () => {
      try {
        const current = await authApi.currentUser()
        if (active) {
          setUser(current)
        }
      } catch {
        if (active) {
          setUser(null)
        }
      } finally {
        if (active) {
          setStatus('ready')
        }
      }
    })()

    return () => {
      active = false
    }
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    setUser(await authApi.login(email, password))
  }, [])

  const register = useCallback(async (payload: RegisterPayload) => {
    setUser(await authApi.register(payload))
  }, [])

  const logout = useCallback(async () => {
    await authApi.logout()
    setUser(null)
  }, [])

  const value = useMemo<AuthContextValue>(
    () => ({ user, status, login, register, logout }),
    [user, status, login, register, logout],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
