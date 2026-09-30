/**
 * Portal-only Saved Screeners list. The protected API remains the ownership
 * boundary; this component only presents the caller-scoped collection and
 * replays a stored definition through the Screener's canonical URL helpers.
 */

import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router'
import { useAuth } from '../../auth/useAuth.ts'
import { ApiError, savedScreenersApi } from '../../lib/api.ts'
import type { SavedScreener, SavedScreenerFilters } from '../../lib/api.ts'
import {
  SCREENER_SORT_OPTIONS,
  deserializeScreenerFilters,
  patchScreenerFilters,
  signalLabel,
} from '../../lib/screenerFilters.ts'
import { LOGIN_ROUTE } from '../../nav.ts'

function messageFor(error: unknown): string {
  if (error instanceof ApiError) {
    const firstValidation = Object.values(error.errors)[0]?.[0]
    return firstValidation ?? error.message
  }

  return 'No se pudo contactar con el servidor. Inténtalo de nuevo.'
}

function describeFilters(filters: SavedScreenerFilters): string {
  const parts: string[] = []

  if (filters.signal.length > 0) {
    parts.push(filters.signal.map(signalLabel).join(', '))
  }
  if (filters.rsi_min !== null) {
    parts.push(`RSI ≥ ${filters.rsi_min}`)
  }
  if (filters.rsi_max !== null) {
    parts.push(`RSI ≤ ${filters.rsi_max}`)
  }
  if (filters.min_rvol !== null) {
    parts.push(`RVOL ≥ ${filters.min_rvol}`)
  }
  if (filters.price_above_sma200) {
    parts.push('Precio > SMA200')
  }
  if (filters.ma_cross === 'bullish') {
    parts.push('Cruce alcista')
  }
  if (filters.ma_cross === 'bearish') {
    parts.push('Cruce bajista')
  }

  const sort = SCREENER_SORT_OPTIONS.find((option) => option.value === filters.sort)
  if (sort !== undefined) {
    parts.push(`Orden: ${sort.label}`)
  }

  return parts.length > 0 ? parts.join(' · ') : 'Sin criterios · ranking por defecto'
}

const PRIMARY_BUTTON_CLASS =
  'rounded-md border-2 border-outline bg-primary-container px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60'

const SECONDARY_BUTTON_CLASS =
  'rounded-md border-2 border-outline bg-surface-bright px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60'

export default function PortalSavedScreeners() {
  const { user } = useAuth()
  const navigate = useNavigate()
  const [items, setItems] = useState<SavedScreener[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [reloadToken, setReloadToken] = useState(0)
  const [removingId, setRemovingId] = useState<number | null>(null)
  const [removeError, setRemoveError] = useState<string | null>(null)

  useEffect(() => {
    if (user === null) {
      return
    }

    let active = true

    void (async () => {
      try {
        const list = await savedScreenersApi.list()
        if (active) {
          setItems(list)
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
        setError(messageFor(caught))
        setLoading(false)
      }
    })()

    return () => {
      active = false
    }
  }, [user, reloadToken, navigate])

  function reload() {
    setLoading(true)
    setError(null)
    setReloadToken((token) => token + 1)
  }

  function apply(screener: SavedScreener) {
    const patch = deserializeScreenerFilters(screener.filters)
    const params = patchScreenerFilters(new URLSearchParams(), patch)
    navigate(`/screener${params.size > 0 ? `?${params.toString()}` : ''}`)
  }

  async function remove(id: number) {
    setRemovingId(id)
    setRemoveError(null)

    try {
      await savedScreenersApi.remove(id)
      // Reload the caller-scoped API list after the protected mutation rather
      // than maintaining a shared cache or assuming ownership client-side.
      reload()
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 404) {
        // The row is already absent for this caller; refresh the authoritative list.
        reload()
      } else if (caught instanceof ApiError && caught.status === 401) {
        navigate(LOGIN_ROUTE, { replace: true })
      } else {
        setRemoveError(messageFor(caught))
      }
    } finally {
      setRemovingId(null)
    }
  }

  return (
    <section className="flex flex-col gap-4" aria-labelledby="portal-screeners-heading">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 id="portal-screeners-heading" className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
            Screeners guardados
          </h2>
          <p className="mt-1 text-sm text-on-surface-variant">
            Repite una definición guardada o elimina las que ya no necesitas.
          </p>
        </div>
        <span className="rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a]">
          {items.length} {items.length === 1 ? 'screener' : 'screeners'}
        </span>
      </header>

      {loading ? (
        <div role="status" aria-busy="true" className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]">
          <p className="font-mono text-xs uppercase tracking-wider text-on-surface-variant">Cargando screeners…</p>
          <div className="mt-4 flex flex-col gap-2" aria-hidden="true">
            <div className="h-9 rounded-[2px] bg-surface-container" />
            <div className="h-9 rounded-[2px] bg-surface-container" />
          </div>
        </div>
      ) : null}

      {!loading && error !== null ? (
        <div role="alert" className="flex flex-col gap-3 rounded-md border-2 border-secondary bg-secondary-container p-5 shadow-[2px_2px_0px_#1a1a1a]">
          <div>
            <p className="font-headline text-sm font-black uppercase tracking-wide text-on-secondary-container">No se pudieron cargar tus screeners</p>
            <p className="mt-1 font-mono text-xs text-on-secondary-container">{error}</p>
          </div>
          <button type="button" onClick={reload} className={PRIMARY_BUTTON_CLASS}>Reintentar</button>
        </div>
      ) : null}

      {!loading && error === null && items.length === 0 ? (
        <div className="rounded-md border-2 border-outline bg-surface-bright p-8 text-center shadow-[2px_2px_0px_#1a1a1a]">
          <p className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">No tienes screeners guardados</p>
          <p className="mt-2 text-sm text-on-surface-variant">Guarda una definición desde el Screener para volver a aplicarla aquí.</p>
        </div>
      ) : null}

      {!loading && error === null && items.length > 0 ? (
        <>
          {removeError !== null ? <p role="alert" className="rounded-[4px] border-2 border-secondary bg-secondary-container px-3 py-2 font-mono text-xs text-on-secondary-container">{removeError}</p> : null}
          <div className="overflow-x-auto rounded-md border-2 border-outline bg-surface-bright shadow-[2px_2px_0px_#1a1a1a]">
            <table className="w-full border-collapse text-left">
              <thead>
                <tr className="border-b-2 border-outline bg-surface-container font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
                  <th scope="col" className="px-4 py-2">Nombre / criterios</th>
                  <th scope="col" className="px-4 py-2 text-right">Acciones</th>
                </tr>
              </thead>
              <tbody>
                {items.map((screener) => (
                  <tr key={screener.id} className="border-b border-outline-variant align-top">
                    <th scope="row" className="px-4 py-3 text-left font-normal">
                      <p className="font-mono text-sm font-bold text-on-surface">{screener.name}</p>
                      <p className="mt-0.5 max-w-2xl text-xs text-on-surface-variant">{describeFilters(screener.filters)}</p>
                    </th>
                    <td className="px-4 py-3 text-right">
                      <div className="flex flex-wrap justify-end gap-2">
                        <button type="button" onClick={() => apply(screener)} className={PRIMARY_BUTTON_CLASS}>Aplicar</button>
                        <button type="button" onClick={() => void remove(screener.id)} disabled={removingId === screener.id} className={SECONDARY_BUTTON_CLASS}>
                          {removingId === screener.id ? 'Eliminando…' : 'Eliminar'}
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      ) : null}
    </section>
  )
}
