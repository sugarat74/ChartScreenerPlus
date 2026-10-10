import { useEffect, useState } from 'react'
import { legalApi } from '../lib/api.ts'
import type { LegalInfo } from '../lib/api.ts'

export type LegalInfoState =
  | { status: 'loading' }
  | { status: 'ready'; info: LegalInfo }
  | { status: 'error' }

let cached: Promise<LegalInfo> | null = null

/** One request per page load: the footer, the legal pages and the register form share it. */
function loadLegalInfo(): Promise<LegalInfo> {
  if (!cached) {
    cached = legalApi.info().catch((error: unknown) => {
      cached = null
      throw error
    })
  }
  return cached
}

/** Test hook: forget the cached response. */
export function resetLegalInfoCache(): void {
  cached = null
}

export function useLegalInfo(): LegalInfoState {
  const [state, setState] = useState<LegalInfoState>({ status: 'loading' })

  useEffect(() => {
    let active = true
    loadLegalInfo().then(
      (info) => {
        if (active) setState({ status: 'ready', info })
      },
      () => {
        if (active) setState({ status: 'error' })
      },
    )
    return () => {
      active = false
    }
  }, [])

  return state
}
