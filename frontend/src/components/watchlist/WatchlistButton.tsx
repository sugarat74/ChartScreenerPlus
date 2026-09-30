/**
 * "Seguir"/"Siguiendo" toggle for one instrument.
 *
 * This is the only auth-aware affordance on the chart page: a Visitor gets a
 * sign-in link, never a redirect, so the chart stays browseable. Reads and
 * writes go through the owned `watchlistApi` (server-side scoped to the session
 * user); the button is a UI affordance, not access control.
 */

import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { useAuth } from '../../auth/useAuth.ts'
import { ApiError, watchlistApi } from '../../lib/api.ts'
import { LOGIN_ROUTE } from '../../nav.ts'

function messageFor(error: unknown): string {
  if (error instanceof ApiError) {
    const firstValidation = Object.values(error.errors)[0]?.[0]
    return firstValidation ?? error.message
  }

  return 'No se pudo contactar con el servidor. Inténtalo de nuevo.'
}

const BASE_BUTTON_CLASS =
  'rounded-md border-2 border-outline px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-70'

export default function WatchlistButton({ ticker }: { ticker: string }) {
  const { user, status: authStatus } = useAuth()

  // Membership is keyed to `user.id|ticker` so a session or ticker change never
  // renders a stale "Siguiendo" while the fresh check is in flight.
  const [membership, setMembership] = useState<{ key: string; following: boolean } | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<{ key: string; message: string } | null>(null)

  const membershipKey = user === null ? null : `${user.id}|${ticker}`
  const following =
    membership !== null && membership.key === membershipKey ? membership.following : null
  const checking = user !== null && following === null
  const visibleError = error !== null && error.key === membershipKey ? error.message : null

  useEffect(() => {
    if (user === null) {
      return
    }

    const key = `${user.id}|${ticker}`
    let active = true

    void (async () => {
      try {
        const items = await watchlistApi.list()
        if (active) {
          setMembership({ key, following: items.some((item) => item.ticker === ticker) })
        }
      } catch (caught) {
        if (active) {
          setError({ key, message: messageFor(caught) })
        }
      }
    })()

    return () => {
      active = false
    }
  }, [user, ticker])

  async function toggle() {
    if (user === null || following === null) {
      return
    }

    const key = `${user.id}|${ticker}`
    setBusy(true)
    setError(null)

    try {
      if (following) {
        try {
          await watchlistApi.remove(ticker)
        } catch (caught) {
          // A 404 means it was already gone: treat it as removed.
          if (!(caught instanceof ApiError && caught.status === 404)) {
            throw caught
          }
        }
        setMembership({ key, following: false })
      } else {
        await watchlistApi.add(ticker)
        setMembership({ key, following: true })
      }
    } catch (caught) {
      setError({ key, message: messageFor(caught) })
    } finally {
      setBusy(false)
    }
  }

  if (authStatus === 'loading') {
    return (
      <button type="button" disabled className={BASE_BUTTON_CLASS}>
        Comprobando…
      </button>
    )
  }

  if (user === null) {
    return (
      <Link
        to={LOGIN_ROUTE}
        className={`${BASE_BUTTON_CLASS} bg-primary-container text-on-primary-container`}
      >
        Inicia sesión para seguir
      </Link>
    )
  }

  const isFollowing = following === true
  const label = checking ? 'Comprobando…' : isFollowing ? 'Siguiendo' : 'Seguir'

  return (
    <div className="flex flex-col items-end gap-2">
      <button
        type="button"
        onClick={toggle}
        aria-pressed={isFollowing}
        disabled={checking || busy}
        className={[
          BASE_BUTTON_CLASS,
          isFollowing
            ? 'bg-surface-bright text-on-surface'
            : 'bg-primary-container text-on-primary-container',
        ].join(' ')}
      >
        {busy ? 'Guardando…' : label}
      </button>

      {visibleError ? (
        <p
          role="alert"
          className="max-w-60 rounded-[4px] border-2 border-secondary bg-secondary-container px-2 py-1 font-mono text-[11px] text-on-secondary-container"
        >
          {visibleError}
        </p>
      ) : null}
    </div>
  )
}
