# Feature Implementation Spec: Natural-language Screener (AI to filters)

## Source Feature

- `id`: `ai-screener-natural-language`
- `area`: `screener`
- `depends_on`: `screener-filters-ui`, `screener-precomputed`, `ai-company-card`, `premium-panels-auth-only` (all `accepted`)
- `status`: `passing`
- `source`: `feature_list.json`

## Goal

A Registered User describes in Spanish or English what they are looking for, for example «acciones con RSI por debajo de 30 por encima de la SMA200 y volumen relativo alto». Chartiko turns it into the **existing** Screener filters and applies them. The AI only fills a strict, enumerated filter schema: it never sees the Candidate list or user data, and never answers in free form beyond a short explanation. The result is ordinary URL filter state, so the user can adjust it, share the link or save it as a Saved Screener as usual. Parts of the request that the filters cannot express are listed back to the user instead of being silently dropped.

## Non-Goals

- No new Screener filters (sector, market cap, price, fundamentals, dates). Requests for them go to `unsupported`. A sector filter is a separate feature.
- No chat, conversation memory or follow-up refinement turns. Each query stands alone.
- No AI ranking, commentary or recommendations about specific stocks, and no buy/sell advice.
- No Visitor access, no streaming, and no change to the public `GET /api/screener` contract.
- No new AI provider: it reuses the DevExpert Inference configuration of the AI company card.

## Job Story

When I know what setup I want but not which filter combination expresses it,
I want to type it in my own words,
so I can get the right Candidate list in one step and learn the filters by seeing how my request was translated.

## Users And Permissions

- **Registered User or Admin**: may call `POST /api/screener/interpret`, under per-user and global daily caps.
- **Visitor**: the endpoint returns `401`. The SPA shows the input as a sign-in prompt, with the same pattern as the premium panels and no flash while auth is loading.
- The endpoint is in the `auth:sanctum` group. No endpoint accepts a `user_id`.

## Domain Decisions

1. **Endpoint.** `POST /api/screener/interpret` with `{query: string 3..300, locale: es|en}` (the locale defaults to the app locale) and throttle `throttle:{ai_screener.requests_per_minute},1,ai-screener`. Response `200`:
   ```json
   {"filters": {"signal": [], "rsi_min": null, "rsi_max": 30, "min_rvol": 1.5, "price_above_sma200": true,
                "ma_cross": null, "pattern": [], "pattern_status": "any", "sort": "rvol_desc"},
    "unsupported": ["sector tecnológico"], "explanation": "RSI ≤ 30, precio sobre SMA200, RVOL ≥ 1,5."}
   ```
   `filters` uses the **public API parameter names** of `GET /api/screener`. Errors use `{code, message}` with locale-independent codes, mirroring `CompanyCardUnavailable`:
   - `user_daily_limit` returns 429;
   - `daily_limit_reached` returns 503;
   - `provider_not_configured` returns 503;
   - `provider_unavailable` returns 503;
   - `invalid_provider_response` returns 503;
   - input validation returns 422.
2. **One filter vocabulary.** Extract the private filter resolution from `ScreenerController` into `App\Services\Screener\ScreenerFilterParser::fromArray(array): array`, with the same rules, messages and `ValidationException`s. Both the controller (from the query string) and the interpreter (from AI output) use it. Responses of `GET /api/screener` must not change, and `ScreenerApiTest` is the regression net. Enum lists such as `SIGNAL_TYPES`, `SORTS`, `PATTERN_STATUSES` and `ChartPattern::TYPES` move to or are exposed by the parser, so the AI schema is built from the same constants.
3. **Strict schema** (`App\Services\Screener\Ai\ScreenerQuerySchema`, version `screener-query-v1`). Every field is required, and nullability is expressed by type unions, as strict `json_schema` demands:
   - `signal`: array of the signal enum, max 9;
   - `rsi_min` and `rsi_max`: number 0..100 or null;
   - `min_rvol`: number 0..20 or null;
   - `price_above_sma200`: boolean;
   - `ma_cross`: bullish/bearish or null;
   - `pattern`: array of the pattern enum, max 4;
   - `pattern_status`: enum;
   - `sort`: enum;
   - `unsupported`: array of strings, max 5 items of 120 chars;
   - `explanation`: string, max 300 chars.

   The PHP validator rejects anything outside the schema (`invalid_provider_response`). It then runs `ScreenerFilterParser`; a validation failure there is also `invalid_provider_response`. `unsupported` and `explanation` are trimmed and control characters removed. They are rendered as plain text, never HTML.
