/**
 * Same-origin API client for the Laravel backend.
 *
 * Auth is first-party Sanctum session-cookie auth (never bearer tokens), so
 * every call sends cookies (`credentials: 'include'`) and mutating calls first
 * fetch `/sanctum/csrf-cookie` and echo it back in `X-XSRF-TOKEN`. In dev the
 * Vite server proxies `/api` and `/sanctum` to Laravel, keeping the browser
 * same-origin.
 *
 * Every request carries the SPA's selected locale as `Accept-Language`, so
 * Laravel localizes validation/auth/application messages. Status codes,
 * response shapes and stable identifiers (Signal types, errors keys) never
 * depend on it.
 */

import { getActiveLocale } from '../i18n/locales.ts'
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

export type IngestionRunItemStatus = 'processing' | 'success' | 'failed'

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

/**
 * User-facing text for a failed call: the first server validation message,
 * else the server message (both already localized via `Accept-Language`),
 * else `networkFallback` for a transport failure.
 */
export function apiErrorMessage(error: unknown, networkFallback: string): string {
  if (error instanceof ApiError) {
    const firstValidation = Object.values(error.errors)[0]?.[0]
    return firstValidation ?? error.message
  }

  return networkFallback
}

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`))
  return match ? decodeURIComponent(match[1]) : null
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  headers.set('Accept-Language', getActiveLocale())
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
    headers: { Accept: 'application/json', 'Accept-Language': getActiveLocale() },
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

  /** Trigger a run; a database queue worker performs the EOD requests. */
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

/** Coarse device label; `null` parts mean "unknown" (translated by the SPA). */
export type AdminDevice = { browser: string | null; os: string | null }

export type AdminPage<T> = {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export type AdminUsersSummary = {
  users_total: number
  admins_total: number
  users_new_7d: number
  users_new_30d: number
  users_active_24h: number
  sessions_active: number
  failed_logins_24h: number
  activity_retention_days: number
}

export type AdminUserRow = {
  id: number
  name: string
  email: string
  role: UserRole
  created_at: string | null
  last_login_at: string | null
  last_activity_at: string | null
  active_sessions_count: number
  saved_screeners_count: number
  watchlist_count: number
}

/** An active session. `ref` is opaque: the session id never leaves the server. */
export type AdminSession = {
  ref: string
  user_id: number | null
  ip_address: string | null
  device: AdminDevice
  last_activity_at: string
  is_current: boolean
}

export type AdminSessionRow = AdminSession & {
  user: { id: number; name: string; email: string; role: UserRole } | null
}

export type AdminActivityEventType = 'login' | 'failed' | 'logout' | 'session_revoked'

export type AdminActivityEvent = {
  id: number
  event: AdminActivityEventType
  email: string | null
  ip_address: string | null
  device: AdminDevice
  sessions_revoked: number | null
  actor: { id: number; name: string } | null
  created_at: string | null
  user?: { id: number; name: string; email: string } | null
}

export type AdminUserDetail = {
  user: AdminUserRow
  sessions: AdminSession[]
  activity: AdminActivityEvent[]
}

function adminQuery(params: Record<string, string | number | null | undefined>): string {
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value !== null && value !== undefined && value !== '') {
      query.set(key, String(value))
    }
  }
  const text = query.toString()
  return text === '' ? '' : `?${text}`
}

/**
 * Admin overview of users, sessions and sign-in activity
 * (admin-users-sessions). Authorized server-side by `auth:sanctum` + `admin`
 * (401 guest, 403 non-admin); the SPA guard is only a UI affordance.
 */
export const adminUsersApi = {
  async summary(): Promise<AdminUsersSummary> {
    const payload = await request<{ summary: AdminUsersSummary }>('/api/admin/users/summary')
    return payload.summary
  },

  list(params: { search?: string; page?: number; perPage?: number } = {}): Promise<AdminPage<AdminUserRow>> {
    return request<AdminPage<AdminUserRow>>(
      `/api/admin/users${adminQuery({ search: params.search, page: params.page, per_page: params.perPage })}`,
    )
  },

  detail(id: number): Promise<AdminUserDetail> {
    return request<AdminUserDetail>(`/api/admin/users/${id}`)
  },

  /** End every session of a user; the caller's current session is kept. */
  async revokeUserSessions(id: number): Promise<number> {
    await ensureCsrfCookie()
    const payload = await request<{ revoked: number }>(`/api/admin/users/${id}/sessions`, { method: 'DELETE' })
    return payload.revoked
  },

  sessions(params: { page?: number } = {}): Promise<AdminPage<AdminSessionRow>> {
    return request<AdminPage<AdminSessionRow>>(`/api/admin/sessions${adminQuery({ page: params.page })}`)
  },

  /** End one session by its opaque reference (the current one is refused with 422). */
  async revokeSession(ref: string): Promise<number> {
    await ensureCsrfCookie()
    const payload = await request<{ revoked: number }>(`/api/admin/sessions/${encodeURIComponent(ref)}`, {
      method: 'DELETE',
    })
    return payload.revoked
  },

  activity(
    params: { event?: AdminActivityEventType | null; userId?: number | null; page?: number } = {},
  ): Promise<AdminPage<AdminActivityEvent>> {
    return request<AdminPage<AdminActivityEvent>>(
      `/api/admin/activity${adminQuery({ event: params.event, user_id: params.userId, page: params.page })}`,
    )
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

/** The four chartist pattern types the engine detects (chart-patterns-detect). */
export type ScreenerPatternType = 'double_top' | 'double_bottom' | 'cup_with_handle' | 'bull_flag'

export type PatternStatus = 'forming' | 'confirmed'

/** `any` matches both statuses; it only narrows a non-empty pattern filter. */
export type ScreenerPatternStatus = 'any' | PatternStatus

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
  /** OR-combined like `signals`; `patternStatus` narrows them. */
  patterns: ScreenerPatternType[]
  patternStatus: ScreenerPatternStatus
  sort: ScreenerSort
}

/** A pattern summary on a Candidate (the chart uses the instrument detail). */
export type CandidatePattern = {
  type: string
  status: PatternStatus
  breakout_level: number
  end_date: string
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
  patterns: CandidatePattern[]
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
  if (filters.patterns.length > 0) {
    params.set('pattern', filters.patterns.join(','))
    if (filters.patternStatus !== 'any') {
      params.set('pattern_status', filters.patternStatus)
    }
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
 * The nine-key canonical definition stored with a Saved Screener. It uses the
 * API's own param names (snake_case) and includes `sort`, so applying a Screener
 * restores the exact filters and ranking the user saved. The pattern keys are
 * optional on read: Screeners saved before them come back with API defaults.
 */
export type SavedScreenerFilters = {
  signal: ScreenerSignalType[]
  rsi_min: number | null
  rsi_max: number | null
  min_rvol: number | null
  price_above_sma200: boolean
  ma_cross: ScreenerMaCross | null
  pattern?: ScreenerPatternType[]
  pattern_status?: ScreenerPatternStatus
  sort: ScreenerSort
}

/** One user-owned Saved Screener returned by the API. */
export type SavedScreener = {
  id: number
  name: string
  filters: SavedScreenerFilters
}

/**
 * Authenticated Saved Screeners client. Every call is scoped server-side to the
 * session user (`auth:sanctum`); the SPA never sends a `user_id` and a guest
 * gets a `401`. Mutations fetch the CSRF cookie first, like every other write.
 */
export const savedScreenersApi = {
  async list(): Promise<SavedScreener[]> {
    const payload = await request<{ screeners: SavedScreener[] }>('/api/screeners')
    return payload.screeners
  },

  /** Save the current filter definition under a name (a duplicate name is a `422`). */
  async create(name: string, filters: SavedScreenerFilters): Promise<SavedScreener> {
    await ensureCsrfCookie()
    const payload = await request<{ screener: SavedScreener }>('/api/screeners', {
      method: 'POST',
      body: JSON.stringify({ name, filters }),
    })
    return payload.screener
  },

  /** Delete one of the caller's Screeners (`204`); another user's id is a `404`. */
  async remove(id: number): Promise<void> {
    await ensureCsrfCookie()
    await request<void>(`/api/screeners/${id}`, { method: 'DELETE' })
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

/** One key point of a pattern; `role` names it (left_low, rim_right…). */
export type InstrumentPatternPoint = { date: string; price: number; role: string }

/** An active chartist pattern; `breakout_level` is its only price line. */
export type InstrumentPattern = {
  type: string
  status: PatternStatus
  start_date: string
  end_date: string
  breakout_level: number
  points: InstrumentPatternPoint[]
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
  /** Absent from responses of older deployments; treat as empty. */
  patterns?: InstrumentPattern[]
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

/** Owner identity shown on the legal pages (every field configured by the owner). */
export type LegalOwner = {
  name: string
  tax_id: string
  address: string
  email: string
  registry: string | null
}

/**
 * `GET /api/legal` (public). `owner` is null and `published` false until the
 * owner's identity is configured (`docs/specs/legal-compliance-eu.md`).
 */
export type LegalInfo = {
  published: boolean
  owner: LegalOwner | null
  updated_at: string
  retention: {
    sign_in_activity_days: number
    session_minutes: number
    backup_days: number
    server_log_days: number
    notification_days: number
  }
}

export const legalApi = {
  async info(): Promise<LegalInfo> {
    return request<LegalInfo>('/api/legal')
  },
}
