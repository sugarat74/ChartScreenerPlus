# Feature Implementation Spec: Filter and sort Candidates by table columns

## Source Feature

- `id`: `screener-table-column-controls`
- `area`: `screener`
- `depends_on`: `screener-api`, `screener-filters-ui`, `candidate-list-ranking`, `screener-pagination`, `screener-precomputed`, `saved-screeners`, `screener-company-classification`
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Let any Screener user filter and order the Candidate table from each displayed column heading. The columns are Symbol/Company, Sector, Industry, HQ Country, Close, Change, RVOL, RSI (14), and Signals. Controls must work across the full matching universe, not just the currently visible page, and the server response remains the single source for filtered and ordered Candidates.

Make table criteria URL-backed using the existing Screener state conventions. The same canonical filter shape must survive paging and Saved Screener save/load, so reopening a URL or a saved definition restores the controls and results.

## Non-Goals

- No new table columns, universe, pagination size, data source, company classification data, or changes to existing signal/pattern meanings.
- No client-side filtering or sorting of the current page.
- No automatic expansion of the AI natural-language Screener schema or prompt. Until that feature is separately updated, unsupported requests remain reported as unsupported.
- No entitlement gate for basic company metadata filters; Market Context-only sector trend and relative-rank filters keep their existing authorization behavior.
- No changes to the Screener's default ranking when the user has not selected a column sort.

## Job Story

When I inspect the Candidate table, I want to filter and order by the information shown in its headings, so I can narrow and compare the complete Candidate set without exporting it or changing pages manually.

## Users And Permissions

- Visitor, Registered User and Admin may use basic column filters and sorts wherever they can access the Screener.
- Preserve existing server authorization for Market Context filters. Column controls for ordinary Sector, Industry and HQ Country values do not require Market Context access.

## Acceptance Scenarios

### Scenario 1: Text identity filters

Given the Candidate table is available,
When a user filters Symbol/Company with a ticker or company-name fragment,
Then the API returns only Candidates whose ticker or company name contains the case-insensitive fragment, including matches outside the current page.

### Scenario 2: Classification filters

Given Candidates have nullable sector, industry and country fields,
When a user selects a Sector or HQ Country, or enters an Industry fragment,
Then only matching non-null values are returned; labels remain localized in the UI while API values use stable canonical codes/source values.

### Scenario 3: Numeric ranges

Given a numeric table column is selected,
When the user sets minimum and/or maximum bounds for Close, Change, RVOL or RSI,
Then bounds are inclusive, multiple active criteria combine with AND, null values do not match an active bound, and invalid/inverted ranges are rejected consistently by the API.

### Scenario 4: Signals filter

Given the existing Signal, Pattern and Pattern status filters are supported,
When the user filters from the Signals heading,
Then the existing URL/API semantics are reused (OR within each selected type list, AND between signal and pattern criteria); no new signal or pattern vocabulary is introduced.

### Scenario 5: Sort by any column

Given Candidates include ties and null values,
When the user activates a column heading sort in ascending or descending order,
Then the full server result is ordered by that column, nulls are last in either direction, ties use ticker ascending as the deterministic tie-break, and pagination follows that same order.

### Scenario 6: URL and saved state

Given the user applies column criteria and a column sort,
When they reload/share the URL or save and reopen a Screener,
Then all criteria and sort restore and the same API result is requested. Clearing criteria clears column filters but preserves the selected sort, matching existing behavior.

### Scenario 7: Empty and invalid values

Given criteria produce no matches or a URL contains malformed values,
When the Screener loads,
Then a valid no-match combination uses the existing empty state; malformed values are normalized or receive the existing localized validation error and never become SQL identifiers or raw SQL fragments.

## Repository Research

### Files Inspected

