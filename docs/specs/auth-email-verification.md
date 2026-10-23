# Feature Implementation Spec: Email verification required for new accounts

## Source Feature

- `id`: `auth-email-verification`
- `area`: `auth`
- `depends_on`: `auth-registration-login` (accepted)
- `status`: `not_started` (requested 2026-10-23: "quiero que para registrarse haya que validar el correo")
- `source`: `feature_list.json`
- Next: `auth-password-reset` depends on this and reuses its shared mail pieces (section "Shared mail pieces").

## Goal

A new account must confirm that it owns its email address before it can use any account-only feature. After registering, the user receives a confirmation email (es/en) with a link that is valid for 24 h. Until they open it, they can sign in, but they only see a "Confirma tu correo" screen with a resend button, and the API treats every account-only feature as unavailable. Existing accounts and Admins are never locked out.

Production cannot send email today (`MAIL_MAILER=log`). The feature therefore ships **dormant**: it is enforced only when a flag is on and a real email transport is configured. It goes live in the same deploy as Brevo (`alerts-email-golive`).

## Non-Goals

- Password reset (`auth-password-reset`), email change, a re-verification flow, magic-link sign-in.
- Signing a user in from the link: the link proves mailbox ownership, it never creates a session.
- Pruning unverified accounts, an Admin "verified" column or badge, an Admin resend action (follow-ups, see Open Questions).
- Moving the alert digest onto the new mail layout. The digest keeps its own views.
- Fixing the guest 500 on non-JSON `auth:sanctum` requests. That is `api-guest-json-errors`. The new middleware and routes simply never redirect.
- Brevo account, DNS and secrets (`alerts-email-golive`).

## Job Story

When I create a Chartiko account,
I want to confirm my email with one click, in my language,
so that my account works and nobody can open an account with an address they do not own.

## Decisions (resolve every ambiguity)

**D1. One flag, plus a transport guard.**

- `AUTH_EMAIL_VERIFICATION_ENABLED` is read as `config('auth.verification.enabled')` and defaults to `false`.
- `EmailVerification::required()` is true only when **all** of these hold:
  - the flag is on;
  - `OutboundMail::ready()` is true, meaning:
    - `mail.provider` and `mail.provider_location` are both non-blank;
    - in production, the mailer is not `log` or `array`.
- So turning the flag on before Brevo exists changes nothing.
- When verification is not required:
  - no verification email is sent. Production's log mailer would otherwise write addresses and live links into `laravel.log`;
  - no account is ever pending;
  - the screen never appears.
- Rollback: set the flag to `false`. Pending users get full access immediately.

**D2. "Treated as verified".**

- The migration backfills `email_verified_at = now()` for every user whose value is null.
- While verification is **not required**, `register()` also stamps `email_verified_at = now()` on the new account (with `forceFill`; the column is not fillable). This extends the owner's rule "accounts from before the release are verified" to every account created before enforcement goes live.
- Therefore the go-live needs no backfill command, and the go-live order cannot cause a lockout.
- The column now means "verified, or created while verification was not required". Document this in `docs/domain-model.md`.

**D3. Admins are never pending.**

- `EmailVerification::pending(User $u)` is defined as `required() && ! $u->isAdmin() && ! $u->hasVerifiedEmail()`.
- This is the **only predicate**. Nothing else reads the flag or `email_verified_at` to decide access.

**D4. Denial rule (one rule, two enforcement points, both from D3).**

- Routes: every account-only API route sits behind the `verified` middleware alias.
  - The alias is overridden in `bootstrap/app.php` with `App\Http\Middleware\EnsureEmailVerified`, so the framework middleware, which redirects, is never used.
  - The middleware always returns JSON: `403 {"code":"email_verification.required","message":"<localized>"}`.
- Checks outside a route group: the `view-market-context` ability also denies when `pending()`. Three places depend on this:
  - `capabilities.market_context` is `false` for a pending account;
  - the public `GET /api/screener` answers sector filters with its existing `403 market_context.filter_unavailable`;
  - the existing `AlertEvaluator` check skips a Saved Screener alert with sector filters while its owner is pending (other alerts keep producing in-app notifications; the digest skip below keeps them out of email).
- Behind `verified`, the ability is never reached for a pending user, so the route answer is always `email_verification.required`, never a 404.
- Routes that stay reachable while pending, under `auth:sanctum` only:
  - `GET /api/user` and `POST /api/logout`;
  - `/api/account*`, so a user can still export or delete the account (GDPR rights do not depend on verification, and deletion is the only way out of an account with a mistyped address);
  - the resend endpoint.
- The admin group is unchanged, because Admins are never pending.
- Hiding UI is not access control: the SPA gate (D7) is only an affordance.

**D5. Who handles the link: the SPA, with a POST.**

- The email links to `https://www.chartiko.com/verificar-correo/{id}/{hash}?expires=…&signature=…`. nginx already serves `index.html` for non-`/api` paths.
- The SPA page then POSTs to `/api/email/verify/{id}/{hash}?expires=…&signature=…` with CSRF.
  - The signature is made by `URL::temporarySignedRoute('verification.verify', now()->addMinutes(1440), ['user' => id, 'hash' => sha1(email)], absolute: false)`.
  - The SPA URL is that relative API path with `/api/email/verify/` replaced by `/verificar-correo/`.
- A GET never changes anything, so mail link scanners cannot verify an address. This matches the digest's unsubscribe rule.
- The POST needs no session: the signature is the credential.
- `hash = sha1(email)` is compared with `hash_equals`.
- The query must carry only `expires` and `signature`.

