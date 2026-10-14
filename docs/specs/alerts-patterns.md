# Feature Implementation Spec: Alerts on chartist patterns (Watchlist)

## Source Feature

- `id`: `alerts-patterns`
- `area`: `backend` + `frontend` (API, evaluator, Portal form, inbox)
- `depends_on`: `alerts-engine`, `alerts-ui`, `chart-patterns-detect` (all `accepted`)
- `status`: `accepted` (first note 2026-10-12; rewritten as a full spec and accepted 2026-10-14, base `main` 1514730)
- `source`: `feature_list.json`

## Goal

Let a Registered User choose chartist pattern types (`double_top`, `double_bottom`, `cup_with_handle`, `bull_flag`) and be told in the app, after the daily run, when one of them **appears** or **is confirmed** (breakout) on an Instrument of their Watchlist. It reuses the `alerts-engine` machinery (baseline without notifying, once per as-of, 50-item cap, ownership, limit) through a third Alert kind, `watchlist_pattern`, and adds a Portal form plus inbox rendering that links to the chart, where the pattern is already drawn. Saved Screener alerts already cover pattern filters through `screener_new_candidates`; they are unchanged.

## Non-Goals

- No email (that is `alerts-email-digest`), push, SMS or real-time evaluation.
- No alert on invalidation, expiry or disappearance; no price-level alerts; no targets.
- No change to detection, parameters or vocabulary (`chart-patterns-calibration` owns that) and no pattern history table.
- No change to `watchlist_signal` or `screener_new_candidates` behaviour, payloads or stored state.
- No change to the pipeline order or its gating.

## Job Story

When a stock I follow starts drawing, or breaks out of, a formation I trade,
I want a notification in Chartiko the day after the data shows it,
so I can open its chart without scanning my whole Watchlist by hand.

## Users And Permissions

- Registered User: one `watchlist_pattern` Alert of their own (create/patch/pause/delete); it counts toward `config('alerts.max_per_user')` (10). Foreign id `404`, guest `401`, as today (`app/Http/Controllers/AlertController.php:170-173`).
- Visitor: no access (`401`; Portal redirects to `/login`). Admin: same as a Registered User for their own alerts.

## Domain Decisions

1. **New kind `watchlist_pattern`, not an extension of `watchlist_signal`.** Reasons from the code: `signal_types` is `required|min:1` for `watchlist_signal` (`AlertController.php:64-71`), so a patterns-only user would need conditional "one of the two lists" validation; any type change resets the *whole* `last_state` (`AlertController.php:142-150`), so editing patterns would silently re-baseline signals and vice versa; old `last_state` rows `{signals: …}` have no pattern part and would need a per-part baseline; the form's "save disabled while none selected" and the inbox title (`NotificationsPage.tsx:28-34`) assume one meaning per kind. A separate kind keeps each rule, state and notification single-purpose and leaves every existing alert and notification byte-for-byte unchanged. Cost: one more slot of the 10-alert limit (at most 1 + 1 + Saved Screeners), accepted.
2. **Rule fields.** New nullable JSON columns on `alerts`: `pattern_types` (non-empty subset of `ChartPattern::TYPES`, `app/Models/ChartPattern.php:27`, stored in that canonical order, deduplicated) and `pattern_events` (non-empty subset of `['appears', 'confirmed']`, canonical order; omitted on create ⇒ `['confirmed']`). Both are `null` for the other kinds. One `watchlist_pattern` per user (duplicate ⇒ `messages.alerts.duplicate`).
3. **Data source.** The evaluator reads the current `chart_patterns` rows of the followed Instruments, filtered to the chosen types. Rows are the replaced current set, one per `(instrument, type)` (`DetectPatterns.php:151-152`, unique key in the migration), the same set the chart shows (`InstrumentController.php:72-84`).
4. **Pattern identity = `type` + `start_date`.** `start_date` is the date of the first key point (`engine/app/patterns/rules.py:369-376`: left peak/low, left rim, pole start), which is fixed once the swing is established, so it separates "the same formation one day later" from "a newer formation of the same type that replaced it". `end_date` is not used (it can move while a handle/flag develops).
5. **`last_state` shape:** `{"patterns": {"<TICKER>": {"<type>": {"start": "Y-m-d", "status": "forming|confirmed"}}}}`, tickers `ksort`ed, types in canonical order, only chosen types. `null` ⇒ next run is a baseline.
6. **Diff rules** (previous `P`, current `C`, per ticker and chosen type; `same` = `P` has the type with the same `start`):
   - `appears` (if chosen): `C` has it and not `same` (absent before, or a different instance).
   - `confirmed` (if chosen): `C.status = confirmed` and not (`same` and `P.status = confirmed`).
   - If both fire for one ticker/type, emit **one** item with event `confirmed`.
   - Absent from `C` (expired after 10 sessions, invalidated, unfollowed) ⇒ nothing. A pattern that disappears and later reappears (any `start`) is absent in `P` and notifies again, exactly like a Signal that reappears; `last_state` only remembers the previous evaluation.
   - A ticker newly added to the Watchlist with an active pattern notifies at the next run (same as `watchlist_signal`, `AlertEvaluator.php:163-171`).
