# Feature Implementation Spec: Sector and regime context on the chart, and Screener sector filters

## Source Feature

- `id`: `market-context-patterns`
- `area`: `frontend` (plus its Laravel endpoint, Screener filters, access ability and the access change of `/mercado`)
- `depends_on`: `market-context-ui` (`accepted`), `chart-patterns-ui` (`accepted`)
- `status`: `not_started` at planning time (2026-10-23)
- `source`: `feature_list.json`; product decisions in `docs/specs/market-context.md` → "Domain Decisions (shared)" and "Feature 5". This spec is the implementation contract and wins where it is more precise. The umbrella and `docs/specs/market-context-ui.md` were narrowly corrected on 2026-10-23 to match it (see "Resolved Ambiguities").

## Goal

On a stock's chart page a Registered User sees, in one line, its sector ETF, the sector's trend, the sector's relative-strength rank and the Market Regime, with a link to `/mercado`. The Chartist Pattern panel repeats the same chips next to each pattern. The Screener gets two filters, "sector trend" and "top N sectors by relative strength", which work in Saved Screeners, Saved Screener alerts and the natural-language Screener.

**Owner decision 2026-10-23 (verbatim):** "quiero que la parte de mercado solo este accesible a usuarios registrados, a futuro quiero establecer algunas de las funcionalidades de pago". Every Market Context surface, including the existing `/mercado` page and `GET /api/market-context`, is for Registered Users only. The check is one server-side ability, `view-market-context`, so a future paid plan changes one place. `MARKET_CONTEXT_PUBLIC` and owner option (b) still apply on top: while the flag is off only Admins see any of it.

Everything stays descriptive: no forecast, target, probability or advice wording, and no EOD wording outside Admin.

## Non-Goals

- No paid plans, plan field, billing or "upgrade" UI (AGENTS.md scope). Only the seam (C1).
- No context at the pattern's detection date (umbrella "Later"). Chips use the latest snapshot.
- No filter by sector name or industry, and no new sector or regime data. No change to `market-context:compute`, `market-regime:compute`, rules or tables other than `screener_rows`.
- No new fields in the public Candidate JSON or in `GET /api/instruments/{ticker}`.
- No regime filter in the Screener: the regime is one value for the whole market and partly derived from FRED.
- No change to the `MARKET_CONTEXT_PUBLIC` default, the legal texts or the Admin tab.

## Job Story

When I look at a stock, a pattern or a Screener list,
I want to see whether its sector and the whole market are with it or against it, and filter by strong sectors,
so I can judge the setup in its context with rules I can read.

## Users And Permissions

`allowed` = the `view-market-context` ability (C1).

| Actor | `MARKET_CONTEXT_PUBLIC=false` (default, today) | `true` (after the legal notice is published) |
| --- | --- | --- |
| Visitor | Sees nothing of Market Context: no header link, no strip, no filter group. `/mercado` shows "Esta página aún no está disponible". APIs → 401. Sector filters on `GET /api/screener` → 401 | Header link, then a sign-in prompt on `/mercado`, on the chart (strip) and in the filter panel. APIs → 401. Sector filters → 401 |
| Registered User | Nothing visible (as today). APIs → 404 `market_context.not_available`. Sector filters → 403 | Everything: `/mercado` with history, strip, pattern chips, filters, Saved Screeners, alerts, NL |
| Admin | Everything, as a preview ("Vista previa" banner/chip) | Everything |

The ability check is enforced server-side. Hiding UI is not access control.

## Acceptance Scenarios

Fixture "context" (Laravel and Vitest; today 2026-10-23 New York): `market-context` universe with SPY, XLK, XLE and XLU; sector rows on 2026-10-22: XLK (Information Technology, `uptrend`, rs_rank 1), XLE (Energy, `downtrend`, rs_rank 10) and XLU (Utilities, `mixed`, rs_rank `null`, rs_60 `null`), plus 8 more rows for rank_of 10. Stocks AAPL (Information Technology), XOM (Energy), NEE (Utilities), ZZZ (sector `null`) and ODD (sector `Conglomerates`), all with bars and snapshots on 2026-10-22. Regime row 2026-10-22 `neutral`, score 1.

### Scenario 1: Chart strip for a Registered User (flag on)
Given the fixture, `MARKET_CONTEXT_PUBLIC=true` and a signed-in user
When they open `/instruments/AAPL`
Then a "Contexto de mercado" strip shows "Datos del 22 oct 2026", "Sector: Tecnología de la información (XLK)" (XLK links to `/instruments/XLK`), "▲ Alcista", "Fuerza relativa 1/10", "Entorno: ● Neutral", a "Ver contexto de mercado" link to `/mercado`, the disclaimer and the FRED notice. Each active pattern in "Patrones activos" carries the same three chips, and one note says the context is from the latest session, not the pattern's date.

### Scenario 2: Visitors and gated users
Given flag on and a Visitor on `/instruments/AAPL`
Then the strip is a dashed sign-in box, no `/market-context` request is made, and the pattern panel has no chips.
Given flag off: a Visitor and a Registered User see no strip and no request. An Admin sees the strip with a "Vista previa" chip.
And `GET /api/instruments/AAPL/market-context` is 401 for guests in both modes, 404 for users with the flag off, and 200 for users with the flag on and for Admins.

### Scenario 3: Edge stocks and ETFs
Then ZZZ and ODD show "Sector: sin ETF sectorial asignado" with the regime still shown. NEE shows "◆ Mixta" and "Fuerza relativa: sin datos". `/instruments/XLK` shows no strip, makes no request, and its endpoint returns 404.

