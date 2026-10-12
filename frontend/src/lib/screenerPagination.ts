/**
 * Candidate list pagination (screener-pagination). Pure helpers, like
 * `screenerFilters.ts`: the URL owns `page` (1-based) and `limit` (page size)
 * next to the filter keys, and the API receives `limit` + `offset`.
 *
 * Both keys are lenient: an unknown page size falls back to the default and a
 * bad page to 1, so a hand-edited URL never breaks the list. Defaults are
 * omitted from the URL to keep it clean.
 */

/** Page sizes offered to the user; the API clamps `limit` to 1..500. */
export const SCREENER_PAGE_SIZES: readonly number[] = [25, 50, 100, 200]

/** The API default; also what an absent/unknown `limit` resolves to. */
export const DEFAULT_SCREENER_PAGE_SIZE = 50

export type ScreenerPagination = {
  /** 1-based page number. */
  page: number
  /** Candidates per page (one of `SCREENER_PAGE_SIZES`). */
  limit: number
}

export function parseScreenerPagination(params: URLSearchParams): ScreenerPagination {
  const limit = Number(params.get('limit'))
  const page = Number(params.get('page'))

  return {
    page: Number.isInteger(page) && page >= 1 ? page : 1,
    limit: SCREENER_PAGE_SIZES.includes(limit) ? limit : DEFAULT_SCREENER_PAGE_SIZE,
  }
}

/**
 * Patch `page`/`limit` and keep every other param. Changing the page size
 * always restarts at page 1 (the old page number would point elsewhere).
 */
export function patchScreenerPagination(
  prev: URLSearchParams,
  patch: Partial<ScreenerPagination>,
): URLSearchParams {
  const next = new URLSearchParams(prev)
  const current = parseScreenerPagination(prev)
  const limit = patch.limit ?? current.limit
  const page = patch.limit !== undefined && patch.limit !== current.limit ? 1 : (patch.page ?? current.page)

  if (limit === DEFAULT_SCREENER_PAGE_SIZE) {
    next.delete('limit')
  } else {
    next.set('limit', String(limit))
  }

  if (page <= 1) {
    next.delete('page')
  } else {
    next.set('page', String(page))
  }

  return next
}

/** Back to the first page (after any filter or ranking change). */
export function resetScreenerPage(prev: URLSearchParams): URLSearchParams {
  const next = new URLSearchParams(prev)
  next.delete('page')
  return next
}

/** Rows to skip for the API. */
export function screenerOffset(pagination: ScreenerPagination): number {
  return (pagination.page - 1) * pagination.limit
}

/** Number of pages for `total` candidates (at least 1). */
export function screenerPageCount(total: number, limit: number): number {
  return Math.max(1, Math.ceil(total / limit))
}

/** 1-based position of the first and last candidate shown on this page. */
export function screenerPageRange(offset: number, returned: number): { from: number; to: number } {
  return returned === 0 ? { from: 0, to: 0 } : { from: offset + 1, to: offset + returned }
}
