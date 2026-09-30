/**
 * Same-origin API client for the Laravel backend.
 *
 * Auth is first-party Sanctum session-cookie auth (never bearer tokens), so
 * every call sends cookies (`credentials: 'include'`) and mutating calls first
 * fetch `/sanctum/csrf-cookie` and echo it back in `X-XSRF-TOKEN`. In dev the
 * Vite server proxies `/api` and `/sanctum` to Laravel, keeping the browser
 * same-origin.
 */

import { CHART_BAR_LIMIT } from './chartData.ts'

export type UserRole = 'user' | 'admin'

export type AuthUser = {
  id: number
  name: string
  email: string
  role: UserRole
}

export type RegisterPayload = {
  name: string
  email: string
  password: string
  password_confirmation: string
}

export type ValidationErrors = Record<string, string[]>

/** Lifecycle of one ingestion run (`docs/domain-model.md`). */
export type IngestionRunStatus = 'queued' | 'running' | 'completed' | 'failed' | 'partial'

export type IngestionRunItemStatus = 'success' | 'failed'

export type IngestionRunSummary = {
  id: number
  status: IngestionRunStatus
  universe: { id: number; slug: string; name: string } | null
  started_at: string | null
  finished_at: string | null
  total: number
  succeeded: number
  failed: number
}

export type IngestionRunItem = {
  id: number
  ticker: string | null
  status: IngestionRunItemStatus
  bars_stored: number
  message: string | null
}

export type IngestionRunDetail = IngestionRunSummary & {
  items: IngestionRunItem[]
}

/**
 * Terminal statuses: a run that has finished and can be retried. `queued` and
 * `running` are transient, so the panel keeps polling them.
 */
export function isTerminalRunStatus(status: IngestionRunStatus): boolean {
  return status === 'completed' || status === 'failed' || status === 'partial'
}

export class ApiError extends Error {
  readonly status: number
  readonly errors: ValidationErrors

