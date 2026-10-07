import { useCallback, useEffect, useState } from 'react'
import { useTranslateRef } from '../../../i18n/useI18n.ts'
import { apiErrorMessage } from '../../../lib/api.ts'

export interface AdminResource<T> {
  data: T | null
  loading: boolean
  error: string | null
  reload: () => void
}

/**
 * Load one Admin resource for `key` (a serialized request: path + params).
 * Keeps the previous data while refreshing, ignores stale responses and never
 * refetches on a language switch (errors use the latest translator).
 */
export function useAdminResource<T>(key: string, load: () => Promise<T>): AdminResource<T> {
  const tRef = useTranslateRef()
  const [state, setState] = useState<{ key: string; data: T | null; error: string | null; done: boolean }>({
    key: '',
    data: null,
    error: null,
    done: false,
  })
  const [token, setToken] = useState(0)

  useEffect(() => {
    let active = true

    load()
      .then((data) => {
        if (active) {
          setState({ key, data, error: null, done: true })
        }
      })
      .catch((caught: unknown) => {
        if (active) {
          setState((previous) => ({
            key,
            data: previous.data,
            error: apiErrorMessage(caught, tRef.current('common.networkError')),
            done: true,
          }))
        }
      })

    return () => {
      active = false
    }
    // `load` is recreated every render; `key` and `token` identify the request.
    // oxlint-disable-next-line react-hooks/exhaustive-deps
  }, [key, token, tRef])

  const reload = useCallback(() => setToken((value) => value + 1), [])

  return {
    data: state.data,
    loading: !state.done || state.key !== key,
    error: state.key === key ? state.error : null,
    reload,
  }
}
