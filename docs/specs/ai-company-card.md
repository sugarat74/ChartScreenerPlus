# Feature Implementation Spec: AI-generated company card per Candidate

## Source Feature

- `id`: `ai-company-card`
- `area`: `ai` (Laravel provider client, service, tables, API; SPA chart panel and Candidate row entry; legal texts)
- `depends_on`: `auth-registration-login`, `instrument-detail-api`, `chart-interactive`, `candidate-list-ranking`, `chart-patterns-detect`, `app-multilanguage`, `legal-compliance-eu`, `pronostico-jev` (all `accepted`)
- `status`: `not_started` (planned 2026-10-15 on base `main` bc691e1)
- `source`: `feature_list.json`

## Goal

A Registered User can open, for any Candidate or Instrument, a **Company Card**: a structured sheet written by an external, OpenAI-compatible LLM with the company's sector and industry, a short business description, key financial metrics (P/E, ROIC, EBITDA, etc., each with its period) and a qualitative three-month outlook based on macro and sector context. Laravel asks the provider for JSON that follows a fixed schema, validates it server-side, stores one shared card per Instrument and language, and reuses it while it is fresh, so the provider is called on demand and at most once per key and freshness window. The card is labelled as AI-generated, unverified and not investment advice.

This is an explicit user scope decision (2026-10-15). It is **not** the AI Copilot (no chat, no free-text prompt from the user). It does not fetch news or browse the web.

## Non-Goals

- No chat/Copilot, free-text questions, or user-editable prompt; no `/copilot` route.
- No news retrieval, web search/browsing or tool calls; no verified fundamentals data provider (recommended follow-up, see Risks).
- No price target, fair value, stop, buy/sell/hold rating or position sizing anywhere in the schema, prompt or UI.
- No alerts, notifications, email or Screener filters based on card content; card data never enters `screener_rows`.
- No Admin UI for the generation log (follow-up `ai-company-card-admin-log`); the log table and its limits are in scope.
- No change to the Jev forecast, `GET /api/instruments/{ticker}`, `GET /api/screener` or their payloads.
- No streaming responses, queue job or background pre-generation of cards for the Universe.

## Job Story

When a Candidate shows up in my Screener and I only know its ticker,
I want a short, structured sheet with what the company does, its key ratios and a three-month view of its sector and macro context,
so I can decide whether it deserves a closer look without leaving Chartiko, knowing the content is AI-generated and unverified.

## Users And Permissions

- Visitor: sees the card panel's title, a one-line explanation and a sign-in **link** (`LOGIN_ROUTE`) on the chart; the Candidate row link is visible to everyone. API: `401`, never a provider call.
- Registered User: reads any stored card and requests generation, within the limits below. Cards are shared system data (no owner, no identity inside the card).
- Admin: same as a Registered User; no special path in this feature.

## Domain Decisions (user can change)