**D6. Email.**

- It is a queued Mailable, `App\Mail\VerifyEmailAddress` (`tries` 3, `backoff` [60, 300]), sent in the locale of the request that triggered it (register or resend). Nothing new is stored.
- It contains **no user-supplied text**: no name. That keeps the confirmation email from being used to deliver someone else's text to a stranger's inbox.
- No images and no tracking.
- No `List-Unsubscribe` header: this is transactional, not list mail.
- Sender:
  - From is the global `MAIL_FROM_ADDRESS`, proposed as `Chartiko <no-reply@chartiko.com>`;
  - a new global Reply-To, `MAIL_REPLY_TO_ADDRESS=contacto@chartiko.com`, is routed by Cloudflare.
  - One authenticated sender serves account and alert emails. This replaces `alertas@` in the go-live spec; the owner can veto it.

**D7. SPA: one gate in `AppLayout`.**

- When `user.email_verification_pending === true`, the main area renders `VerifyEmailNotice` instead of `<Outlet/>` for every path except:
  - `/privacidad`, `/aviso-legal`, `/cookies`;
  - `/portal/cuenta`;
  - `/verificar-correo/*`.
- Public pages are included in the gate (the owner said "only see"). A pending user who wants to browse as a Visitor can sign out.
- The header keeps the name chip and the sign-out button and hides the notification bell. `UnreadNotificationsProvider` does not fetch while the account is pending.

**D8. Throttles, each with its own prefix.**

| Endpoint | Throttle | Keyed by | Why |
|---|---|---|---|
| `POST /api/register` (new) | `throttle:10,60,register` | IP | Registration now sends email: this stops mass sign-ups from mailing many addresses and burning Brevo's ~300/day. |
| Verify | `throttle:12,1,email-verify` | IP | |
| Resend | `throttle:6,60,email-verification-resend` | user | Plus a 60 s cooldown in the SPA. |

## Users And Permissions

| Actor | Access |
|---|---|
| Visitor | Unchanged. Can POST a verify link (the signature is the credential). |
| Pending Registered User | Signs in and out. Can use `/api/user`, `/api/account*` and resend. Gets 403 `email_verification.required` on every other account-only route. Every capability is `false`. |
| Verified Registered User and Admin | Unchanged. |

## Acceptance Scenarios

1. **Dormant (production default).**
   - Given the flag is off or no provider is configured.
   - When a Visitor registers.
   - Then the response is 201 with `email_verification_pending: false`, `email_verified_at` is set, no mail is queued, every feature works, and `/api/legal` returns `account_email: null`.
2. **Register while required.**
   - Given verification is required.
   - When a Visitor registers with `Accept-Language: en`.
   - Then the response is 201 with `email_verification_pending: true`, one `VerifyEmailAddress` is queued to that address in `en`, and the SPA shows "Confirm your email" with the address.
3. **Denied while pending.**
   - Given a pending user.
   - When they call any route in the verified group (the table under "Routes").
   - Then the response is 403 JSON `{code:"email_verification.required"}` (localized), and nothing is written.
   - `/api/user`, `/api/logout`, `/api/account*` and resend still work.
4. **Verify.**
   - Given a link at most 24 h old.
   - When the SPA page POSTs it, with or without a session.
   - Then the response is 200 `{status:"verified"}`, `email_verified_at` is set and a `Verified` event fires. If this is the session user, the SPA refreshes `/api/user` and the gate disappears.
   - A second POST returns 200 `{status:"already_verified"}`.
5. **Expired or tampered link.**
   - When the link is over 24 h old, the response is 403 `email_verification.link_expired`. If the account is already verified, it is `already_verified` instead.
   - When the signature, id or hash is altered, or the user no longer exists, the response is 403 `email_verification.invalid_link`.
   - Neither case changes anything.
6. **Resend.**
   - When the user is pending, the response is 202 `{status:"sent"}` and one mail is queued.
   - When the user is verified or an Admin, the response is 200 `{status:"already_verified"}` and nothing is queued.
   - When verification is not required, the response is 409 `email_verification.unavailable`.
   - The 7th call within an hour returns a localized 429 with `Retry-After`.
   - A guest gets 401; a non-stateful caller gets 400.
7. **Admin never locked out.**
   - Given an Admin with a null `email_verified_at` while verification is required.
   - Then every admin and account-only route works, and `email_verification_pending` is false.
8. **Backfill.**
   - Given users with a null `email_verified_at`.
   - When the migration runs, every one of them is verified. Rows that already have a value are untouched.
9. **Transport guard.**
   - Given the flag is on but `APP_ENV=production` with `MAIL_MAILER=log`, or the provider is blank.
   - Then verification is not required (scenario 1 applies).
10. **Mail failure.** A transport error never fails registration or resend. The `failed()` log carries the user id and the exception class, never the address.

## Repository Research

### Files Inspected

- **Auth core:**
  - `routes/api.php`: route groups, throttle prefixes, the Market Context `can:` group, the admin group.
  - `bootstrap/app.php`: `statefulApi`, CSRF exception, `SetLocale`, the `admin` alias, exception localizer.
  - `app/Http/Controllers/Auth/AuthController.php`: register in a transaction, `requireStatefulSession`.
  - `app/Models/User.php`: `email_verified_at` cast; `MustVerifyEmail` contract commented out. The base class already uses the `MustVerifyEmail` trait.
  - `app/Support/AuthUserPayload.php`, `app/Providers/AppServiceProvider.php`: the `view-market-context` gate.
  - `app/Http/Middleware/EnsureUserIsAdmin.php`, `SetLocale.php`, `app/Http/LocalizedFrameworkMessages.php`.
