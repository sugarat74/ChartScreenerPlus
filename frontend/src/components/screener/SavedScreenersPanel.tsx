/**
 * Saved Screeners panel for `/screener`.
 *
 * This is the only auth-aware affordance on the Screener: a Visitor gets a
 * plain sign-in link (never a redirect) so `/screener` stays anonymous. Saving
 * persists the CURRENT URL filter set; applying deserializes the stored
 * definition and patches the URL, so the results are restored server-side
 * through the page's existing fetch effect. Reads/writes go through
 * `savedScreenersApi` (server-side scoped to the session user); the panel is a
 * UI affordance, not access control.
 */

import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { useAuth } from '../../auth/useAuth.ts'
import { ApiError, savedScreenersApi } from '../../lib/api.ts'
import type { SavedScreener, SavedScreenerFilters, ScreenerFilters } from '../../lib/api.ts'
import {
  SCREENER_SORT_OPTIONS,
  deserializeScreenerFilters,
  serializeScreenerFilters,
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

/** A compact, human-readable summary of a stored definition (mono row text). */
function describeFilters(filters: SavedScreenerFilters): string {
  const parts: string[] = []

  if (Array.isArray(filters.signal) && filters.signal.length > 0) {
    parts.push(filters.signal.map(signalLabel).join(', '))
  }
  if (typeof filters.rsi_min === 'number') {
    parts.push(`RSI ≥ ${filters.rsi_min}`)
  }
  if (typeof filters.rsi_max === 'number') {
    parts.push(`RSI ≤ ${filters.rsi_max}`)
  }
  if (typeof filters.min_rvol === 'number') {
    parts.push(`RVOL ≥ ${filters.min_rvol}`)
  }
  if (filters.price_above_sma200 === true) {
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

const CARD_CLASS =
  'rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]'

const LABEL_CLASS =
  'font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant'

const PRIMARY_BUTTON_CLASS =
  'w-fit rounded-md border-2 border-outline bg-primary-container px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60'

const SECONDARY_BUTTON_CLASS =
  'w-fit rounded-md border-2 border-outline bg-surface-bright px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60'

const NAME_INPUT_CLASS =
  'w-full rounded-md border-2 border-outline bg-surface-bright px-3 py-2 font-mono text-sm text-on-surface shadow-[2px_2px_0px_#1a1a1a] focus:border-outline focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none sm:w-64'

interface SavedScreenersPanelProps {
  /** The current URL-backed filter state; saving persists exactly this. */
  filters: ScreenerFilters
  /** Apply a stored definition: the page patches the URL and refetches. */
  onApply: (patch: Partial<ScreenerFilters>) => void
  /** Optional external reload trigger; a save also bumps an internal token. */
  reloadToken?: number
}

export default function SavedScreenersPanel({
  filters,
  onApply,
  reloadToken = 0,
}: SavedScreenersPanelProps) {
  const { user, status: authStatus } = useAuth()
  const navigate = useNavigate()

  const [items, setItems] = useState<SavedScreener[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [localToken, setLocalToken] = useState(0)

  const [name, setName] = useState('')
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [savedMessage, setSavedMessage] = useState<string | null>(null)

  const [removingId, setRemovingId] = useState<number | null>(null)
  const [removeError, setRemoveError] = useState<string | null>(null)

  // Fetch the caller's Screeners on mount and after a save/retry. The keys
  // (`user`, `reloadToken`, `localToken`) make a login/logout or save re-run the
  // request.
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
          // Session expired: send the user back through sign-in.
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
  }, [user, reloadToken, localToken, navigate])

  function reload() {
    setLoading(true)
    setError(null)
    setLocalToken((token) => token + 1)
  }

  async function handleSave(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    const trimmed = name.trim()
    if (trimmed === '') {
      setSaveError('Escribe un nombre para el screener.')
      setSavedMessage(null)
      return
    }

    setSaving(true)
    setSaveError(null)
    setSavedMessage(null)

    try {
      await savedScreenersApi.create(trimmed, serializeScreenerFilters(filters))
      setName('')
      setSavedMessage(`«${trimmed}» guardado.`)
      reload()
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 401) {
        navigate(LOGIN_ROUTE, { replace: true })
        return
      }
      setSaveError(messageFor(caught))
    } finally {
      setSaving(false)
    }
  }

  async function handleRemove(id: number) {
    setRemovingId(id)
    setRemoveError(null)

    try {
      await savedScreenersApi.remove(id)
      setItems((current) => current.filter((item) => item.id !== id))
    } catch (caught) {
      // A 404 means the row was already gone: drop it locally too.
      if (caught instanceof ApiError && caught.status === 404) {
        setItems((current) => current.filter((item) => item.id !== id))
      } else if (caught instanceof ApiError && caught.status === 401) {
        navigate(LOGIN_ROUTE, { replace: true })
      } else {
        setRemoveError(messageFor(caught))
      }
    } finally {
      setRemovingId(null)
    }
  }

  if (authStatus === 'loading') {
    return (
      <section role="status" aria-busy="true" className={CARD_CLASS}>
        <p className="font-mono text-xs uppercase tracking-wider text-on-surface-variant">
          Comprobando sesión…
        </p>
      </section>
    )
  }

  if (user === null) {
    return (
      <section className={CARD_CLASS}>
        <h2 className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
          Screeners guardados
        </h2>
        <p className="mt-1 text-sm text-on-surface-variant">
          Guarda este filtro con un nombre y vuelve a aplicarlo más tarde. Necesitas una cuenta.
        </p>
        <div className="mt-4">
          <Link to={LOGIN_ROUTE} className={PRIMARY_BUTTON_CLASS}>
            Inicia sesión para guardar
          </Link>
        </div>
      </section>
    )
  }

  return (
    <section className={CARD_CLASS}>
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
            Screeners guardados
          </h2>
          <p className="mt-1 text-sm text-on-surface-variant">
            Guarda el filtro actual y reutilízalo cuando quieras.
          </p>
        </div>
        <span className="rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a]">
          {items.length} {items.length === 1 ? 'screener' : 'screeners'}
        </span>
      </header>

      <form
        onSubmit={handleSave}
        className="mt-4 flex flex-wrap items-end gap-3 border-t-2 border-outline-variant pt-4"
      >
        <div className="flex flex-col gap-1">
          <label htmlFor="saved-screener-name" className={LABEL_CLASS}>
            Nombre
          </label>
          <input
            id="saved-screener-name"
            type="text"
            maxLength={60}
            value={name}
            onChange={(event) => setName(event.target.value)}
            placeholder="Cruce dorado RVOL"
            className={NAME_INPUT_CLASS}
          />
        </div>
        <button type="submit" disabled={saving} className={PRIMARY_BUTTON_CLASS}>
          {saving ? 'Guardando…' : 'Guardar screener'}
        </button>
      </form>

      {saveError !== null ? (
        <p
          role="alert"
          className="mt-3 rounded-[4px] border-2 border-secondary bg-secondary-container px-3 py-2 font-mono text-xs text-on-secondary-container"
        >
          {saveError}
        </p>
      ) : null}

      {savedMessage !== null ? (
        <p
          role="status"
          className="mt-3 rounded-[4px] border-2 border-outline bg-surface-container px-3 py-2 font-mono text-xs text-on-surface"
        >
          {savedMessage}
        </p>
      ) : null}

      <div className="mt-4">
        {loading ? (
          <div role="status" aria-busy="true" className="flex flex-col gap-2">
            <span className="font-mono text-xs uppercase tracking-wider text-on-surface-variant">
              Cargando screeners…
            </span>
            <div className="h-9 rounded-[2px] bg-surface-container" aria-hidden="true" />
            <div className="h-9 rounded-[2px] bg-surface-container" aria-hidden="true" />
          </div>
        ) : null}

        {!loading && error !== null ? (
          <div
            role="alert"
            className="flex flex-col gap-3 rounded-md border-2 border-secondary bg-secondary-container p-4 shadow-[2px_2px_0px_#1a1a1a]"
          >
            <div>
              <p className="font-headline text-sm font-black uppercase tracking-wide text-on-secondary-container">
                No se pudieron cargar tus screeners
              </p>
              <p className="mt-1 font-mono text-xs text-on-secondary-container">{error}</p>
            </div>
            <button type="button" onClick={reload} className={PRIMARY_BUTTON_CLASS}>
              Reintentar
            </button>
          </div>
        ) : null}

        {!loading && error === null && removeError !== null ? (
          <p
            role="alert"
            className="mb-3 rounded-[4px] border-2 border-secondary bg-secondary-container px-3 py-2 font-mono text-xs text-on-secondary-container"
          >
            {removeError}
          </p>
        ) : null}

        {!loading && error === null && items.length === 0 ? (
          <p className="rounded-[4px] border-2 border-dashed border-outline-variant bg-surface-container px-3 py-4 text-center font-mono text-xs text-on-surface-variant">
            No tienes screeners guardados.
          </p>
        ) : null}

        {!loading && error === null && items.length > 0 ? (
          <ul className="flex flex-col">
            <li className="flex items-center justify-between gap-3 border-b-2 border-outline py-2 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
              <span>Nombre / criterios</span>
              <span>Acciones</span>
            </li>
            {items.map((screener) => (
              <li
                key={screener.id}
                className="flex flex-wrap items-center justify-between gap-3 border-b border-outline-variant py-3"
              >
                <div className="min-w-0">
                  <p className="truncate font-mono text-sm font-bold text-on-surface">
                    {screener.name}
                  </p>
                  <p className="mt-0.5 max-w-2xl text-xs text-on-surface-variant">
                    {describeFilters(screener.filters)}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <button
                    type="button"
                    onClick={() => onApply(deserializeScreenerFilters(screener.filters))}
                    className={PRIMARY_BUTTON_CLASS}
                  >
                    Aplicar
                  </button>
                  <button
                    type="button"
                    onClick={() => handleRemove(screener.id)}
                    disabled={removingId === screener.id}
                    className={SECONDARY_BUTTON_CLASS}
                  >
                    {removingId === screener.id ? 'Eliminando…' : 'Eliminar'}
                  </button>
                </div>
              </li>
            ))}
          </ul>
        ) : null}
      </div>
    </section>
  )
}