1. **Visibility:** Registered Users only, like Jev (`docs/user-and-access-model.md:23`, `routes/api.php:42-43`). Cost control.
2. **Entry points:** (a) a small `Ficha IA` / `AI card` link on each `CandidateTable` row to `/instruments/{ticker}#ai-company-card`; it navigates only, so the Screener stays anonymous and calls no `useAuth` (`CONSTRAINTS.md:48`); (b) a `CompanyCardPanel` on the chart page after `ForecastPanel` (`InstrumentChartPage.tsx:357-359`). When the hash is present, the page scrolls to the panel after the `ready` state.
3. **On demand, never on page view:** opening the panel only **reads** the stored card (`GET`, database only). The provider is called only when the user presses `Generar ficha` / `Generate card` (`POST`) and no fresh card exists.
4. **Cache key and freshness:** one row per `(instrument_id, locale, schema_version)`. A card is *fresh* when `generated_at >= now - AI_CARD_FRESHNESS_DAYS` (default 7) **and** its `model` and `prompt_version` equal the current config. A stale card is still shown (with its date and a `stale` flag) until a successful regeneration replaces it; a failed regeneration keeps it.
5. **Concurrency:** `Cache::lock('ai-card:'.sha256(key), timeout+15)->block(timeout+10)`; after acquiring the lock the service re-reads the row, so a second concurrent request returns the card the first one produced without a second call (Jev uses a non-blocking lock and `busy`, `ForecastService.php:32-36`; blocking is better here because generation is slow). Lock wait timeout → `503 busy`.
6. **Limits:** route throttle `throttle:{AI_CARD_REQUESTS_PER_MINUTE},1,ai-card` (default 5) on `POST`, following the prefixed-throttle rule (`routes/api.php:21-25`); per-user daily cap `AI_CARD_USER_DAILY_LIMIT` (default 10) and global daily cap `AI_CARD_DAILY_LIMIT` (default 200) counted from `ai_company_card_generations` rows created since UTC midnight (every generation attempt counts, success or not; a json_schema -> json_object fallback is one attempt). Reused cards never count.
7. **Language:** generated in the request locale (`App::getLocale()` from `SetLocale`, `es` fallback; `config/locales.php`), stored per locale. Company, ticker, sector and industry names are data and are not translated (`CONSTRAINTS.md:37`).
8. **Context sent to the provider:** always the instrument identity (`ticker`, `company`, `sector`, `exchange` from `instruments`, `database/data/sp500.csv` header `ticker,company,sector,exchange`). Only when `AI_CARD_MARKET_DATA_RIGHTS_VERIFIED=true` also our latest technical context: latest close and date, change % vs previous bar, `sma20/50/200`, `rsi14`, `rvol`, `macd_hist`, `adx`, current Signal types and active chartist patterns (type, status, `breakout_level`). Never user id, email, Watchlist, Saved Screeners or session data. The mode (`identity` / `identity+technical`) is stored on the card. Rationale: same rights caution as Jev (`config/forecast.php:4-7`), but identity data alone is public and lets the feature run before rights are confirmed.
9. **Accuracy:** the prompt orders `null` instead of guessing and a `period` for every metric; the UI shows `—` for `null`, the per-metric period, the model's `data_as_of`, generation date, model id and a permanent warning: generated by AI, may contain errors, figures not verified. The schema keeps `metrics` separate from `outlook` so a later verified fundamentals provider can fill `metrics` while the LLM writes only the outlook.
10. **Provider:** any OpenAI-compatible Chat Completions API (`{AI_BASE_URL}/chat/completions`, Bearer key). Default response format `json_schema` with `strict: true`; if the provider answers `400` to it and `AI_RESPONSE_FORMAT_FALLBACK=true`, retry **once** with `{type: "json_object"}` and the schema embedded in the system prompt. `AI_RESPONSE_FORMAT=json_object` skips the first attempt.

## API Contract

All routes inside the existing `Route::middleware('auth:sanctum')` group (`routes/api.php:42`). Unknown ticker (normalized `strtoupper(trim())`) → explicit `404 {"message": __('messages.instrument_not_found')}` like `InstrumentController.php:41-47` (no `firstOrFail`).

- `GET /api/instruments/{ticker}/company-card` (`throttle:60,1,ai-card-read`) → `200 {card: Card|null, available: bool}`. Never calls the provider. Returns the row for the current locale and schema version. *(Implementation addition, 2026-10-15: `available` = enabled and fully configured, so the panel shows a "not available yet" state instead of a button that can only fail; it reveals no configuration detail.)*
- `POST /api/instruments/{ticker}/company-card` (no body) → `200 {card: Card, reused: bool}`; `429` throttle or `{code: "user_daily_limit"}`; `503 {code, message}` with `code` in `provider_not_configured`, `provider_unavailable`, `invalid_provider_response`, `busy`, `daily_limit_reached`. Message text from `lang/{es,en}/company_card.php`; status and `code` never depend on locale.
- `Card` = `{ticker, locale, schema_version, model, generated_at (ISO-8601 UTC), fresh: bool, context_mode, context_as_of_date|null, content: <validated schema object> }`.

### Card JSON schema (`company-card-v1`, strict: every property required, `additionalProperties: false`)