### Scenario 4: Screener filters
Given a signed-in user with the flag on
When they request `GET /api/screener?sector_trend=uptrend` → AAPL only. `?sector_rs_rank_max=1` → AAPL. `?sector_rs_rank_max=11` → AAPL and XOM (NEE has a null rank, ZZZ and ODD have no sector). `?sector_trend=mixed&sector_rs_rank_max=11` → none. The Candidate JSON keys are unchanged.
And `sector_trend=sideways`, `sector_trend[]=uptrend`, `sector_rs_rank_max=0|12|3.5|abc` → 422 on that key, for any caller (validation runs before access).
And a valid sector filter is 401 for a guest, 403 for a user with the flag off, and 200 for an Admin.

### Scenario 5: Stale sector snapshot
Given the latest sector rows are dated 2026-10-14 and AAPL's latest bar is 2026-10-22 (8 days)
Then after `screener:refresh` AAPL's `sector_trend`/`sector_rs_rank` are null and the filters do not match it. At 7 days they are filled. The strip shows the values with the "Desactualizado" badge (stale against today, as `/mercado` does).

### Scenario 6: Saved Screener, alert and NL round-trip
Given an allowed user saves `{…, sector_trend: "uptrend", sector_rs_rank_max: 3}`
Then `GET /api/screeners` returns the 11-key object, and applying it restores both URL params. An older row returns both keys as `null`. The Saved Screener alert evaluates with the filters. With the flag turned off again, that alert is `skipped` for the non-Admin owner (no notification, state untouched). The NL Screener maps "acciones de los 3 sectores más fuertes en tendencia alcista" to `sector_rs_rank_max: 3, sector_trend: "uptrend"`.

### Scenario 7: `/mercado` is Registered-only
Given flag on
Then a Visitor on `/mercado` sees the page title and a sign-in box, and makes no `/api/market-context` request. `GET /api/market-context` returns 401 for guests and 200 for users. With the flag off: a Visitor sees "Esta página aún no está disponible" with no request, and a user gets the API 404, as today.

## Repository Research

### Files Inspected

- Specs: `docs/specs/market-context.md`, `market-context-ui.md`, `market-regime.md` (format), `ai-screener-natural-language.md` (grep), `chart-patterns-ui.md` (feature entry).
- Laravel: `routes/api.php`; `app/Http/Controllers/{InstrumentController,MarketContextController,ScreenerController,SavedScreenerController,ScreenerInterpretController,CompanyCardController,Auth/AuthController}.php`; `app/Http/Middleware/EnsureMarketContextPublic.php`; `app/Services/MarketContext/{MarketContextPayload,MarketContextCache,MarketContextComputer}.php`; `app/Services/Screener/{ScreenerFilterParser,CandidateQuery,ScreenerRowBuilder}.php`; `app/Services/Screener/Ai/{ScreenerQuerySchema,ScreenerQueryPrompt,ScreenerQueryInterpreter}.php`; `app/Services/Alerts/AlertEvaluator.php`; `app/Console/Commands/RunIngestionPipeline.php`; models `Instrument`, `User`, `ScreenerRow`, `MarketSectorSnapshot`, `MarketRegimeSnapshot`; migrations `create_screener_rows_table`, `create_market_sector_snapshots_table`; `config/{market_context,ai_screener}.php`; `lang/es/market_context.php`; `app/Providers/AppServiceProvider.php`; `database/data/sp500.csv` (11 GICS sectors, all mapped to an ETF).
- SPA: `pages/{InstrumentChartPage,MarketPage,ScreenerPage}.tsx`, `components/chart/PatternPanel.tsx`, `components/screener/ScreenerFilterPanel.tsx`, `components/market/{RegimeCard,SectorTable,marketText}.tsx|ts`, `components/AppHeader.tsx`, `nav.ts`, `auth/{context,useAuth}.ts`, `legal/useLegalInfo.ts`, `lib/{api,screenerFilters}.ts`, `i18n/guide/es.ts` (sections `filtros`, `mercado`), `i18n/messages/es.ts` (`marketContext.*`); test names in `MarketPage.test.tsx`, `AppHeader.test.tsx`, `HistoryPanel.test.tsx`, `MarketContextApiTest.php`, `MarketContextHistoryTest.php`.
- Docs: `AGENTS.md`, `PROGRESS.md`, `CONSTRAINTS.md` (Market Context, Authorization, Saved Screeners, NL Screener, Screener Rows, Public API).

### Existing Patterns To Follow

- Pipeline order: `patterns:detect` → Market Context block (`market-context:compute`) → `macro:refresh` → `market-regime:compute` → `screener:refresh` → `alerts:evaluate`. So `screener:refresh` already reads the day's sector snapshot.
- ETF exclusion: `Instrument::stocks()`; stock-only endpoints answer 404 for ETFs (`CompanyCardController` looks up with `stocks()`).
- Filter vocabulary: `ScreenerFilterParser` (422 per key, `__('messages.screener.*')`) is shared by the API and the NL schema. `CandidateQuery::fromSaved` is lenient. `SavedScreenerController` uses `array:` whitelist plus `sometimes` for later keys and returns a canonical object.
- Cache: `MarketContextCache` uses a version token bumped by the three commands, plus day keys. Values are plain arrays with no user data.
- SPA: auth-only panels render nothing while auth loads, a dashed sign-in box for Visitors (`ScreenerAiQuery`, `HistoryPanel`), `TrendChip` (`SectorTable.tsx`), regime icon and label (`RegimeCard.tsx` `LABEL_STYLE`), `formatMarketDate`, `translate.test.ts` wording guards.

### Current Gaps

- No access ability: today's gate (`market-context.public`) is a release switch that lets guests in when the flag is on. No capability in the user payload.
- No instrument-level context endpoint, no `screener_rows` sector columns, no sector filters anywhere.
- No E2E harness (`frontend/package.json` has no `test:e2e`). The dev DB is empty: never seed, restore or overwrite it.

