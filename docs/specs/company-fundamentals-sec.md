# Feature Implementation Spec: Verified SEC fundamentals in the company card with a grounded 3-month outlook

## Source Feature

- `id`: `company-fundamentals-sec`
- `area`: `ai` (Laravel SEC client, parser, table, scheduled command; card service/schema/prompt; SPA panel; legal texts)
- `depends_on`: `ai-company-card`, `premium-panels-auth-only`, `db-schema-market-data`, `ingestion-scheduler`, `app-multilanguage`, `legal-compliance-eu` (`premium-panels-auth-only` is `not_started`; it is listed so both features do not edit `CompanyCardPanel` at the same time)
- `status`: `not_started` (planned 2026-10-15 on `origin/main` 012334a)
- `source`: `feature_list.json`

## Goal

User request (2026-10-15): "en el análisis de IA busca la forma que salgan los datos de PER, EBITDA, etc. y una previsión a 3 meses vista". In production the AI card fills only 1–2 of 9 metrics because the model has no data source (`feature_list.json` `ai-company-card` evidence). This feature computes the metrics ourselves from the companies' own SEC filings (EDGAR XBRL "companyfacts", public domain, free, no key) plus our latest close, stores them per Instrument, shows them with their period and source, and gives them to the LLM as verified context. The LLM then writes only the qualitative part: business description and a prominent **3-month outlook** grounded on those figures.

**Scope change:** `AGENTS.md:64` lists "verified fundamentals data feeds" as out of scope. This explicit user request brings SEC EDGAR fundamentals into scope (only this source, only for the card); update `AGENTS.md` accordingly.

## Non-Goals

- No other data vendor, no news, no analyst estimates. **Forward P/E is dropped** (no SEC source).
- No fundamentals in the Screener, `screener_rows`, alerts or filters; no public (Visitor) endpoint.
- No price targets, fair values, ratings or advice; no change to the rights flag `AI_CARD_MARKET_DATA_RIGHTS_VERIFIED`.
- No change to `ingestion:pipeline`, its lock, its stages or `GET /api/instruments/{ticker}`.
- No XBRL instance-document parsing; companyfacts + submissions JSON only. No non-USD filers (the S&P 500 universe is US).

## Job Story

When I open a Candidate's company card,
I want correct, sourced ratios (P/E, EBITDA, margins, leverage, ROIC, yield) and a short 3-month scenario based on them,
so I can judge the company quickly and trust which part is filing data and which part is AI opinion.

## Users And Permissions

- Visitor: nothing (panel hidden by `premium-panels-auth-only`); `GET`/`POST /company-card` stay `401`.
- Registered User / Admin: see fundamentals (database read, also when the AI provider is disabled) and the card; generation limits unchanged.
- Operator: runs/schedules `fundamentals:refresh`; sets `SEC_USER_AGENT`.

## SEC Research (real requests, 2026-10-15, UA "Chartiko research contact@chartiko.com", 8 requests)

Endpoints: `https://www.sec.gov/files/company_tickers.json` (ticker→CIK), `https://data.sec.gov/submissions/CIK##########.json` (SIC, filings list), `https://data.sec.gov/api/xbrl/companyfacts/CIK##########.json` (all facts; 3–8 MB uncompressed, 0.2–0.5 MB gzip). Each fact has `start` (durations only), `end`, `val`, `accn`, `fy`, `fp`, `form`, `filed`, optional `frame`.