- `company`: `{ name: string, ticker: string, sector: string|null, industry: string|null, description: string|null }`
- `metrics`: object with exactly these keys, each `{ value: number|null, currency: string|null, period: string|null }`: `pe_ttm`, `pe_forward`, `roic_percent`, `ebitda_millions`, `ebitda_margin_percent`, `revenue_growth_yoy_percent`, `net_debt_to_ebitda`, `market_cap_millions`, `dividend_yield_percent`. Units are fixed by the key (ratio `x`, percent, millions of `currency`), never by the model; `currency` is required (ISO 4217) only when the value is a currency amount and `value` is non-null.
- `outlook`: `{ horizon_months: integer (must be 3), stance: "bullish"|"neutral"|"bearish", summary: string, macro_context: string, sector_context: string, drivers: string[], risks: string[], confidence: "low"|"medium"|"high" }`
- `data_as_of`: string|null (latest period the model's figures refer to), `limitations`: string, `sources`: string[] (plain-text descriptions, rendered as text, never as links).

Server-side validation (`CompanyCardSchema::validate`, no new Composer dependency): exact key sets at every level; types; enums; `horizon_months === 3`; finite numbers within sane bounds (`|value| <= 1e7`, percent fields `-1000..1000`); `company.ticker` equals the requested ticker (case-insensitive) else invalid; string caps (description 600, summary 700, contexts 500, items 200, limitations 400, period/currency 40/3 chars matching `^[A-Z]{3}$`); `drivers`/`risks` 1–5 items, `sources` 0–5; strings trimmed and control characters stripped. Any violation → `invalid_provider_response`, nothing stored.

## Acceptance Scenarios

### Scenario 1: generate and reuse
Given the provider is enabled and `NVDA` has no card in `es`
When a Registered User with `Accept-Language: es` posts to `/api/instruments/NVDA/company-card`
Then exactly one Chat Completions request is sent (with `response_format.type = json_schema`, `strict: true`, the model and max tokens from config), the validated card is stored, the response is `200` with `reused: false`, and a second user posting within 7 days gets `reused: true` with no new provider request.

### Scenario 2: Visitor
Given a Visitor on `/instruments/NVDA`
When the page renders and when the API is called directly
Then the panel shows a sign-in link and no generate button; `GET` and `POST` return `401`; no provider request and no log row exist.

### Scenario 3: invalid or unsafe output
Given the provider returns content that is not JSON, misses a key, has an extra key, a wrong ticker, `finish_reason: "length"`, a non-null `refusal`, or `horizon_months: 6`
When generation runs
Then the response is `503 invalid_provider_response`, no card is written (an older card stays unchanged), and one log row with status `invalid_response` and token usage (if returned) is written.

### Scenario 4: json_object fallback
Given the provider answers `400` to the `json_schema` request
When `AI_RESPONSE_FORMAT_FALLBACK=true`
Then exactly one retry is sent with `response_format: {type: "json_object"}`, whose valid output is stored; with the fallback disabled the result is `503 provider_unavailable`.

### Scenario 5: not configured / rights
Given `AI_CARD_ENABLED=false`, or the key, base URL or model is empty
When a user posts
Then `503 provider_not_configured` without an HTTP request. Given it is enabled with `AI_CARD_MARKET_DATA_RIGHTS_VERIFIED=false`, the request body contains identity fields only (no close, indicators, Signals or patterns) and the card stores `context_mode: "identity"`.

### Scenario 6: limits and concurrency
Given the user already has `AI_CARD_USER_DAILY_LIMIT` attempts today
When they post for an Instrument without a fresh card
Then `429 user_daily_limit` without a provider call; a fresh card is still returned (`reused: true`) because reuse never counts. At the global cap the response is `503 daily_limit_reached`. Two requests for the same key while the lock is held result in one provider call.

### Scenario 7: bilingual display and disclaimers
Given a stored card
When the chart page opens in `en` or `es`
Then the panel shows sector/industry, description, the metric table (formatted with `useI18n` formatters; `—` for `null`; period per metric), the outlook stance as a scenario wording (`Escenario base: alcista/neutral/bajista`), drivers, risks, confidence, limitations, `data_as_of`, generation date and model, and the warning "Generated by AI · may contain errors · figures not verified · not investment advice"; a `stale` card shows an "outdated" note and the generate button. No price target or buy/sell wording exists in the catalogs.

### Scenario 8: entry from a Candidate row
Given the Screener lists `NVDA`
When the user clicks its `AI card` link
Then the browser goes to `/instruments/NVDA#ai-company-card` and, once the chart is ready, the panel is scrolled into view; no provider call happens until the user presses generate.

## Repository Research

### Files Inspected

- `config/forecast.php:4-16` — opt-in `enabled` + `rights_verified` flags, key/model from env, `requests_per_minute`, `timeout_seconds`. Pattern to mirror in `config/ai_card.php`.
- `app/Services/Forecast/JevClient.php:33-36` (refuses unless enabled, rights verified, key and model valid), `:42-44` (`Http::withToken()->acceptJson()->timeout()`), `:59-67` (transport/HTTP errors logged by type/status only, mapped to `provider_unavailable`), `:69-99` (strict response validation before use).
- `app/Services/Forecast/ForecastService.php:21-53` — config check, `Cache::lock` keyed by a sha256 of the logical key, read-before-call deduplication, persistence of a versioned row.
- `app/Services/Forecast/ForecastUnavailable.php` — reason-carrying exception; `app/Http/Controllers/ForecastController.php:107-117` maps reasons to `422/503 {code, message}` via `__('forecast.*')`.
- `app/Services/Forecast/ForecastStateBuilder.php:24-66,101-114` — how latest bar, snapshot fields and same-date Signals are read; reuse the field list for the technical context.
- `database/migrations/2026_10_13_000001_create_forecast_predictions_table.php` — versioned shared rows (`model_version`, `question_version`, unique logical key), no user identity.
- `app/Console/Commands/CopySqliteToPgsql.php:47-70` — new tables must be classified (`SKIPPED_TABLES`, like the forecast tables at `:66-69`).
- `routes/api.php:21-25,42-43` — prefixed throttles; forecast route inside `auth:sanctum` with `throttle:N,1,forecast`.
- `tests/Feature/ForecastFeatureTest.php:45-82` — `config()->set` to enable, `Http::fake`, `Http::assertSentCount`, assertion that the user's email is never sent.
- `app/Http/Controllers/InstrumentController.php:41-47,62-84,126-127` — explicit JSON 404, latest snapshot, Signals and patterns queries, instrument `company`/`sector`.
- `database/migrations/2026_09_29_100002_create_instruments_table.php:16-19`, `database/data/sp500.csv:1` — `sector` (GICS sector) already exists; **industry does not**, the model provides it.
- `app/Services/Screener/CandidateQuery.php:180-181`, `frontend/src/lib/api.ts:410-423` — Candidates already carry `company` and `sector`.
- `frontend/src/components/screener/CandidateTable.tsx:1-5,53-67` — ticker link per row; docblock says it is the only interactive element (update it).
- `frontend/src/pages/InstrumentChartPage.tsx:357-359` and `frontend/src/components/chart/ForecastPanel.tsx:10-81` — panel placement, `useAuth` + sign-in link pattern, stale-request guard with a generation counter, disclaimer line.
- `frontend/src/lib/api.ts:73-110,631-666` — `request()` sends `Accept-Language`, CSRF before mutations, `ApiError`/`apiErrorMessage`, `forecastApi` shape.
- `frontend/src/i18n/messages/en.ts:22-36`, `lang/en/forecast.php` — forecast catalog and API reason messages.
- `frontend/src/i18n/legal/en.ts:85-95,141-147` (+ `es.ts`), `config/legal.php:35`, `app/Http/Controllers/LegalController.php:24-31` — privacy "what we do not do", recipients, not-advice section, `updated_at`, retention facts returned to the SPA.
- `.env.example:68-73` — Jev env block style.
- `AGENTS.md:7,58-62`, `CONSTRAINTS.md:46,59,169-174`, `CONTEXT.md:83-85`, `DESIGN.md:137,141`, `docs/user-and-access-model.md:23-25,40`, `docs/build-brief.md:29,33`, `docs/risks-and-open-questions.md:24`, `docs/technical-discovery.md:11` — scope texts that this feature changes.
- `specs/pronostico-jev/{spec,design}.md`, `docs/forecast-jev-operations.md` — Jev requirements, activation and rights confirmation.
- `deploy/nginx.conf` — no `fastcgi_read_timeout` override (nginx default 60 s), relevant to a synchronous LLM call.

### Existing Patterns To Follow

- Opt-in provider with rights flag, key only in server config, transport errors logged without payloads (Jev).
- Shared, versioned, identity-free result rows reused across users; lock + re-read to avoid duplicate paid calls.
- `auth:sanctum` group + prefixed `throttle`; reason codes `{code, message}` with localized messages.
- Laravel 13 models with `#[Fillable]`, `casts()`, `HasFactory`; `RefreshDatabase` + in-memory SQLite; `Http::fake` only; locale tests through `LocalizationTest`.
- SPA panel per `ForecastPanel`: `useAuth` inside the panel, sign-in link, `useI18n` formatters, design tokens, `role="alert"` errors, no auto-retry.
- Scheduled pruning via `model:prune` (`routes/console.php:58`).

### Current Gaps

- No generic LLM client, no `config/ai_card.php`, no tables, no catalogs for the card.
- No JSON-schema validation library (decision: hand-written validator, no new dependency).
- No persistent E2E harness (only Vitest + PHPUnit); Jev was verified the same way.
- Privacy text does not mention Jev/TypeSafe either (pre-existing gap; see Durable Documentation Impact).
- `CONSTRAINTS.md:59` still says the watchlist toggle is the only auth-aware chart affordance, although `ForecastPanel` already reads the session.

## Technical Approach

**Config** `config/ai_card.php` (all from env, defaults in brackets): `enabled` `AI_CARD_ENABLED` [false]; `rights_verified` `AI_CARD_MARKET_DATA_RIGHTS_VERIFIED` [false]; `base_url` `AI_BASE_URL` [empty; e.g. `https://api.openai.com/v1`]; `api_key` `AI_API_KEY`; `model` `AI_MODEL`; `timeout_seconds` `AI_TIMEOUT_SECONDS` [40, must stay below nginx's 60 s]; `max_tokens` `AI_MAX_TOKENS` [1800]; `max_tokens_param` `AI_MAX_TOKENS_PARAM` [`max_tokens`; `max_completion_tokens` for newer OpenAI models]; `temperature` `AI_TEMPERATURE` [0.2; `null` omits it]; `response_format` `AI_RESPONSE_FORMAT` [`json_schema`|`json_object`]; `response_format_fallback` `AI_RESPONSE_FORMAT_FALLBACK` [true]; `freshness_days` `AI_CARD_FRESHNESS_DAYS` [7]; `requests_per_minute` `AI_CARD_REQUESTS_PER_MINUTE` [5]; `user_daily_limit` `AI_CARD_USER_DAILY_LIMIT` [10]; `daily_limit` `AI_CARD_DAILY_LIMIT` [200]; `log_retention_days` `AI_CARD_LOG_RETENTION_DAYS` [90]; constants `schema_version` `company-card-v1`, `prompt_version` `company-card-prompt-v1`. `base_url` must be `https://`, or `http://` only for `localhost`/`127.0.0.1` (local LLM servers); otherwise not configured.

**Generic client** `App\Services\Ai\OpenAiCompatibleClient::structured(array $messages, string $schemaName, array $schema): StructuredCompletion` — posts to `rtrim(base_url,'/').'/chat/completions'` with `withToken`, `acceptJson`, `timeout`; body `{model, messages, response_format, <max_tokens_param>, temperature?}`. Parses `choices[0]`: requires `finish_reason === 'stop'`, `message.refusal` empty, `message.content` a string; strips a surrounding ```` ```json ```` fence; `json_decode` to array. Returns decoded array, usage (`prompt_tokens`, `completion_tokens`, `total_tokens` or nulls), used format and latency. Throws `AiProviderException` with reason `provider_unavailable` (transport, non-2xx, timeout) or `invalid_provider_response`. Logs only exception class / HTTP status, never key, prompt or content. Has no card knowledge, so future AI features can reuse it.

**Card domain** `App\Services\CompanyCard\`: `CompanyCardSchema` (schema array + `validate(array, string $ticker): array`), `CompanyCardPrompt` (system + user messages: role "financial data summarizer", language, `null` instead of guessing, periods required, no targets/ratings/advice, 3-month horizon, fixed units per key; user message is JSON of the context), `CompanyCardContextBuilder` (decision 8), `CompanyCardService` (`current()` read, `generate()` with config check → caps → lock → re-read → client → validate → upsert card + log row in one transaction → payload), `CompanyCardUnavailable` (reason). Controller `App\Http\Controllers\CompanyCardController` (`show`, `store`).

**Storage**
- `ai_company_cards`: `id`, `instrument_id` FK cascade, `locale` string(8), `schema_version` string(40), `prompt_version` string(40), `model` string(120), `context_mode` string(24), `context_as_of_date` date nullable, `content` json, `generated_at` timestamp, `timestamps`; unique `(instrument_id, locale, schema_version)`. Model `AiCompanyCard`.
- `ai_company_card_generations`: `id`, `instrument_id` FK cascade, `user_id` FK nullable `nullOnDelete`, `locale`, `model`, `schema_version`, `prompt_version`, `response_format` string(16), `status` string(24) (`succeeded`|`invalid_response`|`provider_error`), `error_code` nullable, `prompt_tokens`/`completion_tokens`/`total_tokens` unsigned int nullable, `latency_ms` unsigned int nullable, `timestamps`; indexes `(created_at)`, `(user_id, created_at)`. Model `AiCompanyCardGeneration` with `Prunable` (older than `log_retention_days`), scheduled with the existing `model:prune` entry. No prompt or response text is stored.
- Both tables in `CopySqliteToPgsql::SKIPPED_TABLES` (postdate the cut-over).

**SPA**: `companyCardApi.get/generate` and `CompanyCard` types in `frontend/src/lib/api.ts`; `frontend/src/components/chart/CompanyCardPanel.tsx` (states: guest link, loading, none yet + generate, ready, stale, generating, error with manual retry; request-generation guard as in `ForecastPanel.tsx:18-43`; `id="ai-company-card"`); `InstrumentChartPage` mounts it with `key={ticker}` after `ForecastPanel` and scrolls on `#ai-company-card`; `CandidateTable` gains a compact link in the ticker cell; catalogs `companyCard.*` in `es.ts`/`en.ts`.

## Expected File Changes

- `config/ai_card.php`, `.env.example` — create/modify; config and the `AI_*` block (disabled, empty key).
- `app/Services/Ai/OpenAiCompatibleClient.php`, `StructuredCompletion.php`, `AiProviderException.php` — create.
- `app/Services/CompanyCard/{CompanyCardSchema,CompanyCardPrompt,CompanyCardContextBuilder,CompanyCardService,CompanyCardUnavailable}.php` — create.
- `app/Http/Controllers/CompanyCardController.php`, `routes/api.php` — create/modify.
- `app/Models/{AiCompanyCard,AiCompanyCardGeneration}.php`, `database/factories/AiCompanyCardFactory.php`, two migrations — create.
- `app/Console/Commands/CopySqliteToPgsql.php`, `routes/console.php` — modify (skip tables; prune model).
- `lang/{es,en}/company_card.php` — create.
- `tests/Feature/CompanyCardFeatureTest.php`, `tests/Unit/CompanyCardSchemaTest.php`, `tests/Unit/OpenAiCompatibleClientTest.php` — create.
- `frontend/src/lib/api.ts`, `frontend/src/components/chart/CompanyCardPanel.tsx` (+ `.test.tsx`), `frontend/src/pages/InstrumentChartPage.tsx`, `frontend/src/components/screener/CandidateTable.tsx`, `frontend/src/i18n/messages/{es,en}.ts` — create/modify.
- `frontend/src/i18n/legal/{es,en}.ts`, `config/legal.php` (`updated_at`), `app/Http/Controllers/LegalController.php` (`ai_card_log_days` retention) — modify.
- Durable docs listed below; `docs/ai-company-card-operations.md` — create.

## Visual Design Impact

- UI involved: yes. Source: `DESIGN.md` (card/tile, badge, primary button, dense mono figures).
- Panel: bordered card like `ForecastPanel`, title `Ficha de empresa (IA)` / `Company card (AI)`, an `IA`/`AI` badge, a permanent warning line, a two-column metric grid (label, mono value + unit, small period), outlook block with a stance chip (neutral token colors; never gain/loss colors that read as a buy/sell call), lists for drivers/risks, footer with `data_as_of`, generation date and model.
- States: guest, loading, empty + generate, generating (button disabled, "may take up to a minute"), ready, stale, error.
- Candidate row link: small mono underlined text under the company name; must not widen the table.
- New design artifact required: no; add one `Core Screens` bullet to `DESIGN.md`.

## Durable Documentation Impact

- `AGENTS.md`: **update.** Line 7: replace "An AI Copilot is planned but out of MVP scope." with "An AI Copilot (chat) is planned but out of MVP scope; an AI-generated Company Card per Instrument is in scope." After line 60 add: "Also in scope since 2026-10-15 (explicit user decision): the AI Company Card (`ai-company-card`), a structured, on-demand sheet per Instrument generated by an OpenAI-compatible provider for Registered Users, shared and cached, labelled as AI-generated, unverified and not advice; no chat, news retrieval, price targets or ratings." Line 62: change "fundamentals/news" to "verified fundamentals data feeds and news" and "AI Copilot" to "AI Copilot (chat or free-text AI)".
- `CONSTRAINTS.md`: **update.** Line 46: "MUST NOT add a Copilot (chat or free-text AI) route or surface to the SPA. Reason: the AI Copilot is out of MVP scope; the AI Company Card panel is not a Copilot." Line 59: list the forecast panel and the Company Card panel as auth-aware affordances that read the session inside the component and offer a Visitor only a sign-in link. New section `## AI Company Card`: routes in `auth:sanctum` with prefixed throttle; provider only through `OpenAiCompatibleClient` and `config/ai_card.php`, disabled by default, key never in Git/browser/logs; only identity (+ technical context when rights verified) may leave the server; output MUST be validated against `company-card-v1` and invalid output MUST NOT be stored or shown; no target/rating/advice fields; reuse a fresh card before any call, one call per key under concurrency, daily caps count attempts; `schema_version`/`prompt_version` MUST be bumped when schema or prompt change; tests MUST use `Http::fake`; generation log keeps no prompt/response content and is pruned after `log_retention_days`.
- `CONTEXT.md`: **update.** Add `### Company Card (Ficha IA)`: "A structured sheet for one Instrument generated on demand by an external LLM: sector and industry, business description, key financial metrics with their period, and a qualitative three-month outlook from macro and sector context. Shared per Instrument and language, refreshed after a freshness window. Its figures are AI-generated and unverified; it never contains price targets or buy/sell advice. It is not the Copilot." Amend `Copiloto IA`: "...out of MVP scope. Distinct from the Company Card."
- `ARCHITECTURE.md`: **update.** New section "AI Company Card" (client, service, tables, flow, fallback) and the `AI_*` keys under Configuration.
- `docs/domain-model.md`: **update.** Core concept + relationship (Instrument has many Company Cards, one per locale/schema version); lifecycle `absent -> fresh -> stale -> fresh`.
- `docs/user-and-access-model.md`: **update.** Permission rows "Read / generate an AI Company Card": Visitor no, Registered yes, Admin yes; note on shared rows and the generation log's `user_id`.
- `DESIGN.md`: **update.** Core Screens bullet for the panel; leave the Copilot line.
- `docs/build-brief.md:29,33`, `docs/technical-discovery.md:11`, `docs/risks-and-open-questions.md:24`: **update** wording to match AGENTS.md; add the hallucination/cost risks.
- Legal (`frontend/src/i18n/legal/{es,en}.ts`): **update.** Privacy: a section "AI features" stating that, on a Registered User's request, the server sends the Instrument identity and market technical context to an external AI provider (named once the owner configures it; do not invent it), with no account data; that the generation log keeps account id, Instrument, date, status and token counts for `{aiCardLogDays}` days for cost control and abuse prevention (legitimate interest). The owner should decide whether the same section also covers Jev/TypeSafe, which is currently undisclosed. Legal notice "Not investment advice": add "AI-generated company cards may contain errors or outdated figures". Bump `config/legal.php` `updated_at`.
- `docs/ai-company-card-operations.md`: **create.** Activation steps, env vars, cost caps, provider data-processing check, how to bump versions.

## Implementation Plan

1. Config, env block, generic client with fake-backed unit tests (json_schema body, fallback, fence stripping, finish/refusal handling, no key in logs).
2. Schema + validator with exhaustive unit tests; prompt and context builder (rights flag on/off).
3. Migrations, models, prune, copy-command classification; service with caps, lock, reuse, logging; controller, routes, lang files; feature tests.
4. SPA API client, panel, chart page mount + hash scroll, Candidate row link, catalogs, Vitest.
5. Legal texts and retention fact; durable docs; ops doc; `.\init.ps1`; evidence in `feature_list.json` and `PROGRESS.md`.

## Implementation Tasks

- [ ] `config/ai_card.php` + `.env.example` `AI_*` block (disabled).
- [ ] `OpenAiCompatibleClient` + `StructuredCompletion` + `AiProviderException`; unit tests with `Http::fake` and `Http::assertSent`.
- [ ] `CompanyCardSchema` (schema array identical to what is sent; validator) + unit tests for every rejection rule.
- [ ] `CompanyCardPrompt`, `CompanyCardContextBuilder` (no user data; identity-only mode).
- [ ] Migrations, `AiCompanyCard`, `AiCompanyCardGeneration` (`Prunable`), factory, `SKIPPED_TABLES`, `model:prune` entry.
- [ ] `CompanyCardService` + `CompanyCardUnavailable`; `CompanyCardController`; routes with throttles; `lang/{es,en}/company_card.php`.
- [ ] `tests/Feature/CompanyCardFeatureTest.php` covering scenarios 1–6.
- [ ] `companyCardApi`, `CompanyCardPanel` (+ test), chart page mount and hash scroll, `CandidateTable` link, `companyCard.*` catalogs.
- [ ] Legal texts, `updated_at`, retention fact; durable docs; ops doc.
- [ ] `.\init.ps1`; record evidence.

## Verification Plan

- `php artisan test --filter=CompanyCard` and `php artisan test --filter=OpenAiCompatibleClient` — all pass, offline.
- Feature tests assert: `401` for guests with `Http::assertNothingSent()`; one request then `reused: true` (`Http::assertSentCount(1)`, `assertDatabaseCount('ai_company_cards', 1)`); request body has `response_format.type=json_schema`, `json_schema.strict=true`, configured model and `max_tokens`; body contains neither the user's email nor id; identity-only body when rights flag is false; every invalid fixture leaves no card and one `invalid_response` log row; fallback sends exactly two requests; caps return `429 user_daily_limit` / `503 daily_limit_reached` with no request; stale card regenerated, failed regeneration keeps the old row; `es` and `en` stored separately; JSON `404` for unknown ticker.
- `LocalizationTest` passes with the new `company_card.php` files.
- `npm --prefix frontend run test` — `CompanyCardPanel.test.tsx`: guest link without generate button, `—` for nulls, periods, disclaimer, stale note, error alert without auto-retry, Spanish/English labels; `CandidateTable` link href `/instruments/NVDA#ai-company-card`.
- `.\init.ps1` — exit 0 (Laravel, SPA lint/build/tests, engine).
- E2E: no persistent E2E harness exists; API flows are covered by feature tests and UI by Vitest, as for `pronostico-jev`.
- Optional manual (needs a real key, never in CI): enable locally, generate one card in each language and check timing stays under the timeout.

## Evidence To Capture

- Focused test counts and `init.ps1` totals; names of the fallback, rights-flag, cap and concurrency tests.
- Screenshot or DOM snapshot of the panel in `es` and `en` (ready, stale, guest).
- Confirmation that no paid provider call was made in tests.

## Risks

- **Hallucinated or stale figures** (main risk): an LLM without retrieval will invent or misdate ratios. Mitigated by `null` instructions, per-metric period, labels and validation; real fix is the follow-up verified fundamentals provider filling `metrics`.
- **Advice-like output:** the schema has no target/rating fields, but free text can still drift; prompt forbids it and the stance is rendered as a scenario. Not machine-checked.
- **Provider variance:** not every "OpenAI-compatible" server supports `json_schema`, `strict`, `refusal` or `max_tokens` vs `max_completion_tokens`; covered by config knobs and the single fallback.
- **Latency:** synchronous call up to 40 s blocks a PHP worker; nginx default 60 s. If the chosen model is slower, a queued job + polling is the escalation.
- **Cost:** bounded by caps and reuse; numbers depend on the chosen model.
- **Legal/rights:** sending market-derived context needs the same rights confirmation as Jev; provider data-processing terms must be checked before enabling.

## Open Questions For The User

1. Which provider and model (and therefore the monthly budget)? Defaults assume a mid-size model with `json_schema` support.
2. Keep Registered Users only, or also Visitors reading already generated cards (no generation)?
3. Freshness window (7 days) and caps (10 per user per day, 200 global per day) acceptable?
4. Confirm that market-derived technical context may be sent to the chosen provider (`AI_CARD_MARKET_DATA_RIGHTS_VERIFIED`).

## Validator Checklist

- [ ] Implementation stays within this feature's scope (no chat, news, targets, ratings, alerts, admin UI).
- [ ] Acceptance scenarios 1–8 pass; every provider test uses `Http::fake`.
- [ ] Invalid provider output is never stored or shown; no prompt/response content or key in logs or tables.
- [ ] Only identity (+ technical context when rights verified) leaves the server.
- [ ] Spanish and English catalogs complete; disclaimers visible; no EOD wording in public UI.
- [ ] New tables classified in `CopySqliteToPgsql`; schema portable SQLite/PostgreSQL.
- [ ] Durable docs (AGENTS, CONSTRAINTS, CONTEXT, ARCHITECTURE, domain model, access model, DESIGN, legal) updated as specified.
- [ ] `feature_list.json` and `PROGRESS.md` updated with evidence; `.\init.ps1` passes.