4. **Prompt** (`ScreenerQueryPrompt`, version `screener-query-prompt-v1`). The system message lists each filter with its meaning in the request locale, taken from the existing UI and manual vocabulary (RSI, RVOL, SMA200, golden/death cross, MA alignment, MACD, pivot breakout, the four patterns, forming/confirmed). It defines numeric conventions: «sobrevendido» means `rsi_max` 30, «sobrecomprado» means `rsi_min` 70, «volumen alto» means `min_rvol` 1.5, unless the user gives numbers. It instructs the model to put everything else in `unsupported`, to never invent filters and to write `explanation` in the locale. The user's text goes **only** in the user message, so the output cannot escape the enumerated schema. Temperature is 0.
5. **Provider configuration.** `config/ai_screener.php`, read through `new OpenAiCompatibleClient('ai_screener')`:
   - shares the provider settings `base_url`, `api_key` and `model` (the same `AI_*` env vars);
   - adds its own `enabled` flag (`AI_SCREENER_ENABLED`, default false);
   - `timeout_seconds` 20, `max_tokens` 800, `temperature` 0, and the same `response_format` and fallback settings;
   - `requests_per_minute` 6, `user_daily_limit` 30, `daily_limit` 600, `log_retention_days` 90, plus the schema and prompt versions.

   `deploy/configure-ai-env.py` adds `AI_SCREENER_ENABLED=true` to `SETTINGS`.
6. **Cache.** Identical requests reuse the result for 24 h: the key is `sha256(normalized query + locale + schema and prompt versions)` in the default cache store. A cache hit does not count against the caps and is not sent to the provider. The cached value holds only the validated response. The user text is never a readable key.
7. **Usage log and privacy.** New table `ai_screener_queries` with columns `id, user_id (nullOnDelete), locale, query_length, outcome (ok|cached|invalid|unavailable|limited), unsupported_count, prompt_tokens, completion_tokens, latency_ms, model, schema_version, prompt_version, created_at`. **The query text is never stored or logged**, because it is free text that may contain personal data. The model is `MassPrunable` after `log_retention_days` and is added to `model:prune`. Caps count rows with outcome `ok` or `invalid` from the last 24 h.
8. **Privacy text.** The «Funciones de IA» paragraph in `frontend/src/i18n/legal/{es,en}.ts` adds that a signed-in user's free-text Screener request is sent to DevExpert Inference to be translated into filters, that it is not stored by Chartiko, and that users should not include personal data. The input hint says the same. Bump `config/legal.php` `updated_at` and run `web-legal-compliance-eu`. If `account-self-service` lands first, `ai_screener_queries` rows (metadata only) join its export; otherwise that feature picks them up.

## Acceptance Scenarios

### Scenario 1: Happy path
Given a signed-in user and a faked provider returning valid output,
when they submit «sobrevendidas por encima de la SMA200 con volumen alto» in Spanish,
then the endpoint returns filters `rsi_max: 30, price_above_sma200: true, min_rvol: 1.5`.
The SPA applies them to the URL (page reset to 1), the Candidate list reloads, and the explanation is shown with an «Deshacer» action that restores the previous URL filters.

### Scenario 2: Unsupported parts
Given a request that mentions a sector or a price,
then those parts appear in `unsupported` and are listed under the input, and only supported filters are applied.

### Scenario 3: Invalid AI output
Given provider output with an unknown signal, an RSI of 150, an extra key or non-JSON,
then the response is `503 invalid_provider_response`, no filters change in the SPA, and a log row with outcome `invalid` exists.

### Scenario 4: Limits and configuration
- A 31st non-cached request in 24 h returns `429 user_daily_limit`.
- When the global cap is reached, the response is `503 daily_limit_reached`.
- With `AI_SCREENER_ENABLED=false` or a missing key, the response is `503 provider_not_configured`, and the SPA hides the input for signed-in users with a short notice.
- A repeated identical query is served from cache without a provider call (`Http::assertSentCount`).

### Scenario 5: Access and privacy
- A Visitor gets `401`. A request with `query` shorter than 3 or longer than 300 characters gets `422`.
- No log line, DB row or cache key contains the query text (asserted with a unique marker string).
- The Visitor UI shows a sign-in prompt, not the input.

### Scenario 6: REST unchanged
After the parser extraction, `ScreenerApiTest` passes unchanged and `GET /api/screener` returns the same payload for a fixed set of query strings.