## Contract

### C1. Access ability `view-market-context` (the single seam)

- Defined once in `AppServiceProvider::boot()` (or `App\Policies\MarketContextAccess` registered there): `Gate::define('view-market-context', fn (User $user): Response => …)`:
  - `Response::allow()` when `$user->isAdmin()` or `config('market_context.public')`;
  - otherwise `Response::denyAsNotFound(__('market_context.not_available'))`.
  - Guests never pass, because the user parameter is not nullable (Laravel denies guests automatically).
- **Paid-plan seam:** a future plan replaces only this closure (for example `|| $user->hasPlan('pro')`, denying a non-subscriber with `Response::denyWithStatus(403, …)`). Every check below calls the ability, never `isAdmin()` or the flag directly. Document this in `CONSTRAINTS.md` and `docs/user-and-access-model.md`.
- Consumers:
  1. routes (`can:view-market-context`);
  2. `ScreenerController`, `SavedScreenerController` and `ScreenerInterpretController` via `Gate::forUser($user)->allows(…)`;
  3. `AlertEvaluator`;
  4. the user payload capability.
- **Capability flag:** `App\Support\AuthUserPayload::make(User $user): array` returns `$user->toArray() + ['capabilities' => ['market_context' => Gate::forUser($user)->allows('view-market-context')]]`. It is used by `POST /api/register`, `POST /api/login`, `GET /api/user` and `PATCH /api/account`. The account export is unchanged.
- Remove `EnsureMarketContextPublic`, its `market-context.public` alias (`bootstrap/app.php`) and its CONSTRAINTS exception. `GET /api/legal` keeps `market_context` (= the flag) for the legal texts and the Visitor prompts.

### C2. Routes (all in the existing `auth:sanctum` group; guest → 401 JSON)

| Route | Middleware after `auth:sanctum` | Change |
| --- | --- | --- |
| `GET /api/market-context` | `can:view-market-context`, `throttle:60,1,market-context` | moved from public; body unchanged (C1 of `market-context-ui`) |
| `GET /api/market-context/history` | `can:view-market-context`, `throttle:60,1,market-context-history` | gate swapped, behaviour unchanged |
| `GET /api/instruments/{ticker}/market-context` | `can:view-market-context`, `throttle:60,1,instrument-market-context` | new (C3) |

A denied user gets 404 `{"message": __('market_context.not_available')}` (from `denyAsNotFound`). The test must assert the JSON body, not only the status.

### C3. `GET /api/instruments/{ticker}/market-context`

`InstrumentMarketContextController@show` (or `MarketContextController@instrument`). The ticker is `trim` + `strtoupper` and looked up with `Instrument::stocks()`. An unknown ticker or an ETF → 404 `{"message": __('messages.instrument_not_found')}`. 200 body (keys and order are the contract):

```json
{
  "ticker": "AAPL",
  "as_of": "2026-10-23",
  "sector": {"date": "2026-10-22", "stale": false, "etf": "XLK", "sector_key": "information_technology",
             "trend": "uptrend", "rs_rank": 1, "rs_rank_of": 10},
  "regime": {"date": "2026-10-22", "stale": false, "regime": "neutral"}
}
```

- `as_of` = `MarketContextPayload::today()`. `stale` = `MarketContextPayload::stale($date, $asOf)` (more than 7 days).
- `sector`:
  - Read from the rows of the global latest `market_sector_snapshots.date` (as in `MarketContextPayload::sectors`), joined to `instruments` for the ETF ticker. The row whose stored `sector` equals the stock's `instruments.sector` is used.
  - `null` when the stock's sector is `null`, when no row matches, or when the table is empty.
  - `trend` and `rs_rank` may be `null`, as stored.
  - `rs_rank_of` = number of rows with a non-null `rs_rank` on that date.
  - `sector_key` uses `MarketContextPayload::sectorKey`.
- `regime`: the latest `market_regime_snapshots` row (`date`, `stale`, `regime`, which can be `insufficient`), or `null`. No score, components or macro values.
- Cache: new `MarketContextCache::instrumentContext(string $asOf, Closure $build)`, key `market-context:{version}:instrument-context:{as_of}`. It holds `{sectors: {<GICS sector>: {date, etf, sector_key, trend, rs_rank, rs_rank_of}}, regime: {…}|null}` (plain arrays, shared by every caller). The controller picks the stock's entry. The existing version bumps invalidate it.

### C4. `screener_rows` columns and refresh

- Migration `2026_10_25_000001_add_sector_context_to_screener_rows_table.php`: `sector_trend` `string(10)` nullable, `sector_rs_rank` `unsignedTinyInteger` nullable, each indexed. Non-destructive `down()` drops them. Do not run `migrate:fresh`, `migrate:rollback` or `--env=testing`; `php artisan migrate` on the dev DB is fine.
- `ScreenerRowBuilder::refresh()`:
  - Loads once the rows of the latest sector date `S` (normalised `substr(…, 0, 10)`), keyed by stored `sector`.
  - Per stock row: `$s = $map[$instrument->sector] ?? null`.
  - Fills both columns from `$s` when `$s !== null` and `row.date − S <= 7` calendar days (`MarketRegimeRules::MARKET_STALE_AFTER_DAYS`; `S` after `row.date` counts as fresh). Otherwise both are `null`.
  - Staleness is measured against the row's own bar date, not today, so the Screener stays internally consistent and the build stays deterministic.
  - ETFs keep getting no row (unchanged). Stocks with no sector, or an unmapped one, get `null`.
- The Candidate payload (`CandidateQuery::payload`) is **unchanged**: sector context never reaches the public Candidate JSON.
- Refresh paths: the pipeline already refreshes after the compute, and so does `deploy.sh`. `RunMarketContextCommand` (Admin rerun) runs `screener:refresh` after a successful `market-context:compute`. A console `market-context:compute` by hand must be followed by `screener:refresh`: add this to `deploy/README.md` and the CONSTRAINTS Screener Rows rule.

