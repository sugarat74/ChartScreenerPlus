# Feature Implementation Spec: Alerts — rules, daily evaluation and in-app notifications (backend)

## Source Feature

- `id`: `alerts-engine`
- `area`: `backend`
- `depends_on`: `saved-screeners`, `watchlist`, `signals-detect`, `ingestion-scheduler`, `app-multilanguage`
- `status`: `not_started` (planned 2026-10-10 at the user's explicit request; "automatic alerts/notifications" were out of MVP scope in `AGENTS.md`)
- `source`: `feature_list.json`

## Goal

Let a Registered User define a small number of Alerts and receive in-app Notifications after each daily pipeline run when an Alert fires. Two Alert kinds:

1. **`screener_new_candidates`** — tied to one of the user's Saved Screeners: fires when Instruments enter its Candidate list compared with the previous evaluation.
2. **`watchlist_signal`** — tied to the user's Watchlist and a chosen set of Signal types: fires when one of those Signals **appears** on a followed Instrument (it was not active at the previous evaluation).

This slice is backend only: data model, evaluation command wired into the pipeline, notification storage and a JSON API. The UI is `alerts-ui`; email is `alerts-email-digest`.

## Non-Goals

- No UI, no email, no push, no SMS, no webhooks.
- No intraday or real-time alerts; evaluation happens once per completed daily pipeline.
- No price-level alerts ("tell me when X crosses 100") and no pattern alerts (pattern types can be added once `chart-patterns-detect` is accepted, as a later extension).
- No sharing alerts between users; no Admin management UI.

## Job Story

When the daily data is updated,
I want to be told which new stocks entered my saved screener or which of my followed stocks just triggered a signal I care about,
so I don't have to re-run everything by hand every day.

## Users And Permissions

- Registered User: CRUD on own Alerts (max 10), read/mark own Notifications. Foreign ids are `404` (same convention as Saved Screeners/Watchlist). Visitors: `401`.
- Admin: same as a Registered User for their own alerts; no access to others' alerts.

## Domain Decisions

1. **Tables.**
   - `alerts`: `id, user_id (FK cascade), kind (string 32), saved_screener_id (nullable FK cascade), signal_types (json, nullable), active (bool, default true), last_evaluated_as_of (date, nullable), last_state (json, nullable), timestamps`. `user_id` not fillable (structural ownership guard as in `SavedScreener`).
   - Notifications use Laravel's database notifications (`notifications` table via `php artisan make:notifications-table`), notifiable `User`, class `App\Notifications\AlertTriggered` with data `{alert_id, kind, as_of, saved_screener_name?, items: [{ticker, name, reason}]}`.
2. **Evaluation baseline.** The first evaluation of a new Alert stores `last_state` and **does not notify** (no flood of "everything is new"). Later evaluations notify only on additions versus `last_state`, then replace `last_state`.
3. **Idempotence.** An Alert is evaluated at most once per `as_of` (the latest stored bar date of the universe): if `last_evaluated_as_of == as_of`, skip. Re-running the command never duplicates notifications.
4. **Screener logic reuse.** Extract candidate computation from `ScreenerController` into `App\Services\Screener\CandidateQuery` (same filters, same results); the controller and the evaluator both use it. Behavior of `/api/screener` must not change (existing tests stay green).
5. **Pipeline.** `ingestion:pipeline` runs `alerts:evaluate` as its last stage only when the signals stage succeeded. `alerts:evaluate` can also be run manually.
6. **Limits & retention.** Max 10 Alerts per user (`422` beyond). Max 50 items per notification (rest summarized as a count). Notifications older than 90 days are pruned daily (`model:prune` style command or scheduled delete), stated in the privacy policy.
7. **Deactivation.** Deleting a Saved Screener deletes its Alerts (FK cascade). An Alert can be paused (`active=false`) and is then skipped.

## API (auth:sanctum, stateful SPA)

- `GET /api/alerts` → `{alerts: [...]}`; `POST /api/alerts` `{kind, saved_screener_id?, signal_types?}` (validation: kind enum; screener must be the user's; `signal_types` ⊆ the 9 Signal types, non-empty for `watchlist_signal`); `PATCH /api/alerts/{id}` `{active?, signal_types?}`; `DELETE /api/alerts/{id}` → `204`.
- `GET /api/notifications?page=` → paginated, newest first, `{data: [{id, kind, as_of, items, read_at, created_at}], meta, unread_count}`; `POST /api/notifications/read` `{ids?: [], all?: true}` → `204`.
- Messages localized (Accept-Language), keys and status codes language-independent.

## Acceptance Scenarios

### Scenario 1: New Candidates notification
Given an active `screener_new_candidates` Alert evaluated yesterday with candidates {A, B},
When today's pipeline yields candidates {B, C},
Then one notification lists C (reason "new candidate") and `last_state` becomes {B, C}.

### Scenario 2: Signal appears on a followed instrument
Given a `watchlist_signal` Alert for `golden_cross` and a followed NVDA without it yesterday,
When today NVDA has `golden_cross`,
Then one notification lists NVDA with reason `golden_cross`; a signal that persists the next day does not notify again.

### Scenario 3: First evaluation and idempotence
A newly created Alert stores its baseline without notifying; running `alerts:evaluate` twice for the same as-of creates no duplicates.

### Scenario 4: Ownership and limits
Another user's alert/notification id → `404`; an 11th Alert → `422`; Visitor → `401`.

### Scenario 5: Failure isolation
If one Alert throws during evaluation, others still evaluate; the command reports it and exits `1`; the failing Alert's state is unchanged.

## Repository Research

### Files Inspected

- `app/Http/Controllers/ScreenerController.php` — filtering/sorting in the controller (needs extraction).
- `app/Models/{SavedScreener,User,Signal,Instrument}.php` — ownership guard pattern, `watchlist()` relation, signal vocabulary.
- `app/Console/Commands/RunIngestionPipeline.php`, `routes/console.php` — stage composition, schedule, `model:prune` precedent.
- `tests/Feature/{SavedScreenersApiTest,WatchlistApiTest}.php` — ownership/404 test conventions.
- `config/mail.php` (`log` default) — irrelevant here (no email).

### Current Gaps

- No `alerts`/`notifications` tables, no evaluator, no API, no candidate-query service.

## Expected File Changes

- Create: migrations (`alerts`, `notifications`), `app/Models/Alert.php`, `database/factories/AlertFactory.php`, `app/Services/Screener/CandidateQuery.php`, `app/Services/Alerts/AlertEvaluator.php`, `app/Notifications/AlertTriggered.php`, `app/Console/Commands/EvaluateAlerts.php`, `app/Http/Controllers/{AlertController,NotificationController}.php`, `tests/Feature/{AlertsApiTest,AlertEvaluationTest,NotificationsApiTest}.php`.
- Modify: `app/Http/Controllers/ScreenerController.php` (use the service), `app/Models/User.php` (`alerts()`), `app/Models/SavedScreener.php` (`alerts()`), `app/Console/Commands/RunIngestionPipeline.php`, `routes/api.php`, `routes/console.php` (notification pruning), `lang/{es,en}/messages.php`, legal texts (`frontend/src/i18n/legal/*.ts`: alerts processing, 90-day notification retention).

## Visual Design Impact

- UI involved: no (see `alerts-ui`).

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — Alerts path (evaluation stage, notifications store, candidate service).
- `CONSTRAINTS.md`: update — once per as-of, baseline without notify, ownership 404, limits, retention, pipeline gating.
- `CONTEXT.md` / `docs/domain-model.md`: update — Alert and Notification terms, lifecycle.
- `AGENTS.md`: update — MVP scope guard (in-app alerts now in scope; email separate).
- `docs/user-and-access-model.md`: update — Alerts/Notifications owned by the Registered User.
- `docs/specs/legal-compliance-eu.md` + legal texts: update — new processing (alert rules and notifications, 90 days).

## Implementation Plan

1. Extract `CandidateQuery` with no behavior change (screener tests green). 2. Migrations, model, factory, relations. 3. Evaluator + notification + command + tests. 4. Pipeline stage + pruning. 5. API + tests. 6. Docs and legal text, `init.ps1`.

## Implementation Tasks

- [ ] `CandidateQuery` extraction; `/api/screener` unchanged.
- [ ] `alerts` + `notifications` tables (SQLite and PostgreSQL), model, ownership guard.
- [ ] `AlertEvaluator` (baseline, diff, once per as-of, 50-item cap) and `AlertTriggered`.
- [ ] `alerts:evaluate` + pipeline stage + 90-day pruning schedule.
- [ ] Alerts and notifications API with validation, limits, 404/401.
- [ ] es/en messages; legal texts; docs; tests; evidence.

## Verification Plan

- `php artisan test` (evaluation scenarios with factories and controlled signals/bars; API ownership; pipeline stage order); CI `test-pgsql` green.
- `php artisan schedule:list` shows the pruning entry; `.\init.ps1` exit 0.
- Local smoke: create alerts for a QA user, run `ingestion:pipeline` offline-equivalent (`alerts:evaluate` after seeding a new as-of), inspect `GET /api/notifications`.

## Evidence To Capture

- Test output per scenario, `schedule:list`, smoke JSON excerpts (no personal data), `init.ps1`.

## Key Implementation Risks

- Notification floods: baseline-without-notify and the 50-item cap.
- Behavior drift when extracting the screener query: keep the existing tests as the guard.
- Scope creep into email/UI.

## Validator Checklist

- [ ] Two kinds only; baseline, diff, once-per-as-of semantics proven; failures isolated.
- [ ] Ownership 404, limits 422, visitor 401; screener API unchanged.
- [ ] Pipeline stage gated on signals success; pruning scheduled; legal texts and docs updated; `init.ps1` exit 0.
