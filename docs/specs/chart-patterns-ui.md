# Feature Implementation Spec: Chartist patterns in the Screener and the chart

## Source Feature

- `id`: `chart-patterns-ui`
- `area`: `frontend` + `api`
- `depends_on`: `chart-patterns-detect`, `screener-filters-ui`, `chart-interactive`, `saved-screeners`, `app-multilanguage`
- `status`: implemented 2026-10-10 (planned the same day)
- `source`: `feature_list.json`

## Goal

Make the patterns stored by `chart-patterns-detect` useful to Visitors and Registered Users: filter Candidates by pattern type and status in the Screener (and in Saved Screeners), see pattern badges in the Candidate list, and see each active pattern drawn on the Instrument chart as its key points plus one breakout-level line, with an accessible text list. All copy in Spanish and English.

## Non-Goals

- No new detection rules or parameter changes (owned by `chart-patterns-detect`).
- No price targets, measured moves, stops, probabilities or "buy/sell" wording.
- No alerts on patterns (a later extension of `alerts-engine`).
- No pattern history or backtesting; only the current active set.

## Job Story

When I scan the S&P 500 for setups,
I want to list the stocks that are forming or have just confirmed a Double Bottom or a Cup with Handle and see the formation on the chart,
so I can review the geometry myself before deciding anything.

## Users And Permissions

- Visitor and Registered User: read patterns via the public screener and instrument-detail APIs (same anonymous, throttled access as today).
- Registered User: Saved Screeners can include the pattern filter.
- Admin: no extra rights.

## Acceptance Scenarios

### Scenario 1: Filter by pattern
Given stored patterns for several instruments,
When I call `GET /api/screener?pattern=double_bottom,cup_with_handle&pattern_status=confirmed`,
Then only universe members with an active pattern of one of those types and that status are returned, each with `patterns: [{type, status, breakout_level, end_date}]`.

### Scenario 2: Validation
Given `?pattern=triangle` or `?pattern_status=maybe`,
Then the API returns `422` with an `errors.pattern` / `errors.pattern_status` key (localized message, same keys in both languages).

### Scenario 3: Chart drawing
Given an instrument with a confirmed `double_bottom`,
When I open `/instruments/{ticker}`,
Then the chart shows markers on the pattern points (labelled by role), one horizontal price line at `breakout_level` titled with the pattern name, and the accessible panel lists the pattern, status, dates and level. No target line exists.

### Scenario 4: Saved Screener round-trip
Given I save a screener with a pattern filter,
When I re-apply it,
Then the same pattern filter is restored; Saved Screeners created before this feature still load (missing keys default to "no pattern filter").

### Scenario 5: Languages and mobile
Given English is selected, pattern names, statuses and roles are in English; at 390 px the filter group and badges do not overflow.

## Repository Research

### Files Inspected

- `app/Http/Controllers/ScreenerController.php` — `SIGNAL_TYPES`, `?signal` parsing (comma/array), `422` localized errors, eager loads, `signal_count_desc` sort.
- `app/Http/Controllers/InstrumentController.php` — detail payload `{instrument, bars, snapshot, signals}`.
- `app/Models/SavedScreener.php` — `filters` is the canonical seven-key object in API param names.
- `frontend/src/lib/screenerFilters.ts` — filter state ⇄ URL params, `SCREENER_SIGNAL_TYPES`, active-filter count.
- `frontend/src/components/screener/{ScreenerFilterPanel,CandidateTable,SavedScreenersPanel}.tsx`.
- `frontend/src/components/chart/{InteractiveChart,SignalLevelsPanel}.tsx` — `lightweight-charts` 5.2.1 price lines; accessible panel.
- `CONSTRAINTS.md` (Frontend Chart: at most the pivot line, never stop/target), `DESIGN.md`.

### Existing Patterns To Follow

- Fixed vocabulary arrays on both sides; unknown values are `422`; filters live in the URL; i18n keys in `frontend/src/i18n/messages/*.ts` and `lang/*/messages.php`.
- Chart levels are guarded with `Number.isFinite`; the canvas is mirrored by an accessible text panel.

### Current Gaps

- No `pattern`/`pattern_status` params, no `patterns` in payloads, no pattern labels, markers or panel.

## Technical Approach

1. **Screener API:** add `PATTERN_TYPES` and `?pattern=` (comma or array, same parser as `?signal`), `?pattern_status=forming|confirmed|any` (default `any`, only meaningful with `pattern`). Eager-load `chartPatterns` of the latest `as_of_date`. Candidate payload gains `patterns` (type, status, breakout_level, end_date). Optional sort `pattern_recent_desc` is **not** added (non-goal).
2. **Instrument detail API:** add `patterns: [{type, status, start_date, end_date, breakout_level, points: [{date, price, role}]}]`.
3. **Saved Screeners:** canonical object grows to nine keys (`pattern`, `pattern_status`); validation accepts both; reading an old row fills defaults. No data migration.
4. **Frontend filters:** `screenerFilters.ts` gets `patterns` + `patternStatus`; `ScreenerFilterPanel` adds a "Patrones" chip group (four types) and a status segmented control; active-filter count includes them.
5. **Candidate list:** compact pattern badges next to signal badges (type label + status dot), text not colour-only.
6. **Chart:** `InteractiveChart` adds series markers for each point (role label short text) and one `createPriceLine` at `breakout_level` per active pattern (title = pattern label). New `PatternPanel` lists patterns accessibly. Lines limited to active patterns; no extrapolation.
7. **Copy:** each pattern label has a one-line neutral description in the panel ("formación detectada automáticamente; informativa"), consistent with the footer disclaimer.