### C5. Filters (`GET /api/screener`, one vocabulary)

`ScreenerFilterParser`:
- `SECTOR_TRENDS = ['uptrend','mixed','downtrend']` (= `MarketSectorSnapshot` constants), `SECTOR_RS_RANK_MAX = 11`, `MARKET_CONTEXT_KEYS = ['sector_trend','sector_rs_rank_max']`.
- `fromArray()` adds two keys:
  - `sector_trend`: absent or `''` → `null`; a string in the list → itself; anything else (including an array) → 422 `errors.sector_trend` = `messages.screener.sector_trend_invalid`.
  - `sector_rs_rank_max`: absent or `''` → `null`; `filter_var(..., FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 11]])` on a string or int; otherwise (`0`, `12`, `3.5`, `abc`, an array, a bool) → 422 `messages.screener.sector_rs_rank_max_invalid`.
- `usesMarketContext(array $filters): bool` returns true when either key is non-null.

`ScreenerController::index`:
1. Parse the filters (422 first).
2. If `usesMarketContext`: when `$request->user('sanctum')` is null → 401 `{"message": __('market_context.sign_in_required')}`; when the ability denies → 403 `{"message": __('market_context.filter_unavailable')}`.
3. Otherwise unchanged.

The route stays public and anonymous. This check is the documented exception to "no authorization branch" (CONSTRAINTS Public API).

`CandidateQuery::filtered`: `sector_trend` → `where('sector_trend', $v)`; `sector_rs_rank_max` → `where('sector_rs_rank', '<=', $v)`. NULL never matches. Filters combine with AND. The `Filters` phpstan type gains both keys. `fromSaved()` is lenient: a valid trend string or `null`; an int in `1..11` or `null`.

### C6. Saved Screeners and alerts

- `SavedScreenerController::store`:
  - `array:` whitelist plus `sector_trend,sector_rs_rank_max`.
  - Rules: `filters.sector_trend` `sometimes|nullable|in:uptrend,mixed,downtrend`; `filters.sector_rs_rank_max` `sometimes|nullable|integer|between:1,11`.
  - After validation, a non-null sector key with a denied ability → 403 `market_context.filter_unavailable` (nothing stored).
  - `canonicalFilters()` returns 11 keys (both default `null`) for writes and reads, so old rows load.
- `AlertEvaluator::evaluate`: for `KIND_SCREENER`, when the Saved Screener's `fromSaved` filters use market context and `Gate::forUser($alert->user)->denies('view-market-context')` → return `SKIPPED` before any state change or notification. Otherwise evaluate with the filters. `AlertController` is unchanged.

### C7. Natural-language Screener (`screener-query-v2`)

- `ScreenerQuerySchema`:
  - `NAME = 'screener_query_v2'`.
  - `FILTER_KEYS` plus both keys, required: `sector_trend` `{"type": ["string","null"], "enum": [...SECTOR_TRENDS, null]}` and `sector_rs_rank_max` `{"type": ["integer","null"]}`.
  - `validate()` accepts `null`, or an exact PHP `int` 1..11 (a float, string, 0 or 12 → `InvalidScreenerQuery`), and keeps the parser pass.
- `config/ai_screener.php`: `schema_version` `screener-query-v2`, `prompt_version` `screener-query-prompt-v2`.
- `ScreenerQueryPrompt` (es and en) adds two lines:
  - `sector_trend`: trend of the stock's sector ETF (SPDR): uptrend = close > SMA50 > SMA200, downtrend = the reverse, mixed otherwise, or null.
  - `sector_rs_rank_max`: 1 to 11, only stocks whose sector is among the N strongest of 11 by 3-month return versus the S&P 500, or null.
  - Conventions: "strong / leading sectors" means 3; "sector in uptrend" means `sector_trend` uptrend.
  - Sector **names**, industries and the market regime stay in `unsupported`.
  - No market data is sent to the provider: only the vocabulary in the prompt.
- `ScreenerInterpretController`, after `interpret()` (the cache stays shared): if `usesMarketContext($result['filters'])` and the ability denies → set both to `null` and append `__('ai_screener.market_context_unavailable')` to `unsupported` (once).

### C8. Lang (PHP, es and en, same keys)

- `market_context.sign_in_required` ("Inicia sesión para usar los filtros de contexto sectorial." / "Sign in to use the sector context filters.")
- `market_context.filter_unavailable` ("Los filtros de contexto sectorial no están disponibles para tu cuenta." / "…are not available for your account.")
- `messages.screener.sector_trend_invalid`, `messages.screener.sector_rs_rank_max_invalid` (values 1 to 11)
- `ai_screener.market_context_unavailable` ("Filtros de contexto sectorial (no disponibles para tu cuenta)")

## SPA

### Access hook (the single client seam)

`frontend/src/auth/useMarketContextAccess.ts` → `{status: 'loading'|'allowed'|'signIn'|'hidden', preview: boolean}`:

| Situation | `status` |
| --- | --- |
| auth `loading` | `loading` |
| user with `capabilities.market_context === true` | `allowed` |
| user without the capability, or with it absent | `hidden` |
| guest, `useLegalInfo` ready and `market_context === true` | `signIn` |
| guest otherwise | `hidden` |

`preview` = `allowed` and the legal info is ready with `market_context === false`. `AuthUser` gains `capabilities?: {market_context: boolean}`. No component reads `role` or the flag for Market Context anymore.

### Chart page

