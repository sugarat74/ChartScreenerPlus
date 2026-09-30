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
| Save / apply Saved Screeners | no | yes (own) | yes |
| Maintain Watchlist | no | yes (own) | yes |
| Trigger / re-run Ingestion Runs | no | no | yes |
| Manage Instrument universe | no | no | yes |
| View ingestion logs and telemetry | no | no | yes |

## Ownership Boundaries

- Saved Screeners and Watchlist Entries are private: a Registered User only sees and modifies their own.
- **Watchlist storage and endpoints (implemented):** a watchlist is one implicit list per user, stored in the `watchlist_items` pivot (`user_id`, `instrument_id`, unique `(user_id, instrument_id)`, cascade FKs). There is no `watchlists` table; ownership is `user_id`. The endpoints are `GET /api/watchlist` (list), `POST /api/watchlist {ticker}` (follow, idempotent) and `DELETE /api/watchlist/{ticker}` (unfollow). All three are `auth:sanctum` and every query is scoped server-side to `$request->user()->watchlist()`; no endpoint accepts a `user_id`, so a cross-user read is an empty list and a cross-user delete is a `404`.
- **Saved Screener storage and endpoints (implemented):** a Saved Screener is a row in the `saved_screeners` table (`user_id`, `name`, `filters` json, unique `(user_id, name)`, cascade FK to `users`); ownership is `user_id` and rows are created through `$request->user()->savedScreeners()`. The endpoints are `GET /api/screeners` (list), `POST /api/screeners {name, filters}` (save) and `DELETE /api/screeners/{screener}` (delete). All three are `auth:sanctum` and every query is scoped server-side to `$request->user()->savedScreeners()`; no endpoint accepts a `user_id`, so a cross-user read is an empty list and a cross-user delete is a `404`. `name` is unique per user; `filters` is the canonical seven-key screener definition (including `sort`).
- Registered Users never own or edit Instruments, Daily Bars, Snapshots, Signals or Ingestion Runs; those are system/Admin-owned.
- Admin actions are global (affect all users' Candidates on the next run).

## Access Rules

- Browsing requires no session.
- Saving a Screener, editing a Watchlist, or running ingestion requires authentication.
- **Watchlist enforcement (implemented):** `GET/POST /api/watchlist*` sit in the `auth:sanctum` group, so a guest receives **401**. A Visitor opening `/portal` is redirected to `/login` by the component-level `RequireAuth`; the chart page's follow toggle instead offers a sign-in **link** and never redirects (the chart stays browseable). The redirect/link is a UI affordance — the API is the enforcement point.
- **Saved Screeners enforcement (implemented):** `GET/POST/DELETE /api/screeners*` sit in the `auth:sanctum` group, so a guest receives **401**. The panel on `/screener` offers a Visitor a sign-in **link** (never a redirect) because the Screener stays anonymous; a signed-in user only ever sees their own Screeners because ownership is enforced at the query. The link is a UI affordance — the API is the enforcement point.
- Only Admin-role accounts see the Admin panel; hiding it in the UI is not sufficient, the API must enforce the role.
- **Enforcement mechanism (implemented):** admin API routes are grouped under `Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')` in `routes/api.php`. `auth:sanctum` rejects a guest with **401**; the `admin` middleware alias (`App\Http\Middleware\EnsureUserIsAdmin`) then rejects an authenticated non-admin with **403**. An Admin passes.
- **Role granting:** registration and every request can only ever produce `role=user`. The role is not mass-assignable and no endpoint accepts it. Admin is granted out of band with `php artisan app:make-admin {email}` (promotes an existing account; unknown email fails) or `UserFactory::admin()` in tests.
- Registration is email-based; no paid plan gating in the MVP.

## Revocation / Expiry

- Sessions expire after a configured idle/absolute timeout and can be revoked by the user logging out.
- Deactivating or deleting an account revokes access to owned Saved Screeners and Watchlists (cascade delete acceptable in the MVP).
- Admin role is granted out of band (seed/manual), not self-service in the MVP.

## Edge Cases

- A Visitor tries a protected action: redirect to registration/sign-in, preserve the attempted action when feasible.
- A user attempts to read another user's Saved Screener by id: must be denied (ownership check on every request).
- The last Admin account must not be deletable/demotable through the UI in a way that locks the system (manual recovery acceptable, but documented).
- Anonymous users may hit rate limits on Screener queries; the MVP should keep at least a basic throttle.
