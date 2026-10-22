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
  /** Stable reason `code` from the JSON body, when the endpoint sends one. */
  readonly code: string | null

  constructor(status: number, message: string, errors: ValidationErrors = {}, code: string | null = null) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
    this.code = code
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
    throw toApiError(response.status, payload)
  }

  return payload as T
}

function toApiError(status: number, payload: Record<string, unknown> | null): ApiError {
  const message = typeof payload?.message === 'string' ? payload.message : `Error ${status}`
  const errors = (payload?.errors ?? {}) as ValidationErrors
  const code = typeof payload?.code === 'string' ? payload.code : null
  return new ApiError(status, message, errors, code)
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

export type ChangePasswordPayload = {
  current_password: string
  password: string
  password_confirmation: string
}

/** A downloaded copy of the account's data (`GET /api/account/export`). */
export type AccountExportFile = {
  blob: Blob
  filename: string
}

/** Default file name when the response carries no usable `Content-Disposition`. */
export const ACCOUNT_EXPORT_FALLBACK_FILENAME = 'chartiko-account.json'

function attachmentFilename(header: string | null): string {
  const match = header?.match(/filename="?([^";]+)"?/i)
  return match ? match[1] : ACCOUNT_EXPORT_FALLBACK_FILENAME
}

/**
 * Account self-service (account-self-service). Every endpoint acts on the
 * session user only; the API never takes a user id.
 */
