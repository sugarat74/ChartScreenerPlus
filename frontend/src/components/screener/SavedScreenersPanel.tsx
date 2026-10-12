/**
 * Saved Screeners panel for the public Screener at `/`.
 *
 * This is the only auth-aware affordance on the Screener: a Visitor gets a
 * plain sign-in link (never a redirect) so the Screener stays anonymous. Saving
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
import { useI18n, useTranslateRef } from '../../i18n/useI18n.ts'
import { ApiError, apiErrorMessage, savedScreenersApi } from '../../lib/api.ts'
import type { SavedScreener, ScreenerFilters } from '../../lib/api.ts'
import {
  describeSavedFilters,
  deserializeScreenerFilters,
  serializeScreenerFilters,
} from '../../lib/screenerFilters.ts'
import { LOGIN_ROUTE } from '../../nav.ts'


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
  const { t, formatDecimal } = useI18n()
  const tRef = useTranslateRef()
  const navigate = useNavigate()

  const [items, setItems] = useState<SavedScreener[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [localToken, setLocalToken] = useState(0)

  const [name, setName] = useState('')
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  // Only the name is kept; the confirmation is translated at render time so it
  // follows a language switch.
  const [savedName, setSavedName] = useState<string | null>(null)

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
        setError(apiErrorMessage(caught, tRef.current('common.networkError')))
        setLoading(false)
      }
    })()

    return () => {
      active = false
    }
  }, [user, reloadToken, localToken, navigate, tRef])

  function reload() {
    setLoading(true)
    setError(null)
    setLocalToken((token) => token + 1)
  }

  async function handleSave(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    const trimmed = name.trim()
    if (trimmed === '') {
      setSaveError(t('saved.nameRequired'))
      setSavedName(null)
      return
    }

    setSaving(true)
    setSaveError(null)
    setSavedName(null)

    try {
      await savedScreenersApi.create(trimmed, serializeScreenerFilters(filters))
      setName('')
      setSavedName(trimmed)
      reload()
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 401) {
        navigate(LOGIN_ROUTE, { replace: true })
        return
      }
      setSaveError(apiErrorMessage(caught, t('common.networkError')))
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
        setRemoveError(apiErrorMessage(caught, t('common.networkError')))
      }
    } finally {
      setRemovingId(null)
    }
  }

  if (authStatus === 'loading') {
    return null
  }

  if (user === null) {
    return (
      <section aria-label={t('saved.title')} className="flex justify-end">
        <Link
          to={LOGIN_ROUTE}
          className="font-mono text-xs font-bold text-on-surface-variant underline underline-offset-4 hover:text-on-surface focus:outline-2 focus:outline-offset-2 focus:outline-outline"
        >
          {t('saved.signInToSave')}
        </Link>
      </section>
    )
  }

  return (
    <details className="group border-b border-outline-variant pb-2">
      <summary className="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 py-1 focus:outline-2 focus:outline-offset-2 focus:outline-outline [&::-webkit-details-marker]:hidden">
        <span className="font-headline text-sm font-bold uppercase tracking-wide text-on-surface">
          {t('saved.title')}
        </span>
        <span className="flex items-center gap-2 font-mono text-xs text-on-surface-variant">
          <span>
            {loading
              ? t('saved.loading')
              : error !== null
                ? t('saved.loadError')
                : items.length === 1
                  ? t('saved.countOne', { count: items.length })
                  : t('saved.countOther', { count: items.length })}
          </span>
          <span aria-hidden="true" className="inline-block text-lg leading-none transition-transform group-open:rotate-45">+</span>
        </span>
      </summary>

      <form
        onSubmit={handleSave}
        className="mt-3 flex flex-wrap items-end gap-3 border-t border-outline-variant pt-4"
      >
        <div className="flex flex-col gap-1">
          <label htmlFor="saved-screener-name" className={LABEL_CLASS}>
            {t('saved.nameLabel')}
          </label>
          <input
            id="saved-screener-name"
            type="text"
            maxLength={60}
            value={name}
            onChange={(event) => setName(event.target.value)}
            placeholder={t('saved.namePlaceholder')}
            className={NAME_INPUT_CLASS}
          />
        </div>
        <button type="submit" disabled={saving} className={PRIMARY_BUTTON_CLASS}>
          {saving ? t('saved.saving') : t('saved.save')}
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

      {savedName !== null ? (
        <p
          role="status"
          className="mt-3 rounded-[4px] border-2 border-outline bg-surface-container px-3 py-2 font-mono text-xs text-on-surface"
        >
          {t('saved.savedMessage', { name: savedName })}
        </p>
      ) : null}

      <div className="mt-4">
        {loading ? (
          <div role="status" aria-busy="true" className="flex flex-col gap-2">
            <span className="font-mono text-xs uppercase tracking-wider text-on-surface-variant">
              {t('saved.loading')}
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
                {t('saved.loadError')}
              </p>
              <p className="mt-1 font-mono text-xs text-on-secondary-container">{error}</p>
            </div>
            <button type="button" onClick={reload} className={PRIMARY_BUTTON_CLASS}>
              {t('common.retry')}
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
            {t('saved.empty')}
          </p>
        ) : null}

        {!loading && error === null && items.length > 0 ? (
          <ul className="flex flex-col">
            <li className="flex items-center justify-between gap-3 border-b-2 border-outline py-2 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
              <span>{t('saved.colNameCriteria')}</span>
              <span>{t('saved.colActions')}</span>
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
                    {describeSavedFilters(screener.filters, t, formatDecimal)}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <button
                    type="button"
                    onClick={() => onApply(deserializeScreenerFilters(screener.filters))}
                    className={PRIMARY_BUTTON_CLASS}
                  >
                    {t('saved.apply')}
                  </button>
                  <button
                    type="button"
                    onClick={() => handleRemove(screener.id)}
                    disabled={removingId === screener.id}
                    className={SECONDARY_BUTTON_CLASS}
                  >
                    {removingId === screener.id ? t('saved.removing') : t('saved.remove')}
                  </button>
                </div>
              </li>
            ))}
          </ul>
        ) : null}
      </div>
    </details>
  )
}