7. **Notification item** (extends, never changes, the existing shape `{ticker, name, reason}`): `{ticker, name, reason: "pattern:<type>:<event>", status: "forming|confirmed", breakout_level: float}`, e.g. `pattern:double_bottom:confirmed`. Items ordered by ticker, then canonical type; cap `config('alerts.max_items')` (50) with `more` as today. Notification `kind` = `watchlist_pattern`; `NotificationController` already passes `items` through untouched (`NotificationController.php:39`).
8. **Baseline resets.** Re-activating, or changing `pattern_types` or `pattern_events` to a different canonical set, clears `last_state`/`last_evaluated_as_of` (same rule as signal types).
9. **Pipeline.** Unchanged: `patterns:detect → screener:refresh → alerts:evaluate`, alerts gated on signals + refresh (`RunIngestionPipeline.php:83-87`). If `patterns:detect` fails for an instrument its previous rows stay (fetch-before-delete), so pattern alerts see unchanged state and do not notify falsely; the change surfaces on the next successful run (a `confirmed` status lasts 10 sessions). No new gating.

## API Contract (`auth:sanctum`, existing routes)

- `POST /api/alerts` `{kind: "watchlist_pattern", pattern_types: [...], pattern_events?: [...]}` → `201 {alert}`.
- `PATCH /api/alerts/{id}` adds `pattern_types?`, `pattern_events?` (only for `watchlist_pattern`).
- Alert payload (all kinds) gains `pattern_types` and `pattern_events` (`null` when not a pattern alert); existing keys unchanged.
- Validation (`422`, framework messages localized through `lang/{es,en}/validation.php`, keys language-independent):
  - `kind` ∈ `Alert::KINDS` (now three).
  - `pattern_types`: required for `watchlist_pattern`, prohibited for the other kinds, `array|min:1`; `pattern_types.*` ∈ `ChartPattern::TYPES` (e.g. `triangle` ⇒ error on `pattern_types.0`).
  - `pattern_events`: optional, prohibited for the other kinds, `array|min:1`; `pattern_events.*` ∈ `Alert::PATTERN_EVENTS` (`disappears` ⇒ `pattern_events.0`).
  - `signal_types` and `saved_screener_id` prohibited for `watchlist_pattern`; `signal_types` on PATCH prohibited unless `watchlist_signal`.
  - Duplicate ⇒ `errors.kind.0` = `messages.alerts.duplicate`; limit ⇒ `messages.alerts.limit` (both already es/en).
  - Add attribute names `pattern_types` / `pattern_events` (+ `.*`) to both `validation.php` files (`tipos de patrón` / `eventos de patrón`; `pattern types` / `pattern events`).

## Acceptance Scenarios

### Scenario 1: Confirmation notifies once
Given a `watchlist_pattern` alert (`double_bottom`, events default `confirmed`) baselined while NVDA had a forming double bottom starting 2026-08-03,
When the next as-of has the same instance `confirmed`,
Then one notification `kind=watchlist_pattern` lists NVDA with `reason=pattern:double_bottom:confirmed`, `status=confirmed` and its `breakout_level`; the following as-of (still confirmed) notifies nothing.

### Scenario 2: Appearance, replacement and reappearance
Given events `["appears"]` and AAPL without `bull_flag` at the previous evaluation,
When a `bull_flag` row appears, Then AAPL is notified with `pattern:bull_flag:appears` (status forming);
when next day the row has a different `start_date`, Then it notifies again (new instance); when the row disappears, Then nothing; when it reappears later, Then it notifies `appears`.

