import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { useLocation } from 'react-router'
import { useAuth } from '../auth/useAuth.ts'
import { useEmailVerificationPending } from '../auth/useEmailVerificationPending.ts'
import { notificationsApi } from '../lib/api.ts'
import { UnreadNotificationsContext } from './context.ts'

/**
 * Keeps the unread alert-notification count for the signed-in user.
 *
 * There are no live updates (alerts are evaluated once per daily run): the
 * count is fetched on sign-in, on every route change and on `refresh()`.
 * A Visitor never triggers a request and always sees `0`. A failed request
 * keeps the last known count; the bell is a convenience, not a gate.
 */
export function UnreadNotificationsProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  const { pathname } = useLocation()
  // An account that still has to confirm its email (auth-email-verification)
  // has no access to notifications: it is treated like a Visitor here.
  const pending = useEmailVerificationPending()
  const userId = pending ? null : (user?.id ?? null)
  const [count, setStoredCount] = useState(0)
  const [reloadToken, setReloadToken] = useState(0)
  // Bumped whenever the count is set locally, so an older in-flight fetch
  // cannot overwrite a fresher value (e.g. right after "mark all read").
  const sequence = useRef(0)

  useEffect(() => {
    if (userId === null) {
      return
    }

    const requestSequence = ++sequence.current
    void notificationsApi
      .list(1)
      .then((page) => {
        if (sequence.current === requestSequence) {
          setStoredCount(page.unread_count)
        }
      })
      .catch(() => {
        // Keep the last known count; the inbox shows errors with retry.
      })
  }, [userId, pathname, reloadToken])

  const refresh = useCallback(() => setReloadToken((token) => token + 1), [])
  const setCount = useCallback((next: number) => {
    sequence.current += 1
    setStoredCount(Math.max(0, next))
  }, [])

  const value = useMemo(
    () => ({ count: userId === null ? 0 : count, refresh, setCount }),
    [userId, count, refresh, setCount],
  )

  return <UnreadNotificationsContext.Provider value={value}>{children}</UnreadNotificationsContext.Provider>
}
