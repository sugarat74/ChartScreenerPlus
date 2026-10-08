# Feature Implementation Spec: Admin overview of users, sessions and sign-in activity

## Source Feature

- `id`: `admin-users-sessions`
- `area`: `admin`
- `depends_on`: `auth-roles-admin`, `admin-ingestion-panel`, `access-control-guard`, `app-multilanguage` (all `accepted`)
- `source`: `feature_list.json`

## Goal

When an Admin signs in, the Admin area offers, next to ingestion, a supervision view of accounts:
who is registered, who is active, which sessions are open and how sign-ins behave, plus one safe
action: ending sessions. Retention of sign-in data is **90 days** (operator decision 2026-10-07).

## Non-Goals

Role changes, account suspension/deletion, impersonation, real-time presence/websockets, page-view
analytics and exports. Role granting stays out of band (`app:make-admin`), as
`docs/user-and-access-model.md` requires.

## Backend

- `login_events` table (`App\Models\LoginEvent`): `event` (`login`, `failed`, `logout`,
  `session_revoked`), `user_id` (nullable, null on delete), `actor_id` (Admin who revoked),
  `email` (lower-cased; never a password), `ip_address`, `user_agent`, `sessions_revoked`,
  `created_at`. `MassPrunable` removes rows older than `config('admin.activity_retention_days')`
  (default 90, env `ADMIN_ACTIVITY_RETENTION_DAYS`); `model:prune` is scheduled daily at 03:15
  (`prune-login-events`).
- `App\Services\Admin\LoginEventRecorder` listens to Laravel's `Login`, `Failed` and `Logout`
  events on the `web` guard (registered in `AppServiceProvider`), so the auth controller is unchanged.
- `App\Services\Admin\SessionDirectory` reads the `database` session store. Session ids never leave
  the server: the API exposes `ref = substr(HMAC-SHA256(session_id, APP_KEY), 0, 40)`. "Active" means
  authenticated and `last_activity` within `SESSION_LIFETIME`.
- Endpoints (all in the `auth:sanctum` + `admin` group):

  | Method | Path | Purpose |
  |---|---|---|
  | GET | `/api/admin/users/summary` | totals, admins, new 7/30 d, active 24 h, active sessions, failed sign-ins 24 h, retention days |
  | GET | `/api/admin/users?search=&page=&per_page=` | case-insensitive name/email search, newest first, `per_page` default 25 / max 100 |
  | GET | `/api/admin/users/{id}` | user + active sessions + latest 50 events |
  | DELETE | `/api/admin/users/{id}/sessions` | end all sessions of the user except the caller's current one |
  | GET | `/api/admin/sessions?page=` | active sessions with user, IP, device, `is_current` |
  | DELETE | `/api/admin/sessions/{ref}` | end one session; `422` for the caller's current session, `404` unknown |
  | GET | `/api/admin/activity?event=&user_id=&page=` | sign-in log, newest first; invalid `event` is `422` |

  Search lower-cases the input and treats `%`/`_` literally (`LIKE … ESCAPE '!'`); case-insensitive
  matching of non-ASCII letters works on PostgreSQL (production) but SQLite's `LOWER` is ASCII-only,
  so locally `PÉREZ` does not match `pérez`. "Last activity" is the newest of the latest session
  activity and the latest sign-in (session rows vanish on sign-out/revocation/expiry);
  "active in 24 h" counts users with session activity in the last 24 h.
  Recording never breaks authentication (a failed write is `report()`ed), and `session_revoked`
  rows store no IP/user agent because the request belongs to the acting Admin.
  Lists return `{data, meta: {current_page, last_page, per_page, total}}`. User rows carry last
  sign-in, last activity and counts via subqueries (no N+1). Responses never include password
  hashes, remember tokens, session ids or payloads. Revocations are logged as `session_revoked`
  with the acting Admin and count.
- `CopySqliteToPgsql` lists `login_events` as skipped (it postdates the one-time cut-over).

## SPA

- `/admin` becomes a layout route (`pages/admin/AdminLayout.tsx`) with one guard and section tabs:
  Ingestion (`/admin`), Users (`/admin/users`, `/admin/users/:userId`), Sessions (`/admin/sessions`),
  Activity (`/admin/activity`). The header Admin tab stays active on nested routes; page metadata
  uses the Admin title for every `/admin/*` path (noindex already covered by nginx and
  `RouteMetadata`).
- Search, page and event filter live in the URL. Ending sessions asks for confirmation; the current
  session shows "This session" instead of a button. All copy is in the `adminUsers` catalog section
  (es/en).

## Verification

- `php artisan test --filter=AdminUsersSessionsTest`: 401/403 on every endpoint, activity recording
  without passwords, search/pagination/secret-free payloads, summary counts, active-session listing
  without ids, revocation (one, all, audited, current protected via a real database-session sign-in),
  user detail, activity filters/validation, 90-day prune, schedule entry, user-agent labels.
- `npm --prefix frontend run test`: Admin screens (restricted state for non-admins with no admin
  calls, current-session protection and confirmed revocation, declined confirmation, summary and
  URL-backed search, section tabs).
- `.\init.ps1`.

## Privacy

Draft processing record: `docs/legal/tratamiento-actividad-de-acceso.md` (owner data pending; no
public privacy page exists yet — tracked in `docs/risks-and-open-questions.md`).
