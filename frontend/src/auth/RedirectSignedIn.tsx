import { useEffect, useState } from 'react'
import { Navigate } from 'react-router'
import { authApi } from '../lib/api.ts'
import { DEFAULT_ROUTE } from '../nav.ts'
import { useAuth } from './useAuth.ts'

/**
 * Rendered by the sign-in and register pages while the context still holds a
 * user. The 401 paths of the signed-in surfaces send an expired session here
 * without clearing that user, so redirecting home blindly would bounce
 * between home and sign-in forever. The session is checked first: a live one
 * goes home, an expired one clears the user and the page shows its form.
 */
export default function RedirectSignedIn() {
  const { setCurrentUser } = useAuth()
  const [live, setLive] = useState(false)

  useEffect(() => {
    let active = true

    void (async () => {
      try {
        const current = await authApi.currentUser()
        if (!active) {
          return
        }
        if (current === null) {
          setCurrentUser(null)
        } else {
          setLive(true)
        }
      } catch {
        // Server unreachable: keep the old behaviour and go home.
        if (active) {
          setLive(true)
        }
      }
    })()

    return () => {
      active = false
    }
  }, [setCurrentUser])

  return live ? <Navigate to={DEFAULT_ROUTE} replace /> : null
}
