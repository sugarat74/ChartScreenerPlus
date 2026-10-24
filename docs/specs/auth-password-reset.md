# Feature Implementation Spec: Forgot password by email

## Source Feature

- `id`: `auth-password-reset`; `area`: auth; `status`: not_started.
- `depends_on`: `auth-email-verification` (accepted).
- Source: `feature_list.json`; owner request of 2026-10-23, prepared 2026-10-24.

## Goal and job story

When I cannot remember my Chartiko password, I can request a link by email, set a new password within 60 minutes, and return to sign-in. The request response must not reveal whether the address has an account. A successful reset ends every existing session of that account.

## Non-goals

- No magic-link sign-in, email change, new account verification, SMS, new mail provider or Admin reset tool.
- No access grant from merely opening an email link; a POST with a valid token changes the password but does not sign the visitor in.
- Do not alter `api-guest-json-errors` or the verification requirement for new accounts.

## Users and permissions

- Any Visitor can request a reset and submit a valid token. The response to the request is identical for known and unknown addresses.
- A signed-in user already has `PUT /api/account/password`; the reset flow remains public because forgotten credentials cannot authenticate.
- A pending email-verification account can reset its password, but remains pending after signing in again.

## Acceptance scenarios

1. Given an existing account, when its email is submitted, then the same generic response appears as for an unknown address; one queued, localized reset email is sent only to that account.
2. Given a valid unused token younger than 60 minutes, when a new password meeting `Password::defaults()` and confirmation are POSTed, then the password and `remember_token` change, all account session rows are removed, the token is consumed, and the page offers sign-in without creating a session.
3. Given an expired, reused or modified token, when reset is submitted, then the password and sessions stay unchanged and a localized error is shown.
4. Given excessive requests, when the rate limit is crossed, then the API returns a localized 429. There is no mail to an unknown address and no address or token in application logs.
5. Given an account with a pending verification state, when reset succeeds, then `email_verified_at` remains unchanged.

## Repository research

- `routes/api.php`, `app/Http/Controllers/Auth/AuthController.php`: public register/login routes use Sanctum's first-party session and CSRF; login is throttled.
- `config/auth.php`, `database/migrations/0001_01_01_000000_create_users_table.php`: Laravel's `users` password broker uses `password_reset_tokens`, 60-minute expiry and 60-second token-generation throttle; the table already exists.
- `app/Support/OutboundMail.php`: shared readiness guard. Production must not write live reset links to a `log`/`array` mailer.
- `app/Http/Controllers/AccountController.php`, `app/Services/Admin/SessionDirectory.php`: `Password::defaults()`, `remember_token` rotation and `deleteForUser($id, $keepSessionId)` are established patterns. Its own password-change path retains the current session; this recovery flow must call the directory with `null` to remove all sessions.
- `docs/specs/auth-email-verification.md`, `app/Http/Controllers/Auth/EmailVerificationController.php`, `frontend/src/pages/VerifyEmailPage.tsx`: localized queued account email, public token page and no automatic sign-in conventions.
- `frontend/src/pages/LoginPage.tsx`, `frontend/src/router.tsx`, `frontend/src/nav.ts`, `frontend/src/lib/api.ts`, `frontend/src/i18n/messages/{es,en}.ts`, `DESIGN.md`: login, route, request/CSRF, translations and visual conventions.
- `frontend/package.json`: Vitest component tests exist; no persistent browser E2E command was found. `init.ps1` runs the full gate.

## Technical approach