- **Callers of the market-context ability and account code:**
  - `app/Http/Controllers/ScreenerController.php`: `user('sanctum')` and the ability on a public route.
  - `SavedScreenerController.php`, `ScreenerInterpretController.php`, `AccountController.php`.
  - `app/Services/Account/AccountExporter.php` (already exports `email_verified_at`).
- **Alert email digest:**
  - `app/Mail/AlertDigest.php`, `app/Jobs/SendAlertDigest.php`, `app/Services/Alerts/{AlertDigestSender,AlertDigestContent,AlertEmailDigestAvailability}.php`.
  - `resources/views/mail/alert-digest*.blade.php`, `config/{mail,alerts,legal}.php`, `app/Http/Controllers/LegalController.php`.
- **Database and tests:**
  - `database/factories/UserFactory.php`: verified by default, plus an `unverified()` state.
  - The latest migration is `2026_10_25_000001_*`.
  - `tests/Feature/{AuthTest,AccessControlGuardTest}.php` and the `phpunit.xml` env (`MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`).
- **Laravel internals checked in `vendor/`:**
  - `MailManager::setGlobalAddress`: a global `reply_to` is used only when an address is set.
  - `UrlGenerator::hasCorrectSignature` and `signatureHasNotExpired`.
  - `SendQueuedMailable::failed`.
  - The framework `verified` alias (`EnsureEmailIsVerified`, which redirects).
  - The `Registered`-event auto listener: unused, because register dispatches no event.
- **SPA:**
  - `frontend/src/auth/*`: context, `RequireAuth`, `RedirectSignedIn`, `useSignedIn`, `useMarketContextAccess`.
  - `layouts/AppLayout.tsx`, `components/AppHeader.tsx`, `notifications/UnreadNotificationsProvider.tsx`.
  - `router.tsx`, `nav.ts`, `components/RouteMetadata.tsx`, `pages/RegisterPage.tsx`, `lib/api.ts` (`request`, `authApi`, `AuthUser`, `LegalInfo`).
  - `i18n/messages/es.ts` (`auth`), `i18n/legal/{es,types}.ts`, `pages/LegalPage.tsx`.
- **Deploy and docs:**
  - `deploy/nginx.conf` (SPA fallback), `deploy/configure-legal-env.py`, `.github/workflows/ci-deploy.yml`, `deploy/README.md` (alert email section), `.env.example`.
  - `docs/specs/alerts-email-golive.md`, `docs/specs/legal-compliance-eu.md` (processing inventory), `CONSTRAINTS.md` (Auth, Account Self-Service), `docs/user-and-access-model.md`, `docs/risks-and-open-questions.md`.

### Patterns To Follow

- The digest's three-part availability check (flag + provider + location) also gates the privacy text. `OutboundMail` reuses that idea.
- Signed **relative** URLs rooted at `APP_URL`, as in `AlertDigest::unsubscribeUrl`.
- `failed()` logs no address.
- Session-only endpoints use `requireStatefulSession()`.
- Errors use the shape `{code, message}`, as in `account.admin_cannot_delete`.
- Session tests follow `AuthTest`: `Origin` plus `sanctum.stateful`, and `forgetGuards()`.

### Gaps

- No shared transactional mail layout, no `mail.provider`, no global Reply-To.
- `POST /api/register` has no throttle.
- No E2E harness (`frontend/package.json` has only `vitest`). Coverage is Laravel feature tests, Vitest and a manual browser check.

## Technical Approach

### Shared mail pieces (also used by `auth-password-reset`)

**`config/mail.php`** gains:

- `'provider' => env('MAIL_PROVIDER')` and `'provider_location' => env('MAIL_PROVIDER_LOCATION')`;
- `'reply_to' => ['address' => env('MAIL_REPLY_TO_ADDRESS'), 'name' => env('MAIL_FROM_NAME', env('APP_NAME'))]`.

**`config/alerts.php`**: the digest's provider settings fall back to the new mail variables, so one pair of variables names Brevo everywhere and the digest code does not change:

- `provider` becomes `env('ALERT_EMAIL_PROVIDER', env('MAIL_PROVIDER'))`;
- `provider_location` follows the same pattern.

**`app/Support/OutboundMail.php`** exposes `ready(): bool`, `provider(): ?string` and `providerLocation(): ?string`, as defined in D1.

**Mail views:**

- `resources/views/mail/layouts/account.blade.php` (HTML) is the digest's frame:
  - 600 px table, `#f4f1ea` background, CHARTIKO badge, no images;
  - `@yield('heading')`, `@yield('body')`;
  - a footer with `account_email.layout.why` and a privacy link.
- `account-text.blade.php` is the plain-text equivalent.

**`lang/{es,en}/account_email.php`** holds:

- `layout.*`: `why`, `privacy`, `ignore`;
- `verify.*`: `subject`, `heading`, `intro`, `button`, `fallback`, `expires`.

Password reset will add `reset.*` to the same file.

**Email text (es):**

| Key | Text |
|---|---|
| `subject` | "Confirma tu email en Chartiko" |
| `heading` | "Confirma tu dirección de email" |
| `intro` | "Para activar tu cuenta de Chartiko, confirma que esta dirección es tuya." |
| `button` | "Confirmar mi email" |
| `fallback` | "Si el botón no funciona, copia este enlace en tu navegador:" + the full URL |
| `expires` | "El enlace caduca en 24 horas. Si caduca, inicia sesión y pide otro." |
| `ignore` | "Si no has creado una cuenta en Chartiko, ignora este email: sin confirmarla, la cuenta no puede usar ninguna función." |
| `why` | "Recibes este email porque se ha creado una cuenta en chartiko.com con esta dirección." |

