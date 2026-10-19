/**
 * Natural-language Screener input (docs/specs/ai-screener-natural-language.md).
 *
 * Signed-in only, like the premium panels: nothing renders while auth is
 * loading (no flash), a Visitor sees a sign-in prompt instead of the input,
 * and the server enforces the boundary (`401`). A request is sent only on
 * submit, never while typing. The answer is ordinary filter state: the page
 * patches the URL with it (page reset to 1), so the user can adjust, share or
 * save it as usual. The previous URL is kept here so "Undo" restores it.
 * The explanation and the unsupported parts are rendered as plain text.
 */

import { useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate } from 'react-router'
import { useAuth } from '../../auth/useAuth.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import { ApiError, apiErrorMessage, screenerApi } from '../../lib/api.ts'
import type { ScreenerFilters } from '../../lib/api.ts'
import { interpretedFiltersToPatch } from '../../lib/screenerFilters.ts'
import { LOGIN_ROUTE } from '../../nav.ts'

const MIN_LENGTH = 3
const MAX_LENGTH = 300

const EXAMPLE_KEYS = ['first', 'second', 'third'] as const

const PRIMARY_BUTTON_CLASS =
  'w-fit shrink-0 rounded-md border-2 border-outline bg-primary-container px-3 py-2 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:translate-y-0 disabled:cursor-not-allowed disabled:opacity-60'

const SECONDARY_BUTTON_CLASS =
  'w-fit rounded-md border-2 border-outline bg-surface-bright px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none'

const INPUT_CLASS =
  'min-w-0 flex-1 rounded-md border-2 border-outline bg-surface-bright px-3 py-2 font-mono text-sm text-on-surface shadow-[2px_2px_0px_#1a1a1a] focus:border-outline focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:opacity-60'

const BADGE_CLASS =
  'rounded border border-outline bg-primary-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase text-on-primary-container'

interface ScreenerAiQueryProps {
  /** The current URL query; captured before applying so Undo can restore it. */
  searchParams: URLSearchParams
  /** Apply interpreted filters: the page patches the URL and resets the page. */
  onApply: (patch: Partial<ScreenerFilters>) => void
  /** Restore a previous URL query exactly (Undo). */
  onRestore: (params: URLSearchParams) => void
}

type Applied = { explanation: string; unsupported: string[]; previous: URLSearchParams }

