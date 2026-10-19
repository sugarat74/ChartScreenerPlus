# Feature Implementation Spec: Account self-service (export, delete, name and password)

## Source Feature

- `id`: `account-self-service`
- `area`: `auth`
- `depends_on`: `auth-registration-login`, `user-portal`, `legal-privacy-publication` (all `accepted`)
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

A Registered User manages their own account without writing to `contacto@chartiko.com`. A new Account page lets them change their display name and password, download a copy of their personal data as JSON, and permanently delete the account together with everything it owns. These cover GDPR rights of access, portability, rectification and erasure (arts. 15, 16, 17 and 20) for the data Chartiko holds about the account. The email channel stays open for anything the page does not cover.

## Non-Goals

- No email change, password reset or email verification. All three need an outbound email provider (planned `email-provider-smtp`).
- No soft delete, grace period or account restore: deletion is immediate and permanent.
- No Admin-side deletion of other users, and no role changes.
- No deletion of shared, non-personal data the user triggered: AI company cards are shared market content and stay. `ai_company_card_generations.user_id` is already `nullOnDelete`.
- No purge of backups. Backups expire on their own schedule, as the privacy policy already states.
- No CSV/ZIP export and no asynchronous export job: the JSON is small and built synchronously.

## Job Story

When I stop using Chartiko, or want to know what it keeps about me,
I want to download my data and delete my account myself,
so I can exercise my rights immediately without depending on an email reply.

## Users And Permissions

- **Registered User**: every endpoint acts only on `$request->user()`. No endpoint accepts a `user_id`.
- **Admin**: same endpoints for their own profile, export and password. **Self-deletion is refused** with `422 account.admin_cannot_delete`; the role must be removed out of band first (`app:make-admin` stays the only role tool). This also protects the forecast backtest runs (`requested_by` cascades) and avoids deleting the last Admin.
- **Visitor**: `401` everywhere; the SPA route is guarded by `RequireAuth`.
- All endpoints are session-only (the same `requireStatefulSession` as `AuthController`), so they cannot be called with a bearer token.

## Domain Decisions

1. **Endpoints** (inside the existing `auth:sanctum` group, new `AccountController`):
   - `PATCH /api/account` with `{name}`: required, string, max 255. Returns `{user}`.
   - `PUT /api/account/password` with `{current_password, password, password_confirmation}`: `current_password` uses the `current_password:web` rule and `password` uses `Password::defaults()` plus `confirmed`. On success, every **other** session of the user is deleted (`sessions` rows with that `user_id` and a different id), the current session id is regenerated and the response is `204`. Throttle `throttle:6,1,account-password`.
   - `GET /api/account/export`: returns the JSON (Decision 2) with `Content-Disposition: attachment; filename="chartiko-account-<YYYY-MM-DD>.json"`. Throttle `throttle:5,1,account-export`.
   - `DELETE /api/account` with `{password, confirmation}`. `confirmation` must equal the literal `DELETE` (`ELIMINAR` is accepted too, so both locales work). The password must match. Throttle `throttle:6,1,account-delete`.
2. **Export contents** (`App\Services\Account\AccountExporter`), with `format: "chartiko-account-export-v1"` and `generated_at`:
   - `profile`: name, email, role, `created_at`, `updated_at`, `email_verified_at`.
   - `watchlist`: ticker, name, `added_at`.
   - `saved_screeners`: name, criteria (as stored), `created_at`.
   - `alerts`: every stored field except internal ids of other users, plus `created_at` and last-triggered data where present.
   - `notifications`: type, data, `read_at`, `created_at`.
   - `sign_in_activity`: the user's own `login_events` (event, ip_address, user_agent, `created_at`), within the existing 90-day retention.
   - `active_sessions`: ip_address, user_agent and last activity only, never the session id or payload.
   - `ai_card_requests`: ticker, locale and `created_at` from `ai_company_card_generations` for this user.

   It never includes the password hash, `remember_token`, session ids or payloads, or other users' data. Each section is built from the User relations, so it is scoped by construction.
3. **Deletion order** (`App\Services\Account\AccountDeleter`, one DB transaction):
   1. Record a `login_events` row `account_deleted` with `user_id` null, the email, and no IP or user agent. It is kept for the existing 90-day activity retention, which the policy already discloses.
   2. Delete all `sessions` rows of the user.
   3. `$user->delete()`. Notifications go through the existing `deleting` hook; watchlist, saved screeners and alerts cascade by FK; `login_events.user_id` and `ai_company_card_generations.user_id` become null.

   Then the controller logs out the web guard, invalidates the session, regenerates the CSRF token and returns `204`. Any failure rolls back, so there is never a half-deleted account.