The en version is equivalent. The link is a plain `<a>` styled as a button; there is no tracking and no image.

### Verification backend

**`config/auth.php`** gains `'verification' => ['enabled' => (bool) env('AUTH_EMAIL_VERIFICATION_ENABLED', false), 'expire' => 1440]`. The expiry is fixed at 24 h, as the owner decided, and the framework's `auth.verification.expire` key is reused.

**`app/Support/EmailVerification.php`** provides:

- `required()` and `pending(?User)`, as in D1 and D3;
- `link(User): string`, the SPA URL from D5, built as `AlertDigestContent::siteUrl(...)` from the relative signed route.

**`App\Services\Auth\EmailVerificationMailer::send(User)`**:

- calls `Mail::to($user->email)->locale(app()->getLocale())->queue(new VerifyEmailAddress(EmailVerification::link($user)))` inside `try/catch (Throwable)`;
- on failure, logs only `user_id` and the exception class.

`User::sendEmailVerificationNotification()` is overridden to call it, so the framework notification can never be sent. Do **not** implement the `MustVerifyEmail` contract.

**`App\Mail\VerifyEmailAddress`** is a `ShouldQueue` Mailable:

- it receives the URL built at request time, so the 24 h run from the click on "create" or "resend";
- views `mail.verify-email` and `mail.verify-email-text`;
- `failed()` logs no address.

**`AuthController::register()`**:

- adds `throttle:10,60,register` on the route;
- inside the transaction, if `! EmailVerification::required()`, it calls `forceFill(['email_verified_at' => now()])` before the save;
- after the transaction commits, if required, it calls `$user->sendEmailVerificationNotification()`.

**`App\Http\Controllers\Auth\EmailVerificationController`:**

- **`verify(Request, int $user, string $hash)`.** Steps run in this order:
  1. `! URL::hasCorrectSignature($request, false)` → 403 `invalid_link`.
  2. The user is missing, or `! hash_equals(sha1($u->email), $hash)` → 403 `invalid_link`.
  3. `$u->hasVerifiedEmail()` → 200 `{status:'already_verified'}`.
  4. `! URL::signatureHasNotExpired($request)` → 403 `link_expired`.
  5. Otherwise call `markEmailAsVerified()`, then `event(new Verified($u))`, and return 200 `{status:'verified'}`.

  Errors use the shape `{code: 'email_verification.<x>', message: __('messages.email_verification.<x>')}`.

- **`resend(Request)`:**
  - first `requireStatefulSession`;
  - if `! required()` → 409 `unavailable`;
  - if `! pending($user)` → 200 `already_verified`;
  - otherwise send and return 202 `{status:'sent'}`.

**`App\Http\Middleware\EnsureEmailVerified`**: if `EmailVerification::pending($request->user())`, it returns the JSON 403 from D4; otherwise it calls `$next`. It is registered as the `verified` alias in `bootstrap/app.php`.

**`AuthUserPayload::make()`** adds `'email_verification_pending' => EmailVerification::pending($user)`. `email_verified_at` is already in `toArray()`.

**`AppServiceProvider`**: the `view-market-context` closure first returns `Response::denyAsNotFound(__('market_context.not_available'))` when `EmailVerification::pending($user)`. Update its comment to explain why.

**Digest**: `AlertDigestSender::queue()` skips users for whom `pending()` is true, and `SendAlertDigest::handle()` re-checks it. This is one condition each, so no digest ever goes to an unverified address.

**`LegalController`** adds `'account_email' => EmailVerification::required() ? ['provider' => …, 'provider_location' => …] : null`. Password reset will widen this condition.

**Migration** `database/migrations/2026_10_26_000001_backfill_users_email_verified_at.php`:

- `up()` runs `DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()])`;
- `down()` is a documented no-op, because the backfilled rows cannot be told apart.

### Routes (`routes/api.php`)

| Method | Path | Middleware | Responses |
|---|---|---|---|
| POST | `/api/register` | `throttle:10,60,register` | unchanged, plus `email_verification_pending` |
| POST | `/api/email/verify/{user}/{hash}` | `throttle:12,1,email-verify`; `whereNumber('user')`, `hash` `[a-f0-9]{40}`; name `verification.verify` | 200 `verified` or `already_verified`; 403 `invalid_link` or `link_expired`; 429 |
| POST | `/api/email/verification-notification` | `auth:sanctum`, `throttle:6,60,email-verification-resend` | 202 `sent`; 200 `already_verified`; 409 `unavailable`; 400 non-stateful; 401; 429 |

Inside `auth:sanctum`, split the group into two parts:

- **Not verified** (reachable while pending): `GET /user`, `POST /logout`, `/account*`, resend.
- **`Route::middleware('verified')->group(...)`**, which wraps every other existing route:
  - forecast;
  - company-card GET and POST;
  - `screener/interpret`;
  - `watchlist*`, `alerts*`, `notifications*`, `alerts/email`, `screeners*`;
  - the `can:view-market-context` group, nested inside.

The admin group is unchanged. Add a comment that states the rule. Verify needs no CSRF exemption: the SPA sends the token.

### SPA

**`lib/api.ts`:**