export default function ScreenerAiQuery({ searchParams, onApply, onRestore }: ScreenerAiQueryProps) {
  const { user, status: authStatus } = useAuth()
  const { t, locale } = useI18n()
  const navigate = useNavigate()
  const inputRef = useRef<HTMLInputElement>(null)

  const [query, setQuery] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [unavailable, setUnavailable] = useState(false)
  const [applied, setApplied] = useState<Applied | null>(null)

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (busy) {
      return
    }
    const text = query.trim()
    if (text.length < MIN_LENGTH) {
      setError(t('aiQuery.tooShort'))
      return
    }

    setBusy(true)
    setError(null)
    const previous = new URLSearchParams(searchParams)
    try {
      const result = await screenerApi.interpret(text, locale)
      onApply(interpretedFiltersToPatch(result.filters))
      setApplied({ explanation: result.explanation, unsupported: result.unsupported, previous })
    } catch (caught) {
      if (caught instanceof ApiError && caught.status === 401) {
        navigate(LOGIN_ROUTE, { replace: true })
        return
      }
      if (caught instanceof ApiError && caught.code === 'provider_not_configured') {
        setUnavailable(true)
        return
      }
      // Nothing was applied: the current filters stay as they were.
      setError(apiErrorMessage(caught, t('common.networkError')))
    } finally {
      setBusy(false)
    }
  }

  function pickExample(text: string) {
    setQuery(text)
    setError(null)
    inputRef.current?.focus()
  }

  function undo() {
    if (applied !== null) {
      onRestore(applied.previous)
      setApplied(null)
    }
  }

  if (authStatus === 'loading') {
    return null
  }

  if (user === null) {
    return (
      <section
        aria-labelledby="ai-query-title"
        className="flex flex-wrap items-center justify-between gap-3 rounded-md border-2 border-dashed border-outline-variant bg-surface-container px-4 py-3"
      >
        <div className="min-w-0">
          <h2 id="ai-query-title" className="flex items-center gap-2 font-headline text-sm font-bold uppercase tracking-wide text-on-surface">
            {t('aiQuery.title')}
            <span className={BADGE_CLASS}>{t('aiQuery.aiBadge')}</span>
          </h2>
          <p className="mt-1 text-xs text-on-surface-variant">{t('aiQuery.guestIntro')}</p>
        </div>
        <Link
          to={LOGIN_ROUTE}
          className="font-mono text-xs font-bold text-on-surface underline underline-offset-4 hover:text-primary focus:outline-2 focus:outline-offset-2 focus:outline-outline"
        >
          {t('aiQuery.signIn')}
        </Link>
      </section>
    )
  }

  if (unavailable) {
    return (
      <p role="status" className="rounded-[4px] border-2 border-dashed border-outline-variant bg-surface-container px-3 py-2 font-mono text-xs text-on-surface-variant">
        {t('aiQuery.unavailable')}
      </p>
    )
  }

  return (
    <section aria-labelledby="ai-query-title" className="flex flex-col gap-3 rounded-md border-2 border-outline bg-surface-bright p-4 shadow-[2px_2px_0px_#1a1a1a]">
      <h2 id="ai-query-title" className="flex items-center gap-2 font-headline text-sm font-bold uppercase tracking-wide text-on-surface">
        {t('aiQuery.title')}
        <span className={BADGE_CLASS}>{t('aiQuery.aiBadge')}</span>
      </h2>

      <form onSubmit={handleSubmit} className="flex flex-col gap-2" aria-busy={busy}>
        <label htmlFor="ai-query-input" className="sr-only">
          {t('aiQuery.label')}
        </label>
        <div className="flex flex-col gap-2 sm:flex-row">
          <input
            ref={inputRef}
            id="ai-query-input"
            type="text"
            maxLength={MAX_LENGTH}
            value={query}
            disabled={busy}
            onChange={(event) => setQuery(event.target.value)}
            placeholder={t('aiQuery.placeholder')}
            aria-describedby="ai-query-hint"
            autoComplete="off"
            className={INPUT_CLASS}
          />
          <button type="submit" disabled={busy} className={PRIMARY_BUTTON_CLASS}>
            {busy ? t('aiQuery.submitting') : t('aiQuery.submit')}
          </button>
        </div>
        <p id="ai-query-hint" className="text-xs text-on-surface-variant">
          {t('aiQuery.hint')}
        </p>
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant">
            {t('aiQuery.examplesLabel')}
          </span>
          {EXAMPLE_KEYS.map((key) => (
            <button
              key={key}
              type="button"
              disabled={busy}
              title={t(`aiQuery.examples.${key}`)}
              onClick={() => pickExample(t(`aiQuery.examples.${key}`))}
              className="max-w-full truncate rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 text-left font-mono text-[11px] text-on-surface hover:bg-surface-container-high focus:outline-2 focus:outline-offset-2 focus:outline-outline disabled:opacity-60"
            >
              {t(`aiQuery.examples.${key}`)}
            </button>
          ))}
        </div>
      </form>

      {error !== null ? (
        <p role="alert" className="rounded-[4px] border-2 border-secondary bg-secondary-container px-3 py-2 font-mono text-xs text-on-secondary-container">
          {error}
        </p>
      ) : null}

      {applied !== null ? (
        <div role="status" className="flex flex-col gap-2 rounded-[4px] border-2 border-outline bg-surface-container px-3 py-2">
          <div className="flex flex-wrap items-start justify-between gap-2">
            <div className="min-w-0">
              <p className="font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant">
                {t('aiQuery.appliedTitle')}
              </p>
              <p className="mt-1 text-sm break-words text-on-surface">{applied.explanation}</p>
            </div>
            <div className="flex gap-2">
              <button type="button" onClick={undo} className={SECONDARY_BUTTON_CLASS}>
                {t('aiQuery.undo')}
              </button>
              <button type="button" onClick={() => setApplied(null)} className={SECONDARY_BUTTON_CLASS}>
                {t('aiQuery.dismiss')}
              </button>
            </div>
          </div>
          {applied.unsupported.length > 0 ? (
            <div>
              <p className="font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant">
                {t('aiQuery.unsupportedTitle')}
              </p>
              <ul className="mt-1 list-disc pl-5 text-xs break-words text-on-surface">
                {applied.unsupported.map((part, index) => (
                  <li key={`${index}-${part}`}>{part}</li>
                ))}
              </ul>
            </div>
          ) : null}
          <p className="text-xs text-on-surface-variant">{t('aiQuery.appliedHint')}</p>
        </div>
      ) : null}
    </section>
  )
}