## Expected File Changes

- Laravel: `app/Http/Controllers/ScreenerController.php`, `app/Http/Controllers/InstrumentController.php`, `app/Http/Controllers/SavedScreenerController.php` (+ its request validation), `lang/{es,en}/messages.php`; tests `tests/Feature/{ScreenerApiTest,InstrumentDetailApiTest,SavedScreenersApiTest}.php`.
- Frontend: `frontend/src/lib/api.ts`, `frontend/src/lib/screenerFilters.ts`, `frontend/src/components/screener/{ScreenerFilterPanel,CandidateTable}.tsx`, `frontend/src/components/chart/InteractiveChart.tsx`, `frontend/src/components/chart/PatternPanel.tsx` (create), `frontend/src/i18n/messages/{es,en}.ts`, tests next to the touched components.

## Visual Design Impact

- UI involved: yes. Source: `DESIGN.md`.
- States: filter chips (none/selected), status control, badges (forming/confirmed), chart markers + level line, panel empty state ("Sin patrones activos"), loading/error unchanged.
- New design artifact: no; reuse chip, badge and price-line styles. Status must not rely on colour alone.

## Durable Documentation Impact

- `CONSTRAINTS.md`: update — Frontend Chart rule now allows pattern point markers and one `breakout_level` line per active pattern; still never a stop/target. Public API: new params and `422` rules.
- `ARCHITECTURE.md`: update — screener/instrument payloads include patterns.
- `AGENTS.md`: not needed (scope guard updated by `chart-patterns-detect`).
- `docs/user-and-access-model.md`: not needed (no new permissions).

## Implementation Plan

1. API params + payloads + tests. 2. Saved Screener keys + backwards-compat test. 3. Frontend filter state + panel + tests. 4. Badges. 5. Chart markers/line + `PatternPanel` + tests. 6. i18n, docs, `init.ps1`, browser check.

## Implementation Tasks

- [ ] `?pattern` / `?pattern_status` parsing, filtering and `422`s, localized.
- [ ] `patterns` in candidate and instrument-detail payloads.
- [ ] Saved Screener nine-key object with defaults for old rows.
- [ ] Filter state/URL round-trip and the "Patrones" group.
- [ ] Candidate badges.
- [ ] Chart markers + breakout line + `PatternPanel` (accessible).
- [ ] es/en strings; Vitest and feature tests; docs; evidence.

## Verification Plan

- `php artisan test` (screener filter/422/payload, instrument detail payload, saved screener round-trip and legacy row).
- `npm --prefix frontend run test -- --run`, `lint`, `build`.
- Real browser (Playwright + Edge, local dev with seeded patterns): filter → badges → open chart → markers and one level line per pattern, panel text; es/en; no overflow at 390 px; no target line drawn.
- `.\init.ps1` exit 0. No persistent E2E harness exists; the Playwright check is recorded as evidence.

## Evidence To Capture

- Test names, browser screenshots (screener with pattern filter, chart with a pattern), `init.ps1` output.

## Key Implementation Risks

- Overstating patterns as advice: copy must stay neutral and the disclaimer visible.
- Chart clutter with several patterns: draw only active ones; markers short; panel carries detail.
- Saved Screener compatibility: old nine-key absence must not break re-apply.

## Validator Checklist

- [ ] Filters, payloads and `422`s as specified; anonymous access unchanged and throttled.
- [ ] Chart draws points + one breakout line per active pattern, never targets/stops; accessible panel present.
- [ ] Old Saved Screeners load; new ones round-trip.
- [ ] es/en complete; mobile OK; DESIGN.md respected; docs updated; `init.ps1` exit 0.

## Implementation Findings

1. **Status control.** The segmented status control is disabled until a pattern chip is selected, and removing the last pattern resets `pattern_status` to `any`, so the status never filters on its own (the API also ignores it without `pattern`).
2. **Payload compatibility.** `patterns` is optional in the SPA types (`InstrumentDetailResponse.patterns?`, `candidate.patterns ?? []`) so an SPA deployed ahead of the API cannot crash; Saved Screener pattern keys are optional on input, always stored canonically, and defaulted on read for older rows.
3. **Chart.** Markers use `createSeriesMarkers` with short role labels from `patterns.rolesShort.*`; only points inside the loaded bar window are drawn (the panel lists all). Breakout lines are dotted in the tertiary token colour to distinguish them from SMA (dashed) and the signal pivot.
4. **Browser QA note.** The first Playwright run counted the previous result list; the script now waits for the filtered `/api/screener` response. Port 5173 was held by another local process, so the check ran Vite on 5180.
