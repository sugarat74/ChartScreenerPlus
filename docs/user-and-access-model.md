# User and Access Model

## User Types

- **Visitor (anonymous):** can browse the Screener, view Candidate lists and charts.
- **Registered User:** an authenticated Visitor who can additionally save Screeners and maintain a Watchlist.
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
| Open `/mercado` and `GET /api/market-context` (Market Context) | yes once `MARKET_CONTEXT_PUBLIC=true` (404 before) | yes once public (404 before) | yes (preview with a banner while not public) |
| Market Context history charts (`/api/market-context/history`) | no (sign-in prompt; API 401) | yes once public | yes |
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

## Ownership Boundaries

- Saved Screeners and Watchlist Entries are private: a Registered User only sees and modifies their own.
- **Watchlist storage and endpoints (implemented):** a watchlist is one implicit list per user, stored in the `watchlist_items` pivot (`user_id`, `instrument_id`, unique `(user_id, instrument_id)`, cascade FKs). There is no `watchlists` table; ownership is `user_id`. The endpoints are `GET /api/watchlist` (list), `POST /api/watchlist {ticker}` (follow, idempotent) and `DELETE /api/watchlist/{ticker}` (unfollow). All three are `auth:sanctum` and every query is scoped server-side to `$request->user()->watchlist()`; no endpoint accepts a `user_id`, so a cross-user read is an empty list and a cross-user delete is a `404`.
- **Saved Screener storage and endpoints (implemented):** a Saved Screener is a row in the `saved_screeners` table (`user_id`, `name`, `filters` json, unique `(user_id, name)`, cascade FK to `users`); ownership is `user_id` and rows are created through `$request->user()->savedScreeners()`. The endpoints are `GET /api/screeners` (list), `POST /api/screeners {name, filters}` (save) and `DELETE /api/screeners/{screener}` (delete). All three are `auth:sanctum` and every query is scoped server-side to `$request->user()->savedScreeners()`; no endpoint accepts a `user_id`, so a cross-user read is an empty list and a cross-user delete is a `404`. `name` is unique per user; `filters` is the canonical seven-key screener definition (including `sort`).
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
- **Market Context (implemented, `market-context-ui`):** the history endpoint sits in the `auth:sanctum` group (guest **401**); the page, the payload and the history also pass the release gate `market-context.public`, which answers **404** to everyone but Admins while `MARKET_CONTEXT_PUBLIC` is off. The gate is a go-live switch, not authorization. Owner decision 2026-10-23: it stays off until the legal notice with the FRED terms clause is published. The Admin endpoints (`/api/admin/market-context*`) are in the admin group (401/403).
- Registration is email-based; no paid plan gating in the MVP.

## Revocation / Expiry

- Sessions expire after a configured idle/absolute timeout and can be revoked by the user logging out.
- **Admin session revocation (implemented, `admin-users-sessions`):** an Admin can end any user's session (`DELETE /api/admin/sessions/{ref}`) or all of a user's sessions (`DELETE /api/admin/users/{id}/sessions`). The Admin's own current session is never ended by these actions (single: `422`; all: kept). Every revocation is recorded in `login_events` with the acting Admin. Admins can also read the user list, active sessions and sign-in activity (kept 90 days); they cannot change roles, suspend or delete accounts through the app.
- Deactivating or deleting an account revokes access to owned Saved Screeners and Watchlists (cascade delete acceptable in the MVP).
- **Account self-service (implemented, `account-self-service`):** a Registered User changes their name (`PATCH /api/account`) and password (`PUT /api/account/password`, which ends every other session of the account and keeps the current one), downloads their data (`GET /api/account/export`) and deletes the account (`DELETE /api/account` with the password and the typed word `DELETE`/`ELIMINAR`) from `/portal/cuenta`. Deletion is immediate and permanent: sessions, Watchlist, Saved Screeners, Alerts and notifications go; the shared sign-in and AI-card logs keep their rows without the user id; an `account_deleted` activity row keeps only the email until the 90-day prune. Admins cannot self-delete (`422 account.admin_cannot_delete`). Email change, password reset and email verification wait for an outbound email provider.
- Admin role is granted out of band (seed/manual), not self-service in the MVP.

## Edge Cases

- A Visitor tries a protected action: redirect to registration/sign-in, preserve the attempted action when feasible.
- A user attempts to read another user's Saved Screener by id: must be denied (ownership check on every request).
- The last Admin account must not be deletable/demotable through the UI in a way that locks the system (manual recovery acceptable, but documented).
- Anonymous users may hit rate limits on Screener queries; the MVP should keep at least a basic throttle.