### Scenario 3: Both events, filters and baseline
Given events both chosen, a new instance first seen already `confirmed` yields one item with `:confirmed`; unchosen types and non-followed Instruments never notify; the first evaluation stores the baseline without notifying; a second `alerts:evaluate` for the same as-of is `already evaluated`.

### Scenario 4: Validation, ownership, limit
Empty or unknown `pattern_types`, unknown `pattern_events`, `signal_types`/`saved_screener_id` on a pattern alert, `pattern_types` on another kind ⇒ `422` on that field; a second pattern alert ⇒ localized duplicate `422`; an 11th alert of any kind ⇒ localized limit `422`; foreign id ⇒ `404`; guest ⇒ `401`.

### Scenario 5: Portal and inbox
Given a signed-in user on `/portal`, When they tick "Doble suelo", keep "Cuando se confirma" and save, Then the card shows "Activa" and pause/resume/delete work; When a pattern notification exists, Then the inbox shows "Patrones en tu Watchlist", a chip "NVDA · Doble suelo · confirmado · Ruptura 123,45" (`formatPrice`, no currency symbol) linking to `/instruments/NVDA`, where the pattern is drawn. English equivalents; 390 px without overflow; no EOD wording.

### Scenario 6: Backward compatibility
Existing `watchlist_signal`/`screener_new_candidates` alerts, their `last_state`, notifications and inbox rendering are unchanged; all existing alert tests pass unmodified.

## Repository Research

### Files Inspected

- `app/Models/Alert.php`, `database/migrations/2026_10_10_192850_create_alerts_table.php`, `database/factories/AlertFactory.php`, `config/alerts.php` — kinds, fillable guard, casts, limits.
- `app/Http/Controllers/AlertController.php` — validation, lock + limit + duplicate, canonical types, baseline reset, payload.
- `app/Services/Alerts/AlertEvaluator.php` — as-of, baseline, `watchlistState`, `additions` (note: `else` branch treats every non-screener kind as signals; must become explicit).
- `app/Notifications/AlertTriggered.php`, `app/Http/Controllers/NotificationController.php`, `app/Console/Commands/EvaluateAlerts.php`, `RunIngestionPipeline.php`.
- `app/Models/ChartPattern.php`, `database/migrations/2026_10_10_120000_create_chart_patterns_table.php`, `app/Console/Commands/DetectPatterns.php`, `engine/app/patterns/rules.py` — four types (confirmed in engine registry `rules.py:343-346`, `ChartPattern::TYPES`, `SCREENER_PATTERN_TYPES`, `patterns.types` i18n), statuses, `start_date` anchor, replace semantics.
- `tests/Feature/{AlertEvaluationTest,AlertsApiTest}.php` — helpers (`member`, `newSession`) and conventions.
- `frontend/src/components/portal/{WatchlistAlertForm,AlertStatusChip,usePortalAlerts}.tsx/ts`, `pages/PortalPage.tsx:202-266`, `pages/NotificationsPage.tsx`, `lib/api.ts:756-858`, `lib/screenerFilters.ts` (`patternLabel`, `SCREENER_PATTERN_TYPES`), `i18n/messages/{es,en}.ts` (`alerts`, `notifications`, `patterns`), `components/chart/{InteractiveChart,PatternPanel}.tsx`, `i18n/legal/{es,en}.ts`.
- `docs/specs/{alerts-engine,alerts-ui,chart-patterns-detect}.md`, `ARCHITECTURE.md` (Alerts Path), `CONSTRAINTS.md` (Alerts), `CONTEXT.md`, `docs/domain-model.md`, `AGENTS.md`.

### Existing Patterns To Follow

- Canonical-order dedupe of type lists (`canonicalTypes`); ownership via `$request->user()->alerts()`; notification + state update in one transaction.
- Portal card pattern of `WatchlistAlertForm` (fieldset of checkboxes, `AlertStatusChip`, 401 → login, 404 → drop, inline `role="alert"` error, `role="status"` notice, remount key while loading).
- Labels via `patternLabel`/`t('patterns.status.*')`; prices via `formatPrice` from `useI18n`.

### Current Gaps

- No pattern fields, kind, state or rendering anywhere in alerts; inbox title falls back to the Screener text for unknown kinds (`NotificationsPage.tsx:28-34`).
- No persistent E2E harness (`frontend/package.json` has only Vitest); browser QA is an ad-hoc Playwright run as in `alerts-ui`.

## Technical Approach

