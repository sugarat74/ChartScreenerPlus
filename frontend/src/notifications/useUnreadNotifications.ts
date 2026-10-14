import { useContext } from 'react'
import { UnreadNotificationsContext } from './context.ts'
import type { UnreadNotificationsValue } from './context.ts'

/**
 * The signed-in user's unread notification count shared by the header bell
 * and the inbox. Must be used below an `UnreadNotificationsProvider`.
 */
export function useUnreadNotifications(): UnreadNotificationsValue {
  const context = useContext(UnreadNotificationsContext)

  if (context === null) {
    throw new Error('useUnreadNotifications must be used inside an UnreadNotificationsProvider')
  }

  return context
}
