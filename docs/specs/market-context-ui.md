# Feature Implementation Spec: Market Context page (/mercado), API and Admin status

## Source Feature

- `id`: `market-context-ui`
- `area`: `frontend` (plus its Laravel API, Admin endpoints and legal texts)
- `depends_on`: `market-regime` (`accepted`, in production)
- `status`: `not_started` at planning time (2026-10-23)
- `source`: `feature_list.json`. Product decisions: `docs/specs/market-context.md` → "Domain Decisions (shared)" and "Feature 4". This spec is the implementation contract; where it is more precise than the umbrella, this spec wins (see "Resolved Ambiguities"). The umbrella's Feature 4 section was narrowly corrected on 2026-10-23 to match it.

## Goal

The first user-visible part of the Market Context plan. A public page `/mercado` shows the latest Market Regime with its reasons, the 11 sectors ranked by relative strength, S&P 500 Breadth, rates and the yield curve, net liquidity, the Fed and the ECB, and leading data. Each block has its source, its own date, a stale badge when stale and a "¿Qué significa?" note. Registered Users also get 1-year and 3-year history charts, enforced server-side. Admins get a Market Context tab with the state of every stage and series and buttons to rerun the three commands. Everything is descriptive: no forecast, target, probability or advice wording, and no EOD wording outside Admin.

The page goes public only when the owner sets `MARKET_CONTEXT_PUBLIC=true` (see "Legal Gate"). Until then only Admins can open it, as a preview of the production data.

## Non-Goals

- No chart context strip, Screener filters or instrument-detail field (`market-context-patterns`). No put/call card (`options-put-call`).
- No change to the computers, rules, thresholds, `MacroSnapshotService` formulas, tables, schedules or pipeline order. The only command change is run recording and cache invalidation (Technical Approach 5).
- No VIX card (VIXCLS is off); no new FRED series; no indexable landing, sitemap entry or prerendering (route stays `noindex`).
- No regime-change alerts, emails or AI text. No market context data is sent to the AI provider (FRED terms).
- No new chart library: history charts are inline SVG.
- No separate terms-of-use page and no change to the legal notice gate (owner decision; see "Legal Gate").

## Job Story

When I look at a setup and want to know whether the market is with it or against it,
I want one page that shows the regime, its reasons, the sectors and the macro readings, each with its date and source,
so I can judge the context with rules I can read.

## Users And Permissions

| Actor | `MARKET_CONTEXT_PUBLIC=true` | `false` (default) |
| --- | --- | --- |
| Visitor | `/mercado` and `GET /api/market-context`; a sign-in prompt where the history charts go; `/history` → 401 | no header link; page shows "not available"; API 404 |
| Registered User | the above + history charts (`/history` 200) | no header link; API and history 404 |
| Admin | the above + the Admin tab, status and reruns | everything above (preview, with a banner) + Admin tab |

Admin endpoints are 401 for guests and 403 for non-admins in both modes. Hiding UI is never access control.

> **Superseded access (owner decision 2026-10-23, implemented by `market-context-patterns`):** "quiero que la parte de mercado solo este accesible a usuarios registrados, a futuro quiero establecer algunas de las funcionalidades de pago". The Visitor column above no longer applies. `/mercado`, `GET /api/market-context` and its history require a Registered User with the `view-market-context` ability (Registered User AND (flag on OR Admin)). The ability replaces the `market-context.public` middleware (C6). Visitors get 401 from the APIs and a sign-in prompt on `/mercado` once the flag is on. The flag and option (b) are unchanged. Contract: `docs/specs/market-context-patterns.md` (C1, C2, "SPA → `/mercado` and header"). Scenarios 1 and 5 and the gate tests listed below change with it.

## Legal Gate (FRED terms) — decision

Facts found on 2026-10-23:

- Chartiko's terms of use are the "Condiciones de uso" section of the legal notice (`frontend/src/i18n/legal/{es,en}.ts` → `notice`, page `/aviso-legal`, title "Aviso legal y condiciones de uso"). There is no separate terms page.
- The legal notice is **withheld in production** (`notice_published = false`) until a NIF and an address exist. Only `/privacidad` and `/cookies` are public. So any clause added to the terms today is not visible to anyone.
- FRED requires (recorded in `docs/specs/macro-series-fred.md`): the verbatim notice on the page, a link to the FRED API Terms of Use, and that Chartiko's own terms state that users agree to be bound by them.

Decision for this feature:

1. **Include the text.** Add the FRED clause to the terms ("Condiciones de uso" item) and a sources paragraph to "Propiedad intelectual", in es and en, shown while `market_context` is public (same pattern as the `emailDigest` paragraphs). Bump `config('legal.updated_at')`.
2. **Show the binding clause on the page itself.** The `/mercado` legal footer carries the FRED notice, the ToU link and an explicit "by using the macroeconomic data on this page you agree to be bound by the FRED® API Terms of Use" sentence. This is the only place the clause is visible while the legal notice is withheld.
3. **Gate the public link and data** behind `MARKET_CONTEXT_PUBLIC` (default `false`), not behind the legal notice. Gating on the notice would block the page until NIF/address exist, with no date. The owner flips the flag after choosing one of:
   - (a) accept the on-page clause as the interim terms for this page (planner's recommendation; low risk, all three FRED items are visible where the data is shown);
   - (b) wait until the legal notice is published (it already holds the clause after this feature);
   - (c) ask for a follow-up feature that publishes the terms of use as their own page under the privacy gate (name + email). This changes the legal-pages structure and registration wording, so it is not done here.

**Owner decision 2026-10-23: option (b).** `/mercado` stays private behind `MARKET_CONTEXT_PUBLIC=false` (Admin preview only, the page is "en pruebas") until the legal notice (`/aviso-legal`, which carries the FRED terms-of-use clause added by this feature) is published. Turning the flag on therefore requires the legal notice to be public first (NIF and address configured). The build is unchanged: flag off by default, Admin preview, FRED clause in the legal notice gated by `market_context`, and the `/mercado` legal footer.

## Acceptance Scenarios

Fixture "production-like" (used by Laravel and Vitest): today 2026-10-23 (New York); regime 2026-10-22 `neutral`, score 1, available 5, components breadth `ok` 0 (55.2), index_trend `ok` +1 (`uptrend`), sector_participation `ok` 0 (6), yield_curve `ok` 0 (0.31, as_of 10-21), liquidity `ok` 0 (−0.85, as_of 10-15), financial_conditions and volatility `disabled`; 11 sector rows and one breadth row on 10-22; DGS/DFEDTAR/ECBDFR/WALCL/WDTGAL/WLRRAOL/ICSA/PERMIT/OECD observations, PERMIT and OECD last dated 2026-09-01; NFCI/VIXCLS off.

### Scenario 1: Public page (flag on)
Given the fixture and `MARKET_CONTEXT_PUBLIC=true`
When a Visitor opens `/mercado`
Then the header shows "Mercado", the regime card shows "● Neutral", "Puntuación +1", "5 componentes cuentan (mínimo 4)", "Datos del 22 oct 2026", one reason per component in rules order, and the two disabled ones as "No disponible · fuente no habilitada · no cuenta"; the sector table lists 11 rows by `rs_rank`; each card shows source, date and "¿Qué significa?"; PERMIT/OECD show "Datos de septiembre de 2026"; the footer shows the FRED notice verbatim, the ToU link and the binding sentence; in place of every history chart there is a sign-in prompt and no `/history` request is made.

### Scenario 2: Registered User history
Given a signed-in Registered User on `/mercado`
When they open "Ver evolución" on the regime card and pick "3 años"
Then the SPA requests `/api/market-context/history?metric=regime_score&range=1y`, then `range=3y`, draws the line with the ±3 reference lines and shows "Datos disponibles desde 22 oct 2025" because history starts after the 3-year window.

### Scenario 3: History is server-side auth-only
Given `GET /api/market-context/history?metric=dgs10&range=1y`
Then a guest gets 401, a Registered User 200, an unknown metric (`t10y3m`, `vix`, `sector_rs_60:SPY`, `sector_rs_60:AAPL`) or range (`5y`) 422 with `errors.metric` / `errors.range`.

### Scenario 4: Stale and insufficient
Given the breadth and sector rows are dated 2026-10-14 (9 days old) and the regime row is `insufficient` with `available` 3
Then the breadth card and the sector table carry the "Desactualizado" badge, the regime shows "? Sin datos suficientes" with no score and "Hacen falta al menos 4 componentes con datos al día; hoy cuentan 3", and a stale component shows its value, date, the badge and "no cuenta".

### Scenario 5: Not public yet (flag off, the default)
Given `MARKET_CONTEXT_PUBLIC=false`
Then the header has no "Mercado" link for Visitors and Registered Users, `GET /api/market-context` and `/history` return 404 JSON for them, and `/mercado` shows "Esta página aún no está disponible". An Admin sees the link, the full page, a "Vista previa" banner and working history.

### Scenario 6: Empty data
Given no regime, snapshot or macro rows (the dev database)
Then `GET /api/market-context` is 200 with `regime`, `sectors` and `breadth` null and every macro reading `{value: null, …}` (or `macro: null` when `MACRO_ENABLED=false`), and the page shows a "Todavía no hay datos" state per block, never an error.

### Scenario 7: Cache invalidation
Given the payload is cached
When a regime row changes directly in the database
Then the API still returns the cached payload; after `market-regime:compute` (or `market-context:compute` / `macro:refresh`) finishes, the next call returns the new data.

### Scenario 8: Admin status and rerun
Given an Admin on `/admin/market-context`
Then they see the public flag, macro enabled state, the last run of each command (status, times, exit code), snapshot coverage (latest/first date, rows, regime distribution `favourable 119 · neutral 133 · adverse 0 · insufficient 0` in production) and every configured series with last observation, last refresh, last attempt, last error code and a stale flag (NFCI/VIXCLS listed as disabled).
When they click "Recalcular" on `market-regime:compute`
Then `POST /api/admin/market-context/runs` returns 202 with status `queued`, the row polls through `running` to `succeeded` (or `failed` with the exit code), and a second click while it is queued/running returns 409.

## Repository Research

### Files Inspected

- Specs: `docs/specs/market-context.md`, `market-regime.md`, `macro-series-fred.md`, `market-context-sectors.md` (headings), `premium-panels-auth-only.md`, `legal-privacy-publication.md`, `admin-ingestion-panel.md` (grep).
- Backend: `app/Services/MarketContext/MarketContextComputer.php`, `app/Services/Macro/{MacroSnapshotService,CentralBankCalendar}.php`, `app/Services/MarketRegime/{MarketRegimeComputer,MarketRegimeRules}.php`, models `MarketSectorSnapshot`, `MarketBreadthSnapshot`, `MarketRegimeSnapshot`, `MacroSeries`; `config/{macro,central_banks,market_context,ingestion}.php`; `app/Console/Commands/{ComputeMarketContext,RunIngestionPipeline}.php`; `routes/api.php`; `bootstrap/app.php`; `app/Http/Controllers/{LegalController,Admin/IngestionRunController,Admin/ForecastBacktestController}.php`; `app/Http/Middleware/EnsureUserIsAdmin.php`; `app/Jobs/RunIngestionJob.php`; `lang/{es,en}/`; `phpunit.xml`; `deploy/{nginx.conf,deploy.sh,alphapulse-queue.service}`; `.github/workflows/ci-deploy.yml` (main auto-deploys).
- Frontend: `router.tsx`, `nav.ts`, `components/{AppHeader,AppFooter,RouteMetadata}.tsx`, `pages/{LegalPage,AdminPage}.tsx`, `pages/admin/AdminLayout.tsx`, `components/screener/{SavedScreenersPanel,ScreenerAiQuery}.tsx` (sign-in prompts), `legal/useLegalInfo.ts`, `lib/api.ts`, `i18n/{format.ts,translate.test.ts,messages/index.ts,legal/*,guide/*}`, `pages/HowItWorksPage.test.tsx`, `public/{robots.txt,sitemap.xml}`, `package.json`.
- Docs: `AGENTS.md`, `DESIGN.md`, `CONSTRAINTS.md` (Market Context, Macro, Admin Panel, Public API, Legal Pages), `ARCHITECTURE.md` (Market sections), `CONTEXT.md`, `docs/risks-and-open-questions.md`.

### Existing Patterns To Follow

- Public read endpoints are anonymous with a named throttle (`throttle:60,1,<prefix>`); errors are explicit JSON with `__()` messages; decimals become JSON numbers.
- Auth-only endpoints sit in the `auth:sanctum` group; Admin endpoints in the `['auth:sanctum','admin']` group with 401/403 tests each.
- Admin triggers create state first and dispatch a `$tries = 1` job on the `database` queue (production runs `queue:work`; tests run `sync`/`Queue::fake()`); the SPA polls every 2500 ms.
- `GET /api/legal` already carries feature switches that change legal texts (`email_digest`), fetched once per page load and shared by footer, header-level consumers and legal pages.
- Guest variant of an auth-only feature: dashed box with a short text and a sign-in link (`ScreenerAiQuery`), nothing rendered while auth is `loading` (`SavedScreenersPanel`).
- Dates: `formatMarketDate` (UTC calendar day). Signs: `formatChangePercent`. Catalog parity, placeholders and the EOD ban are enforced by `translate.test.ts` (keys under `admin.` are exempt from the EOD ban).
- Route metadata: every non-home route is `noindex, follow`.

### Current Gaps

- No Market Context endpoint, history, Admin endpoint, page, components, i18n, run ledger or cache.
- No public terms of use (see "Legal Gate"). No E2E harness in `frontend/package.json` (no `test:e2e`).
- The dev database is empty: no local real data. Do not restore or overwrite it.

## Contract

### C1. `GET /api/market-context` (public, gated by the flag)

Middleware `market-context.public` + `throttle:60,1,market-context`. 200 body (illustrative values; `…` elides; the keys and their order are the contract; `R` = reading `{"value": float|null, "as_of": "Y-m-d"|null, "stale": bool}` from `MacroSnapshotService`, or `null` when its series is disabled):

```json
{
  "as_of": "2026-10-23",
  "public": true,
  "regime": {
    "date": "2026-10-22", "stale": false, "regime": "neutral", "score": 1, "available": 5, "rules_version": 1,
    "rules": {"min_available": 4, "favourable_min_score": 3, "adverse_max_score": -3},
    "components": {"breadth": {"status": "ok", "score": 0, "value": 55.2, "as_of": "2026-10-22", "stale": false}, "…": "every stored key, stored order"}
  },
  "sectors": {"date": "2026-10-22", "stale": false, "rows": [
    {"ticker": "XLK", "sector_key": "information_technology", "ret_5": 1.23, "ret_20": 4.5, "ret_60": 9.87, "rs_60": 3.21, "rs_rank": 1, "trend": "uptrend", "breadth_200": 71.43}
  ]},
  "breadth": {"date": "2026-10-22", "stale": false, "members": 503, "pct_above_sma50": 58.1, "pct_above_sma200": 55.2,
              "new_highs": 21, "new_lows": 4, "advancers": 301, "decliners": 195, "spy_trend": "uptrend"},
  "macro": {
    "rates": {"dgs3mo": R, "dgs2": R, "dgs10": R, "dgs10_change_20": R},
    "curve": {"spread_10y2y": R, "spread_10y3m": R, "inverted": false},
    "liquidity": {"net_liquidity": R, "change_4w_pct": R, "change_13w_pct": R},
    "leading": {"icsa": R, "icsa_4w": R, "icsa_4w_vs_52w_min_pct": R, "permit": R, "permit_change_3m_pct": R,
                "nfci": null, "oecd_cli": R, "oecd_cli_change_1m": R},
    "central_banks": {
      "fed": {"lower": R, "upper": R, "last_change": {"direction": "cut", "from": {"lower": 4.0, "upper": 4.25}, "to": {"lower": 3.75, "upper": 4.0}, "effective_date": "2026-09-17"},
              "last_meeting": {"start": "2026-09-15", "decision": "2026-09-16"}, "next_meeting": {"start": "2026-10-27", "decision": "2026-10-28"},
              "calendar": {"source_url": "https://www.federalreserve.gov/monetarypolicy/fomccalendars.htm", "checked_at": "2026-10-22"}},
      "ecb": {"deposit_rate": R, "last_change": {"direction": "cut", "from": 2.75, "to": 2.5, "effective_date": "…"},
              "last_meeting": {…}, "next_meeting": {…}, "calendar": {"source_url": "…", "checked_at": "2026-10-22"}}
    },
    "sources": {"rates": ["…H.15 Selected Interest Rates, via FRED®"], "liquidity": ["…"], "central_banks": ["…FOMC Press Release, via FRED®", "Source: ECB statistics, via FRED®"], "leading": ["…"]}
  }
}
```

Rules:
- `as_of` = today in `config('ingestion.timezone')`. `regime`, `sectors`, `breadth` use their latest row (breadth: main Universe); each is `null` when no row exists. `stale` = `as_of − date > MarketRegimeRules::MARKET_STALE_AFTER_DAYS` (7).
- `regime.components` is the stored JSON unchanged (an absent key = not computed by that rules version). `rules` comes from `MarketRegimeRules` constants.
- `sectors.rows`: rows of the latest sector date, ordered `rs_rank` asc (nulls last), then ticker. `sector_key` = `str_replace(' ', '_', strtolower($sector))`, one of `communication_services, consumer_discretionary, consumer_staples, energy, financials, health_care, industrials, information_technology, materials, real_estate, utilities`. Only the umbrella's fields; decimals as JSON numbers.
- `macro` = `MacroSnapshotService::snapshot($asOf)` (**not** point-in-time: the page shows the newest published values; each reading carries its own date) without `as_of`, `point_in_time` and `volatility`, plus `calendar` per bank from `config/central_banks.php` (`source_url`, `checked_at`) and `sources`. `macro` is `null` when `config('macro.enabled')` is false. A block whose series are all disabled stays `null`, as the service returns it.
- `sources.<card>` = de-duplicated `attribution` strings of the **enabled** series, config order: `rates` ← category `rates`; `central_banks` ← `policy`; `liquidity`; `leading`.
- Cached (C5). No `Cache-Control` change.
- Errors: 404 `{"message": __('market_context.not_available')}` when gated; 429 from the throttle.

### C2. `GET /api/market-context/history?metric=&range=` (Registered Users)

In the `auth:sanctum` group, middleware `market-context.public` + `throttle:60,1,market-context-history`. `range` optional, default `1y`, one of `1y|3y`. Closed metric list (`MarketContextHistory::METRICS`):

| `metric` | Points | Frequency |
| --- | --- | --- |
| `regime_score` | `market_regime_snapshots.score` per date; `null` value for `insufficient` rows (a gap, never 0) | session |
| `pct_above_sma200` | main-Universe Breadth rows, non-null values | session |
| `dgs10` | `DGS10` observations | daily |
| `spread_10y3m` | `DGS10 − DGS3MO` on dates where both exist | daily |
| `net_liquidity` | `(WALCL − WDTGAL − WLRRAOL) / 1000` on dates where all three exist (billions) | weekly |
| `icsa_4w` | mean of 4 `ICSA` observations ending on each date, only when the 4 are consecutive 7-day steps | weekly |
| `nfci` | `NFCI` observations | weekly |
| `sector_rs_60:{ticker}` | `rs_60` of that sector ETF; `{ticker}` must be a `market-context` Universe member with a non-null `sector` (not SPY) | session |

200 body: `{"metric": "dgs10", "range": "1y", "disabled": false, "from": "2025-10-21", "to": "2026-10-21", "first_date": "2025-10-21", "points": [{"date": "2025-10-21", "value": 4.1234}]}`.
- `to` = date of the latest point; `from` = `to` minus 1 or 3 years (`subYearsNoOverflow`); points with `date >= from`, ascending; values rounded to 4 dp. No points → `from`, `to`, `first_date` null and `points: []`.
- A macro metric whose series is disabled, or any macro metric while `macro.enabled` is false → 200 with `"disabled": true` and `points: []`.
- The last point of `spread_10y3m`, `net_liquidity` and `icsa_4w` must equal the corresponding `MacroSnapshotService` reading for the same data (test).
- 401 guest, 404 gated, 422 `errors.metric` (`__('market_context.metric_invalid')`) / `errors.range` (`…range_invalid`). Cached (C5).

### C3. Admin endpoints (`['auth:sanctum','admin']`, prefix `admin`)

`GET /api/admin/market-context` → 200:

```json
{
  "public": false, "macro_enabled": true,
  "runs": {"market-context:compute": RUN|null, "macro:refresh": RUN|null, "market-regime:compute": RUN|null},
  "snapshots": {
    "sectors": {"first_date": "2025-10-22", "latest_date": "2026-10-22", "latest_rows": 11},
    "breadth": {"first_date": "2025-10-22", "latest_date": "2026-10-22"},
    "regime": {"first_date": "2025-10-22", "latest_date": "2026-10-22", "count": 252, "rules_version": 1,
               "latest": {"regime": "neutral", "score": 1, "available": 5},
               "distribution": {"favourable": 119, "neutral": 133, "adverse": 0, "insufficient": 0}}
  },
  "series": [{"code": "DGS3MO", "category": "rates", "frequency": "daily", "license": "public_domain", "enabled": true,
              "last_observation_date": "2026-10-21", "last_refreshed_at": "…Z", "last_attempted_at": "…Z", "last_error": null, "stale": false}]
}
```

- `RUN` = `{"status": "queued|running|succeeded|failed|interrupted", "queued_at": ISO|null, "started_at": ISO|null, "finished_at": ISO|null, "exit_code": int|null}`. `interrupted` = stored `queued`/`running` whose `started_at ?? queued_at` is older than `config('market_context.run_stale_after_minutes')` (30).
- `series`: every code of `config('macro.series')` in config order, joined with `macro_series` (nulls when no row); `enabled` = the series' config flag; `stale` from `last_observation_date` vs today and `stale_after_days[frequency]` (`false` when null). Snapshot blocks are `null` when their table is empty.

`POST /api/admin/market-context/runs` body `{"command": "market-context:compute|macro:refresh|market-regime:compute"}`:
- 422 `errors.command` for anything else (`market_context.command_invalid`); 422 `errors.command` `market_context.macro_disabled` for `macro:refresh` while `macro.enabled` is false;
- 409 `{"message": __('market_context.run_active')}` when that command's record is `queued`/`running` and not `interrupted` (a pipeline run counts);
- otherwise record `queued`, dispatch `RunMarketContextCommand($command)` and return 202 `{"command": "…", "run": RUN}`. Commands run with no options (latest 5 sessions; macro incremental).

### C4. Run log (`App\Services\MarketContext\MarketContextRunLog`)

- `COMMANDS` = the three names. Records live in the cache store (`Cache::forever("market-context:run:{command}")`), one per command, best effort (production cache store is `database`; deploys do not clear it).
- `queued(cmd)`; `started(cmd)` (keeps `queued_at` only when the previous status was `queued`); `finished(cmd, exit)` (`succeeded` when 0, else `failed`) **and** `MarketContextCache::bump()`.
- Each of `ComputeMarketContext`, `RefreshMacroSeries`, `ComputeMarketRegime` records its own run whoever starts it (pipeline, schedule, Admin job, console) by wrapping `handle()` in a shared helper: `started` → run → `finished(exit)`; an exception records `failed` (exit 1) and is rethrown. `macro:refresh` records only after its disabled and lock-held early exits (those paths do not run anything).
- Job `App\Jobs\RunMarketContextCommand`: `$tries = 1`, `$timeout = 900` (macro budget 300 s + one series), `handle()` = `Artisan::call($command)`; `failed()` records `failed` if the record is still `queued`.

### C5. Cache (`App\Services\MarketContext\MarketContextCache`)

- `version()` reads `market-context:version` (a token; created on first read); `bump()` stores a new token (`(string) Str::uuid()`). Keys: `market-context:{version}:latest:{as_of}` and `market-context:{version}:history:{metric}:{range}`, TTL `config('market_context.cache_ttl_seconds')` (86400).
- The date in the latest key recomputes stale flags each day even without a run. The three commands bump on finish, including the 10:00 `macro-refresh` event. Gated and admin requests share the same payload (it holds no user data).

### C6. Gate and legal endpoint

- `config/market_context.php`: `public` (`MARKET_CONTEXT_PUBLIC`, default `false`), `cache_ttl_seconds` 86400, `run_stale_after_minutes` 30. `.env.example` documents the key.
- Middleware `App\Http\Middleware\EnsureMarketContextPublic`, alias `market-context.public`: passes when `config('market_context.public')` or `$request->user('sanctum')?->isAdmin()`; otherwise JSON 404 (C1). This is a release gate, not authorization; record it as the single exception to "no controller authorization branch" in `CONSTRAINTS.md` → Public API.
- `GET /api/legal` adds `"market_context": bool` (= the flag). The SPA uses it for the header link, the legal-text groups and the preview banner.
- `lang/{es,en}/market_context.php`: `not_available`, `metric_invalid`, `range_invalid`, `command_invalid`, `macro_disabled`, `run_active`.

## SPA

### Route, navigation, metadata

- `router.tsx`: `{ path: 'mercado', element: <MarketPage /> }`; admin child `{ path: 'market-context', element: <AdminMarketContextPage /> }`. `nav.ts`: `MARKET_ROUTE = '/mercado'`; NAV_ITEMS gets `{ path: '/mercado', labelKey: 'nav.market', marketContext: true }` after Screener. `AppHeader` shows a `marketContext` item only when `useLegalInfo()` is ready with `market_context === true`, or the user is an Admin (UI affordance only); nothing while legal info loads.
- `AdminLayout`: tab `{ to: '/admin/market-context', labelKey: 'admin.marketContext.nav', end: true }` after Ingestion.
- `RouteMetadata`: `/mercado` → `meta.marketTitle` / `meta.marketDescription`, `noindex, follow` (default). No sitemap entry.

### Files

`frontend/src/pages/MarketPage.tsx`; `frontend/src/components/market/`: `MarketCard.tsx` (shell: title, date line, stale badge, children, source list, "¿Qué significa?" `<details>`, history slot), `RegimeCard.tsx`, `SectorTable.tsx`, `BreadthCard.tsx`, `RatesCard.tsx`, `LiquidityCard.tsx`, `CentralBanksCard.tsx`, `LeadingCard.tsx`, `HistoryPanel.tsx` (auth-aware), `HistoryChart.tsx` (SVG), `MarketLegalFooter.tsx`, `marketText.ts` (reason sentences, date lines, units); `frontend/src/pages/admin/AdminMarketContextPage.tsx`; `lib/api.ts` types + `marketContextApi.{latest,history}` and `adminMarketContextApi.{status,rerun}`.

### Layout (DESIGN.md tokens; mobile first at 390 px, no horizontal page scroll)

1. Header block: `h1` "Contexto de mercado", one-line intro, "Datos del {regime.date}". Admin preview banner when `public === false`.
2. Regime card (full width): label chip with icon **and** text — favourable `▲ Favorable` (`text-gain`), neutral `● Neutral`, adverse `▼ Adverso` (`text-loss`), insufficient `? Sin datos suficientes`; "Puntuación {+n}" (hidden when insufficient); "{available} componentes cuentan (mínimo {min})"; reasons list (C7); history `regime_score`.
3. Sector table (full width): segmented control 1 semana / 1 mes / 3 meses (`aria-pressed`) switching the Return column between `ret_5/20/60`; fixed columns `#` (rs_rank), Sector + ETF ticker (row link to `/instruments/{ticker}`, `aria-label` "Ver el gráfico de {ticker}"), Rentabilidad (signed, gain/loss colour + sign), "vs S&P 500 (3 meses)" (`rs_60`, signed pp), Tendencia chip (`▲ Alcista` / `◆ Mixta` / `▼ Bajista` / `Sin datos`), "Sobre SMA200" (`breadth_200` %). Wrapped in a focusable `role="region"` that scrolls horizontally under `sm`. History: a sector `<select>` (default rank 1) + `sector_rs_60:{ticker}`.
4. Cards grid `grid-cols-1 md:grid-cols-2 xl:grid-cols-3`:
   - Breadth: % above SMA50/SMA200, new 52-week highs/lows, advancers/decliners, members, SPY trend chip. History `pct_above_sma200` (reference lines 40 and 60).
   - Rates and curve: 3m, 2y, 10y (%), 10y change over 20 sessions (pp), spreads 10y–2y and 10y–3m (pp), "Invertida" badge when `inverted`. History selector `dgs10` | `spread_10y3m` (zero line).
   - Liquidity: net liquidity (billions USD) with 4- and 13-week % change and the formula line. History `net_liquidity`.
   - Central banks: Fed range "lower–upper %" and ECB deposit rate, last change "{Bajada|Subida} el {date}: de {from} a {to}" or "Sin cambios en el historial guardado", last and next meeting ("{start}–{decision}" or "Sin fecha publicada"), "Calendario oficial revisado el {checked_at}" linking `source_url`. No history.
   - Leading data: claims (latest, 4-week average, % above the 52-week minimum), permits (latest, 3-month %), OECD CLI (latest, 1-month change), NFCI. History selector `icsa_4w` | `nfci` (only when NFCI is enabled).
5. `MarketLegalFooter` (C8).

### States

- Page: loading (`common.loading`), error (`role="alert"` + retry), unavailable (API 404: "Esta página aún no está disponible"), ready.
- Block `null` or every reading `value: null`: "Todavía no hay datos" inside the card (no error styling). `macro: null`: macro cards render once as a single "Datos macroeconómicos no disponibles" card.
- Reading: `null` (series disabled) → "No disponible · fuente no habilitada"; `value: null` → "Sin datos"; `stale: true` → value + "Desactualizado" badge (text, bordered chip; `title` = stale hint). Card-level stale (regime/sectors/breadth) → badge next to the date.
- Date lines: "Datos del {formatMarketDate}"; monthly readings (`permit`, `permit_change_3m_pct`, `oecd_cli`, `oecd_cli_change_1m`) → "Datos de {month year}" (new `formatMarketMonth`, UTC).
- History (`HistoryPanel`): auth `loading` → nothing; Visitor → dashed box "Evolución histórica" + "Inicia sesión para ver la evolución de 1 y 3 años." + link to `/login`, no request; signed in → button "Ver evolución" (`aria-expanded`, `aria-controls`), fetched on first open, 1 año / 3 años toggle, loading, error (`role="alert"` + retry), empty "Sin datos para este periodo", `disabled` → "No disponible · fuente no habilitada", "Datos disponibles desde {first_date}" when `first_date > from + 7 days`. A 401 (session expired) shows the sign-in box.
- `HistoryChart`: `<svg role="img" viewBox="0 0 640 180">` with an `aria-label` summary "Evolución de {metric} del {from} al {to}: mínimo {min}, máximo {max}, último {last}"; line `text-tertiary` via `currentColor`; `null` values break the path; dashed labelled reference lines (`regime_score` −3/0/+3, `pct_above_sma200` 40/60, `spread_10y3m`/`nfci`/`sector_rs_60` 0); min/max labels left, first/last dates bottom, last value marker; theme tokens only.

### C7. Regime reasons

One `<li>` per key in the stored order, skipping absent keys. Each: component name, sentence, date line, and the contribution as text + icon: `▲ suma +1`, `● suma 0`, `▼ resta 1`; non-`ok` items end with "no cuenta". Sentences (es; en equivalent):
- `breadth`: "{value} % de las acciones del S&P 500 sobre su media de 200 sesiones"
- `index_trend`: "El S&P 500 (SPY) está en tendencia alcista | mixta | bajista"
- `sector_participation`: "{value} de 11 sectores en tendencia alcista"
- `yield_curve`: "Diferencial 10 años – 3 meses: {value} pp" / inverted (`value < 0`): "Curva 10 años – 3 meses invertida ({value} pp)"
- `financial_conditions`: "Condiciones financieras (NFCI): {value}"
- `liquidity`: "Liquidez neta: {value} % en 13 semanas"
- `volatility`: "VIX: {value}"
- `disabled`: "{name}: no disponible (fuente no habilitada)"; `missing`: "{name}: sin datos"; `stale`: sentence + "Desactualizado".

### C8. Attribution and legal texts on the page

- Every card: "Fuente" + its strings. Regime, sectors, breadth: `marketContext.sourceChartiko` ("Cálculo de Chartiko a partir de los precios de cada sesión de las acciones del S&P 500 y de los ETF sectoriales."). Macro cards: `macro.sources.<card>` verbatim (proper names, not translated; keeps "Source: ECB statistics" exact). Liquidity and curve add "Diferenciales y liquidez neta calculados por Chartiko".
- Footer (`MarketLegalFooter`), in this order:
  1. `marketContext.legal.disclaimer`: "Información descriptiva, no es asesoramiento de inversión." / "Descriptive information, not investment advice."
  2. Only when `macro !== null`: `marketContext.legal.fredNotice`, identical in both catalogs and rendered with `lang="en"`: **"This product uses the FRED® API but is not endorsed or certified by the Federal Reserve Bank of St. Louis."**
  3. `marketContext.legal.fredBinding` with `{terms}` → external link `https://fred.stlouisfed.org/docs/api/terms_of_use.html` (`rel="noopener noreferrer"`), label `marketContext.legal.fredTerms`: es "Al usar los datos macroeconómicos de esta página aceptas quedar vinculado por las {terms}." / "Condiciones de uso de la API de FRED®"; en "By using the macroeconomic data on this page you agree to be bound by the {terms}." / "FRED® API Terms of Use".
  4. `marketContext.legal.oecdLicence` with `{licence}` → `https://creativecommons.org/licenses/by/4.0/` ("CC BY 4.0"): "Datos de la OCDE bajo licencia {licence}; Chartiko calcula la variación mensual." / "OECD data licensed under {licence}; the monthly change is calculated by Chartiko."
- Legal notice (`frontend/src/i18n/legal/{es,en}.ts`, `types.ts`, `LegalPage.tsx`): new optional `marketContext?: string[]` on `LegalSection`, rendered after `items` (as list items) or `paragraphs` while `info.market_context === true`, with a `{fredTermsLink}` placeholder (external link, label = `fredTerms`):
  - "Condiciones de uso" (item) es: "Los datos macroeconómicos de la página Mercado proceden de la API FRED® del Banco de la Reserva Federal de St. Louis. Al usar Chartiko aceptas quedar vinculado por las {fredTermsLink}." en: "The macroeconomic data on the Market page come from the FRED® API of the Federal Reserve Bank of St. Louis. By using Chartiko you agree to be bound by the {fredTermsLink}."
  - "Propiedad intelectual" (paragraph) es: "Los datos macroeconómicos proceden de FRED® y de sus fuentes originales (Junta de la Reserva Federal, Departamento de Trabajo y Oficina del Censo de EE. UU., Banco Central Europeo y OCDE), citadas junto a cada dato. Chartiko usa la API FRED® pero no está respaldado ni certificado por el Banco de la Reserva Federal de St. Louis." en: equivalent.
  - `config/legal.php` `updated_at` = implementation date.

### i18n keys (es and en, same keys and placeholders; no hardcoded text)

- `nav.market` (Mercado / Market); `meta.marketTitle` ("Contexto de mercado | Chartiko"), `meta.marketDescription`.
- `marketContext.`: `title`, `intro`, `dataAsOf` ("Datos del {date}" / "Data as of {date}"), `dataMonth` ("Datos de {month}" / "Data for {month}"), `stale` (Desactualizado / Stale), `staleHint`, `notAvailable`, `sourceDisabled`, `noData`, `noReading`, `loadError`, `retry`, `unavailable`, `previewBanner`, `meaning` (¿Qué significa? / What does it mean?), `source`, `sourceChartiko`, `sourceComputed`, `macroUnavailable`.
- `marketContext.regime.`: `title`, `labels.{favourable,neutral,adverse,insufficient}` (Favorable, Neutral, Adverso, Sin datos suficientes / Favourable, Neutral, Adverse, Not enough data), `score` ("Puntuación {score}"), `available` ("{available} componentes cuentan (mínimo {min})"), `insufficientBody` ("{min}", "{available}"), `reasons`, `contribution.{plus,zero,minus}`, `notCounted`, `components.{breadth,index_trend,sector_participation,yield_curve,financial_conditions,liquidity,volatility}.name`, `components.*.reason` (+ `index_trend.reason{Up,Mixed,Down}`, `yield_curve.reasonInverted`), `reasonDisabled`, `reasonMissing`, `meaning` (rules: each component adds +1, 0 or −1; favourable with +3 or more, adverse with −3 or less, otherwise neutral; fewer than 4 → not enough data; stale/disabled do not count; uncalibrated initial thresholds; descriptive, not a forecast).
- `marketContext.trend.{uptrend,mixed,downtrend,unknown}`.
- `marketContext.sectors.`: `title`, `period.{5,20,60}`, `periodAria`, `columns.{rank,sector,return,rs,trend,breadth}`, `rowLinkAria`, `names.{11 sector keys}` (es: Servicios de comunicación, Consumo discrecional, Consumo básico, Energía, Financiero, Salud, Industria, Tecnología de la información, Materiales, Inmobiliario, Servicios públicos), `historySector`, `meaning`.
- `marketContext.breadth.`: `title`, `above50`, `above200`, `newHighs`, `newLows`, `advancers`, `decliners`, `members`, `spyTrend`, `meaning`.
- `marketContext.rates.`: `title`, `dgs3mo`, `dgs2`, `dgs10`, `change20`, `spread10y2y`, `spread10y3m`, `inverted`, `meaning`.
- `marketContext.liquidity.`: `title`, `net`, `change4w`, `change13w`, `formula`, `meaning`.
- `marketContext.centralBanks.`: `title`, `fed`, `fedRange` ("Rango de tipos oficiales de la Fed" / "Fed funds rate range"), `ecb`, `ecbRate`, `lastChange`, `raise`, `cut`, `changeFromTo`, `noChange`, `lastMeeting`, `nextMeeting`, `meetingDates`, `noMeeting`, `calendarSource`, `meaning`.
- `marketContext.leading.`: `title`, `icsa`, `icsa4w`, `icsaVsMin`, `permit`, `permitChange`, `oecd`, `oecdChange`, `nfci`, `meaning`.
- `marketContext.units.`: `percent`, `pp`, `billionsUsd` ("{value} mil M USD" / "USD {value} bn"), `thousandUnits` ("{value} mil (tasa anual)" / "{value}k (annual rate)"), `index`.
- `marketContext.history.`: `show`, `hide`, `rangeAria`, `range.{1y,3y}`, `loading`, `error`, `empty`, `availableFrom`, `signInTitle`, `signInBody`, `signIn`, `chartLabel`, `metrics.{regime_score,pct_above_sma200,dgs10,spread_10y3m,net_liquidity,icsa_4w,nfci,sector_rs_60}`, `metricAria`.
- `marketContext.legal.`: `disclaimer`, `fredNotice`, `fredTerms`, `fredBinding`, `oecdLicence`, `licenceLabel`.
- `admin.marketContext.` (EOD wording allowed here): `nav`, `title`, `intro`, `public`, `publicOn`, `publicOff`, `macroOn`, `macroOff`, `runs`, `commands.{compute,macro,regime}` (labels for `market-context:compute`, `macro:refresh`, `market-regime:compute`; the SPA maps the command name to the key), `status.{queued,running,succeeded,failed,interrupted,never}`, `columns.{command,status,queued,started,finished,exit,code,category,frequency,licence,enabled,lastObservation,lastRefresh,lastAttempt,lastError,stale}`, `rerun`, `rerunActive`, `rerunError`, `regimeHint` ("Después de recalcular el contexto o las series, recalcula el entorno."), `macroDisabledNote`, `snapshots`, `firstDate`, `latestDate`, `rows`, `distribution`, `series`, `loadError`.
- Wording rules (tested): no `EOD`/end-of-day outside `admin.*`; in `marketContext.*` and the manual section no `compra|comprar|venta|vender|recomend|objetivo|probabilidad|previsión|pronóstico|buy|sell|target|recommend|probabilit|forecast|predict`.

## Resolved Ambiguities And Umbrella Corrections

1. **Go-live flag** `MARKET_CONTEXT_PUBLIC` (default off) with Admin preview; see "Legal Gate". The feature's user-visible behaviour ("anyone can open /mercado") is verified with the flag on; production turns on by owner action.
2. **History metric `t10y3m` → `spread_10y3m`.** `T10Y3M` is a copyrighted St. Louis Fed series that Chartiko does not fetch; the metric is the locally computed `DGS10 − DGS3MO`. Umbrella corrected.
3. **Sector toggle** switches only the return column: `rs_5` does not exist (relative strength is stored for 20 and 60 sessions) and the rank is by `rs_60`, so the "vs S&P 500" column is fixed at 3 months.
4. **Cards show the newest values** (non point-in-time) with their own dates, while the regime uses point-in-time inputs; a regime reason can therefore show an older value than the card (both dated). Explained in the regime "¿Qué significa?".
5. **Sign-in prompt for Visitors** in the history slot, as the umbrella asks (the premium-panels rule of hiding applies to the AI panels only).
6. **Leading card includes the OECD CLI** (enabled in Feature 2); NFCI shows "No disponible" while off. **No VIX card.**
7. **Admin section is its own tab** `/admin/market-context` inside the Admin area, next to Ingestion. Umbrella corrected.
8. **"Cached until the next pipeline run"** = version token bumped when any of the three commands finishes, plus a per-day key; there is no ledger table: the last run per command is a cache record.
9. **Monthly dates** read "Datos de septiembre de 2026", not the 1st of the month.
10. **Wording**: "target range" is avoided ("Fed funds rate range" / "Rango de tipos oficiales de la Fed") so the advice-wording guard stays simple.

## Expected File Changes

- Create (Laravel): `app/Http/Controllers/MarketContextController.php`, `app/Http/Controllers/Admin/MarketContextAdminController.php`, `app/Http/Middleware/EnsureMarketContextPublic.php`, `app/Services/MarketContext/{MarketContextPayload,MarketContextHistory,MarketContextCache,MarketContextRunLog}.php`, `app/Console/Concerns/RecordsMarketContextRun.php` (or equivalent), `app/Jobs/RunMarketContextCommand.php`, `lang/{es,en}/market_context.php`.
- Modify (Laravel): `routes/api.php`, `bootstrap/app.php` (alias), `config/market_context.php`, `config/legal.php` (`updated_at`), `app/Http/Controllers/LegalController.php`, `app/Console/Commands/{ComputeMarketContext,RefreshMacroSeries,ComputeMarketRegime}.php`, `.env.example`.
- Create (SPA): the files listed under "SPA → Files".
- Modify (SPA): `router.tsx`, `nav.ts`, `components/AppHeader.tsx`, `components/RouteMetadata.tsx`, `pages/admin/AdminLayout.tsx`, `lib/api.ts`, `i18n/format.ts`, `i18n/messages/{es,en}.ts`, `i18n/legal/{types,es,en}.ts`, `pages/LegalPage.tsx`, `i18n/guide/{es,en}.ts`.
- Tests: listed under "Verification".

## Visual Design Impact

- UI involved: yes. Source: `DESIGN.md` (cards with 2px border and hard shadow, mono numbers right-aligned, bordered mono chips, headline uppercase titles, `max-w-7xl`), dark mode tokens only (no ink hex in shadows), `alphapulse/` as reference.
- New states: regime chip (4), trend chip (4), stale badge, "No disponible" reading, dashed sign-in box, history SVG, Admin preview banner, Admin status tables. No new hues: gain/loss for signs, tertiary blue for history lines.
- New design artifact: no. Update `DESIGN.md` (Layout tab list, Core Screens "Mercado", Admin tab, open question "stale vs fresh" resolved by the badge).

## Durable Documentation Impact

- `ARCHITECTURE.md`: update. New "Market Context API and page" section (C1–C6, cache, gate, run log, job); Market Regime/Macro "no API yet" lines removed; SPA routing (`/mercado`, Admin tab).
- `CONSTRAINTS.md`: update.
  - Market Context: the history endpoint **MUST** stay in `auth:sanctum`; the metric list is closed; public endpoints **MUST** sit behind `market-context.public` until the owner's go-live; the FRED notice, ToU link and binding sentence **MUST** be shown whenever macro data is; per-card attribution **MUST** come from `config('macro.series.*.attribution')`; payload and history **MUST** be served from `MarketContextCache` and the three commands **MUST** bump it; Admin reruns are limited to the three commands, without options.
  - Public API: the gate is the one documented exception to "no authorization branch".
  - Admin Panel: the Market Context rerun job is the second Admin trigger (it does not run `ingestion:pipeline`).
- `CONTEXT.md`: no new term; add "shown on `/mercado`" to Market Context.
- `docs/user-and-access-model.md`: Visitor / Registered User / Admin rows for `/mercado`, history and the Admin tab; the go-live flag.
- `docs/risks-and-open-questions.md`: update the FRED terms risk (terms withheld with the legal notice; interim on-page clause; flag default off; owner options a–c).
- `deploy/README.md`: new item (Spanish): set `MARKET_CONTEXT_PUBLIC=true` in `shared/.env`, `php artisan config:cache`, check the page; preview as Admin before.
- "Cómo funciona" (`i18n/guide/{es,en}.ts`): new section `mercado` ("Contexto de mercado" / "Market context") after `grafico`, with entries for the regime (thresholds identical to `MarketRegimeRules`), sectors, breadth, rates and curve, liquidity, central banks and leading data, and a note on dates, staleness and sources. Same structure in both languages.
- `docs/specs/market-context.md`: corrected (done by the planner).
- `AGENTS.md`: not needed (scope paragraph already lists the feature).

## Implementation Plan (build order)

One feature, built in this order with a green test run after each step. It is large but cohesive (one page over existing data, no new table). If one implementer pass cannot finish it, the clean cut is after step 4: the backend (C1–C6, fully tested, nothing user-visible while the flag is off) is committed and steps 5–7 continue in a second session on the same feature id. Splitting `feature_list.json` is only needed if the orchestrator wants separate acceptance; then use `market-context-api` (steps 1–4) and `market-context-ui` (steps 5–7).

1. Config, gate middleware, `GET /api/legal` field, lang files.
2. `MarketContextPayload` + `MarketContextCache` + `GET /api/market-context`.
3. `MarketContextHistory` + history endpoint.
4. `MarketContextRunLog`, command recording, job, Admin endpoints.
5. SPA API types, `MarketPage` and components, i18n, header/nav/route/metadata, legal footer.
6. Admin tab; legal texts; manual.
7. Durable docs, `.\init.ps1`, manual checks.

## Implementation Tasks

- [ ] `config/market_context.php` keys; `.env.example`; middleware + alias; `LegalController` `market_context`; `lang/{es,en}/market_context.php`.
- [ ] Payload builder (C1 rules, sector key map, stale, sources, calendar); cached controller action; throttle.
- [ ] History service (8 metrics, ranges, `disabled`, rounding, consistency with the snapshot service); validation messages.
- [ ] Run log + `finished()` bump; wrap the three commands (respecting macro early exits); job with `$tries`/`$timeout`/`failed()`; Admin controller (status, rerun, 409/422 rules).
- [ ] `lib/api.ts` types and clients; `formatMarketMonth`.
- [ ] `MarketPage` and `components/market/*` with every state; `HistoryPanel`/`HistoryChart`; `MarketLegalFooter`.
- [ ] Router, nav flag, header visibility, `RouteMetadata`, Admin tab + `AdminMarketContextPage` with polling.
- [ ] Catalogs es/en (all keys above), legal texts + `LegalPage` group rendering, guide section.
- [ ] Tests below; durable docs; `feature_list.json` evidence and `PROGRESS.md`.

## Verification Plan

Laravel (`php artisan test --filter="MarketContext|LegalInfo|Localization|MarketRegime|Macro|IngestionPipelineCommand"`), offline (`Http::preventStrayRequests()`), time pinned with `Carbon::setTestNow`:

- `tests/Feature/MarketContextApiTest.php`: exact top-level and block keys on the production-like fixture; `components` passthrough incl. disabled; 11 `sector_key`s; rows ordered by `rs_rank` with a null rank last; decimals are JSON numbers; monthly readings dated 2026-09-01; stale at 7 vs 8 days for regime/sectors/breadth; `macro: null` when disabled; NFCI off → `leading.nfci` null and no Chicago Fed source; empty DB → nulls; gate: flag off guest/user 404 + admin 200, flag on guest 200; cache: direct DB change invisible until a recording command finishes (Scenario 7) and visible on the next day key.
- `tests/Feature/MarketContextHistoryTest.php`: 401/200/404-gated; 422 for `t10y3m`, `vix`, `sector_rs_60:SPY`, `sector_rs_60:AAPL`, `range=5y`; default range `1y`; hand-computed points for every metric (regime `insufficient` → `null`, spread on common dates only, net liquidity skips a missing leg, `icsa_4w` gap after a missing week); `from`/`to`/`first_date` for 1y vs 3y; NFCI off and macro disabled → `disabled: true`; last points equal `MacroSnapshotService` readings.
- `tests/Feature/AdminMarketContextTest.php`: 401/403 on both endpoints; status shape (runs null, series in config order incl. NFCI/VIXCLS disabled, distribution counts, stale flags); POST each command 202 with `Queue::fake()` asserting the job; 422 unknown command; 422 `macro:refresh` with macro off; 409 while queued/running; 202 again when the record is older than 30 min (`interrupted` shown first); the job (sync) runs the command and records `succeeded`, or `failed` with exit 1.
- `tests/Feature/MarketContextRunLogTest.php`: each command records running → succeeded; an exception records failed and rethrows; `macro:refresh` disabled and lock-held record nothing; a pipeline run records all three and bumps the cache; the pipeline exit code and existing assertions are unchanged.
- `LegalInfoTest`: `market_context` follows the flag. `LocalizationTest`: new lang file parity.

Vitest (`npm --prefix frontend run test`):

- `pages/MarketPage.test.tsx`: Scenarios 1, 4, 5 (unavailable + admin banner), 6, error + retry; es and en; regime label text and icon present (never colour alone); reasons for ok/stale/missing/disabled and an absent key skipped; insufficient body; sector toggle values and row links; monthly date line; FRED notice equals the exact string in both locales, ToU and CC BY hrefs; per-card sources from the payload; Visitor sees sign-in boxes and makes no `/history` request; auth loading renders neither; no EOD or advice wording in the rendered text.
- `components/market/HistoryPanel.test.tsx` + `HistoryChart.test.tsx`: request URLs per metric/range, sector select metric, error/empty/disabled/`availableFrom`, 401 → sign-in box; SVG path breaks at `null`, reference lines and `aria-label` summary.
- `components/AppHeader.test.tsx` (new): "Mercado" link hidden while legal info loads, hidden for Visitor/User when `market_context` false, shown when true, shown for Admin when false.
- `pages/admin/AdminMarketContextPage.test.tsx`: renders runs/snapshots/series; rerun POST then polling until terminal; 409 message; `macro:refresh` disabled with the note.
- `pages/LegalPage.test.tsx`: FRED item and paragraph only when `market_context` true; link target.
- `components/RouteMetadata.test.tsx`: `/mercado` title and `noindex, follow`.
- `i18n/translate.test.ts`: parity (existing) + advice-wording guard over `marketContext.*`; `pages/HowItWorksPage.test.tsx`: `mercado` section in both locales, structure parity, guard over its text.
- No E2E harness exists; Vitest + Laravel feature tests cover the flows, plus the manual checks.

Gate: `.\init.ps1` exit 0 (does not start servers).

Manual checks (record in `PROGRESS.md`):
- Local: Laravel on :8000 + `npm --prefix frontend run dev`; the dev DB is empty, so check the empty states, then serve the Vitest fixture through Playwright request interception (`/api/market-context*`, `/api/legal`) to check the full page at 1280 px and 390 px, light and dark, keyboard focus on toggles, disclosures and history, and no horizontal page scroll at 390 px. Never seed, restore or overwrite `database/database.sqlite`, and never run destructive or `--env=testing` artisan commands.
- Production after deploy, flag still off: sign in as Admin, open `/mercado` (preview banner) and `/admin/market-context`; compare the regime (2026-10-22 or later, neutral/score in the history), distribution and series dates with the database; open each history for 1y and 3y; then the owner decides the flag.

## Evidence To Capture

Test names and counts, `.\init.ps1` summary, screenshots (desktop/mobile, light/dark, Visitor and signed-in, Admin tab), production preview notes, recorded in `feature_list.json` evidence and `PROGRESS.md`.

## Key Implementation Risks

- Queue timeouts: `macro:refresh` may take up to ~300 s; set the job `$timeout` (900) and keep `$tries = 1`. The `database` queue `retry_after` (90 s) only matters with several workers; production runs one.
- `$request->user('sanctum')` on a route without `auth:sanctum` must resolve the stateful SPA session; cover the admin preview in a feature test.
- SQLite vs PostgreSQL dates: normalise with `substr(…, 0, 10)` and filter in PHP, as the computers do.
- Cache stampede is negligible (one payload per day per version); never cache per user.
- Legal: the binding clause lives only on `/mercado` until the legal notice is published (owner decision a–c).

## Validator Checklist

- [ ] Scope: no computer/rules/schedule change, no strip/filters/put-call, no new chart library, no FRED data to AI.
- [ ] C1–C6 shapes, status codes, gate, cache and run log exactly as specified; history auth enforced server-side (401) and the metric list closed (422).
- [ ] Page states (loading, empty, error, unavailable, stale, disabled, insufficient), icons + text everywhere colour is used, mobile 390 px without page scroll, dark mode.
- [ ] FRED notice verbatim, ToU link, binding sentence, ECB "Source: ECB statistics", OECD CC BY link; legal notice groups shown only with `market_context`.
- [ ] i18n parity, no hardcoded text, no EOD outside Admin, advice-wording guard green; manual section matches `MarketRegimeRules`.
- [ ] Admin endpoints 401/403; reruns limited to the three commands; 409/422 rules.
- [ ] Durable docs updated; `.\init.ps1` green; `feature_list.json` and `PROGRESS.md` updated; production preview done before the owner flips the flag.