`InstrumentChartPage` stays browseable with no redirect. Update its header comment. For a stock (`kind !== 'etf'`) it calls `useInstrumentMarketContext(ticker, isStock)`, which fetches `instrumentMarketContextApi.get(ticker)` only when `allowed`. The result is shared by:

- **`components/chart/MarketContextStrip.tsx`**, placed between the page header and the OHLC/chart card:
  - `loading`/`hidden` → nothing;
  - `signIn` → dashed box (`marketContext.strip.signInTitle`, `signInBody`, link `/login`);
  - fetching → `role="status"` line;
  - error → `role="alert"` line plus retry (the chart is unaffected);
  - API 401 → the sign-in box; API 404 → nothing.
  - Ready: `<section aria-labelledby>` card (DESIGN tokens) with:
    - title and the date line `Datos del {sector.date ?? regime.date}`, plus `Vista previa` when `preview`;
    - items in a `flex-wrap` row: sector "Sector: {names[sector_key]} (" + `<Link to="/instruments/{etf}">` + ")" or `strip.noSector`; `TrendChip` (moved to `marketText.ts` or exported as is), prefixed by `strip.sectorTrend`; "Fuerza relativa {rank}/{of}" or `strip.rankUnknown`; "Entorno: {icon} {label}" (icon and label from `RegimeCard`'s `LABEL_STYLE`, exported) or `strip.regimeUnknown`. A regime dated differently from the sector shows its own date. Stale items get the existing "Desactualizado" chip;
    - link `strip.openMarket` → `/mercado`;
    - footer: `marketContext.legal.disclaimer` and, when `regime !== null`, `marketContext.legal.fredNotice` (`lang="en"`, verbatim). The regime is partly FRED-derived.
- **`PatternPanel`** gets an optional `context` prop (`InstrumentMarketContext | null`). When it is non-null and patterns exist, each `<li>` gets a chip row with a visually hidden prefix `patterns.contextLabel` and the same three chips (compact `font-mono text-[10px]`), plus one note under the list, `patterns.contextNote`. When it is null → today's panel.
- ETF → neither the hook request nor the strip, as today for the stock-only panels.

### Screener

- `lib/screenerFilters.ts`:
  - `ScreenerFilters` gains `sectorTrend: SectorTrend | null` and `sectorRsRankMax: number | null`.
  - URL params `sector_trend` and `sector_rs_rank_max`; invalid URL values parse to `null`.
  - Wire them into `patchScreenerFilters`, `hasActiveFilters`, `countActiveFilters`, `screenerFiltersKey`, `serializeScreenerFilters` and `deserializeScreenerFilters` (11 keys), `interpretedFiltersToPatch` and `describeSavedFilters` (`screener.sectorContext.summaryTrend` "Sector {trend}", `summaryRank` "Top {n} sectores").
  - New `withoutMarketContext(filters)`.
  - `SavedScreenerFilters` and `InterpretResponse.filters` gain the optional keys.
- `ScreenerPage`:
  - `const access = useMarketContextAccess()`;
  - `effective = access.status === 'allowed' ? filters : withoutMarketContext(filters)`.
  - When the URL carries sector keys and `access.status === 'loading'`, no request is sent until it resolves.
  - When keys were dropped, a `role="status"` notice above the results: `screener.sectorContext.droppedSignIn` with a sign-in link for `signIn`, or `droppedUnavailable` for `hidden`. The URL keeps the keys.
  - `SavedScreenersPanel` receives `effective`.
- `ScreenerFilterPanel`: a new fieldset "Contexto sectorial" after "Patrones chartistas":
  - `allowed`:
    - a segmented control `Cualquiera / ▲ Alcista / ◆ Mixta / ▼ Bajista` (`aria-pressed`, same classes as `ma_cross`, `aria-label` `sectorContext.trendAria`);
    - a `<select>` "Fuerza relativa del sector" with `Todos los sectores` and `Top {n} de 11` for n = 1..11;
    - the hint `sectorContext.hint` ("Según el ETF sectorial de cada acción y la última sesión.");
    - `Vista previa` when `preview`.
  - `signIn` → dashed sign-in box; `loading`/`hidden` → no fieldset.
- `ScreenerAiQuery`: applies the two keys (nothing else changes).

### `/mercado` and header (access change)

- `MarketPage`:
  - `loading` → loading;
  - `signIn` → `h1` plus dashed box (`marketContext.signInTitle`, `marketContext.signInBody`, link), no request;
  - `hidden` → `marketContext.unavailable`, no request;
  - `allowed` → as today. An API 401 shows the sign-in box; a 404 stays `unavailable`.
  - `HistoryPanel` is unchanged.
- `AppHeader`: the `marketContext` item is shown when `access.status` is `allowed` or `signIn` (replaces `isAdmin || marketContextPublic`).

### i18n (es and en, same keys and placeholders, no hardcoded text)

- `marketContext.`: `signInTitle`, `signInBody`.
- `marketContext.strip.`: `title` (Contexto de mercado), `aria`, `loading`, `error`, `retry`, `sector` ("Sector: {name}"), `noSector` (Sector: sin ETF sectorial asignado), `sectorTrend` (Tendencia del sector), `rank` ("Fuerza relativa {rank}/{of}"), `rankUnknown`, `regime` ("Entorno: {label}"), `regimeUnknown`, `regimeDate`, `openMarket` (Ver contexto de mercado), `preview` (Vista previa), `signInTitle`, `signInBody` ("Inicia sesión para ver el sector y el entorno de mercado de este valor."), `signIn`.
- `patterns.`: `contextLabel` (Contexto actual), `contextNote` ("El contexto es el de la última sesión, no el de la fecha del patrón.").
- `screener.sectorContext.`: `legend` (Contexto sectorial), `trendLabel`, `trendAny`, `trendAria`, `rankLabel` (Fuerza relativa del sector), `rankAll` (Todos los sectores), `rankTop` ("Top {n} de 11"), `hint`, `preview`, `signInTitle`, `signInBody`, `signIn`, `summaryTrend`, `summaryRank`, `droppedSignIn`, `droppedUnavailable`.
- Reuse `marketContext.trend.*`, `marketContext.regime.labels.*`, `marketContext.sectors.names.*`, `marketContext.stale`, `marketContext.legal.{disclaimer,fredNotice}`.
- Wording guard: extend the `translate.test.ts` advice regex check to `marketContext.strip.*`, `screener.sectorContext.*` and `patterns.context*`. No EOD wording.

### Accessibility and mobile

- Icons always come with text (never colour alone).
- The strip is a labelled `section`; chips are text. The ETF link has `aria-label` `marketContext.sectors.rowLinkAria`.
- Segmented buttons use `aria-pressed`; the select has a `<label>`; notices use `role="status"`, errors `role="alert"`.
- At 390 px the strip and chip rows wrap, with no horizontal page scroll. Light and dark use theme tokens only.

### "Cómo funciona" (`i18n/guide/{es,en}.ts`, same structure)

- `filtros`: entries "Contexto sectorial: tendencia" and "Contexto sectorial: fuerza relativa" (definitions as in C5 and `market-context-sectors`, null never matches, needs an account).
- `grafico`: an entry for the strip and the pattern chips (latest session, not the pattern date).
- `mercado`: the intro says the page needs a signed-in account; the "Evolución histórica" entry no longer mentions Visitors.

## Resolved Ambiguities And Umbrella Corrections

1. **Access (owner decision 2026-10-23).** This supersedes the umbrella's "Visitor can open `/mercado` … see the strip … use the filters" and `market-context-ui`'s public C1 and C6. Every surface goes through `view-market-context` = Registered User AND (flag on OR Admin). The flag and option (b) are unchanged. Corrected in the umbrella (Users And Permissions, Features 4 and 5) and in `market-context-ui.md` (superseding note).
2. **Dedicated endpoint, not a field in `GET /api/instruments/{ticker}`.** The umbrella's "new `market_context` field, public" is replaced:
   - the public chart payload stays anonymous, identical for every caller and free of any authorization branch;
   - the Registered-only data sits in `auth:sanctum` like the Company Card GET;
   - the field question "omit or null for guests" disappears: the instrument detail never has the key.
3. **Guests and denied users on the Screener filters are rejected, not ignored** (401 / 403 after value validation). Ignoring would silently return a broader list. The SPA strips the keys before the request and says so, so the public page never shows these errors in normal use.
4. **The regime is not a Screener filter and not in the Candidate JSON.** Only the two sector keys are, as the umbrella says.
5. **Staleness:** screener columns are null when the sector snapshot is more than 7 days older than the row's bar date. The strip uses `/mercado`'s rule (against today) and shows the badge.
6. **`rs_rank_of`:** ranks run 1..k over the non-null `rs_60` rows (`MarketContextComputer::rank`), so the strip shows `{rank}/{k}`, not a hard-coded 11. The filter maximum stays 11.
7. **FRED:** the strip shows the verbatim FRED notice next to a regime. No market or FRED data goes to the AI provider; only the filter vocabulary changes.
8. **Alerts** skip, rather than broaden, a Saved Screener with sector filters whose owner lost access.
9. **CONSTRAINTS conflicts** resolved by this decision and updated by the implementer:
   - "New filters (sector …) MUST NOT be added through the schema" → this feature is the new Screener feature that adds them;
   - "`market-context.public` MUST NOT grow into per-user authorization" → replaced by the ability.

## Expected File Changes

- Create (Laravel):
  - `app/Support/AuthUserPayload.php`;
  - `app/Http/Controllers/InstrumentMarketContextController.php` (or a method on `MarketContextController`);
  - `app/Services/MarketContext/InstrumentMarketContext.php` (C3 builder);
  - the migration (C4);
  - tests (below).
- Modify (Laravel):
  - `app/Providers/AppServiceProvider.php`, `routes/api.php`, `bootstrap/app.php` (remove the alias), delete `app/Http/Middleware/EnsureMarketContextPublic.php`;
  - `app/Http/Controllers/{Auth/AuthController,AccountController,ScreenerController,SavedScreenerController,ScreenerInterpretController}.php`;
  - `app/Services/Screener/{ScreenerFilterParser,CandidateQuery,ScreenerRowBuilder}.php`, `app/Services/Screener/Ai/{ScreenerQuerySchema,ScreenerQueryPrompt}.php`, `app/Services/Alerts/AlertEvaluator.php`, `app/Services/MarketContext/MarketContextCache.php`, `app/Jobs/RunMarketContextCommand.php`;
  - `config/ai_screener.php`, `lang/{es,en}/{market_context,messages,ai_screener}.php`.
- Create (SPA): `auth/useMarketContextAccess.ts`, `hooks/useInstrumentMarketContext.ts` (or next to the page), `components/chart/MarketContextStrip.tsx`, tests.
- Modify (SPA):
  - `lib/{api,screenerFilters}.ts`;
  - `pages/{InstrumentChartPage,ScreenerPage,MarketPage}.tsx`;
  - `components/chart/PatternPanel.tsx`, `components/screener/{ScreenerFilterPanel,ScreenerAiQuery}.tsx`, `components/AppHeader.tsx`, `components/market/{RegimeCard,SectorTable,marketText}.tsx|ts` (export helpers);
  - `i18n/messages/{es,en}.ts`, `i18n/guide/{es,en}.ts`.

## Visual Design Impact

- UI involved: yes. Source: `DESIGN.md` (2px bordered card with hard shadow, mono chips, dashed sign-in box, `text-gain`/`text-secondary` with icons) and `alphapulse/` as reference.
- New states: strip (ready, partial, stale, preview, sign-in, error); pattern chip row; sector fieldset (controls, sign-in, preview); Screener dropped-filters notice; `/mercado` page-level sign-in.
- No new design artifact. `DESIGN.md`: add the strip to the chart Core Screen and the fieldset to the Screener filters.

## Durable Documentation Impact

- `CONSTRAINTS.md`: update.
  - Market Context: every endpoint **MUST** sit in `auth:sanctum` behind `can:view-market-context`; the ability **MUST** be the only Market Context entitlement check (paid-plan seam); `screener_rows` sector columns, and no sector context in the Candidate JSON or the instrument detail.
  - Public API: replace the `market-context.public` exception with the sector-filter access check (401/403 after validation).
  - Saved Screeners: canonical 11-key object.
  - NL Screener: v2 and the sector keys; sector names stay unsupported.
  - Screener Rows: run `screener:refresh` after a manual `market-context:compute`.
- `ARCHITECTURE.md`: update the Market Context API section (ability, routes, instrument endpoint, cache key), the Screener section (columns, filters) and SPA access (hook).
- `docs/user-and-access-model.md`: the table above, the ability as the entitlement seam, and the future paid plan noted as out of scope.
- `docs/specs/market-context.md`: corrected by the planner (Users And Permissions, Features 4 and 5).
- `docs/specs/market-context-ui.md`: superseding access note added by the planner.
- `docs/specs/ai-screener-natural-language.md` and `docs/llamadas-api-jev-ia.md` (section "Esquema screener-query-v1"): note v2 and the two keys.
- `deploy/README.md`: item 10 (Market Context go-live) now means "Registered Users see it". Add the `screener:refresh` step after a manual `market-context:compute`.
- `docs/risks-and-open-questions.md`: "Market Context is Registered-only; paid plan later via the ability".
- `CONTEXT.md`: no new term. `AGENTS.md`: not needed.

## Implementation Plan (build order, green tests after each step)

1. **Access seam:** ability, `AuthUserPayload`, routes moved and middleware removed, lang keys. Fix the existing Market Context tests (list below), then SPA `useMarketContextAccess`, `MarketPage` and `AppHeader`. This step alone is the `/mercado` access change; it is a clean commit point.
2. **Instrument context:** C3 builder, cache and endpoint, then the SPA hook, strip and `PatternPanel` chips.
3. **Screener rows and filters:** migration, row builder, parser, `CandidateQuery`, controller access check, Saved Screeners, alerts and the rerun job.
4. **NL schema v2 and prompt.**
5. **SPA filters:** `screenerFilters`, page sanitising and notice, filter fieldset, AI apply, saved summaries.
6. **i18n, guide, durable docs,** `.\init.ps1`, manual checks.

## Verification Plan

Laravel (`php artisan test --filter="MarketContext|Screener|SavedScreener|Alert|Auth|Account|LegalInfo|Localization"`), offline (`Http::preventStrayRequests()`, `Http::fake` for the provider), time pinned:

- New `tests/Feature/MarketContextAccessTest.php`:
  - ability matrix: guest, user flag off/on, admin flag off/on;
  - `capabilities.market_context` in register, login, `/api/user` and `PATCH /account`;
  - for `/api/market-context`, history and the instrument endpoint: guest 401 in both modes, user 404 with the `not_available` JSON message when off, user 200 when on, admin 200 when off; admin preview through the stateful SPA session.
- New `tests/Feature/InstrumentMarketContextApiTest.php`:
  - Scenario 1 and 3 shapes; `rs_rank_of`; null sector, unmapped sector, null rank and null trend; ETF and unknown 404; stale at 7 vs 8 days; no regime → null; `insufficient`;
  - cache invisible until a recording command bumps it;
  - `GET /api/instruments/AAPL` has no `market_context` key for guest, user and admin.
- `ScreenerRowBuilder` tests (existing file, or a new `ScreenerRowSectorContextTest.php`): filled columns; null for null and unmapped sectors; stale at 8 vs 7 days against the row date; empty snapshots → null; ETFs still excluded.
- New `tests/Feature/ScreenerSectorFiltersTest.php`:
  - Scenario 4 results and AND with an existing filter;
  - each 422 case with its key, including a guest sending a bad value;
  - guest 401 `sign_in_required`, user flag off 403, admin 200, user flag on 200;
  - Candidate keys unchanged; `meta.total` correct.
- `SavedScreenerTest` (or the existing Saved Screener feature test): 11-key round-trip; legacy row → nulls; 422s; 403 for a denied user with a non-null key; 201 with null keys.
- Alert evaluator test: sector filters change the candidate set; a denied owner → `skipped`, no notification, state unchanged; an Admin owner with the flag off is evaluated.
- `RunMarketContextCommand` test: the `market-context:compute` rerun refreshes the screener rows.
- `ScreenerQuerySchemaTest`: schema has both keys, both required, `NAME` v2; accepts null and 1..11; rejects 0, 12, 3.0, "3" and an unknown trend.
- `ScreenerInterpretTest`: versions v2; a denied user gets nulls plus the `unsupported` note; allowed and denied share one cached provider call.
- **Existing tests that must change:**
  - `MarketContextApiTest::test_gate_off_hides_the_payload_from_guests_and_users_but_not_admins`: guest → 401.
  - `test_gate_on_serves_guests`: rename to `..._serves_registered_users_and_401s_guests`.
  - Every other `MarketContextApiTest` case requests as a signed-in user (helper).
  - `MarketContextHistoryTest::test_gate_off_is_404_for_users_and_open_for_admins`: same semantics, new middleware.
  - Tests asserting the v1 versions (`ScreenerInterpretTest` lines 116 and 171): v2. `AccountSelfServiceTest` (fixture strings only) may stay.
  - Saved Screener canonical-shape assertions (9 → 11 keys).
  - Auth payload snapshots, if any assert the exact `user` keys.

Vitest (`npm --prefix frontend run test`):

- `auth/useMarketContextAccess.test.tsx`: the access matrix including `preview`.
- `components/chart/MarketContextStrip.test.tsx`:
  - es and en ready, plus every partial and stale state, preview, sign-in, error with retry, 401;
  - FRED notice only with a regime;
  - no EOD or advice wording.
- `PatternPanel.test.tsx`: chips and note with a context, none without.
- `InstrumentChartPage.test.tsx`: allowed stock → one request to `/api/instruments/AAPL/market-context`; ETF → no request; Visitor with flag on → sign-in box and no request; flag off → nothing.
- `lib/screenerFilters.test.ts`: parse (invalid → null), patch, count, key, serialize (11 keys), deserialize (legacy), describe, `withoutMarketContext`.
- `pages/ScreenerPage.test.tsx` (new):
  - guest URL with sector keys → request without them plus notice;
  - allowed → request with them;
  - auth loading with sector keys → no request until resolved.
- `ScreenerFilterPanel.test.tsx` (new): controls and `aria-pressed`, select options, sign-in box, hidden.
- `ScreenerAiQuery.test.tsx`: applies the keys.
- `MarketPage.test.tsx`:
  - Scenario 1 renders as a signed-in user;
  - Visitor with flag on → sign-in box and no `/api/market-context` request;
  - Visitor with flag off → unavailable, no request;
  - API 401 → sign-in;
  - admin preview via capability.
- `AppHeader.test.tsx`: link visible for `allowed` and `signIn`, hidden otherwise (fixtures gain `capabilities`).
- `translate.test.ts` guard extended; `HowItWorksPage.test.tsx` parity for the new entries.
- No E2E harness: Laravel feature tests plus Vitest cover the flows, and the manual checks below cover the browser.

Gate: `.\init.ps1` exit 0. It does not start servers.

Manual checks (record in `PROGRESS.md`):

- Laravel on :8000 plus `npm --prefix frontend run dev` against a scratch SQLite copy in the session scratchpad (`DB_DATABASE`), never the dev DB. Serve the fixtures through Playwright interception (`/api/user` with and without the capability, `/api/legal`, `/api/instruments/*`, `/api/screener*`).
- Check the chart strip, the pattern chips, the filter fieldset and the notice, and `/mercado` as Visitor, user and Admin. Do this at 1280 and 390 px, light and dark, with keyboard focus and no horizontal page scroll.
- After deploy (flag still off): as Admin, check the strip on a stock against `/mercado`'s sector table, and run one filter. As a normal user, confirm nothing shows.

## Evidence To Capture

Test names and counts, `.\init.ps1` summary, screenshots (strip, chips, filters, `/mercado` sign-in) at desktop and mobile, light and dark, plus production preview notes. Record them in `feature_list.json` evidence and `PROGRESS.md`.

## Key Implementation Risks

- `denyAsNotFound` rendering: confirm the JSON 404 body keeps the localized message (`bootstrap/app.php` exception rendering). If it does not, render it explicitly in a thin `market-context.access` middleware that calls the same ability.
- The capability is read at page load: after the owner flips the flag, users see the change on their next load. That is acceptable and documented.
- SQLite vs PostgreSQL date strings when matching `S` (normalise as the payload does). The new indexed columns are covered by the CI `test-pgsql` job.
- Size: one feature, but it touches many files. The clean cut is after step 1 (access seam plus `/mercado`).

## Validator Checklist

- [ ] One ability `view-market-context` used by every route, the filter check, Saved Screeners, NL, alerts and the capability. No direct `isAdmin()` or flag checks for Market Context remain (grep).
- [ ] Guest 401, denied user 404 (APIs) or 403 (filters), Admin preview. The instrument detail and the Candidate JSON are unchanged.
- [ ] Screener columns and staleness exactly as in C4; filters, 422s and access order as in C5; 11-key Saved Screeners; alert skip; NL v2.
- [ ] Strip, chips, fieldset, notices and `/mercado` sign-in in es and en, icon plus text, 390 px without page scroll, dark mode; FRED notice with the regime; no EOD or advice wording.
- [ ] Durable docs updated; `.\init.ps1` green; `feature_list.json` and `PROGRESS.md` updated.

## Implementation Notes (2026-10-23, implementer)

Small, deliberate deviations and choices, all inside the contract:

- `useMarketContextAccess`: a Visitor whose `GET /api/legal` is still loading is `loading` (not `hidden`), so `/mercado` and the strip do not flash "not available" before the sign-in box; a failed legal request is `hidden`. An absent `market_context` field counts as off.
- The hook of the chart page lives next to the strip: `frontend/src/components/chart/useInstrumentMarketContext.ts` (the spec allowed either place). Shared pieces: `components/market/MarketSignInBox.tsx` (dashed sign-in box used by `/mercado`, the strip and the filter fieldset) and `REGIME_LABEL_STYLE` moved from `RegimeCard.tsx` to `marketText.ts`.
- Extra catalog key `marketContext.signIn` (the link label of the `/mercado` sign-in box). The compact pattern trend chip reuses `screener.sectorContext.summaryTrend` ("Sector {trend}") so the chip is not confused with the pattern direction.
- `denyAsNotFound` keeps the localized message: with `APP_DEBUG=false` the 404 body is exactly `{"message": …}` (tests assert it with `app.debug=false`; with debug on Laravel adds its trace keys).
- The local dev DB was not migrated: it already had three pending migrations from earlier features, so `php artisan migrate` there is left to the owner. The new migration was verified up/down/up on a scratch SQLite copy.