Backend: migration adds the two columns; `Alert` gets `KIND_WATCHLIST_PATTERN`, `PATTERN_EVENTS`, casts and fillable; `AlertController` validates per kind (Decision 2 / API Contract), generalizes duplicate detection to "same kind" for both Watchlist kinds and canonicalizes both lists; `AlertEvaluator` dispatches with a `match` on kind and gets `patternState()` + `patternAdditions()` (Decisions 4–7) returning items with the extra keys; `AlertTriggered` docblock types widen. Frontend: types in `api.ts`, a new `WatchlistPatternAlertForm` card under the signal card in `PortalPage`, inbox title/reason/breakout rendering, es/en keys.

## Expected File Changes

- Create `database/migrations/2026_10_14_xxxxxx_add_pattern_fields_to_alerts_table.php` (`pattern_types`, `pattern_events` json nullable; `down` drops them).
- Modify `app/Models/Alert.php`, `database/factories/AlertFactory.php` (`watchlistPattern()` state), `app/Http/Controllers/AlertController.php`, `app/Services/Alerts/AlertEvaluator.php`, `app/Notifications/AlertTriggered.php` (docblock), `lang/{es,en}/validation.php` (attributes).
- Tests: create `tests/Feature/AlertPatternEvaluationTest.php`; extend `tests/Feature/AlertsApiTest.php`.
- Frontend: modify `frontend/src/lib/api.ts` (`AlertKind`, `AlertPatternEvent`, `Alert.pattern_types/pattern_events`, create/update payloads, `NotificationItem.status?`/`breakout_level?`), `pages/PortalPage.tsx`, `pages/NotificationsPage.tsx`, `i18n/messages/{es,en}.ts`; create `components/portal/WatchlistPatternAlertForm.tsx` + `.test.tsx`; extend `pages/NotificationsPage.test.tsx` and `pages/PortalAlerts.test.tsx`.
- Legal: `frontend/src/i18n/legal/{es,en}.ts` parenthetical "(…signal or pattern types you choose for your Watchlist)" — same processing, wording only.

## Visual Design Impact

- UI involved: yes. Source: `DESIGN.md` + the accepted `alerts-ui` cards (no new artifact).
- Portal: new card "Mis alertas de patrones" / "My pattern alerts": intro, hint (automatic geometric detection, informative, not a recommendation), fieldset of 4 pattern checkboxes (2 columns ≥ sm), fieldset "Avisarme" with two checkboxes "Cuando aparece" / "Cuando se confirma (ruptura)" (confirmed pre-ticked on a new alert), status chip, Save/Save changes, Pause/Resume, Delete; save disabled while no type or no event is selected or nothing changed; 422 inline.
- Inbox: title "Patrones en tu Watchlist" / "Patterns on your Watchlist"; item chip `TICKER · <pattern> · <event label> · Ruptura <price>` with the event as text ("aparece"/"confirmado"; status shown only when an `appears` item is already confirmed); `aria-label` includes the same text. Unknown `pattern:` reasons fall back to the raw type.
- i18n keys (es/en): `alerts.patternTitle`, `patternIntro`, `patternHint`, `patternTypesLegend`, `patternEventsLegend`, `patternEvents.appears`, `patternEvents.confirmed`, `patternNoneSelected`; `notifications.kindWatchlistPattern`, `patternEvent.appears`, `patternEvent.confirmed`, `breakoutLevel` (`Ruptura {price}`). No "EOD"/"fin de día" wording.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update Alerts Path — third kind, `pattern_types`/`pattern_events`, pattern state/identity/diff, item extra keys, Portal card, inbox rendering.
- `CONSTRAINTS.md`: update Alerts — pattern alerts read the current `chart_patterns` set, identity `type + start_date`, never notify disappearance/invalidation, one per user.
- `CONTEXT.md` (Alert) and `docs/domain-model.md`: update the Alert definition with the pattern kind.
- `AGENTS.md`: update the scope-guard sentence "Alerts on chartist patterns come later…" to name `alerts-patterns` as in scope (in-app only).
- `docs/specs/alerts-engine.md`: not needed (this spec is the reference). `docs/user-and-access-model.md`: not needed (same ownership). `docs/specs/alerts-email-digest.md`: not changed here; risk noted below.

## Implementation Plan