4. **Login events.** Add `EVENT_ACCOUNT_DELETED = 'account_deleted'` to `LoginEvent::EVENTS`. The Admin activity filter and its es/en labels pick it up. Name and password changes are not logged in this feature.
5. **Privacy text.** Update the rights and terms paragraphs in `frontend/src/i18n/legal/{es,en}.ts`: rights can be exercised from the Account page (export, deletion, name) or by writing to `{ownerEmail}`. Keep the backup and activity-log retention wording. Bump `config/legal.php` `updated_at`.

## Acceptance Scenarios

### Scenario 1: Change name
Given a signed-in user on `/portal/cuenta`,
when they save a new name,
then `PATCH /api/account` returns the updated user, the header shows the new name without a reload, and an empty or 256-character name returns `422`.

### Scenario 2: Change password
Given a user with two sessions,
when they submit the correct current password and a valid confirmed new one,
then the response is `204`, the current session keeps working, the other session gets `401`, and the new password signs in.
A wrong current password returns `422` on `current_password`, with nothing changed.

### Scenario 3: Export
Given a user with watchlist items, a saved screener, alerts, notifications and login events, and a second user with their own data,
when the first user requests the export,
then the JSON has every section from Decision 2 with the first user's data only, and contains no password hash, remember token, session id or payload, nor any of the second user's data.
The response is an attachment.

### Scenario 4: Delete account
Given a user with data in every owned table,
when they confirm with their password and `DELETE`,
then the response is `204` and the session is gone (`GET /api/user` returns `401`).
Their rows in `users`, `sessions`, `watchlist_items`, `saved_screeners`, `alerts` and `notifications` are gone. Their `login_events` and `ai_company_card_generations` rows have `user_id` null. An `account_deleted` event exists, and signing in with the old credentials fails.
Other users' data is untouched.

### Scenario 5: Delete is guarded
A wrong password, or a missing or wrong confirmation word, returns `422` and deletes nothing. An Admin gets `422 account.admin_cannot_delete`. A Visitor gets `401`. A request without a session gets `400`.

### Scenario 6: SPA
On `/portal/cuenta` (one path for both locales, like `/portal/notificaciones`; new `ACCOUNT_ROUTE` in `nav.ts`), reachable from a header link next to the sign-out button and from the Portal, the page shows four sections: profile, password, data download and a danger zone.
- Deletion needs typing the confirmation word and the password; the button stays disabled until both are filled.
- After deletion the user lands on `/` signed out, without a sign-in flash (the existing `hadUser` guard pattern).
- Works at 1280 and 390 px in es and en.

## Repository Research

### Files Inspected
- `routes/api.php`: `auth:sanctum` group; prefixed throttle convention; session-only auth endpoints.
- `app/Http/Controllers/Auth/AuthController.php`: `requireStatefulSession`, `Password::defaults()`, logout sequence.
- `app/Models/User.php`: relations `watchlist`, `savedScreeners`, `alerts`; the `deleting` hook already deletes notifications; `role` is not fillable.
- Migrations: `watchlist_items`, `saved_screeners` and `alerts` cascade; `login_events` and `ai_company_card_generations` null on delete; `sessions.user_id` has no FK (it must be deleted explicitly); `forecast_backtest_runs.requested_by` cascades (Admin only).
- `app/Services/Admin/{LoginEventRecorder,SessionDirectory}.php` and `app/Models/LoginEvent.php`: event constants, `withClient: false` pattern, 90-day retention.
- `frontend/src/router.tsx`, `pages/PortalPage.tsx`, `auth/RequireAuth.tsx`, `lib/api.ts`, `i18n/legal/es.ts` (rights paragraphs at lines ~110 and ~166).

### Existing Patterns To Follow
- User scoping through relations; foreign ids are never accepted.
- es/en catalogs (`lang/{es,en}/messages.php`, `frontend/src/i18n/*`) with a parity test, and route segments from `nav.ts`.
- Feature tests use `actingAs` with a session (see `AuthTest`), and `assertDatabaseMissing` for deletions.

### Current Gaps
- No account endpoints, no Account page, no export or deletion service, and no `account_deleted` event.

## Technical Approach

Keep the controller thin: `AccountController` validates and delegates to `AccountExporter` and `AccountDeleter`. Password changes follow Laravel's `logoutOtherDevices` semantics, implemented by deleting other `sessions` rows (the database session driver is used in production) and rehashing through the `hashed` cast. The SPA adds `AccountPage` with four section components, the API client methods `accountApi.{update,changePassword,export,remove}`, and `useAuth` gains `refreshUser()` or `setUser` for the name change. Deletion reuses the logout state path (the user becomes `null`) so the guards send the user home. Export is downloaded through a Blob link. Run `web-legal-compliance-eu` on the privacy-text change.