### Scenario 7: SPA
The input sits above the filter panel with two or three clickable example prompts in the locale. A submission shows a busy state and disables the button. The result can be saved with the existing Saved Screener flow. It works at 1280 and 390 px in es and en, with no layout shift of the Candidate table.

## Repository Research

### Files Inspected
- `app/Http/Controllers/ScreenerController.php`: private resolvers for `signal`, `rsi_min`/`rsi_max`, `min_rvol`, `price_above_sma200`, `ma_cross`, `pattern`, `pattern_status` and `sort`; enum constants; `422` messages in `messages.screener.*`.
- `app/Models/ChartPattern.php`: `TYPES`, `STATUSES`.
- `app/Services/Ai/{OpenAiCompatibleClient,StructuredCompletion}.php`: the constructor takes a config key; `structured()` handles `json_schema` and the `json_object` fallback; it returns usage and latency.
- `app/Services/CompanyCard/{CompanyCardService,CompanyCardSchema,CompanyCardUnavailable}.php`, `app/Models/AiCompanyCardGeneration.php`, `config/ai_card.php`: caps, error codes, prunable log, PHP schema validation.
- `deploy/configure-ai-env.py`: `SETTINGS` mapping, written on every deploy.
- `routes/api.php`: the `auth:sanctum` group and the throttle convention.
- `frontend/src/pages/ScreenerPage.tsx` (`update`, `applySavedFilters`, `resetScreenerPage`), `lib/screenerFilters.ts` (`parseScreenerFilters`, `patchScreenerFilters`), `lib/api.ts` (`ScreenerFilters`), `components/screener/*`, `components/chart/CompanyCardPanel.tsx` (auth-only panel pattern), `i18n/legal/es.ts` («Funciones de IA»).

### Existing Patterns To Follow
- AI calls only server-side, with the key in the env, opt-in flags, versioned schema and prompt, and caps counted from a prunable log.
- The SPA filter state lives in the URL. `applySavedFilters` already applies a `Partial<ScreenerFilters>` patch, and the interpreter result maps onto it (API names to SPA names).
- Offline tests with `Http::fake` and es/en parity tests.

### Current Gaps
- The filter parsing is private to the controller, and there is no screener AI service, config, log table or UI input.

## Technical Approach

1. Extract `ScreenerFilterParser`, thin the controller, and keep `ScreenerApiTest` green before anything else.
2. Add `config/ai_screener.php`, `ScreenerQuerySchema`, `ScreenerQueryPrompt`, `ScreenerQueryInterpreter`, `ScreenerQueryUnavailable`, the `AiScreenerQuery` model with its migration, and `ScreenerInterpretController`. The interpreter does: cache lookup, caps, provider call, schema validation, parser validation, log and cache store.
3. In the SPA, add a `ScreenerAiQuery` component plus `screenerApi.interpret()` and a mapper from API to `ScreenerFilters`, then wire it into `ScreenerPage` through the existing patch path. Undo keeps the previous `URLSearchParams` in component state.
4. Privacy text, deploy setting and docs.

## Expected File Changes
- `app/Services/Screener/ScreenerFilterParser.php` and `app/Services/Screener/Ai/{ScreenerQuerySchema,ScreenerQueryPrompt,ScreenerQueryInterpreter,ScreenerQueryUnavailable}.php`: create.
- `app/Http/Controllers/ScreenerInterpretController.php`, `app/Models/AiScreenerQuery.php`, the migration and `config/ai_screener.php`: create.
- `app/Http/Controllers/ScreenerController.php` (thin), `routes/api.php`, `routes/console.php` (prune), `lang/{es,en}/*.php`, `deploy/configure-ai-env.py`, `config/legal.php`: modify.
- `tests/Feature/ScreenerInterpretTest.php` and `tests/Unit/ScreenerQuerySchemaTest.php`: create. `ScreenerApiTest` is unchanged.
- `frontend/src/components/screener/ScreenerAiQuery.tsx` (+ test), `pages/ScreenerPage.tsx`, `lib/api.ts`, `lib/screenerFilters.ts`, `i18n/{es,en}.ts`, `i18n/legal/{es,en}.ts`: modify or create.

## Visual Design Impact
- UI involved: yes. A prompt input with examples, a busy state, the explanation, the unsupported list, undo, a sign-in prompt for Visitors and a not-configured notice.
- Design source: `DESIGN.md`, the existing filter panel and `CompanyCardPanel`. No new design artifact.