- **Coverage:** all 503 rows of `database/data/sp500.csv` map to a CIK; `BRK.B`/`BF.B` need `.`→`-`. GOOG/GOOGL (and FOX/FOXA, NWS/NWSA) share a CIK.
- **`fy`/`fp` describe the filing, not the fact** (AAPL's FY2024 D&A appears with `fy=2025` inside the FY2025 10-K). Period labels MUST come from `start`/`end`.
- **Cash-flow items are year-to-date in 10-Qs** (AAPL D&A: 3.2 B Q1, 6.7 B H1, 10.0 B 9M), and income items come both as quarter and YTD. TTM = last FY + current YTD − prior-year YTD works for both.
- **Companyfacts can lag filings:** submissions show XOM's 10-Q filed 2026-08-03, but its companyfacts newest 10-Q is 2026-05-04 (Prologis likewise). Labels and a "newer filing pending" state are required.
- **Concept availability (TTM computed with the algorithm below):**

| Company | Latest period in companyfacts | Revenue concept | EBIT path | D&A concept | Notes |
|---|---|---|---|---|---|
| AAPL | TTM to 2026-06-27 (10-Q) | RevenueFromContractWith…ExcludingAssessedTax 466.8 B (+14.2 %) | OperatingIncomeLoss 154.9 B | DepreciationDepletionAndAmortization 13.1 B | EBITDA 168.0 B (36.0 %), EPS 8.72, debt LTDNoncurrent+LTDCurrent+CommercialPaper 84.3 B, cash 39.5 B → 0.27×, tax 17.3 %, ROIC ≈ 84 %, DPS 1.05 |
| MSFT | FY to 2026-06-30 (10-K) | same, 331.8 B (+17.8 %) | OperatingIncomeLoss 155.2 B | only `Depreciation` 34.3 B + AmortizationOfIntangibleAssets 4.7 B | EBITDA 194.2 B (58.5 %), EPS 17.95, debt `LongTermDebt` 40.3 B, ≈ 0.10×, ROIC ≈ 27 %, DPS 3.64 |
| JPM (SIC 6021) | TTM to 2026-06-30 | `Revenues` only annual; RevenuesNetOfInterestExpense quarterly | none | — | bank: EBITDA, net debt/EBITDA, ROIC not meaningful; EPS 23.34, DPS 6.00 |
| XOM | TTM to 2026-03-31 (Q2 pending) | Revenues 334.2 B (−4.4 %) | **no OperatingIncomeLoss** → pretax 36.6 + InterestExpense 0.7 | DDA 27.1 B | EBITDA 64.4 B, debt LongTermDebtAndCapitalLeaseObligations 33.1 + DebtCurrent 14.5, ≈ 0.61×; DPS only `…CashPaid` 4.04 |
| PLD (REIT, SIC 6798) | TTM to 2026-03-31 (Q2 pending) | Revenues 8.95 B (+6.7 %) | OperatingIncomeLoss 4.69 B | DepreciationAndAmortization 2.71 B | EBITDA 7.40 B, ≈ 4.6×; `DividendsPerShareDeclared` only a stale FY 0.03 → must fall back to `…CashPaid` 4.10 |
| GOOGL | to 2026-06-30 | `Revenues` (RevenueFromContract… stale since 2025) | OperatingIncomeLoss | only `Depreciation` | **no `dei:EntityCommonStockSharesOutstanding`** (multi-class) → `CommonStockSharesOutstanding` 12.23 B |

Shares from `dei:EntityCommonStockSharesOutstanding` exist for AAPL, MSFT, JPM, XOM, PLD. GICS `Financials` in `sp500.csv` (76 rows) includes V, MA, PYPL, SPGI, CME, so the sector column is too coarse to detect banks; SEC SIC is used instead.

## Domain Decisions (user can change)

1. **Source and compute in Laravel** (`App\Services\Fundamentals\`), not in the engine: it is an HTTP JSON integration like the AI client, has no indicator math, and keeps Laravel the single DB owner (`CONSTRAINTS.md:117`). The engine is untouched.
2. **Anchor period:** the end date of the newest 10-K/10-Q duration fact of `EarningsPerShareDiluted` (fallback `NetIncomeLoss`). Every TTM metric must end at the anchor (±3 days); a concept whose newest TTM is older is skipped and the next concept in its chain is tried (fixes PLD/GOOGL stale concepts). Instants use the balance-sheet date = newest `StockholdersEquity` instant.
3. **TTM per concept:** forms `10-K`, `10-K/A`, `10-Q`, `10-Q/A` only; dedupe facts by `(start, end)` keeping the latest `filed` (restatements/amendments). At the anchor take the longest duration ≤ 380 days, `L`. If `L` is 350–380 days → TTM = `L` (covers Q4: the 10-K is used directly, no derived Q4). Otherwise TTM = `A + L − C`, where `A` = annual fact (350–380 d) ending the day before `L.start` (±10 d) and `C` = fact with `L`'s duration ending one year before the anchor (±10 d). Fallback: four contiguous 80–100-day quarters ending at the anchor. Else `null` (`missing_data`). Prior TTM (growth) = same at anchor − 1 year.
4. **Concept chains** (first match at the anchor wins; `us-gaap` unless noted):
   - Revenue: `Revenues`, `RevenueFromContractWithCustomerExcludingAssessedTax`, `RevenueFromContractWithCustomerIncludingAssessedTax`, `SalesRevenueNet`, `RevenuesNetOfInterestExpense` (financials: `RevenuesNetOfInterestExpense` first).
   - EBIT: `OperatingIncomeLoss`; else pretax (`IncomeLossFromContinuingOperationsBeforeIncomeTaxesExtraordinaryItemsNoncontrollingInterest`, `…MinorityInterestAndIncomeLossFromEquityMethodInvestments`) + interest (`InterestExpense`, `InterestExpenseNonoperating`, `InterestExpenseDebt`; missing → `null` EBIT).
   - D&A: `DepreciationDepletionAndAmortization`, `DepreciationAndAmortization`, `DepreciationAmortizationAndAccretionNet`, then `Depreciation` + `AmortizationOfIntangibleAssets` (0 if absent).
   - EPS: `EarningsPerShareDiluted`, `EarningsPerShareBasicAndDiluted` (unit `USD/shares`). TTM EPS by the same formula is an approximation (share counts change); documented.
   - Tax: `IncomeTaxExpenseBenefit` / pretax above.
   - Debt (instants at the balance-sheet date): non-current = `LongTermDebtNoncurrent` ?? `LongTermDebtAndCapitalLeaseObligations` ?? (`LongTermDebt` − `LongTermDebtCurrent`); current = `DebtCurrent` ?? (`LongTermDebtCurrent` ?? `LongTermDebtAndCapitalLeaseObligationsCurrent` ?? 0) + `CommercialPaper` (0) + `ShortTermBorrowings` (0). Total `null` if no long-term concept exists. Leases excluded.
   - Cash: `CashAndCashEquivalentsAtCarryingValue` ?? `CashCashEquivalentsRestrictedCashAndRestrictedCashEquivalents` (marketable securities excluded; documented).
   - Equity: `StockholdersEquity`.
   - Shares: `dei:EntityCommonStockSharesOutstanding` (newest `end`; several facts with that `end` and accession are summed) ?? `CommonStockSharesOutstanding` (newest instant) ?? `WeightedAverageNumberOfDilutedSharesOutstanding` (newest quarter).
   - Dividends per share: `CommonStockDividendsPerShareDeclared`, `CommonStockDividendsPerShareCashPaid`, else (`PaymentsOfDividendsCommonStock` ?? `PaymentsOfDividends`) / shares.
5. **Financial companies:** SIC (from submissions) 6000–6411 (banks, credit, brokers/exchanges, insurance) → `is_financial`; EBITDA, EBITDA margin, net debt/EBITDA and ROIC are `null` with reason `financial_sector`. REITs (6798) are not financial here.
6. **Metrics (`fundamentals-v1`, USD)** — computed on read from stored inputs + latest `daily_bars` close:
   `pe_ttm` = close / EPS (EPS ≤ 0 → `negative_earnings`); `eps_diluted_ttm`; `market_cap_millions` = close × shares / 1e6; `revenue_ttm_millions`; `revenue_growth_yoy_percent` = (TTM / prior − 1) × 100 (prior ≤ 0 → `not_meaningful`); `ebitda_millions` = EBIT + D&A; `ebitda_margin_percent` = EBITDA / revenue × 100; `net_debt_to_ebitda` = (debt − cash) / EBITDA (EBITDA ≤ 0 → `negative_ebitda`); `roic_percent` ≈ EBIT × (1 − t) / (debt + equity − cash), `t` = tax / pretax clamped 0–0.5, US statutory 21 % when pretax ≤ 0, invested capital ≤ 0 → `not_meaningful` (latest balance sheet, not average: documented approximation); `dividend_yield_percent` = DPS / close × 100. No bar → price metrics `no_price`. Absent concept → `missing_data`; no dividend concept → `not_reported`. Values outside the existing card bounds (`CompanyCardSchema`) → `null` `not_meaningful`.
7. **Card split:** metrics come only from fundamentals. The LLM schema becomes `company-card-v2` (drops `metrics` and `data_as_of`; keeps `company`, `outlook`, `limitations`, `sources`), prompt `company-card-prompt-v3`. v1 rows are not deleted but are no longer returned (lookup is by `schema_version`), so unverified LLM figures never show again; users see fundamentals immediately and can generate the outlook.
8. **LLM context:** a `fundamentals` block (period end, form, filing date, financial flag, revenue TTM, growth, EBITDA, margin, net debt/EBITDA, ROIC, EPS TTM, each with period) is sent **whenever a row exists**, independent of `AI_CARD_MARKET_DATA_RIGHTS_VERIFIED` (SEC data is public domain). Metrics derived from **our close** (P/E, market cap, yield, price date) are sent **only when the rights flag is true**, because they encode our market data. Technical context rules unchanged.
9. **Freshness:** a card is fresh only if, in addition to today's rules, its stored `fundamentals_accession` equals the current fundamentals `latest_accession` (both null counts as equal). A new 10-K/10-Q therefore makes the card stale and offers regeneration.
10. **Refresh cadence:** daily `fundamentals:refresh` at 07:00 `America/New_York` on weekdays, separate from the EOD pipeline: one small submissions request per CIK; companyfacts only when the newest 10-K/10-Q accession changed, a pending lag exists or `--force`. One-time backfill after deploy: `php artisan fundamentals:refresh --force` (~1,000 requests, ~4 min at 5 req/s).

## API Contract

- `GET /api/instruments/{ticker}/company-card` → `200 {card: Card|null, available: bool, fundamentals: Fundamentals|null}`; `POST` → `200 {card, reused, fundamentals}`; errors unchanged. Routes, throttles and `401` unchanged.
- `Fundamentals` = `{schema_version: "fundamentals-v1", source: "sec_edgar", currency: "USD", is_financial: bool, period_end: "YYYY-MM-DD"|null, filing: {form, filed_at, accession}|null, pending_filing: {form, filed_at}|null, price: {close, date}|null, fetched_at: ISO-8601 UTC, metrics: {<key>: {value: number|null, period: {kind: "ttm"|"fy"|"instant"|"price", end: "YYYY-MM-DD", form: string|null}|null, reason: null|"negative_earnings"|"negative_ebitda"|"financial_sector"|"missing_data"|"not_reported"|"no_price"|"not_meaningful"}}}` with the 10 keys of decision 6 in that order. Numbers rounded (2 decimals; millions 1 decimal).
- `Card` gains `fundamentals_period_end: string|null`; `content` follows `company-card-v2`.

## Acceptance Scenarios

### Scenario 1: refresh and display
Given the feature enabled with a valid `SEC_USER_AGENT` and fixtures for AAPL
When `php artisan fundamentals:refresh --ticker=AAPL` runs and a signed-in user opens the card (AI disabled)
Then one `company_fundamentals` row exists with anchor 2026-06-27, and the panel shows P/E, EPS 8.72, market cap, revenue 466,823 M (+14.2 %), EBITDA 167,959 M (36.0 %), net debt/EBITDA, ROIC and yield, each with "TTM a 27/06/2026 · 10-Q" or "precio del {date}" and the source "SEC (10-K/10-Q)".

### Scenario 2: bank, negative EPS, missing data
Given JPM (SIC 6021), a company with TTM EPS ≤ 0, and one without any D&A concept
Then JPM's EBITDA/margin/net debt/ROIC show "—" with "no aplica a entidades financieras"; P/E shows "—" "beneficio negativo"; missing D&A gives EBITDA "—" "sin datos en los informes". Other metrics still render.

### Scenario 3: grounded outlook
Given AAPL fundamentals and the provider enabled with the rights flag false
When a user generates the card
Then the request body contains the `fundamentals` block without P/E, market cap, yield or close; the response schema has no `metrics`; the stored card has `schema_version company-card-v2`, `prompt_version company-card-prompt-v3` and the fundamentals accession; with the flag true the price-derived metrics are included.

### Scenario 4: new filing makes the card stale
Given a fresh card made on accession X
When a refresh stores accession Y
Then `GET` returns `fresh: false` and the regenerate button; fundamentals show Y's periods immediately.

### Scenario 5: companyfacts lag
Given submissions list a 10-Q newer than any fact in companyfacts (XOM case)
Then the row keeps the older period, stores `pending_accession`, the UI shows "hay un informe más reciente pendiente de publicar en datos SEC", and the next run re-fetches companyfacts.

### Scenario 6: rate limit, retries, failures
Given an enabled refresh over several CIKs
Then every request carries the configured `User-Agent`; the spacing respects `SEC_REQUESTS_PER_SECOND`; a `429`/`5xx`/timeout is retried at most twice with backoff; a `404` marks `no_data`; a `403` stops the run with an error; one company's failure is recorded and others continue; GOOG and GOOGL cause one companyfacts request; nothing in the ingestion tables, `screener_rows` or pipeline lock changes.

### Scenario 7: disabled / not configured
Given `SEC_FUNDAMENTALS_ENABLED=false` or an empty/invalid `SEC_USER_AGENT`
Then the command exits 1 with a clear message and sends no request, the scheduled event does not run, the API returns `fundamentals: null` and the card still works (outlook without fundamentals, `limitations` says so).

### Scenario 8: UI language and labels
Given a stored card and fundamentals
When shown in `es` and `en`
Then a prominent heading "Previsión a 3 meses" / "3-month outlook" with an `IA`/`AI` badge and the AI warning precedes the stance chip, summary, macro, sector, drivers, risks, confidence; the metric grid has the badge "Fuente: SEC (10-K/10-Q)" and per-metric period/reason; dates use `useI18n` formatters; no "PER estimado" tile; no target/buy/sell wording.

## Repository Research

### Files Inspected

- `app/Services/CompanyCard/CompanyCardSchema.php:16-32,47-102,115-158,186` — v1 name, metric keys incl. `pe_forward`, `CURRENCY_METRICS`, schema and validator.
- `app/Services/CompanyCard/CompanyCardPrompt.php:12-47` — v2 prompt asks the LLM for metrics; rewrite as v3.
- `app/Services/CompanyCard/CompanyCardContextBuilder.php:29-80` — identity always; technical context only with the rights flag; latest bars query pattern.
- `app/Services/CompanyCard/CompanyCardService.php:41-47,117-163,192-225` — read/generate, `find()` by schema version (v1 rows become invisible), `isFresh()`, `payload()`.
- `app/Http/Controllers/CompanyCardController.php:23-53` — add `fundamentals` to both responses.
- `config/ai_card.php:47-48` — `schema_version`/`prompt_version` to bump.
- `routes/api.php:43-50`, `routes/console.php:33-69` — protected card routes; schedule style (named events, timezone, `withoutOverlapping`).
- `app/Console/Commands/CopySqliteToPgsql.php:47-72` — classify the new table (`SKIPPED_TABLES`).
- `app/Models/Instrument.php:13,46-104` — relations (`dailyBars`, `latestBar`).
- `frontend/src/components/chart/CompanyCardPanel.tsx:29-31,100-111,186-293`, `frontend/src/lib/api.ts:649-712`, `frontend/src/i18n/messages/es.ts:54-100` — metric tiles, types, catalog (`pe_forward`, `dataAsOf`, `warning`).
- `frontend/src/i18n/legal/en.ts:67-69,156` (+ `es.ts`), `config/legal.php:35` — AI privacy text, not-advice line, `updated_at`.
- `.env.example:77-92`, `ARCHITECTURE.md:211-219,251-259`, `CONSTRAINTS.md:176-183,185-195,216`, `AGENTS.md:7,62-64`, `CONTEXT.md:85-87`, `docs/domain-model.md:17,28,47`, `docs/risks-and-open-questions.md:51-52`, `docs/ai-company-card-operations.md`.
- `database/data/sp500.csv` — ticker format (`BRK.B`) and GICS sectors.

### Existing Patterns To Follow

- Opt-in external integrations with `enabled` flags and env config (`config/ai_card.php`, `config/forecast.php`); `Http::fake` only in tests; no payloads in logs.
- Per-item try/catch so one failure never stops a batch (`CONSTRAINTS.md:125`); idempotent upserts keyed by a unique column; `Cache::lock` for overlap with a skip that exits 0 (`CONSTRAINTS.md:192`).
- Laravel 13 models (`#[Fillable]`, `casts()`, `HasFactory`), decimal columns cast `decimal:4`, `RefreshDatabase`, portable SQL.

### Current Gaps

- No SEC client, table, command, config or fixtures (`tests/fixtures` does not exist yet).
- `CONSTRAINTS.md:193` forbids retries/backoff for **scheduling of the EOD pipeline**; this feature needs bounded retries in its own client, so the new section must state the scope explicitly.

## Technical Approach

**Config** `config/fundamentals.php`: `enabled` `SEC_FUNDAMENTALS_ENABLED` [false]; `user_agent` `SEC_USER_AGENT` [empty; valid only if it contains a name and an email, e.g. `Chartiko ops@chartiko.com`]; `requests_per_second` `SEC_REQUESTS_PER_SECOND` [5, clamped 1–9]; `timeout_seconds` `SEC_TIMEOUT_SECONDS` [30]; `retries` [2, backoff 2 s then 8 s]; `data_base_url` `SEC_DATA_BASE_URL` [`https://data.sec.gov`]; `tickers_url` `SEC_TICKERS_URL` [`https://www.sec.gov/files/company_tickers.json`] (overridable only for local QA fakes; `https://` or localhost); `tickers_cache_days` [7]; constants `schema_version` `fundamentals-v1`, `financial_sic` `[6000, 6411]`.

**Services** `App\Services\Fundamentals\`: `SecEdgarClient` (`tickers()`, `submissions($cik)`, `companyFacts($cik)`; sets `User-Agent` and `Accept-Encoding: gzip, deflate`, spacing through an injectable `Sleeper`, retries, `SecUnavailable` with reason, logs status/class only; does not follow redirects); `CompanyFactsReader` (keeps only whitelisted concepts right after decoding, to bound memory on 8 MB files); `TtmCalculator` (decisions 2–3); `FundamentalsExtractor` (decision 4–5 → inputs with provenance); `FundamentalsMetrics` (decision 6, pure, used by API and context builder); `FundamentalsRefresher` (per-CIK flow); `CompanyFundamentalsService::payload(Instrument)`.

**Storage** `company_fundamentals`: `id`, `instrument_id` unique FK cascade, `cik` string(10) nullable, `sic` string(4) nullable, `sic_description` string(120) nullable, `is_financial` bool, `status` string(16) (`ok`|`partial`|`no_cik`|`no_data`|`error`), `currency` string(3), `period_end` date nullable, `prior_period_end` date nullable, `balance_sheet_date` date nullable, `shares_as_of` date nullable, `latest_accession` string(20) nullable, `latest_form` string(10) nullable, `latest_filed_at` date nullable, `pending_accession`/`pending_form`/`pending_filed_at` nullable, decimal(24,4) nullable inputs `revenue_ttm`, `revenue_prior_ttm`, `ebit_ttm`, `pretax_ttm`, `tax_ttm`, `da_ttm`, `eps_diluted_ttm`, `dividends_per_share_ttm`, `total_debt`, `cash`, `equity`, `shares_outstanding`, `inputs` json (per input: concept, start, end, form, accession, method), `fetched_at`, `last_error` string(200) nullable, `timestamps`. Model `CompanyFundamental` (`decimal:4` casts). Table in `SKIPPED_TABLES` (derived, rebuilt by the command). `ai_company_cards` gains `fundamentals_accession` string(20) nullable and `fundamentals_period_end` date nullable.

**Command** `fundamentals:refresh {--ticker=*} {--force}`: refuse (exit 1, no request) unless enabled and UA valid; `Cache::lock('fundamentals:refresh', 3600)` (held → skip, exit 0); load ticker map (cached `tickers_cache_days`); universe = `config('ingestion.universe')` members (or `--ticker`), normalized `.`→`-`, grouped by CIK; per CIK in try/catch: submissions → SIC + newest 10-K/10-Q(/A); skip when unchanged, no pending and no `--force`; else companyfacts → extract → upsert rows for every Instrument of that CIK in one transaction; set/clear `pending_*` when submissions are newer than companyfacts; `404` → `no_data`; `403` → abort run (exit 1). Prints counts; exit 0 unless every attempted CIK failed. Writes nothing else; no `screener:refresh` (fundamentals are not Screener data).

**Schedule** (`routes/console.php`): `Schedule::command('fundamentals:refresh')->name('fundamentals-refresh')->dailyAt('07:00')->timezone(config('ingestion.timezone'))->weekdays()->when(fn () => (bool) config('fundamentals.enabled'))->withoutOverlapping(90)->runInBackground()`.

**Card**: `CompanyCardSchema` v2 (name `company_card_v2`, no `metrics`/`data_as_of`); `CompanyCardPrompt` v3 (role: summarizer that receives verified SEC-derived figures; base the outlook on them and on general macro/sector knowledge; may cite the given figures with their period, must not state other financial figures or contradict them; if no fundamentals, say so in `limitations`; same no-advice rules; stance is a scenario); `CompanyCardContextBuilder` adds `fundamentals` (decision 8); `CompanyCardService` stores accession/period, extends `isFresh`, adds `fundamentals_period_end` to the payload; controller adds `fundamentals`. `config/ai_card.php` → `company-card-v2`, `company-card-prompt-v3`.

**SPA**: types `Fundamentals`, `FUNDAMENTAL_METRICS` (10 keys) in `api.ts`, remove `COMPANY_CARD_METRICS`/`metrics` from `CompanyCardContent`; `CompanyCardPanel` order for signed-in users: header (title "Ficha de empresa"); **"Previsión a 3 meses"** block (large heading, `IA` badge, AI warning, generate/stale/error states, stance chip, confidence, summary, macro/sector, drivers/risks); **"Métricas clave"** block with badge "Fuente: SEC (10-K/10-Q)", tiles (value + unit, period line or reason, `—`), footer "Datos de informes presentados ante la SEC (EDGAR), calculados por Chartiko · precio de cierre del {date} · actualizado {date}" and the pending-filing note; then AI business description, sector/industry, limitations, sources and the generation footer. Fundamentals render even when `card` is null or `available` is false.

## Expected File Changes

- Create: `config/fundamentals.php`; `app/Services/Fundamentals/{SecEdgarClient,Sleeper,SecUnavailable,CompanyFactsReader,TtmCalculator,FundamentalsExtractor,FundamentalsMetrics,FundamentalsRefresher,CompanyFundamentalsService}.php`; `app/Console/Commands/RefreshFundamentals.php`; `app/Models/CompanyFundamental.php` + factory; migrations `create_company_fundamentals_table`, `add_fundamentals_to_ai_company_cards`; `tests/fixtures/sec/` (trimmed real JSON: `company_tickers.json` with ~8 entries, `submissions_*.json` and `companyfacts_*.json` for AAPL 320193, MSFT 789019, JPM 19617, XOM 34088, GOOGL 1652044, whitelisted concepts and facts filed since 2023, each < 150 KB; provenance and fetch date in the test docblock); `tests/Unit/Fundamentals/{TtmCalculatorTest,FundamentalsExtractorTest,FundamentalsMetricsTest}.php`; `tests/Feature/FundamentalsRefreshCommandTest.php`.
- Modify: `app/Services/CompanyCard/{CompanyCardSchema,CompanyCardPrompt,CompanyCardContextBuilder,CompanyCardService}.php`, `CompanyCardController.php`, `app/Models/AiCompanyCard.php`, `config/ai_card.php`, `routes/console.php`, `CopySqliteToPgsql.php`, `.env.example` (`SEC_*` block, disabled), `tests/Feature/CompanyCardFeatureTest.php`, `tests/Unit/CompanyCardSchemaTest.php`, `frontend/src/lib/api.ts`, `CompanyCardPanel.tsx` (+ test), `frontend/src/i18n/messages/{es,en}.ts`, `frontend/src/i18n/legal/{es,en}.ts`, `config/legal.php`.
- Docs below; `docs/ai-company-card-operations.md` (SEC section).

## Visual Design Impact

- UI involved: yes; `DESIGN.md` tokens and existing card/tile/badge/chip styles; no new colors. Two clearly labelled blocks (AI vs SEC). Stance chip stays neutral. Mobile 390 px: single-column tiles, long period lines wrap. Add the layout to the `DESIGN.md` Company Card bullet. New design artifact: no.

## Durable Documentation Impact

- `AGENTS.md`: scope guard — add "Also in scope since 2026-10-15: verified company fundamentals computed from SEC EDGAR filings for the AI Company Card (`company-fundamentals-sec`)" and change line 64 to "other fundamentals data feeds and news".
- `CONSTRAINTS.md`: new `## Company Fundamentals (SEC)`: SEC only through `SecEdgarClient` with a descriptive `SEC_USER_AGENT`, ≤ `SEC_REQUESTS_PER_SECOND` (max 9), bounded retries (this client only; EOD scheduling rule at `:193` unchanged); command opt-in, never part of `ingestion:pipeline`, per-CIK isolation, idempotent upsert; labels from `start`/`end`, never `fy`/`fp`; TTM/anchor/financial rules as named constants with tests; tests offline with committed trimmed fixtures. Amend `## AI Company Card`: metrics come only from fundamentals (`company-card-v2` has none); fundamentals may be sent to the provider regardless of the rights flag, price-derived ones only with it; the card is stale when the fundamentals accession changes.
- `ARCHITECTURE.md`: new "Company Fundamentals (SEC)" section (client, refresh flow, table, metrics on read) and `SEC_*` under Configuration; update the AI Company Card section (schema v2, prompt v3, context, freshness, SPA layout).
- `CONTEXT.md`: add `### Fundamentals (Fundamentales SEC)`; update the Company Card entry (figures verified from filings, outlook by AI).
- `docs/domain-model.md`: `Company Fundamentals` (one per Instrument, refreshed from filings).
- `docs/risks-and-open-questions.md`: replace the hallucination risk (`:51`) with residual risks below; add the SEC UA open question.
- `docs/ai-company-card-operations.md`: enabling SEC, backfill command, monitoring `status`/`last_error`.
- Legal: legal notice "not advice" line → "Financial figures are computed by Chartiko from public SEC EDGAR filings and our latest close and may differ from other sources or lag the latest filing; the AI-written outlook may contain errors." Privacy "AI features": the provider also receives public financial figures from SEC filings; no personal data. Name SEC EDGAR as data source. Bump `config/legal.php` `updated_at`.

## Implementation Plan

1. Fixtures (one-off fetch with the owner's UA, trimmed), config, `SecEdgarClient` + tests.
2. `TtmCalculator`, `FundamentalsExtractor`, `FundamentalsMetrics` with unit tests on fixtures and synthetic facts.
3. Migrations, model, refresher, command, schedule, copy-command classification; feature tests.
4. Card schema v2, prompt v3, context, service freshness, controller payload; update card tests.
5. SPA types, panel layout, catalogs, Vitest; legal texts; docs; `.\init.ps1`; QA; evidence.

## Implementation Tasks

- [ ] `config/fundamentals.php`, `.env.example` `SEC_*` block (disabled, empty UA).
- [ ] Client with UA header, gzip, spacing, retries, reasons; tests (`Http::assertSent` header, fake sleeper counts, 429→200, 403, 404, timeout).
- [ ] TTM calculator tests: FY-only, Q1/Q2/Q3 YTD, quarter-only fallback, restated fact (later `filed` wins), 10-Q/A, missing comparative → null, stale concept skipped at anchor, labels from dates.
- [ ] Extractor/metrics tests on fixtures: AAPL values of the research table (±0.1 %), MSFT D&A sum, JPM financial nulls, XOM pretax+interest EBIT, `DebtCurrent`, `CashPaid` DPS and pending filing, GOOGL shares fallback; negative EPS; EBITDA ≤ 0; no price; tax clamp; invested capital ≤ 0.
- [ ] Migrations, model, factory, `SKIPPED_TABLES`; command + refresher + schedule; feature tests (disabled/no UA, unchanged accession skips companyfacts, GOOG/GOOGL single fetch, one failure isolated, lock skip, no ingestion/screener writes, `schedule:list` shows `fundamentals-refresh`).
- [ ] Card v2/v3 + context + freshness + payload; feature tests for Scenarios 3, 4, 7 and v1 rows hidden.
- [ ] SPA types, panel, catalogs (remove `pe_forward`, `dataAsOf`; add metric/reason/source/period keys), Vitest (Scenario 8, fundamentals without card, AI unavailable, no target wording).
- [ ] Legal, docs, ops doc; evidence in `feature_list.json`/`PROGRESS.md`.

## Verification Plan

- `php artisan test --filter="Fundamentals|CompanyCard"`; `npm --prefix frontend run test`, `lint`, `build`; `.\init.ps1` exit 0 (SQLite); CI runs PostgreSQL too (decimal(24,4), date columns).
- No test calls SEC or an AI provider (`Http::fake` with `Http::preventStrayRequests()` in the new tests).
- E2E: no persistent harness; real-browser QA instead.
- Real-browser QA (Playwright/Edge, throwaway SQLite DB outside the repo): a local fake SEC server serving the fixtures (`SEC_DATA_BASE_URL`/`SEC_TICKERS_URL` on 127.0.0.1) and the fake OpenAI-compatible server used for `ai-company-card`; run the command, then as a signed-in user check AAPL, JPM, XOM cards (values, periods, reasons, pending note, SEC badge, "Previsión a 3 meses" heading, es/en, 390 px), generate an outlook and inspect the fake provider body (fundamentals present, no price metrics with the flag off, no user data). Visitor sees no panel.
- Optional owner-run real check (never CI): with the real UA, `php artisan fundamentals:refresh --ticker=AAPL --ticker=JPM --ticker=XOM` and compare against the research table.

## Evidence To Capture

- Test counts and names (TTM edge cases, banks, pending lag, rate-limit/retry); `init.ps1` totals; fake-provider body excerpt; QA screenshots or DOM notes in both languages.

## Risks

- **Concept variance across 500 filers:** chains cover the sampled companies; others may give `missing_data`. Mitigation: provenance in `inputs`, `status partial`, a one-off coverage report from the backfill (count of nulls per metric) recorded in evidence.
- **Companyfacts lag** (XOM, PLD observed): figures can be one quarter old; mitigated by labels and `pending_*`.
- **Approximations:** TTM EPS, ROIC on period-end capital, D&A from `Depreciation` + intangibles, multi-class market cap using one class's close; all documented in the UI footer/ops doc.
- **Memory/time:** JPM companyfacts is 8 MB raw; decode one file at a time and drop unused concepts; backfill ~4 min.
- **SEC access policy:** missing/undeclared UA gets `403`; the owner's contact is required.
- **Spec size:** this is at the upper bound of one feature; if implementation stalls, split into `company-fundamentals-ingest` (steps 1–3) and `company-card-grounded-outlook` (steps 4–5).

## Open Questions For The User

1. Contact for `SEC_USER_AGENT` (SEC requires a real name + email, e.g. `Chartiko <email>`); the `@chartiko.com` mailbox is pending.
2. Accept dropping forward P/E (no free verified source)?
3. Show the 3-month outlook above the metrics (proposed) or below?

## Validator Checklist

- [ ] Only SEC EDGAR is used, via the client, with UA, spacing and bounded retries; nothing changes in the EOD pipeline.
- [ ] TTM/anchor/fallback/financial rules match decisions 2–6 and are covered by fixture-based tests.
- [ ] Metrics shown only from fundamentals; LLM schema v2 has no metrics; v1 cards not shown; staleness on new filing.
- [ ] Provider context: fundamentals always, price-derived only with the rights flag, never user data.
- [ ] UI separates SEC metrics from the AI outlook, es/en complete, no target/advice wording, 390 px OK.
- [ ] New table classified and portable; legal texts and docs updated; `.\init.ps1` passes; evidence recorded.
