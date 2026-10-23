# User and Access Model

## User Types

- **Visitor (anonymous):** can browse the Screener, view Candidate lists and charts.
- **Registered User:** an authenticated Visitor who can additionally save Screeners and maintain a Watchlist.
  - **Pending email verification** (account state, `auth-email-verification`; only while verification is required, i.e. `AUTH_EMAIL_VERIFICATION_ENABLED` on and outbound email ready): a Registered User who has not yet opened the confirmation link. They can sign in and out, read `GET /api/user`, use `/api/account*` (export, delete, change name or password) and resend the email; every other account-only API answers `403 {code: "email_verification.required"}` and every capability is `false`. Admins are never pending; accounts created while verification is not required count as verified.
- **Admin (Operator):** controls and supervises Ingestion Runs and the Instrument universe.

## Roles

- `visitor` (implicit, no account)
- `registered` (user account)
- `admin` (privileged account)

**Concrete storage:** every account row carries a single `role` column (`users.role`), a string defaulting to `user`; the two stored values are `user` (Registered User) and `admin` (Admin). `visitor` is implicit — it means "no authenticated account", not a stored role. The model exposes `User::ROLE_USER` / `User::ROLE_ADMIN` and `User::isAdmin()`. There is no multi-role or permissions table.

## Permissions

| Capability | Visitor | Registered User | Admin |
| --- | --- | --- | --- |
| Browse Screener and results | yes | yes | yes |
| View interactive chart of a Candidate | yes | yes | yes |
| Request an experimental Jev forecast | no | yes | yes |
| Read / generate an AI Company Card | no | yes | yes |
| Describe a Screener search in natural language (AI) | no (sign-in link) | yes | yes |
| Open `/mercado` and `GET /api/market-context` (Market Context) | no: a sign-in box once `MARKET_CONTEXT_PUBLIC=true`, "not available yet" before; API 401 | yes once public (API 404 before) | yes (preview with a banner while not public) |
| Market Context history charts (`/api/market-context/history`) | no (API 401) | yes once public | yes |
| Chart strip and pattern chips (`GET /api/instruments/{ticker}/market-context`) | no: a sign-in box once public, nothing before; API 401 | yes once public (nothing / API 404 before) | yes ("Vista previa" chip while not public) |
| Screener sector filters (`sector_trend`, `sector_rs_rank_max`), in Saved Screeners, alerts and the NL Screener | no: sign-in box in the filter panel once public; the page requests without them; API 401 | yes once public (API 403 before; their alerts are skipped) | yes |
| Market Context Admin tab (status, rerun the three commands) | no | no | yes |
| View forecast evaluation metrics | no | no | yes |
| Start and inspect Jev Backtesting runs | no | no | yes |
| Save / apply Saved Screeners | no | yes (own) | yes |
| Maintain Watchlist | no | yes (own) | yes |
| Trigger / re-run Ingestion Runs | no | no | yes |
| Manage Instrument universe | no | no | yes |
| View ingestion logs and telemetry | no | no | yes |
| View users, active sessions and sign-in activity | no | no | yes |
| End another user's sessions | no | no (own via sign-out) | yes (never own current) |
| Change own name and password; download own data (JSON) | no | yes (own) | yes (own) |
| Delete own account | no | yes (own) | no (remove the role out of band first) |
| Use any other account-only feature while pending email verification | n/a | no (JSON 403 `email_verification.required`; the SPA shows only "Confirma tu correo" with resend) | n/a (never pending) |
| Confirm the email from the emailed link (`POST /api/email/verify/...`, signed, 24 h) | yes (the signature is the credential; it signs nobody in) | yes | yes |

## Ownership Boundaries