1. Migration, model constants/casts/fillable, factory state.
2. Controller validation, duplicate, canonicalization, payload, baseline reset; API tests.
3. Evaluator kind dispatch, pattern state and diff, items; evaluation tests.
4. Frontend types, pattern card, Portal wiring, inbox rendering, i18n, Vitest.
5. Legal wording, docs, `.\init.ps1`, browser QA, evidence in `feature_list.json`/`PROGRESS.md`.

## Implementation Tasks

- [ ] Migration up/down/up on SQLite; `test-pgsql` CI green.
- [ ] `Alert::KIND_WATCHLIST_PATTERN`, `PATTERN_EVENTS`, `KINDS` (3), casts, fillable.
- [ ] `AlertController` store/update rules, default `['confirmed']`, duplicate per Watchlist kind, payload keys, reset on list change.
- [ ] `AlertEvaluator`: explicit `match` (signals path untouched), `patternState`, `patternAdditions`, merge rule (both ⇒ `confirmed`), item keys, cap.
- [ ] PHP tests (below); existing alert tests unchanged and green.
- [ ] `api.ts` types; `WatchlistPatternAlertForm`; `PortalPage` mount (`kind === 'watchlist_pattern'`, remount key); inbox title/reason/breakout; es/en keys; Vitest.
- [ ] Legal parenthetical es/en; docs listed above.

## Verification Plan

- `php artisan test --filter "AlertPatternEvaluationTest|AlertsApiTest|AlertEvaluationTest"`:
  - Evaluation: baseline no notify; forming→confirmed notifies once then not; `appears` on new row, on different `start_date`, not on persistence; both events ⇒ single `:confirmed`; disappearance silent and reappearance notifies; unchosen types/unfollowed instruments ignored; item has `name`, `status`, `breakout_level` (float); 51+ items ⇒ 50 + `more`; once per as-of; pattern and signal alerts of one user evaluate independently.
  - API: create (canonical dedupe, default events), every 422 of Scenario 4 incl. es/en duplicate and limit text, PATCH reset on types/events change and no reset on same set, pause/resume reset, `404`/`401`, payload `pattern_*` null on old kinds.
- `npm --prefix frontend run test`: pattern card (disabled until type+event, default confirmed, create payload, prefill/update/pause/delete, 422 inline, English); inbox pattern notification (title, label, event, breakout price, link `/instruments/NVDA`, unknown reason fallback); Portal renders both Watchlist cards.
- `.\init.ps1` exit 0 (non-blocking checks only; it starts no servers); CI (SQLite + PostgreSQL) green.
- Browser QA (Playwright + Edge, local stack and QA SQLite, as in `alerts-ui`): create the pattern alert in `/portal`; `php artisan alerts:evaluate` (baseline); via tinker add a newer `daily_bars` row (new as-of) and set a followed instrument's `chart_patterns` row to `confirmed` (or insert one); re-run ⇒ `1 notified`, re-run ⇒ already evaluated; bell count; inbox chip text and link opens the chart with the pattern drawn; English; 390 px; Visitor redirected/`401`. Persistent E2E: none exists; feature + component tests plus this run cover the flow.

## Evidence To Capture

- Test names and counts (PHP, Vitest), migration round-trip, `init.ps1` summary, browser QA checklist with command output (`alerts:evaluate` lines) and notification JSON excerpt (no personal data).

## Key Implementation Risks

- Re-anchoring: if the detector picks a different first key point for what looks like the same formation, `appears` re-notifies; acceptable and visible in QA; calibration may reduce it.
- A notification can outlive its pattern (expired by the time the user clicks); the chart shows the current set — acceptable, the item keeps type, status and breakout level.
- `alerts-email-digest` (not started) must render `watchlist_pattern` and `pattern:*` reasons when implemented.
- Breaking the signals path while refactoring `additions`: keep it byte-identical and covered by the existing tests.

## Validator Checklist

- [ ] Only the new kind and fields; `watchlist_signal`/`screener_new_candidates` behaviour, state and notifications unchanged; no detection or pipeline change.
- [ ] Identity `type + start_date`, diff rules and merge rule match Decisions 4–7; disappearance never notifies; once per as-of, baseline, cap and failure isolation hold.
- [ ] All Scenario 4 cases return the stated status/field; messages localized es/en.
- [ ] Portal card and inbox match Visual Design Impact in es/en at 390 px, no EOD wording; link opens the chart.
- [ ] Docs and legal wording updated; `.\init.ps1` exit 0; CI green; `feature_list.json` and `PROGRESS.md` evidence recorded.