  constructor(status: number, message: string, errors: ValidationErrors = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }
}

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`))
  return match ? decodeURIComponent(match[1]) : null
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  if (init.body !== undefined) {
    headers.set('Content-Type', 'application/json')
  }

  const token = readCookie('XSRF-TOKEN')
  if (token) {
    headers.set('X-XSRF-TOKEN', token)
  }

  const response = await fetch(path, { credentials: 'include', ...init, headers })

  if (response.status === 204) {
    return undefined as T
  }

  const contentType = response.headers.get('content-type') ?? ''
  const payload = contentType.includes('application/json')
    ? ((await response.json()) as Record<string, unknown>)
    : null

  if (!response.ok) {
    const message =
      typeof payload?.message === 'string' ? payload.message : `Error ${response.status}`
    const errors = (payload?.errors ?? {}) as ValidationErrors
    throw new ApiError(response.status, message, errors)
  }

  return payload as T
}

async function ensureCsrfCookie(): Promise<void> {
  await fetch('/sanctum/csrf-cookie', {
    credentials: 'include',
    headers: { Accept: 'application/json' },
  })
}

export const authApi = {
  /** Returns the authenticated user, or `null` when there is no session. */
  async currentUser(): Promise<AuthUser | null> {
    try {
      const payload = await request<{ user: AuthUser }>('/api/user')
      return payload.user
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        return null
      }
      throw error
    }
  },

  async login(email: string, password: string): Promise<AuthUser> {
    await ensureCsrfCookie()
    const payload = await request<{ user: AuthUser }>('/api/login', {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    })
    return payload.user
  },

  async register(data: RegisterPayload): Promise<AuthUser> {
    await ensureCsrfCookie()
    const payload = await request<{ user: AuthUser }>('/api/register', {
      method: 'POST',
      body: JSON.stringify(data),
    })
    return payload.user
  },

  async logout(): Promise<void> {
    await ensureCsrfCookie()
    await request<void>('/api/logout', { method: 'POST' })
  },
}

/**
 * Admin ingestion panel data layer. Every call is authorized server-side by
 * the `auth:sanctum` + `admin` middleware; the API answers 401 for a guest and
 * 403 for a non-admin, so the SPA guard is only a UI affordance.
 */
export const adminIngestionApi = {
  async listRuns(limit = 20): Promise<IngestionRunSummary[]> {
    const payload = await request<{ runs: IngestionRunSummary[] }>(
      `/api/admin/ingestion/runs?limit=${limit}`,
    )
    return payload.runs
  },

  /** Trigger a run; with the default `sync` queue it completes inline. */
  async triggerRun(universe?: string): Promise<IngestionRunSummary> {
    await ensureCsrfCookie()
    const payload = await request<{ run: IngestionRunSummary }>('/api/admin/ingestion/runs', {
      method: 'POST',
      body: JSON.stringify(universe ? { universe } : {}),
    })
    return payload.run
  },

  async getRun(id: number): Promise<IngestionRunDetail> {
    const payload = await request<{ run: IngestionRunDetail }>(
      `/api/admin/ingestion/runs/${id}`,
    )
    return payload.run
  },

  /** Re-run only the instruments that failed in the given run. */
  async retryRun(id: number): Promise<IngestionRunSummary> {
    await ensureCsrfCookie()
    const payload = await request<{ run: IngestionRunSummary }>(
      `/api/admin/ingestion/runs/${id}/retry`,
      { method: 'POST' },
    )
    return payload.run
  },
}

/** The 9 fixed signal type strings the engine emits and the screener filters on. */
export type ScreenerSignalType =
  | 'golden_cross'
  | 'death_cross'
  | 'ma_alignment_bullish'
  | 'ma_alignment_bearish'
  | 'pivot_breakout_rvol'
  | 'rsi_overbought'
  | 'rsi_oversold'
  | 'macd_bullish_cross'
  | 'macd_bearish_cross'

export type ScreenerMaCross = 'bullish' | 'bearish'

/** The six ranking orders the frozen screener API supports. */
export type ScreenerSort =
  | 'rvol_desc'
  | 'signal_count_desc'
  | 'change_desc'
  | 'change_asc'
  | 'rsi_desc'
  | 'rsi_asc'

/**
 * The subset of the prototype `FilterState` that the frozen screener API can
 * actually evaluate, so the SPA never invents an unsupported criterion. Every
 * criterion is AND-combined except `signals`, which the API ORs. `sort` is a
 * ranking preference, not a criterion: it is always sent but never counted as
 * an active filter.
 */
export type ScreenerFilters = {
  signals: ScreenerSignalType[]
  rsiMin: number | null
  rsiMax: number | null
  minRvol: number | null
  priceAboveSma200: boolean
  maCross: ScreenerMaCross | null
  sort: ScreenerSort
}

export type ScreenerCandidate = {
  ticker: string
  company: string
  sector: string
  exchange: string
  active: boolean
  date: string
  close: number | null
  change_percent: number | null
  rvol: number | null
  rsi14: number | null
  signals: string[]
}

export type ScreenerResponse = {
  universe: { slug: string; name: string }
  sort: string
  candidates: ScreenerCandidate[]
  meta: { limit: number; returned: number; total: number }
}

/**
 * Serialize the filter params plus `sort`, using the API's own param names so a
 * copied URL is directly usable as an API call. `sort` is always sent (each
 * request is self-describing and the ranking stays server-side); `limit` and
 * `universe` are never sent — the API defaults apply.
 */
function screenerQueryString(filters: ScreenerFilters): string {
  const params = new URLSearchParams()
  if (filters.signals.length > 0) {
    params.set('signal', filters.signals.join(','))
  }
  if (filters.rsiMin !== null) {
    params.set('rsi_min', String(filters.rsiMin))
  }
  if (filters.rsiMax !== null) {
    params.set('rsi_max', String(filters.rsiMax))
  }
  if (filters.minRvol !== null) {
    params.set('min_rvol', String(filters.minRvol))
  }
  if (filters.priceAboveSma200) {
    params.set('price_above_sma200', '1')
  }
  if (filters.maCross !== null) {
    params.set('ma_cross', filters.maCross)
  }
  params.set('sort', filters.sort)
  return params.toString()
}

/**
 * Anonymous screener client. `GET /api/screener` is public (`api` +
 * `throttle:60,1` only) and carries no CSRF/session requirement
 * (`docs/user-and-access-model.md`). Every request carries `sort`; `signal`
 * aborts an in-flight request.
 */
export const screenerApi = {
  async search(filters: ScreenerFilters, signal?: AbortSignal): Promise<ScreenerResponse> {
    const query = screenerQueryString(filters)
    return request<ScreenerResponse>(`/api/screener${query ? `?${query}` : ''}`, { signal })
  },
}

/**
 * Frozen instrument-detail contract (`GET /api/instruments/{ticker}`).
 * `snapshot` carries only the latest as-of values (13 indicator keys, each
 * `number | null`) — there is no per-bar indicator series; a `null` means
 * history was insufficient at that date, never zero.
 */
export type InstrumentBar = {
  date: string
  open: number
  high: number
  low: number
  close: number
  volume: number
}

export type InstrumentSnapshot = {
  date: string
  sma20: number | null
  sma50: number | null
  sma200: number | null
  ema21: number | null
  ema55: number | null
  rsi14: number | null
  adx: number | null
  macd: number | null
  macd_signal: number | null
  macd_hist: number | null
  bb_upper: number | null
  bb_middle: number | null
  bb_lower: number | null
  rvol: number | null
}

export type InstrumentSignal = {
  type: string
  date: string
  /** Signals-detect guarantees numeric metadata, but a guard is still applied. */
  metadata: Record<string, number> | null
}

export type InstrumentDetailResponse = {
  instrument: {
    ticker: string
    company: string
    sector: string
    exchange: string
    active: boolean
  }
  bars: InstrumentBar[]
  snapshot: InstrumentSnapshot | null
  signals: InstrumentSignal[]
  meta: { limit: number; bar_count: number; latest_bar_date: string | null }
}

/**
 * Anonymous instrument-detail client. `GET /api/instruments/{ticker}` is
 * public (`api` middleware only) and needs no CSRF/session
 * (`docs/user-and-access-model.md`). The ticker is path-encoded and the bar
 * window defaults to `CHART_BAR_LIMIT`; `signal` aborts an in-flight request.
 */
export const instrumentApi = {
  async detail(
    ticker: string,
    options: { limit?: number; signal?: AbortSignal } = {},
  ): Promise<InstrumentDetailResponse> {
    const limit = options.limit ?? CHART_BAR_LIMIT
    const path = `/api/instruments/${encodeURIComponent(ticker)}?limit=${limit}`
    return request<InstrumentDetailResponse>(path, { signal: options.signal })
  },
}

/** One owned watchlist entry (same field set as the instrument-detail metadata). */
export type WatchlistEntry = {
  ticker: string
  company: string
  sector: string
  exchange: string
  active: boolean
}

/**
 * Authenticated watchlist client. Every call is scoped server-side to the
 * session user (`auth:sanctum`); the SPA never sends a `user_id` and a guest
 * gets a `401`. Mutations fetch the CSRF cookie first, like every other write.
 */
export const watchlistApi = {
  async list(): Promise<WatchlistEntry[]> {
    const payload = await request<{ items: WatchlistEntry[] }>('/api/watchlist')
    return payload.items
  },

  /** Follow a ticker (idempotent server-side: fresh `201`, already-followed `200`). */
  async add(ticker: string): Promise<WatchlistEntry> {
    await ensureCsrfCookie()
    const payload = await request<{ item: WatchlistEntry }>('/api/watchlist', {
      method: 'POST',
      body: JSON.stringify({ ticker }),
    })
    return payload.item
  },

  /** Unfollow a ticker (`204`); an absent/foreign ticker is a `404`. */
  async remove(ticker: string): Promise<void> {
    await ensureCsrfCookie()
    await request<void>(`/api/watchlist/${encodeURIComponent(ticker)}`, { method: 'DELETE' })
  },
}