- `frontend/src/components/screener/CandidateTable.tsx` — current nine headings and row fields; table is presentational and horizontally scrollable.
- `frontend/src/pages/ScreenerPage.tsx`, `frontend/src/lib/screenerFilters.ts` — URL-owned filter/sort state, API fetch keys, paging, and saved-filter normalization.
- `frontend/src/lib/api.ts`, `frontend/src/components/screener/CandidateResults.tsx` — typed Candidate shape and result rendering.
- `app/Services/Screener/CandidateQuery.php` — SQL filtering, sort allowlist, null-last ordering, ticker tie-break, and pagination query.
- `app/Services/Screener/ScreenerFilterParser.php`, `app/Http/Controllers/ScreenerController.php` — API validation and normalized filter contract.
- `app/Models/ScreenerRow.php` — precomputed sortable/filterable row fields.
- `docs/specs/{screener-filters-ui,candidate-list-ranking,screener-pagination,screener-precomputed,saved-screeners,screener-company-classification,ai-screener-natural-language}.md` — existing contracts and scope boundaries.
- `DESIGN.md`, `docs/user-and-access-model.md` — UI tokens and public Screener access.

### Existing Patterns To Follow

- Filtering, ordering, count and pagination are server-side in `CandidateQuery`; the SPA serializes URL state and renders API order unchanged.
- `ScreenerFilterParser` is shared by query input, saved definitions and AI validation; add only validated public parameter names and normalize the same shape everywhere.
- Missing numeric values are nullable; current sorts put nulls last and tie-break by ticker.
- Candidate classification columns are now visible, but `screener-company-classification` is `passing`, pending independent validation. Keep it as a prerequisite.

### Current Gaps

- Public API has only technical and Market Context criteria; it does not accept identity, ordinary classification, Close or column range filters.
- Sort allowlist currently covers RVOL, RSI, Change and signal count; it lacks Symbol/Company, Sector, Industry, Country and Close. Signals heading has no dedicated interactive control.
- There is no per-column filter/sort UI component or query-state representation for these criteria.
- No persistent browser E2E command is configured; use focused Laravel/Vitest coverage and documented manual responsive/keyboard checks.

## Technical Approach

1. Extend the canonical Screener filter contract with optional criteria: `search` (ticker/company substring), `sector`, `industry` (substring), `country`, and inclusive min/max bounds for `close`, `change_percent`, `rvol`, and `rsi14`. Define strict lengths, enums/codes, numeric ranges and range-order validation. Preserve current Market Context key authorization and validation.
2. Expand the API sort allowlist for every visible column. Use stable canonical values for text sorting (company name then ticker for Symbol/Company; sector, raw industry, country code for classification); numeric columns sort by their stored value; Signals sorts by signal count. Keep nulls last both directions and ticker ASC as final tie-break. Existing sort values remain compatible.
3. Apply criteria in `CandidateQuery` before counting and pagination. Use parameterized query builder predicates and a fixed sort map; never interpolate user text as SQL structure. Confirm the precomputed Screener row has the necessary values and add only narrowly needed indexes if query evidence supports them.
4. Extend URL parse/patch/key, API serialization, clear-filter behavior, saved definition validation/normalization, and localized filter summaries. Filter criteria clear with existing filters; sort persists on clear. Do not change NL Screener's strict schema/prompt in this feature.
5. Add accessible controls associated with each column heading: sort toggle with current direction exposed to assistive technology, and a compact filter control suited to that field (text, canonical select, numeric bounds, or existing signal/pattern selection). Keep states visible, keyboard operable, and usable in the existing horizontally scrolling table. Add Spanish and English strings.
6. Preserve current Candidate columns, row actions, responsive overflow, loading/refreshing/empty/error states, access rules, default sort, and Market Context capability checks.

## Expected File Changes

- `app/Services/Screener/ScreenerFilterParser.php`, `CandidateQuery.php`, `ScreenerController.php` — normalized filter/sort validation and SQL behavior.
- `app/Http/Requests/` or focused Screener validation classes if repository conventions support them — strict column parameter validation.
- `frontend/src/lib/screenerFilters.ts`, `frontend/src/lib/api.ts`, `frontend/src/pages/ScreenerPage.tsx` — URL state, serialization and summaries.
- `frontend/src/components/screener/CandidateTable.tsx` and new focused column-control component(s) — accessible table controls.
- `frontend/src/i18n/messages/{es,en}.ts` — labels, help, validation and active-filter summaries.
- Relevant Laravel Feature tests and frontend Vitest tests; `docs/specs/saved-screeners.md` only if its canonical payload contract needs updating; `PROGRESS.md`, `feature_list.json`.