## Expected File Changes
- `app/Http/Controllers/AccountController.php`, `app/Services/Account/{AccountExporter,AccountDeleter}.php`: create.
- `routes/api.php`, `app/Models/LoginEvent.php`, `lang/{es,en}/messages.php`, `config/legal.php`: modify.
- `tests/Feature/AccountSelfServiceTest.php`: create.
- `frontend/src/pages/AccountPage.tsx` (+ `AccountPage.test.tsx`), `router.tsx`, `nav.ts`, `components/AppHeader.tsx` link, `PortalPage.tsx` link, `lib/api.ts`, `auth/*` (user refresh), `i18n/{es,en}.ts`, `i18n/legal/{es,en}.ts`, the Admin activity event labels: modify or create.
- `docs/user-and-access-model.md`, `PROGRESS.md`, `feature_list.json`: update.

## Visual Design Impact
- UI involved: yes. New page `/portal/cuenta` and a header link.
- Design source: `DESIGN.md` plus the Portal page (cards, buttons, alerts). The danger zone uses the existing error/destructive styling.
- New design artifact required: no.

## Durable Documentation Impact
- `ARCHITECTURE.md`: update, with a short "Account self-service" note (endpoints, deletion order).
- `CONSTRAINTS.md`: update. The account endpoints are session-only and self-scoped; the export never includes secrets; deletion is transactional; Admins cannot self-delete.
- `AGENTS.md`: not needed (no scope or workflow change).
- `docs/user-and-access-model.md`: update. `docs/risks-and-open-questions.md`: update with "no email change or reset until an email provider exists".

## Implementation Plan
1. Baseline `.\init.ps1`.
2. `LoginEvent::EVENT_ACCOUNT_DELETED` and the services with unit-level feature tests (export scoping, deletion cascade).
3. Controller, routes, throttles, messages, and the feature tests for Scenarios 1 to 5.
4. SPA page, header link, API client, auth refresh, and Vitest tests.
5. Privacy text and `updated_at`; run the `web-legal-compliance-eu` check.
6. Playwright + Edge QA (es/en, 1280/390): change name, change password, download, delete, and land home with no flash.
7. Docs, `.\init.ps1`, PR with CI on SQLite and PostgreSQL.

## Implementation Tasks
- [ ] `account_deleted` event constant plus Admin label.
- [ ] `AccountExporter` with sections and secret exclusion.
- [ ] `AccountDeleter` (transaction, sessions, event, delete).
- [ ] `AccountController` with 4 endpoints, routes, throttles and messages.
- [ ] `AccountSelfServiceTest` covering Scenarios 1 to 5, including the PostgreSQL cascade in CI.
- [ ] `AccountPage` plus tests, header and Portal links, and catalogs.
- [ ] Privacy and terms text in es/en, and `updated_at`.
- [ ] Docs.

## Verification Plan
- `php artisan test --filter=AccountSelfService` plus the full suite; CI `test` (SQLite) and `test-pgsql`, where the FK cascades are real.
- Vitest for `AccountPage` (disabled delete button, confirmation word per locale, error rendering, redirect after delete) and the i18n parity test.
- `.\init.ps1` exit 0 (unchanged script, no servers started).
- Browser QA with Playwright + Edge on a throwaway local DB at 1280 and 390 px, es and en.

## Evidence To Capture
- Test counts, CI run ids, and an example export with sections listed and values redacted.
- A DB check after deletion (row counts per table for the deleted id) and screenshots or snapshots of the page states.

## Validator Checklist
- [ ] Every endpoint is self-scoped, session-only and throttled; Visitor gets 401 and no session gets 400.
- [ ] The export holds all sections, only this user's data, and no secrets.
- [ ] Deletion is transactional, removes sessions and owned rows, nulls the shared logs, records `account_deleted`, and blocks Admins.
- [ ] A password change ends other sessions and keeps the current one.
- [ ] SPA flows work in es/en at both widths, with no sign-in flash after deletion.
- [ ] The privacy text mentions the Account page; `updated_at` is bumped; docs are updated; `init.ps1` and both CI jobs are green.

## Key Implementation Risks
- **Missed owned data.** A future table with `user_id` must be added to both the export and the deletion. Add a test that lists every column named `user_id`/`requested_by` in the schema and fails if it is not covered (allowlist).
- **Sessions are not FK-linked.** If they are not deleted explicitly, a deleted user's other browsers would keep a dangling session. Scenario 4 covers this.
- **Destructive action from the UI.** Mitigated by the password, the typed confirmation word and the throttle.