1. Add public `POST /api/password/forgot` and `POST /api/password/reset` with distinct throttle prefixes. Require a stateful first-party session for CSRF on both; reject non-stateful calls with JSON 400. Use Laravel's `Password` broker for token creation, expiry, one-use validation and invalidation. Normalize the email as registration does. Validate email before the request but return the same generic success for any syntactically valid address, including broker throttle or delivery failure; apply an IP rate limit so anonymous traffic is bounded. Do not log addresses, tokens, full mail bodies or reset URLs.
2. Enqueue a dedicated es/en reset Mailable through the existing mail transport only when `OutboundMail::ready()` is true. Keep the same global From/Reply-To and the Brevo pixel disclosure already present in the privacy policy. The email contains a `/restablecer-contrasena?email=...&token=...` SPA URL valid for 60 minutes. Check the current locale at request time and preserve it in the queued Mailable. Never send to an unknown address; avoid user-supplied display text in the message. If outbound mail is unavailable, return the generic response and record a safe operational error without personal data.
3. On successful reset, update the password through the User model cast, rotate `remember_token`, call `SessionDirectory::deleteForUser($user->id, null)`, consume the token and leave `email_verified_at` untouched. Use a database transaction for the password/session writes; the broker's token consumption must happen only for a successful reset. If the requester already has a session, log it out and invalidate/regenerate the session so the database middleware cannot recreate an authenticated row at response end. Return no session that authenticates the visitor.
4. Add a login link, request page and reset page using `DESIGN.md`. The request page always shows the same confirmation text. The reset page handles invalid/expired token, validation, submission and success in es/en. Use the existing API helper for CSRF and error localization. Do not put the token in analytics, logs or page copy; remove it from browser URL after a successful POST.

## Expected file changes

- `routes/api.php`, new `app/Http/Controllers/Auth/PasswordResetController.php`, new `app/Mail/ResetPasswordAddress.php` and mail views: endpoints and email.
- `frontend/src/pages/{LoginPage,ForgotPasswordPage,ResetPasswordPage}.tsx`, `frontend/src/router.tsx`, `frontend/src/nav.ts`, `frontend/src/lib/api.ts`, `frontend/src/i18n/messages/{es,en}.ts`: UI and API contract.
- `lang/{es,en}/*.php`, `tests/Feature/PasswordResetTest.php`, focused Vitest files: server text and meaningful flow tests.
- `docs/specs/auth-password-reset.md`, `PROGRESS.md`, `feature_list.json`: implementation evidence and status when built.

## Visual design impact

- UI: yes. Follow `DESIGN.md` and the existing login form: bordered panel, yellow primary action, visible validation and success states. Include narrow mobile and keyboard/focus states. No new design asset.

## Durable documentation impact

- `ARCHITECTURE.md`: update only if the implementation creates a new mail/session boundary beyond the existing broker and transport.
- `CONSTRAINTS.md`: update with the durable no-enumeration, one-use reset and all-session invalidation rules.
- `AGENTS.md`: no change; workflow and startup are unchanged.
- `docs/user-and-access-model.md`: update the public recovery capability. `docs/risks-and-open-questions.md`: record any real delivery or privacy issue found.

## Implementation tasks

- [ ] Add guarded, throttled public endpoints and a localized queued mail using the existing broker.
- [ ] Implement one-use token reset and all-session invalidation without changing verification state.
- [ ] Add login link, request/reset pages, API client and es/en strings.
- [ ] Add Laravel feature tests for enumeration, throttling, token expiry/reuse, mail readiness, sessions and pending verification; add Vitest for both pages and navigation.
- [ ] Run the gate; update durable docs, `PROGRESS.md` and `feature_list.json` to `passing` only with evidence.

## Verification and evidence

- Run focused `php artisan test --filter=PasswordResetTest` and focused Vitest files, then `./init.ps1` (PowerShell). `init.ps1` executes non-blocking Laravel/SPA/engine checks and must not start long-running servers.
- Use a scratch DB and local mail transport for one browser/API flow: request, email link, reset, old password rejection, new password sign-in and prior-session rejection; never use the production DB or an actual user's address.
- No persistent E2E runner exists in `frontend/package.json`; Laravel integration tests plus React component tests cover the contract, and the scratch browser flow covers wiring.
- Record test counts, the scratch flow result and any delivery caveat in `PROGRESS.md` and `feature_list.json`.

## Validator checklist

- [ ] Generic request response is indistinguishable for known and unknown email.
- [ ] A reset is single-use, expires at 60 minutes, invalidates all sessions, leaves email verification unchanged and never signs in automatically.
- [ ] Brevo readiness, locale, privacy text and no sensitive logging were checked.
- [ ] UI and API scenarios pass in es/en; `./init.ps1` passes; evidence and status are recorded.
- [ ] Scope stays limited to password recovery.