- `AuthUser.email_verification_pending?: boolean`. Absent means `false`.
- `LegalInfo.account_email?: {provider, provider_location} | null`.
- `emailVerificationApi.verify(userId, hash, expires, signature)`:
  - calls `ensureCsrfCookie`, then a POST with `URLSearchParams({expires, signature})`;
  - returns `'verified' | 'already_verified'`, or throws `ApiError` (status plus `code`; `ApiError` already carries the body's `code`).
- `emailVerificationApi.resend()` returns `'sent' | 'already_verified'`.

**`auth/useEmailVerificationPending.ts`** returns `false` outside the provider and while loading. It is the single client seam.

**`components/auth/VerifyEmailNotice.tsx`**, following the auth page pattern from `DESIGN.md` (badge, black headline, bordered card):

- `verifyEmail.title`, plus a body containing `{email}`, plus `spamHint`;
- the **Resend** button, with these states:
  - `idle`;
  - `sending` (disabled, `resending`);
  - `sent` (status message `resent`, then a 60 s cooldown showing `cooldown {seconds}`);
  - `error`: show the server message (429, localized), `auth.serverUnreachable`, or, for a 409, refresh the user;
- **"Ya lo he confirmado"** re-reads `/api/user` with `setCurrentUser`. If the account is still pending, it shows `stillPending`;
- a link to `/portal/cuenta` (`manageAccount`) and a sign-out button;
- `role="status"` and `aria-live` for messages.

**`pages/VerifyEmailPage.tsx`** is the route `/verificar-correo/:userId/:hash`:

- A missing `expires` or `signature` → `invalid`, with no request.
- Otherwise it POSTs once; a ref guard prevents a second request under StrictMode.
- States and what each shows:

  | State | Shows |
  |---|---|
  | `verifying` | progress message |
  | `verified` / `already` | if signed in, refresh the user and a **Continue** button to `/`; otherwise a sign-in link |
  | `expired` | `expiredBody`; resend button if signed in and pending, otherwise a sign-in link |
  | `invalid` | `invalidBody`, same actions as `expired` |
  | `error` | network or 5xx message, with **Retry** |

**`layouts/AppLayout.tsx`** contains the D7 gate. The exempt list is a constant in `nav.ts` (`VERIFY_EMAIL_ROUTE = '/verificar-correo'`, plus the legal routes and the account route).

**Header and notifications**: `AppHeader` hides `NotificationBell` while pending, and `UnreadNotificationsProvider` does not fetch.

**Other wiring:**

- `router.tsx` adds the route.
- `RouteMetadata.tsx` gets `meta.verifyEmailTitle` and `meta.verifyEmailDescription`, matched by prefix. The route stays `noindex`.
- `RegisterPage.tsx` shows `auth.registerVerifyHint` under the intro when `legal.info.account_email` is set. Navigation after register is unchanged: the gate takes over.

**i18n**:

- New `verifyEmail.*` namespace (es/en) with these keys: `badge`, `title`, `body`, `spamHint`, `resend`, `resending`, `resent`, `cooldown`, `checkAgain`, `stillPending`, `manageAccount`, `verifyingTitle`, `verifiedTitle`, `verifiedBody`, `alreadyTitle`, `expiredTitle`, `expiredBody`, `invalidTitle`, `invalidBody`, `errorBody`, `retry`, `continue`, `signIn`.
- Plus `auth.registerVerifyHint` and the two `meta.*` keys.
- No hardcoded text.

**Server messages**: `lang/{es,en}/messages.php` gets `email_verification.{required,invalid_link,link_expired,unavailable}`.

| Key | es |
|---|---|
| `required` | "Confirma tu email para usar esta función." |
| `invalid_link` | "El enlace de confirmación no es válido." |
| `link_expired` | "El enlace de confirmación ha caducado. Pide otro." |
| `unavailable` | "La confirmación por email no está disponible." |

### Privacy text (shown only while `account_email` is non-null)

**`i18n/legal/types.ts`** adds an `accountEmail?: string[]` section field, documented like `emailDigest`.

**`LegalPage.tsx`**:

- appends it when `info.account_email` is set;
- fills `{emailProvider}` and `{emailProviderLocation}` from `info.account_email ?? info.email_digest`.

**Paragraphs (es; en equivalent):**

- **"Cuenta de usuario":** "Al registrarte te enviamos un email para confirmar que la dirección es tuya, con un enlace que caduca a las 24 horas. Hasta que la confirmes, la cuenta solo permite reenviar ese email, gestionarla o eliminarla. Guardamos la fecha de confirmación."
- **"Destinatarios":** "Los emails de tu cuenta (como la confirmación de tu dirección) se envían a través de {emailProvider}, el proveedor de envío de correo, que los trata por cuenta de Chartiko como encargado del tratamiento. Ubicación del tratamiento: {emailProviderLocation}."

Legal basis: contract, already stated. **Do not bump** `config/legal.php` `updated_at` in this feature: the text is dormant. The go-live bumps it.

**`docs/specs/legal-compliance-eu.md`**: add an inventory row for "Account email (verification)": email address and verification date; contract (6.1.b); kept for the life of the account; processor `MAIL_PROVIDER`; shown only while active.

### Config and deploy

- **`.env.example`**: add `AUTH_EMAIL_VERIFICATION_ENABLED=false`, `MAIL_PROVIDER=`, `MAIL_PROVIDER_LOCATION=` and `MAIL_REPLY_TO_ADDRESS=`. Add a comment that `ALERT_EMAIL_PROVIDER*` falls back to `MAIL_PROVIDER*`.
- **Ship (this feature)**: a normal deploy with no environment change. The migration backfills; verification stays dormant (scenario 1).
- **Go-live**: done by `alerts-email-golive`, see the correction below:
  - `configure-mail-env.py` also writes `MAIL_PROVIDER="Brevo"`, `MAIL_PROVIDER_LOCATION="UE (Francia)"`, `MAIL_FROM_ADDRESS=no-reply@chartiko.com`, `MAIL_REPLY_TO_ADDRESS=contacto@chartiko.com` and `AUTH_EMAIL_VERIFICATION_ENABLED=true`;
  - `legal.updated_at` is bumped;
  - run `config:cache` and **restart `alphapulse-queue`**, because verification emails are queued.
- **Rollback**: set the flag to `false`, run `config:cache`, restart the worker.

### Coordination with `alerts-email-golive` (narrow correction made in that spec)

The following edits were made to `docs/specs/alerts-email-golive.md` in this planning session:

- The goal now also switches on email verification, when `auth-email-verification` is accepted.
- The sender is `no-reply@` with Reply-To `contacto@`, instead of `alertas@`.
- Owner step: disable open and click tracking in Brevo.
- The env list uses `MAIL_PROVIDER*` instead of `ALERT_EMAIL_PROVIDER*`, and adds the verification flag.
- New verification checks: register a test account and verify through the received link.

The prompt-only script and the rest of that spec are unchanged. `feature_list.json` is not edited: `alerts-email-golive` should gain `auth-email-verification` in `depends_on` (the coordinator's call).

## Expected File Changes

### Create

- `app/Support/OutboundMail.php`, `app/Support/EmailVerification.php`
- `app/Services/Auth/EmailVerificationMailer.php`
- `app/Mail/VerifyEmailAddress.php`
- `app/Http/Middleware/EnsureEmailVerified.php`
- `app/Http/Controllers/Auth/EmailVerificationController.php`
- `resources/views/mail/layouts/{account,account-text}.blade.php`
- `resources/views/mail/{verify-email,verify-email-text}.blade.php`
- `lang/{es,en}/account_email.php`
- `database/migrations/2026_10_26_000001_backfill_users_email_verified_at.php`
- `tests/Feature/EmailVerificationTest.php`
- `frontend/src/auth/useEmailVerificationPending.ts`
- `frontend/src/components/auth/VerifyEmailNotice.tsx` plus its test
- `frontend/src/pages/VerifyEmailPage.tsx` plus its test
- `frontend/src/layouts/AppLayout.test.tsx`

### Modify

- `config/{auth,mail,alerts}.php`, `.env.example`
- `bootstrap/app.php`, `routes/api.php`
- `app/Models/User.php`, `app/Http/Controllers/Auth/AuthController.php`
- `app/Support/AuthUserPayload.php`, `app/Providers/AppServiceProvider.php`
- `app/Http/Controllers/LegalController.php`
- `app/Services/Alerts/AlertDigestSender.php`, `app/Jobs/SendAlertDigest.php`
- `lang/{es,en}/messages.php`
- `tests/Feature/{AuthTest,LegalInfoTest,AlertEmailDigestTest,MarketContextAccessTest}.php`
- `frontend/src/{lib/api.ts,router.tsx,nav.ts,layouts/AppLayout.tsx}`
- `frontend/src/components/{AppHeader,RouteMetadata}.tsx`, `frontend/src/notifications/UnreadNotificationsProvider.tsx`
- `frontend/src/pages/{RegisterPage,LegalPage}.tsx`
- `frontend/src/i18n/messages/{es,en}.ts`, `frontend/src/i18n/legal/{es,en,types}.ts`, plus the `LegalPage` test

## Visual Design Impact

- UI is involved. Source: `DESIGN.md` and the existing auth pages, reusing the same classes as `RegisterPage` and `RequireAuth`.
- New states:
  - the notice (idle, sending, sent with cooldown, error);
  - the verify page (verifying, verified, already, expired, invalid, error);
  - the header with the bell hidden.
- Check light and dark themes at 390 px and 1280 px.
- The email reuses the digest's look (render it in Edge at 390 px and 640 px: no overflow, no images).
- No new design artifact is needed.

## Durable Documentation Impact

- **`ARCHITECTURE.md`: update.**
  - "Authentication": the verification flow, `EmailVerification` as the single predicate, the `verified` alias, the SPA gate and link page.
  - "Configuration": the new env vars, plus `OutboundMail` and the shared mail layout.
- **`CONSTRAINTS.md`: update.** Auth rules:
  - every account-only route MUST sit in the `verified` group, except `user`, `logout`, `account*` and resend;
  - the denial MUST be the JSON 403 `email_verification.required`, never a redirect;
  - MUST NOT implement the `MustVerifyEmail` contract or use the framework verify notification;
  - verify links MUST be signed relative URLs, POSTed by the SPA (never verified on GET), and MUST never sign the user in;
  - account emails MUST NOT contain user-supplied text or tracking;
  - access decisions read only `EmailVerification::pending()`.
- **`docs/user-and-access-model.md`: update.** Add the pending state to User Types, Permissions and Access Rules, and replace the line "verification waits for a provider".
- **`docs/domain-model.md`: update.** The meaning of `email_verified_at` (D2).
- **`docs/risks-and-open-questions.md`: update.** Line 67: verification is planned and dormant until Brevo. Add the risks of abuse through registration email and Brevo's daily cap.
- **`deploy/README.md`: update.** The email section: shared `MAIL_*` variables, `MAIL_PROVIDER*`, the verification flag, the restart of the worker, rollback.
- **`docs/specs/legal-compliance-eu.md`: update.** The inventory row.
- **`docs/specs/alerts-email-golive.md`: updated** in this planning session (see Coordination).
- **`AGENTS.md`, `CONTEXT.md`: not needed.** There is no workflow change and no new domain term ("pending verification" is an account state, documented in the access model).

## Implementation Plan

1. Add the config, `OutboundMail` and `EmailVerification`, and the migration. Add the `verified` alias and middleware.
2. Build the mail layout, the Mailable, the views and the language files, and the mailer service. Override `User::sendEmailVerificationNotification`.
3. Change `register` (throttle, stamp or send). Add the verify and resend controller and routes, and regroup `routes/api.php`.
4. Update the payload, the market-context ability, the digest skip and `/api/legal`.
5. Laravel tests, then run `php artisan test`.
6. SPA: the API client, the hook, the notice, the page, the `AppLayout` gate, the header and notifications changes, the router and metadata, the register hint, i18n and legal text. Then the Vitest tests.
7. Update docs and `.env.example`. Run `.\init.ps1`, then the manual checks.

## Implementation Tasks

- [ ] Config: `auth.verification`, `mail.provider*` and `mail.reply_to`, the `alerts` fallback, `.env.example`.
- [ ] `OutboundMail`, `EmailVerification` (`required`, `pending`, `link`) and the backfill migration.
- [ ] `EnsureEmailVerified` and the `verified` alias. Regroup `routes/api.php`. Add the register throttle.
- [ ] `VerifyEmailAddress`, the layout and the views, `account_email` lang (es/en), `EmailVerificationMailer`, the `User` override.
- [ ] `AuthController::register` stamp or send. `EmailVerificationController` verify and resend. The `messages.email_verification.*` keys.
- [ ] `AuthUserPayload` pending field, the ability's pending deny, the digest skips, `LegalController` `account_email`.
- [ ] `tests/Feature/EmailVerificationTest.php` and the updates to existing tests (see Verification).
- [ ] SPA API, hook, `VerifyEmailNotice`, `VerifyEmailPage`, `AppLayout` gate, header and notifications, router and meta, register hint.
- [ ] i18n `verifyEmail.*` (es/en), the legal `accountEmail` paragraphs, the `LegalPage` wiring.
- [ ] Vitest tests, docs, `.\init.ps1`, manual checks, evidence in `feature_list.json` and `PROGRESS.md`.

## Verification Plan

### Laravel: `tests/Feature/EmailVerificationTest.php`

Use `Mail::fake()`. To make verification required, use a helper that sets the flag, `mail.provider` and `mail.provider_location` with `config([...])`.

- **Dormant cases**:
  - flag off → register stamps `email_verified_at`, nothing is queued, pending is false;
  - flag on but provider blank → the same;
  - `app()->detectEnvironment(fn () => 'production')` with the `log` mailer → `required()` is false.
- **Register while required**: 201, pending is true, `assertQueued(VerifyEmailAddress::class)` exactly once to that address with locale `en` (from `Accept-Language`). The rendered HTML and text contain the `/verificar-correo/{id}/{sha1}` URL and none of: the user's name, `<img`, a `List-Unsubscribe` header.
- **Verify**:
  - success sets the column and fires the `Verified` event;
  - a second call returns `already_verified`;
  - `travel(1441)->minutes()` → `link_expired` with nothing changed;
  - an expired link for an account that is already verified → `already_verified`;
  - a tampered signature, another id, or a wrong hash → `invalid_link`;
  - a deleted user → `invalid_link`;
  - a link posted with an extra query parameter → `invalid_link`;
  - the request works without a session.
- **Resend**:
  - 202 with one mail queued;
  - verified user → 200 with none queued;
  - Admin with a null `email_verified_at` → 200 `already_verified`;
  - not required → 409;
  - guest → 401;
  - non-stateful → 400;
  - the 7th call within an hour → 429 with a localized message.
- **Denial matrix**: a data provider over every route in the verified group. A pending user gets 403 `{code: "email_verification.required"}` with the es and en messages, and `watchlist_items`, `saved_screeners` and `alerts` stay empty. `/api/user`, `/api/logout`, `PATCH /api/account` and `GET /api/account/export` still answer 200 or 204.
- **Structural guard**:
  - iterate `Route::getRoutes()`: every `api/*` route whose middleware includes `auth:sanctum` and not `admin` must include `verified`, except the allowlist (`api/user`, `api/logout`, `api/account*`, `api/email/verification-notification`);
  - the `verified` alias resolves to `EnsureEmailVerified`.
- **Market Context**: a pending user's `capabilities.market_context` is false; the public `GET /api/screener?sector_trend=up` returns 403. An Admin with a null `email_verified_at` is not pending and passes every admin and Market Context route.
- **Migration backfill**: insert rows with a null value and with a set value, require the migration file and call `up()`. Null rows get a date; rows with a date keep it.
- **Mail failure**: a transport exception does not fail registration (201). The log context has `user_id` and no `@`.
- **Updates to existing tests**:
  - `AuthTest`: register stamps the column while dormant; register is throttled (the 11th call per IP returns 429).
  - `LegalInfoTest`: `account_email` is null while dormant and returns the provider and location while required.
  - `AlertEmailDigestTest`: a pending opted-in user gets no digest.
  - `AccountSelfServiceTest`: unchanged (no new user column or table).

### Vitest

- **`VerifyEmailNotice`**: es/en text including the email; the resend states (sending disabled, sent with cooldown countdown, a 429 shows the server message, the network error message); "ya lo he confirmado" refreshes the user; the account link and sign-out are present.
- **`VerifyEmailPage`**: missing params → invalid with no fetch; verified, already, expired and invalid map to the right states; one POST under StrictMode; continue versus sign-in depends on the session.
- **`AppLayout` gate**: a pending user on `/` and `/portal` sees the notice; on `/privacidad`, `/portal/cuenta` and `/verificar-correo/1/x` sees the real page; a verified user or a Visitor sees the outlet; the bell is hidden and `/api/notifications` is not called while pending.
- **`LegalPage`**: the `accountEmail` paragraphs appear only when `account_email` is set, with the provider filled in.
- **`RegisterPage`**: the hint appears only when `account_email` is set.
- **i18n**: the existing catalog parity test covers the new keys.

### Commands

```powershell
php artisan test --filter=EmailVerification
php artisan test
npm --prefix frontend run test
npm --prefix frontend run lint
npm --prefix frontend run build
.\init.ps1   # non-blocking gate; starts no server
```

There is no E2E harness. The feature tests above cover the API flow, and the manual browser check covers the SPA flow.

### Manual checks

Use a scratch SQLite: point `DB_DATABASE` at a file in the scratchpad and run `php artisan migrate`. **Never** use the dev database with destructive commands, and never use `--env=testing`. Set `AUTH_EMAIL_VERIFICATION_ENABLED=true`, `MAIL_MAILER=log`, `MAIL_PROVIDER="Local"` and `MAIL_PROVIDER_LOCATION="local"`, then:

1. Register in es: the notice shows the address, and `laravel.log` contains one email with a `/verificar-correo/` link.
2. Open `/portal` and `/`: you get the notice. Open `/privacidad` and `/portal/cuenta`: they work.
3. Resend: the cooldown runs. Spam the button: the 429 message appears.
4. Open the link in a private window (no session): "Correo confirmado" with a sign-in link. In the original tab, "Ya lo he confirmado" removes the gate.
5. Edit the signature in the link: "Enlace no válido".
6. Turn the flag off and run `config:clear`: pending users get full access.
7. Render the HTML part in Edge at 390 px, light and dark.

Remove the scratch DB afterwards.

## Evidence To Capture

- `php artisan test` totals and the `EmailVerificationTest` count.
- Vitest totals, lint and build, and `.\init.ps1` exit 0.
- `php artisan route:list --path=api -v` showing the `verified` group, the three new or changed routes and their throttles.
- The manual check results (states, log link, 403 JSON es/en) and screenshots of the notice and the verify page at 390 px and 1280 px.
- A statement that production stays dormant: with the flag off, `/api/legal` has `account_email: null`.

## Validator Checklist

- [ ] With the defaults (flag off), behaviour is identical to today except the register throttle, the stamped `email_verified_at` and the added payload field.
- [ ] One predicate (`EmailVerification::pending`), and every account-only route is in the `verified` group. The structural test proves it.
- [ ] The denial is JSON 403 `email_verification.required`, localized, with no redirect anywhere. Verify, resend and the middleware never return HTML or a 500.
- [ ] Verification happens only on a signed POST, never on GET. The link expires after 24 h and does not sign anyone in. Tampered and expired links are rejected.
- [ ] Existing users are backfilled. Admins are never pending.
- [ ] The email is es/en, HTML plus text, with no user-supplied text, no images and no tracking. Logs contain no addresses.
- [ ] Privacy paragraphs appear only while verification is required. `legal.updated_at` is not bumped by this feature.
- [ ] SPA: the gate, its exemptions and the verify page states match D7 and D5; no hardcoded text.
- [ ] Scope: no password reset, email change, Admin UI or pruning. `feature_list.json` and `PROGRESS.md` are updated with evidence.

## Open Questions (owner; none blocks implementation)

1. **Sender.** `no-reply@chartiko.com` with Reply-To `contacto@` is proposed in place of `alertas@`. Keeping `alertas@` for everything also works; only `MAIL_FROM_ADDRESS` changes.
2. **Unverified accounts.** Should accounts that never verify be deleted after N days? Not built here; they hold only name, email and hash, and can be deleted by the user.
3. **Brevo tracking.** Confirm in the Brevo panel that open and click tracking can be turned off for transactional SMTP. The privacy texts promise no tracking.

## Implementation Notes (2026-10-23, implementer)

Built as specified. Small deviations, each needed or harmless:

1. `VerifyEmailAddress` takes `(string $url, ?int $userId)`: the user id is only for the `failed()` log (`{user_id, error}`), which the spec requires.
2. `EnsureEmailVerified` is prepended before `SubstituteBindings` in the middleware priority list, so a pending account gets `email_verification.required` even for ids that do not exist (route model binding never answers first).
3. `config/alerts.php` falls back with `env('ALERT_EMAIL_PROVIDER') ?: env('MAIL_PROVIDER')` (and the same for the location) instead of `env(..., default)`: an empty `ALERT_EMAIL_PROVIDER=` line, as in `.env.example`, would otherwise block the fallback.
4. The resend control is its own component, `frontend/src/components/auth/ResendVerificationButton.tsx`, shared by `VerifyEmailNotice` and the expired/invalid states of `VerifyEmailPage`.
5. `VerifyEmailPage` re-reads `/api/user` after every successful verify (not only for a signed-in session) and again when **Continue** is pressed: the auth bootstrap request can race the verify POST and leave a stale "pending" user. A signed-in account that is not pending gets **Continue** (not a sign-in link) on the expired and invalid states.
6. The account mail layouts accept optional `ignore` and `why` sections (default: the account-creation lines), so `auth-password-reset` can reuse the frame with its own footer lines.
7. The `AlertEvaluator` skip is covered through the ability (`MarketContextAccessTest`, `EmailVerificationTest`), not a separate evaluator test.