export const accountApi = {
  async update(name: string): Promise<AuthUser> {
    await ensureCsrfCookie()
    const payload = await request<{ user: AuthUser }>('/api/account', {
      method: 'PATCH',
      body: JSON.stringify({ name }),
    })
    return payload.user
  },

  /** Ends every other session of the account; the current one stays signed in. */
  async changePassword(data: ChangePasswordPayload): Promise<void> {
    await ensureCsrfCookie()
    await request<void>('/api/account/password', { method: 'PUT', body: JSON.stringify(data) })
  },

  /** The JSON copy of the account's data, as a file to save. */
  async export(): Promise<AccountExportFile> {
    const response = await fetch('/api/account/export', {
      credentials: 'include',
      headers: { Accept: 'application/json', 'Accept-Language': getActiveLocale() },
    })
    if (!response.ok) {
      const contentType = response.headers.get('content-type') ?? ''
      const payload = contentType.includes('application/json')
        ? ((await response.json()) as Record<string, unknown>)
        : null
      throw toApiError(response.status, payload)
    }
    return {
      blob: await response.blob(),
      filename: attachmentFilename(response.headers.get('content-disposition')),
    }
  },

  /** Permanently deletes the account; the session ends with it. */
  async remove(password: string, confirmation: string): Promise<void> {
    await ensureCsrfCookie()
    await request<void>('/api/account', {
      method: 'DELETE',
      body: JSON.stringify({ password, confirmation }),
    })
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

export type AdminActivityEventType = 'login' | 'failed' | 'logout' | 'session_revoked' | 'account_deleted'

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
export type ScreenerPatternType =
  | 'double_top'
  | 'double_bottom'
  | 'cup_with_handle'
  | 'bull_flag'
  | 'triple_top'
  | 'triple_bottom'
  | 'inverse_cup_with_handle'
  | 'bear_flag'
  | 'bull_pennant'
  | 'bear_pennant'

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
  meta: { limit: number; offset: number; returned: number; total: number }
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
  async search(
    filters: ScreenerFilters,
    signal?: AbortSignal,
    page: { limit?: number; offset?: number } = {},
  ): Promise<ScreenerResponse> {
    const params = new URLSearchParams(screenerQueryString(filters))
    if (page.limit !== undefined) {
      params.set('limit', String(page.limit))
    }
    if (page.offset !== undefined && page.offset > 0) {
      params.set('offset', String(page.offset))
    }
    const query = params.toString()
    return request<ScreenerResponse>(`/api/screener${query ? `?${query}` : ''}`, { signal })
  },

  /**
   * Natural-language Screener (Registered Users only; a Visitor gets `401`).
   * The answer uses the API param names, like a Saved Screener definition.
   * Errors carry a stable `code` (`provider_not_configured`,
   * `user_daily_limit`, ...) and a localized message. The text is never
   * stored by the server.
   */
  async interpret(query: string, locale: string): Promise<ScreenerInterpretation> {
    await ensureCsrfCookie()
    return request<ScreenerInterpretation>('/api/screener/interpret', {
      method: 'POST',
      body: JSON.stringify({ query, locale }),
    })
  },
}

/** `POST /api/screener/interpret` result: filters plus plain-text notes. */
export type ScreenerInterpretation = {
  filters: SavedScreenerFilters
  unsupported: string[]
  explanation: string
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
    sector: string | null
    exchange: string | null
    active: boolean
    /**
     * `etf` for the Market Context ETFs (SPY and the sector SPDRs), which have
     * no Watchlist, forecast or Company Card. Absent from older deployments:
     * treat as `stock`.
     */
    kind?: 'stock' | 'etf'
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

export type Forecast = {
  ticker: string
  as_of_date: string
  target_date: string
  horizon: number
  reference_close: number
  probabilities: { up: number; down: number; flat: number }
  signal: 'up' | 'down' | null
  minimum_probability: number
  model_version: string
  experimental: true
}

/** `fundamentals-v1` metric keys in display order. Units are fixed by key. */
export const FUNDAMENTAL_METRICS = [
  'pe_ttm',
  'eps_diluted_ttm',
  'market_cap_millions',
  'revenue_ttm_millions',
  'revenue_growth_yoy_percent',
  'ebitda_millions',
  'ebitda_margin_percent',
  'net_debt_to_ebitda',
  'roic_percent',
  'dividend_yield_percent',
] as const

export type FundamentalMetricKey = (typeof FUNDAMENTAL_METRICS)[number]

export type FundamentalReason =
  | 'negative_earnings'
  | 'negative_ebitda'
  | 'financial_sector'
  | 'missing_data'
  | 'not_reported'
  | 'no_price'
  | 'not_meaningful'

export type FundamentalPeriod = { kind: 'ttm' | 'fy' | 'instant' | 'price'; end: string; form: string | null }

export type FundamentalMetric = { value: number | null; period: FundamentalPeriod | null; reason: FundamentalReason | null }

/**
 * Company Fundamentals computed by Chartiko from SEC 10-K/10-Q filings
 * (EDGAR) and our latest close. Verified data, separate from the AI outlook.
 */
export type Fundamentals = {
  schema_version: 'fundamentals-v1'
  source: 'sec_edgar'
  currency: string
  is_financial: boolean
  period_end: string | null
  filing: { form: string; filed_at: string | null; accession: string | null } | null
  pending_filing: { form: string | null; filed_at: string | null } | null
  price: { close: number; date: string } | null
  /** ISO-8601 UTC instant of the last SEC fetch. */
  fetched_at: string | null
  metrics: Record<FundamentalMetricKey, FundamentalMetric>
}

/** Validated `company-card-v2` content (AI-generated, unverified; no figures). */
export type CompanyCardContent = {
  company: { name: string; ticker: string; sector: string | null; industry: string | null; description: string | null }
  outlook: {
    horizon_months: 3
    stance: 'bullish' | 'neutral' | 'bearish'
    summary: string
    macro_context: string
    sector_context: string
    drivers: string[]
    risks: string[]
    confidence: 'low' | 'medium' | 'high'
  }
  limitations: string
  sources: string[]
}

export type CompanyCard = {
  ticker: string
  locale: string
  schema_version: string
  model: string
  /** ISO-8601 UTC instant. */
  generated_at: string
  /** False once older than the freshness window or made by another model/prompt. */
  fresh: boolean
  context_mode: 'identity' | 'identity+technical'
  context_as_of_date: string | null
  /** Period end of the SEC figures the outlook was grounded on, if any. */
  fundamentals_period_end: string | null
  content: CompanyCardContent
}

export type CompanyCardResponse = { card: CompanyCard | null; available: boolean; fundamentals: Fundamentals | null }

/**
 * AI Company Card (Registered Users only). `get` reads the stored card for the
 * active language (plus whether generation is switched on and the SEC
 * fundamentals) and never triggers generation; `generate` reuses a fresh
 * card or asks the provider once. Errors carry a localized message; the
 * stable `code` stays in the JSON body.
 */
export const companyCardApi = {
  async get(ticker: string): Promise<CompanyCardResponse> {
    return request<CompanyCardResponse>(`/api/instruments/${encodeURIComponent(ticker)}/company-card`)
  },
  async generate(ticker: string): Promise<{ card: CompanyCard; reused: boolean; fundamentals: Fundamentals | null }> {
    await ensureCsrfCookie()
    return request<{ card: CompanyCard; reused: boolean; fundamentals: Fundamentals | null }>(`/api/instruments/${encodeURIComponent(ticker)}/company-card`, {
      method: 'POST',
    })
  },
}

export type ForecastReportGroup = {
  source: string
  horizon: number
  model_version: string
  question_version: string
  sample: number
  pending: number
  coverage: number | null
  directional_accuracy: number | null
  covered_accuracy: number | null
  brier: number | null
  calibration: { bucket: number; count: number; mean_confidence: number; accuracy: number }[]
}

export const forecastApi = {
  async predict(ticker: string, horizon: number, minimumProbability: number): Promise<Forecast> {
    await ensureCsrfCookie()
    const payload = await request<{ forecast: Forecast }>(`/api/instruments/${encodeURIComponent(ticker)}/forecast`, {
      method: 'POST',
      body: JSON.stringify({ horizon, minimum_probability: minimumProbability }),
    })
    return payload.forecast
  },
  async report(minimumProbability = 60): Promise<ForecastReportGroup[]> {
    const payload = await request<{ groups: ForecastReportGroup[] }>(`/api/admin/forecasts/report?minimum_probability=${minimumProbability}`)
    return payload.groups
  },
}

export type ForecastBacktestCase = {
  ticker: string
  as_of_date: string
  target_date: string | null
  status: 'queued' | 'completed' | 'skipped'
  predicted_class: 'up' | 'down' | 'flat' | null
  observed_class: 'up' | 'down' | 'flat' | null
  probabilities: { up: number; down: number; flat: number } | null
  reason: string | null
}

export type ForecastBacktestRun = {
  id: number
  tickers: string[]
  bars_requested: number
  model_version: string
  question_version: string
  status: 'queued' | 'running' | 'completed' | 'failed'
  total: number
  processed: number
  correct: number
  skipped: number
  accuracy: number | null
  created_at: string
  cases?: ForecastBacktestCase[]
  case_page?: { current: number; last: number; total: number }
}

export const forecastBacktestApi = {
  async list(): Promise<ForecastBacktestRun[]> {
    const payload = await request<{ runs: ForecastBacktestRun[] }>('/api/admin/forecasts/backtests')
    return payload.runs
  },
  async get(id: number, page = 1): Promise<ForecastBacktestRun> {
    const payload = await request<{ run: ForecastBacktestRun }>(`/api/admin/forecasts/backtests/${id}?page=${page}`)
    return payload.run
  },
  async start(tickers: string[], bars: number): Promise<ForecastBacktestRun> {
    await ensureCsrfCookie()
    const payload = await request<{ run: ForecastBacktestRun }>('/api/admin/forecasts/backtests', {
      method: 'POST', body: JSON.stringify({ tickers, bars }),
    })
    return payload.run
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

/**
 * The Alert kinds evaluated once per daily run (`alerts-engine`, plus
 * `watchlist_pattern` from `alerts-patterns`).
 */
export type AlertKind = 'screener_new_candidates' | 'watchlist_signal' | 'watchlist_pattern'

/** What a `watchlist_pattern` alert notifies: a new pattern instance, or its breakout. */
export type AlertPatternEvent = 'appears' | 'confirmed'

/** Canonical order of the pattern events (matches the API). */
export const ALERT_PATTERN_EVENTS: readonly AlertPatternEvent[] = ['appears', 'confirmed']

/** One caller-owned Alert rule (`GET /api/alerts`). */
export type Alert = {
  id: number
  kind: AlertKind
  saved_screener: { id: number; name: string } | null
  signal_types: ScreenerSignalType[] | null
  /** Chosen pattern types; null unless `kind` is `watchlist_pattern`. */
  pattern_types: ScreenerPatternType[] | null
  /** Chosen pattern events; null unless `kind` is `watchlist_pattern`. */
  pattern_events: AlertPatternEvent[] | null
  active: boolean
  last_evaluated_as_of: string | null
  created_at: string | null
}

export type CreateAlertPayload =
  | { kind: 'screener_new_candidates'; saved_screener_id: number }
  | { kind: 'watchlist_signal'; signal_types: ScreenerSignalType[] }
  | { kind: 'watchlist_pattern'; pattern_types: ScreenerPatternType[]; pattern_events?: AlertPatternEvent[] }

export type UpdateAlertPayload = {
  active?: boolean
  signal_types?: ScreenerSignalType[]
  pattern_types?: ScreenerPatternType[]
  pattern_events?: AlertPatternEvent[]
}

/**
 * Authenticated Alerts client (`docs/specs/alerts-engine.md`). Every call is
 * scoped server-side to the session user: another user's id is a `404`, a
 * guest a `401`, the per-user limit and duplicates a localized `422`.
 */
/** The session user's daily alert email preference (alerts-email-digest). */
export type AlertEmailPreference = {
  /** False while the feature is off: the Portal hides the control. */
  available: boolean
  opted_in: boolean
  opted_in_at: string | null
  locale: string | null
}

export const alertEmailApi = {
  async get(): Promise<AlertEmailPreference> {
    return request<AlertEmailPreference>('/api/alerts/email')
  },

  /** Explicit opt-in (records the page language) or opt-out. */
  async update(optIn: boolean): Promise<AlertEmailPreference> {
    await ensureCsrfCookie()
    return request<AlertEmailPreference>('/api/alerts/email', {
      method: 'PUT',
      body: JSON.stringify({ opt_in: optIn }),
    })
  },
}

export const alertsApi = {
  async list(): Promise<Alert[]> {
    const payload = await request<{ alerts: Alert[] }>('/api/alerts')
    return payload.alerts
  },

  async create(data: CreateAlertPayload): Promise<Alert> {
    await ensureCsrfCookie()
    const payload = await request<{ alert: Alert }>('/api/alerts', {
      method: 'POST',
      body: JSON.stringify(data),
    })
    return payload.alert
  },

  /** Pause/resume (`active`) or change the Watchlist Signal types. */
  async update(id: number, data: UpdateAlertPayload): Promise<Alert> {
    await ensureCsrfCookie()
    const payload = await request<{ alert: Alert }>(`/api/alerts/${id}`, {
      method: 'PATCH',
      body: JSON.stringify(data),
    })
    return payload.alert
  },

  async remove(id: number): Promise<void> {
    await ensureCsrfCookie()
    await request<void>(`/api/alerts/${id}`, { method: 'DELETE' })
  },
}

/**
 * One notification item. `reason` is `new_candidate` for Screener alerts, the
 * Signal type that appeared for Watchlist signal alerts, or
 * `pattern:<type>:<appears|confirmed>` for Watchlist pattern alerts, which
 * also carry the pattern `status` and `breakout_level`.
 */
export type NotificationItem = {
  ticker: string
  name: string | null
  reason: string
  status?: 'forming' | 'confirmed'
  breakout_level?: number
}

/** One in-app notification; the SPA renders its text from this neutral data. */
export type AppNotification = {
  id: string
  kind: AlertKind | null
  as_of: string | null
  saved_screener: { id: number; name: string | null } | null
  items: NotificationItem[]
  /** Items beyond the server-side cap, summarized as a count. */
  more: number
  read_at: string | null
  created_at: string | null
}

export type NotificationsPage = {
  data: AppNotification[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
  unread_count: number
}

/** Authenticated notifications inbox client; always scoped to the session user. */
export const notificationsApi = {
  /** Newest first, 20 per page, with the caller's total `unread_count`. */
  async list(page = 1): Promise<NotificationsPage> {
    return request<NotificationsPage>(`/api/notifications?page=${page}`)
  },

  /** Mark some (`ids`) or all of the caller's notifications read (`204`). */
  async markRead(target: { ids: string[] } | { all: true }): Promise<void> {
    await ensureCsrfCookie()
    await request<void>('/api/notifications/read', {
      method: 'POST',
      body: JSON.stringify(target),
    })
  },
}

/**
 * Owner identity shown on the legal pages. `tax_id`, `address` and `registry`
 * are null until the legal notice is published.
 */
export type LegalOwner = {
  name: string
  email: string
  tax_id: string | null
  address: string | null
  registry: string | null
}

/**
 * `GET /api/legal` (public). The privacy policy is published once the owner's
 * name and a valid email are configured; the legal notice also needs tax id and
 * address. `owner` is null while the privacy policy is withheld
 * (`docs/specs/legal-privacy-publication.md`).
 */
export type LegalInfo = {
  privacy_published: boolean
  notice_published: boolean
  owner: LegalOwner | null
  updated_at: string
  retention: {
    sign_in_activity_days: number
    session_minutes: number
    backup_days: number
    server_log_days: number
    notification_days: number
    /** Absent from older deployments. */
    ai_card_log_days?: number
    /** Absent from older deployments. */
    ai_screener_log_days?: number
    /** Absent from older deployments. */
    email_digest_days?: number
  }
  /**
   * The daily alert email (alerts-email-digest) and its sending provider;
   * null (or absent on older deployments) while the feature is off.
   */
  email_digest?: { provider: string; provider_location: string } | null
}

export const legalApi = {
  async info(): Promise<LegalInfo> {
    return request<LegalInfo>('/api/legal')
  },
}
