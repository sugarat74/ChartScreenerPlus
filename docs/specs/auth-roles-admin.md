# Feature Implementation Spec: Enforce admin role server-side

## Source Feature

- `id`: `auth-roles-admin`
- `area`: `auth`
- `depends_on`: `auth-registration-login`
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Give accounts a role and enforce an admin-only boundary on the server: non-admin users must be rejected on admin endpoints regardless of the UI, and admin access is granted out of band (never self-service). This introduces the role model, the `admin` middleware, an admin route group and the out-of-band grant path.

## Non-Goals

- No admin panel UI or ingestion endpoints (those are `admin-ingestion-panel`).
- No multi-role/RBAC beyond `user` and `admin`.
- No permissions matrix, teams, ownership or revocation (later features).
- No self-service role selection at registration or in any profile screen.
- No change to SPA screens beyond what the existing shell already does.

## Job Story

When admin-only functionality is added later,
I want a server-side admin boundary and a safe way to grant admin,
so that ordinary users cannot reach admin capabilities even if they discover the URL.

## Users And Permissions

- Visitor / Registered User (`role=user`): may not access admin endpoints; gets 401 (guest) or 403 (authenticated non-admin).
- Admin (`role=admin`): may access admin endpoints. Admin is granted out of band (command/factory), never through the app.

## Acceptance Scenarios

### Scenario 1: Guest is rejected

Given an unauthenticated request,
When it calls an admin endpoint,
Then it is rejected with 401.

### Scenario 2: Non-admin is forbidden

Given an authenticated user with `role=user`,
When it calls an admin endpoint,
Then it is rejected with 403.

### Scenario 3: Admin is allowed

Given an authenticated user with `role=admin`,
When it calls an admin endpoint,
Then it succeeds (200).

### Scenario 4: Role cannot be self-assigned

Given the registration endpoint,
When a visitor submits a payload that includes a `role` (e.g. `role=admin`),
Then the created account still has `role=user` (the field is ignored and never mass-assignable).

### Scenario 5: Out-of-band grant

Given an existing non-admin account,
When an operator runs the admin-grant command for that email,
Then the account's role becomes `admin`.

## Repository Research

### Files Inspected

- `routes/api.php` — auth routes; `auth:sanctum` group already used.
- `bootstrap/app.php` — `withMiddleware(...)` with `statefulApi()`; the place to register a middleware alias.
- `app/Models/User.php` — `#[Fillable(['name','email','password'])]`, `casts()`; a role helper belongs here.
- `database/migrations/0001_01_01_000000_create_users_table.php` — no role column.
- `database/factories/UserFactory.php` — factory states pattern (`unverified()`).
- `app/Http/Controllers/Auth/AuthController.php` — registration mass-assigns only name/email/password.
- `tests/Feature/AuthTest.php` — `RefreshDatabase`, Sanctum/session test patterns.
- `docs/user-and-access-model.md` — roles `visitor`/`registered`/`admin`; admin granted out of band.
- `ARCHITECTURE.md`, `CONSTRAINTS.md` — auth and middleware conventions.

### Environment Findings (probed, not assumed)

- Laravel 13.34.0; Sanctum first-party SPA auth in place; `auth:sanctum` used on logout/user.
- No role column, no admin middleware, no admin routes, no console commands directory yet (`routes/console.php` exists).
- `bootstrap/app.php` already has a `withMiddleware` closure.

### Existing Patterns To Follow

- Laravel 13 idioms: anonymous-class migrations, `#[Fillable]` attributes, factory states.
- Middleware aliases are registered in `bootstrap/app.php`'s `withMiddleware`.
- Tests use `RefreshDatabase` and SQLite `:memory:`.

### Current Gaps

- No role storage or helper.
- No admin middleware/route group.
- No out-of-band grant path.

## Technical Approach

1. **Role storage.** Add `role` to `users` via a new migration: `string('role')->default('user')->index()`. Keep `role` **out of** `#[Fillable]` so it cannot be mass-assigned; the DB default handles new users.
2. **Model helper.** Add `User::isAdmin(): bool` (`$this->role === 'admin'`). Optionally a `UserRole` enum or constants (`user`, `admin`) to avoid magic strings.
3. **Middleware.** `app/Http/Middleware/EnsureUserIsAdmin.php`: if the authenticated user is missing or not an admin, `abort(403)`. Register alias `admin` in `bootstrap/app.php` `withMiddleware` (`$middleware->alias(['admin' => EnsureUserIsAdmin::class]);`).
4. **Admin route group.** In `routes/api.php`, add a group `Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(...)` containing a minimal enforcement probe `GET /api/admin/ping` returning `{ok: true}`. Later admin features extend this group; note it is a guard probe, not product behavior.
5. **Out-of-band grant.** Add an artisan command `app:make-admin {email}` (promotes an existing user, errors if not found) and a `UserFactory::admin()` state for tests. Admin must never be set through requests.
6. **Do not** expose role in the register/login/user responses beyond what already exists (the user payload already returns attributes; ensure `role` is acceptable to return, but keep passwords hidden).

## Expected File Changes

- `database/migrations/*_add_role_to_users_table.php` — create.
- `app/Models/User.php` — modify; `isAdmin()` helper (and/or role constants).
- `app/Http/Middleware/EnsureUserIsAdmin.php` — create.
- `bootstrap/app.php` — modify; register the `admin` alias.
- `routes/api.php` — modify; admin route group + `GET /api/admin/ping`.
- `app/Console/Commands/MakeAdmin.php` (or `routes/console.php`) — create; `app:make-admin` command.
- `database/factories/UserFactory.php` — modify; `admin()` state.
- `tests/Feature/AdminAccessTest.php` — create; guest 401, non-admin 403, admin 200, role-not-mass-assignable, command promotes.
- `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/user-and-access-model.md` — update.
- `PROGRESS.md`, `feature_list.json` — update with evidence.

