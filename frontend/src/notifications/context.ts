import { createContext } from 'react'

export interface UnreadNotificationsValue {
  /** The caller's unread alert notifications; always `0` for a Visitor. */
  count: number
  /** Re-fetch the count now (it also refreshes on every route change). */
  refresh: () => void
  /** Set the count from a response the caller already has (e.g. the inbox). */
  setCount: (count: number) => void
}

/**
 * Context object lives outside the provider file so that file only exports
 * its React component (keeps Fast Refresh lint clean).
 */
export const UnreadNotificationsContext = createContext<UnreadNotificationsValue | null>(null)