## Visual Design Impact

- UI involved: yes.
- Design source: `DESIGN.md` and existing Candidate table/filter controls.
- Screens/states: `/screener` table headings, active filters, sort direction, loading/refresh, empty/error and narrow viewport.
- New design artifact required: no. Follow existing border, shadow, typography, focus and color tokens. Keep controls compact and avoid hiding sort/filter state behind hover-only interactions.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update only if the API/filter boundary or shared normalization architecture materially changes; expected update to explain the expanded canonical server-side filter contract.
- `CONSTRAINTS.md`: update public API validation and server-side filtering/sorting rules if changed.
- `AGENTS.md`: not needed; no workflow/startup change.
- `docs/specs/saved-screeners.md`: update if added criteria alter the persisted schema; AI Screener spec stays unchanged in this scope.

## Implementation Plan

1. Define the canonical parameter names, validation limits, SQL predicates, sort keys, null handling and saved-state normalization.
2. Implement backend validation/query behavior with focused API and saved-Screener regression coverage.
3. Add URL-backed state and accessible per-heading controls with es/en localization.
4. Verify filter/sort/pagination/save/load integration and responsive interaction; update durable docs and harness evidence.

## Implementation Tasks

- [ ] Finalize and document parameter names and numeric bounds in parser/API tests.
- [ ] Extend `CandidateQuery` filters and sort map with safe, deterministic SQL ordering and pagination.
- [ ] Extend URL state, API client, canonical saved-filter normalization and active-filter summaries.
- [ ] Add per-column accessible filter/sort controls and es/en messages.
- [ ] Cover Visitors and signed-in users, malformed values, empty values, nulls, ties, pagination and saved-state round trips.
- [ ] Update architecture/constraints and the accepted specs affected by the final contract; record evidence in `feature_list.json` and `PROGRESS.md`.

## Verification Plan

- Run focused Screener API, CandidateQuery, Saved Screener and parser/schema tests; verify parameters are allowlisted, SQL values bound, malformed sort values cannot affect SQL, and old queries remain unchanged.
- Run focused Vitest for URL parse/patch, sort keys, summaries, each column control, localization and keyboard interaction; run `npm --prefix frontend run lint` and `npm --prefix frontend run build`.
- Run the repository standard `.\init.ps1` gate after focused checks. It must execute non-blocking verification and must not start long-running development servers.
- Manually verify API ordering/filtering across page boundaries, saved URL/load, no matches, anonymous access, es/en labels and keyboard use at desktop and narrow widths. No persistent E2E runner currently exists; focused API/helper/component coverage plus this manual flow is the available evidence.

## Evidence To Capture

- Focused backend/frontend test results, lint/build and `.\init.ps1` exit status.
- Request/response examples for each column's filter and sort, including null-last ties and page boundaries.
- URL and Saved Screener round trips, anonymous usage, keyboard operation and desktop/narrow viewport observations.
- Updated `feature_list.json` and concise `PROGRESS.md` entry; identify any documented limitation or data-source null behavior.

## Validator Checklist

- [ ] All nine displayed columns have a filter where applicable and ascending/descending sort, with explicit behavior for Signals.
- [ ] Filtering/sorting apply before server pagination; the SPA never locally narrows or reorders a page.
- [ ] URL, saved definitions, summaries, clear-filter and paging behavior are consistent.
- [ ] Nulls, ties, locales, invalid input and SQL injection boundaries are handled deterministically.
- [ ] Visitors and registered users retain current access; Market Context-only filters remain protected.
- [ ] Existing filter/sort values and ranking defaults remain backward compatible.
- [ ] Focused tests, lint/build, standard gate and evidence are recorded; durable docs match the implemented contract.
- [ ] No AI Screener schema/prompt change or unrelated feature work is included.