- Saved Screeners and Watchlist Entries are private: a Registered User only sees and modifies their own.
- **Watchlist storage and endpoints (implemented):** a watchlist is one implicit list per user, stored in the `watchlist_items` pivot (`user_id`, `instrument_id`, unique `(user_id, instrument_id)`, cascade FKs). There is no `watchlists` table; ownership is `user_id`. The endpoints are `GET /api/watchlist` (list), `POST /api/watchlist {ticker}` (follow, idempotent) and `DELETE /api/watchlist/{ticker}` (unfollow). All three are `auth:sanctum` and every query is scoped server-side to `$request->user()->watchlist()`; no endpoint accepts a `user_id`, so a cross-user read is an empty list and a cross-user delete is a `404`.
- **Saved Screener storage and endpoints (implemented):** a Saved Screener is a row in the `saved_screeners` table (`user_id`, `name`, `filters` json, unique `(user_id, name)`, cascade FK to `users`); ownership is `user_id` and rows are created through `$request->user()->savedScreeners()`. The endpoints are `GET /api/screeners` (list), `POST /api/screeners {name, filters}` (save) and `DELETE /api/screeners/{screener}` (delete). All three are `auth:sanctum` and every query is scoped server-side to `$request->user()->savedScreeners()`; no endpoint accepts a `user_id`, so a cross-user read is an empty list and a cross-user delete is a `404`. `name` is unique per user; `filters` is the canonical eleven-key screener definition (including `sort`, the pattern keys and the sector context keys).
- Registered Users never own or edit Instruments, Daily Bars, Snapshots, Signals or Ingestion Runs; those are system/Admin-owned.
- **Experimental forecasts:** `POST /api/instruments/{ticker}/forecast` requires `auth:sanctum`, validates the horizon and minimum probability, and limits requests per account. Forecast records are shared by Instrument/date/model/question rather than owned by a requesting user; they contain no account identity. `GET /api/admin/forecasts/report` additionally requires the Admin role. The chart remains public; the forecast panel is not shown to Visitors (2026-10-15, `premium-panels-auth-only`).
- **AI Company Cards:** `GET`/`POST /api/instruments/{ticker}/company-card` require `auth:sanctum` (a Visitor gets `401`; the panel and the Candidate-row "Ficha IA" link are not shown to Visitors). Cards are shared rows per Instrument/language/schema version with no account identity; any Registered User reads and may (re)generate them within the per-user and global daily caps. The generation log `ai_company_card_generations` keeps the requesting `user_id` (set to `null` when the account is deleted) for caps and abuse prevention, with no prompt or response text, and is pruned after 90 days. Admins have no special path and no UI for it yet.
- **Natural-language Screener:** `POST /api/screener/interpret` requires `auth:sanctum` (a Visitor gets `401` and sees a sign-in link instead of the input; the Screener itself stays anonymous). It never accepts a `user_id`; caps are per session user (30/24 h) and global (600/24 h). The log `ai_screener_queries` keeps the `user_id` (set to `null` on account deletion; exported as `ai_screener_requests`) and metadata only, never the query text, and is pruned after 90 days.
- Admin actions are global (affect all users' Candidates on the next run).

- **Alerts and notifications (implemented by `alerts-engine`):** `alerts` rows are owned by `user_id` (not fillable; created through `$request->user()->alerts()`), notifications by their notifiable user. `GET/POST /api/alerts`, `PATCH/DELETE /api/alerts/{id}`, `GET /api/notifications`, `POST /api/notifications/read` are `auth:sanctum` and always scoped to the caller: a foreign alert id is a `404`, foreign notification ids are ignored. Admins have no access to other users' alerts or notifications.

## Access Rules

- Browsing requires no session.
- Saving a Screener, editing a Watchlist, or running ingestion requires authentication.
- **Watchlist enforcement (implemented):** `GET/POST /api/watchlist*` sit in the `auth:sanctum` group, so a guest receives **401**. A Visitor opening `/portal` is redirected to `/login` by the component-level `RequireAuth`; the chart page's follow toggle instead offers a sign-in **link** and never redirects (the chart stays browseable). The redirect/link is a UI affordance — the API is the enforcement point.
- **Saved Screeners enforcement (implemented):** `GET/POST/DELETE /api/screeners*` sit in the `auth:sanctum` group, so a guest receives **401**. The panel on `/screener` offers a Visitor a sign-in **link** (never a redirect) because the Screener stays anonymous; a signed-in user only ever sees their own Screeners because ownership is enforced at the query. The link is a UI affordance — the API is the enforcement point.
- Only Admin-role accounts see the Admin panel; hiding it in the UI is not sufficient, the API must enforce the role.
- **Enforcement mechanism (implemented):** admin API routes are grouped under `Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')` in `routes/api.php`. `auth:sanctum` rejects a guest with **401**; the `admin` middleware alias (`App\Http\Middleware\EnsureUserIsAdmin`) then rejects an authenticated non-admin with **403**. An Admin passes.
- **Role granting:** registration and every request can only ever produce `role=user`. The role is not mass-assignable and no endpoint accepts it. Admin is granted out of band with `php artisan app:make-admin {email}` (promotes an existing account; unknown email fails) or `UserFactory::admin()` in tests.
- **Market Context (implemented, `market-context-ui` + `market-context-patterns`; owner decision 2026-10-23: "solo accesible a usuarios registrados"):** every Market Context surface is for Registered Users only, enforced server-side by one ability, `view-market-context` = Registered User AND (`MARKET_CONTEXT_PUBLIC` on OR Admin). Every Market Context endpoint sits in `auth:sanctum` behind `can:view-market-context`: a guest gets **401**, a denied user **404** `market_context.not_available`. The public `GET /api/screener` answers its sector filters with **401** for a guest and **403** for a denied user, after value validation; without those keys it stays anonymous. Saving sector filters needs the ability (403), the NL Screener nulls them for a denied user, and a Saved Screener alert using them is skipped while its owner is denied. The SPA reads only `user.capabilities.market_context` (from the same ability) through `useMarketContextAccess`; hiding UI is not access control. Owner decision 2026-10-23: the flag stays off until the legal notice with the FRED terms clause is published, so today only Admins see it (preview). The Admin endpoints (`/api/admin/market-context*`) are in the admin group (401/403).
- **Entitlement seam (paid plans later, out of scope):** the owner plans to make some features paid. The `view-market-context` ability in `AppServiceProvider` is the single place a future plan would change (for example `|| $user->hasPlan('pro')`, denying others with a 403); no plan field, billing or upgrade UI exists.
- Registration is email-based; no paid plan gating in the MVP.
- **Email verification (implemented, `auth-email-verification`; dormant until outbound email works in production):** one predicate, `EmailVerification::pending()` (`app/Support/EmailVerification.php`). Every account-only route except `GET /api/user`, `POST /api/logout`, `/api/account*` and the resend sits in the `verified` group (JSON 403 `email_verification.required`, never a redirect); the `view-market-context` ability also denies a pending account (public Screener sector filters, Saved Screener alerts, capability) and the daily alert email skips it. The SPA gate (`AppLayout`) shows only the "Confirma tu correo" notice except on the legal pages, `/portal/cuenta` and `/verificar-correo/*`; it is an affordance, the API enforces the rule.

## Revocation / Expiry

- Sessions expire after a configured idle/absolute timeout and can be revoked by the user logging out.
- **Admin session revocation (implemented, `admin-users-sessions`):** an Admin can end any user's session (`DELETE /api/admin/sessions/{ref}`) or all of a user's sessions (`DELETE /api/admin/users/{id}/sessions`). The Admin's own current session is never ended by these actions (single: `422`; all: kept). Every revocation is recorded in `login_events` with the acting Admin. Admins can also read the user list, active sessions and sign-in activity (kept 90 days); they cannot change roles, suspend or delete accounts through the app.
- Deactivating or deleting an account revokes access to owned Saved Screeners and Watchlists (cascade delete acceptable in the MVP).
- **Account self-service (implemented, `account-self-service`):** a Registered User changes their name (`PATCH /api/account`) and password (`PUT /api/account/password`, which ends every other session of the account and keeps the current one), downloads their data (`GET /api/account/export`) and deletes the account (`DELETE /api/account` with the password and the typed word `DELETE`/`ELIMINAR`) from `/portal/cuenta`. Deletion is immediate and permanent: sessions, Watchlist, Saved Screeners, Alerts and notifications go; the shared sign-in and AI-card logs keep their rows without the user id; an `account_deleted` activity row keeps only the email until the 90-day prune. Admins cannot self-delete (`422 account.admin_cannot_delete`). Email verification is implemented but dormant until outbound email works in production (`auth-email-verification`); email change and password reset still wait for it.
- Admin role is granted out of band (seed/manual), not self-service in the MVP.

## Edge Cases

- A Visitor tries a protected action: redirect to registration/sign-in, preserve the attempted action when feasible.
- A user attempts to read another user's Saved Screener by id: must be denied (ownership check on every request).
- The last Admin account must not be deletable/demotable through the UI in a way that locks the system (manual recovery acceptable, but documented).
- Anonymous users may hit rate limits on Screener queries; the MVP should keep at least a basic throttle.