## Visual Design Impact

- UI involved: no. `DESIGN.md` is not applicable to this slice.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — record the role model and the `admin` middleware/route-group boundary.
- `CONSTRAINTS.md`: update — MUST rules: `role` is never mass-assignable; admin is enforced by server-side middleware; admin is granted out of band only.
- `docs/user-and-access-model.md`: update — note the concrete storage (`users.role`) and the enforcement mechanism.
- `AGENTS.md`: not needed — no workflow/startup change.
- Other docs: `PROGRESS.md` and `feature_list.json` — update with evidence.

## Key Implementation Risks

- **Privilege escalation via mass assignment** — the highest risk. `role` must not be in `#[Fillable]` and registration must keep ignoring it; add an explicit test.
- **Missing auth before admin check** — apply both `auth:sanctum` and `admin` so guests get 401 and authenticated non-admins get 403; document the ordering.
- **Inventing product endpoints** — `/api/admin/ping` is deliberately a guard probe; later admin features replace/expand it. Do not build real admin behavior here.
- **Grant path safety** — the command must only promote existing users and never be reachable from HTTP.

## Implementation Plan

1. Add the `role` migration; keep it out of `#[Fillable]`.
2. Add `User::isAdmin()` (and role constants/enum).
3. Create `EnsureUserIsAdmin` and register the `admin` alias.
4. Add the admin route group with `GET /api/admin/ping`.
5. Add the `app:make-admin` command and the `UserFactory::admin()` state.
6. Add `tests/Feature/AdminAccessTest.php`.
7. Verify: `php artisan test`, `php artisan route:list`, `.\init.ps1`.
8. Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/user-and-access-model.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [x] Create the `add_role_to_users` migration (`default('user')`, indexed) and run it.
- [x] Add `User::isAdmin()` and role constants/enum; confirm `role` stays out of `#[Fillable]`.
- [x] Create `EnsureUserIsAdmin` and register the `admin` alias in `bootstrap/app.php`.
- [x] Add the `/api/admin` route group with `GET /api/admin/ping` under `auth:sanctum` + `admin`.
- [x] Add the `app:make-admin {email}` command and `UserFactory::admin()`.
- [x] Add `tests/Feature/AdminAccessTest.php` (guest 401, non-admin 403, admin 200, role ignored on register, command promotes).
- [x] Run `php artisan test`, `php artisan route:list`, and `.\init.ps1` (exit 0, no server).
- [x] Update the durable docs and harness state.

## Verification Plan

- `php artisan migrate` (and `migrate:rollback`) → the role column is added/removed cleanly.
- `php artisan test` → `AdminAccessTest` passes: guest 401, non-admin 403, admin 200, registration ignores `role`, command promotes an existing account.
- `php artisan route:list --path=api` → `GET api/admin/ping` shows the `auth:sanctum` + `admin` middleware.
- `.\init.ps1` → Laravel + SPA + engine checks, exit 0, no server started.
- Persistent E2E: none exists and there is no user flow beyond the API; feature tests are the right level. Record the gap.
- Startup script rule: `init.ps1` stays non-blocking and starts no server.

## Implementation Findings

- The DB `default('user')` is applied on INSERT but is **not** hydrated back into the in-memory Eloquent model, so a freshly created instance (registration response, factory model) reported `role: null` while the row held `user`. Fixed by declaring a model-level default `protected $attributes = ['role' => User::ROLE_USER]` (mirrors the DB default; does not affect mass-assignment). Without it the register response would echo `role: null` and then differ from a later `GET /api/user` read.
- The command must promote via `forceFill(['role' => ...])` (or direct assignment) because `role` is intentionally not fillable; plain `fill()`/`update()` would silently ignore it.
- SQLite refuses `dropColumn` while an index references the column, so the migration's `down()` drops `users_role_index` before `role`.
- `app/Console/Commands` is auto-discovered by Laravel 13, so no `withCommands` registration was needed; `php artisan list` shows `app:make-admin`.
- Registration already strips unknown keys during validation and `role` is not fillable, so the payload `role=admin` is ignored twice over.

## Evidence To Capture

- `php artisan test` output (AdminAccessTest + suite green, exit 0).
- `php artisan route:list --path=api` showing `api/admin/ping` with the expected middleware.
- Migration up/rollback output.
- `.\init.ps1` output (exit 0).
- Confirmation `engine/`, `alphapulse/`, the SPA and the market-data schema were not modified.

## Validator Checklist

- [x] Implementation stays within this feature's scope (no admin UI/ingestion, no multi-role RBAC).
- [x] Acceptance scenarios pass.
- [x] Verification evidence is present.
- [x] Persistent E2E coverage was added/updated when the feature has an observable user/API flow and an E2E harness exists, or the spec explains why it is not needed.
- [x] `feature_list.json` and `PROGRESS.md` were updated correctly.
- [x] No unrelated product behavior or extra feature work was added.
- [x] `role` is not mass-assignable and registration cannot set it (tested).
- [x] Admin is enforced server-side via middleware; guests 401, non-admins 403, admins 200.
- [x] Admin grant is out of band only (command/factory), never via HTTP.