## Durable Documentation Impact
- `ARCHITECTURE.md`: update, adding the interpreter flow and the shared `ScreenerFilterParser`.
- `CONSTRAINTS.md`: update. AI output only fills the enumerated filter schema and is validated by the same parser as the public API. The query text is never stored or logged. Schema and prompt versions are bumped on change.
- `AGENTS.md`: not needed.
- `docs/llamadas-api-jev-ia.md`: update with a third section (request, schema, parameters). `docs/ai-company-card-operations.md`: update with the shared provider and the new flag. `docs/risks-and-open-questions.md`: update with prompt injection (bounded by the schema) and cost caps. `docs/user-and-access-model.md`: update with the auth-only screener input.

## Implementation Plan
1. Baseline `.\init.ps1`.
2. Parser extraction, then the existing tests green.
3. Config, schema, prompt, interpreter, log and controller, with the feature and unit tests (Scenarios 1 to 6).
4. SPA component, wiring and Vitest tests.
5. Privacy text and the deploy setting; run the `web-legal-compliance-eu` check.
6. Real provider smoke locally with the key from the environment, never committed: 10 sample queries in es/en with their filters recorded. Then Playwright + Edge QA.
7. Docs, `.\init.ps1`, PR with CI on SQLite and PostgreSQL; post-deploy smoke on production while signed in.

## Implementation Tasks
- [ ] `ScreenerFilterParser` and a thin `ScreenerController`.
- [ ] `config/ai_screener.php` and `AI_SCREENER_ENABLED` in `configure-ai-env.py`.
- [ ] Schema with PHP validator, and prompt with its versions.
- [ ] Interpreter with cache, caps, log, and error codes.
- [ ] `AiScreenerQuery` migration, model and prune registration.
- [ ] Route, throttle and controller, with the tests.
- [ ] `ScreenerAiQuery` UI with examples, undo, unsupported list and states; catalogs.
- [ ] Privacy text, `updated_at` and docs.

## Verification Plan
- `php artisan test` (new suites plus the unchanged `ScreenerApiTest`); CI `test` and `test-pgsql`.
- Vitest: mapper from API to SPA filters, component states, undo, and the Visitor prompt; i18n parity.
- `.\init.ps1` exit 0. It is not changed and starts no server.
- Manual real-provider smoke (Decision 4 conventions hold for the 10 sample queries), then Playwright + Edge at 1280 and 390 px, es and en.

## Evidence To Capture
- Test counts and CI run ids.
- The smoke table (query, filters, unsupported) without any key.
- Proof that no query text was persisted: a DB, log and cache grep for the marker.
- Screenshots or snapshots of the states.

## Validator Checklist
- [ ] `GET /api/screener` is unchanged; one parser serves both paths.
- [ ] AI output is strictly validated; anything invalid gives `invalid_provider_response`, with no partial application.
- [ ] The endpoint is auth-only, throttled and capped; the cache bypasses the caps and the provider.
- [ ] The query text is never stored, logged or used as a readable cache key.
- [ ] `unsupported` is surfaced; explanation and unsupported text are rendered as plain text.
- [ ] Privacy text and the deploy flag are updated; docs are updated; `init.ps1` and both CI jobs are green.

## Key Implementation Risks
- **Model quality on the `chat` model.** Mitigated by temperature 0, an enumerated schema, explicit numeric conventions and the smoke table. If quality is poor, tune the prompt (with a version bump), not the schema.
- **Some providers reject strict `json_schema`.** The existing `json_object` fallback plus PHP validation covers it.
- **Prompt injection.** Its worst case is a valid but odd filter set: there are no tools, no data access and no HTML rendering.
- **Refactor risk** in the controller. Do the extraction first, gated by the existing tests.

## Implementation Findings (2026-10-19)

