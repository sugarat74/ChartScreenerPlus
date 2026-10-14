import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router'
import { useAuth } from '../../auth/useAuth.ts'
import { useTranslateRef } from '../../i18n/useI18n.ts'
import { ApiError, alertsApi, apiErrorMessage } from '../../lib/api.ts'
import type { Alert } from '../../lib/api.ts'
import { LOGIN_ROUTE } from '../../nav.ts'

export type PortalAlerts = {
  alerts: Alert[]
  loading: boolean
  error: string | null
  reload: () => void
  /** Insert or replace one alert after a successful create/update. */
  upsert: (alert: Alert) => void
  /** Drop alerts locally after a delete (or a cascading Saved Screener delete). */
  drop: (predicate: (alert: Alert) => boolean) => void
}

/**
 * The caller's Alert rules for the Portal. The API scopes every call to the
 * session user; this hook only keeps the list the Portal controls render.
 */
export function usePortalAlerts(): PortalAlerts {
  const { user } = useAuth()
  const tRef = useTranslateRef()
  const navigate = useNavigate()
  const [alerts, setAlerts] = useState<Alert[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [reloadToken, setReloadToken] = useState(0)

  useEffect(() => {
    if (user === null) {
      return
    }

    let active = true

    void (async () => {
      try {
        const list = await alertsApi.list()
        if (active) {
          setAlerts(list)
          setError(null)
          setLoading(false)
        }
      } catch (caught) {
        if (!active) {
          return
        }
        if (caught instanceof ApiError && caught.status === 401) {
          navigate(LOGIN_ROUTE, { replace: true })
          return
        }
        setError(apiErrorMessage(caught, tRef.current('common.networkError')))
        setLoading(false)
      }
    })()

    return () => {
      active = false
    }
  }, [user, reloadToken, navigate, tRef])

  const reload = useCallback(() => {
    setLoading(true)
    setError(null)
    setReloadToken((token) => token + 1)
  }, [])

  const upsert = useCallback((alert: Alert) => {
    setAlerts((current) =>
      current.some((item) => item.id === alert.id)
        ? current.map((item) => (item.id === alert.id ? alert : item))
        : [...current, alert],
    )
  }, [])

  const drop = useCallback((predicate: (alert: Alert) => boolean) => {
    setAlerts((current) => current.filter((item) => !predicate(item)))
  }, [])

  return { alerts, loading, error, reload, upsert, drop }
}
