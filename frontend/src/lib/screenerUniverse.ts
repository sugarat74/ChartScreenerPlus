/**
 * Screener universe state (docs/specs/multi-market-universes.md, D5/D8/D10).
 * Pure helpers, like `screenerFilters.ts`: the URL owns `universe=<slug>`
 * only when it is not the default universe, the list of selectable universes
 * comes from `GET /api/universes`, and the server stays the authority on what
 * a caller may see.
 */

import type { ScreenerUniverse } from './api.ts'
import { resetScreenerPage } from './screenerPagination.ts'

export const UNIVERSE_PARAM = 'universe'

/** The sector context filters read US sector ETFs, so only `US` universes have them (D10). */
const MARKET_CONTEXT_PARAMS = ['sector_trend', 'sector_rs_rank_max'] as const

/** A well-formed slug from the URL, else `null`. Existence is checked against the list. */
export function parseUniverseParam(params: URLSearchParams): string | null {
  const raw = params.get(UNIVERSE_PARAM)
  return raw !== null && /^[a-z0-9][a-z0-9-]{0,39}$/.test(raw) ? raw : null
}

export function defaultUniverse(universes: readonly ScreenerUniverse[]): ScreenerUniverse | null {
  return universes.find((universe) => universe.is_default) ?? universes[0] ?? null
}

/**
 * The universe a requested slug resolves to: the listed one, otherwise (no
 * slug, or an unknown / hidden slug) the default one. `null` only while the
 * list is empty.
 */
export function resolveUniverse(
  slug: string | null,
  universes: readonly ScreenerUniverse[],
): ScreenerUniverse | null {
  return universes.find((universe) => universe.slug === slug) ?? defaultUniverse(universes)
}

/** Does the Listing Market of this universe have the US-only sector context? */
export function hasSectorContext(universe: ScreenerUniverse | null): boolean {
  return universe === null || universe.market === 'US'
}

/**
 * The URL after choosing `target`: `universe` is omitted for the default
 * universe, the page restarts at 1, the Close bounds are cleared when the
 * currency changes and the sector context keys when the target has none. Every
 * other criterion and the sort are kept.
 */
export function switchUniverse(
  prev: URLSearchParams,
  target: ScreenerUniverse,
  current: ScreenerUniverse | null,
): URLSearchParams {
  const next = resetScreenerPage(prev)

  if (target.is_default) {
    next.delete(UNIVERSE_PARAM)
  } else {
    next.set(UNIVERSE_PARAM, target.slug)
  }
  if (current !== null && current.currency !== target.currency) {
    next.delete('close_min')
    next.delete('close_max')
  }
  if (!hasSectorContext(target)) {
    for (const key of MARKET_CONTEXT_PARAMS) {
      next.delete(key)
    }
  }

  return next
}

/**
 * The URL with `universe` set to an already-resolved slug (or removed), for
 * applying a Saved Screener; `null` leaves the default universe.
 */
export function withUniverseParam(prev: URLSearchParams, slug: string | null): URLSearchParams {
  const next = new URLSearchParams(prev)
  if (slug === null) {
    next.delete(UNIVERSE_PARAM)
  } else {
    next.set(UNIVERSE_PARAM, slug)
  }
  return next
}

/**
 * The URL without a `universe` parameter that cannot be honoured, or `null`
 * when it is already canonical. An unknown or hidden slug, the default slug and
 * a list with a single universe all drop the parameter, silently (no error).
 */
export function canonicalUniverseParams(
  params: URLSearchParams,
  universes: readonly ScreenerUniverse[],
): URLSearchParams | null {
  const raw = params.get(UNIVERSE_PARAM)
  if (raw === null) {
    return null
  }
  const listed = universes.find((universe) => universe.slug === raw)
  if (universes.length > 1 && listed !== undefined && !listed.is_default) {
    return null
  }
  const next = new URLSearchParams(params)
  next.delete(UNIVERSE_PARAM)
  return resetScreenerPage(next)
}