- **Order kept.** `ScreenerFilterParser::fromArray()` was extracted first; `ScreenerApiTest` (24 tests) passed unchanged before anything else was added. The controller passes `$request->query()`; the AI path passes the validated answer.
- **Container pitfall.** `ScreenerQueryInterpreter` builds `new OpenAiCompatibleClient('ai_screener')` itself. An injected nullable client would be resolved by the container as the default `ai_card` client.
- **Schema keywords.** Ranges and list sizes are described in the schema text and enforced in PHP, not with `minimum`/`maxItems`, which some OpenAI-compatible servers reject in strict mode (same choice as `company-card-v2`). Strict types: a number or boolean sent as a string is `invalid_provider_response`. Text is never cut: a too-long `unsupported` item or `explanation` is rejected.
- **Signal definitions** in the prompt follow the manual: alignment is SMA20/SMA50/SMA200 (no close), RSI overbought/oversold are states (>= 70, <= 30), RVOL is volume over the previous 50-session average, `ma_cross` is the SMA50/SMA200 state, not a fresh cross.
- **Logging.** Outcomes `ok`, `cached`, `invalid`, `unavailable`, `limited` are logged; `provider_not_configured` and input `422` write no row (no provider work). Caps count only `ok`/`invalid` rows of the last 24 h (rolling, not UTC day). Follow-up 2026-10-19: the caps are checked and a `pending` slot is reserved under the `ai-screener:caps` lock before the provider call (then updated to the outcome), so two concurrent requests cannot overshoot a cap.
- **Not-configured UX.** There is no availability endpoint: the SPA learns it from the first `503 provider_not_configured`, then hides the input with a notice (as the company-card panel does). Adding a `GET` availability flag was judged outside the contract.
- **Account self-service** (merged after the spec): `ai_screener_queries.user_id` is nulled by FK on deletion and exported as `ai_screener_requests` (locale, query length, outcome, unsupported count, date). The coverage allowlist, the export test and the deletion test include it; the Account page export text mentions AI searches.
- **Legal.** `GET /api/legal` adds `retention.ai_screener_log_days` (placeholder `{aiScreenerLogDays}`). Besides the AI features paragraph, the "Recipients" section now names DevExpert Inference for the free text. `config/legal.php` `updated_at` already was 2026-10-19 (today), so it did not change. Owner/legal review pending: DevExpert's processor terms (DPA, location, retention, training use) for free text that may contain personal data.
- **Scope docs.** `CONSTRAINTS.md` banned any free-text AI surface; it now carves out this feature only, and `AGENTS.md` gets an "in scope since 2026-10-19" line (the spec said AGENTS was not needed, but its scope guard listed free-text AI as out of scope).
- **Real-provider smoke not run locally** (no key in this environment, by owner instruction). Browser QA used a local stub provider (outside the repo). The orchestrator runs the smoke below in production after deploy.

### Production smoke: 10 sample queries and expected filters

Expected values follow the prompt conventions (Decision 4). Filters not listed are empty/default (`signal []`, numbers `null`, `price_above_sma200 false`, `ma_cross null`, `pattern []`, `pattern_status any`, `sort rvol_desc`).

| # | Locale | Query | Expected filters | Expected `unsupported` |
|---|---|---|---|---|
| 1 | es | sobrevendidas por encima de la SMA200 con volumen alto | `rsi_max 30`, `price_above_sma200 true`, `min_rvol 1.5` | none |
| 2 | es | acciones tecnológicas con cruce dorado | `signal [golden_cross]` | sector tecnológico |
| 3 | es | sobrecompradas ordenadas por mayor subida del día | `rsi_min 70`, `sort change_desc` | none |
| 4 | es | doble suelo confirmado con RSI entre 40 y 60 | `pattern [double_bottom]`, `pattern_status confirmed`, `rsi_min 40`, `rsi_max 60` | none |
| 5 | es | MACD alcista con volumen muy alto y precio menor de 50 dólares | `signal [macd_bullish_cross]`, `min_rvol 2` | precio menor de 50 dólares |
| 6 | en | oversold stocks above the 200-day average with high volume | `rsi_max 30`, `price_above_sma200 true`, `min_rvol 1.5` | none |
| 7 | en | bull flags still forming, most signals first | `pattern [bull_flag]`, `pattern_status forming`, `sort signal_count_desc` | none |
| 8 | en | death cross or bearish MA alignment, lowest RSI first | `signal [death_cross, ma_alignment_bearish]`, `sort rsi_asc` | none |
| 9 | en | pivot breakouts in banks with P/E under 15 | `signal [pivot_breakout_rvol]` | banks sector; P/E under 15 |
| 10 | en | SMA50 above SMA200, RSI above 55, cup with handle | `ma_cross bullish`, `rsi_min 55`, `pattern [cup_with_handle]` | none |

Pass criteria: every answer is `200` (no `invalid_provider_response`), the filters match (wording of `unsupported` and `explanation` may vary, but `explanation` is in the request language), and a repeat of query 1 is served from cache (a `cached` row, no provider call). Record the table with the actual output, never the key.
