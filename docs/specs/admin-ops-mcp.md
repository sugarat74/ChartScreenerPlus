# Feature Implementation Spec: Admin operations MCP server for Chartiko

## Source Feature

- `id`: `admin-ops-mcp`
- `area`: `admin`
- `depends_on`: `admin-ingestion-panel`, `admin-users-sessions`, `auth-roles-admin` (all `accepted`)
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Let an Admin, or an MCP client such as Claude Code acting for an Admin, operate Chartiko's backend through a small set of authorized, audited MCP tools instead of a root SSH session. The server is part of the Laravel app, is served over the existing HTTPS domain, and reuses the Admin API's queries, validation and side effects. Read tools report ingestion, users, sessions, activity and health. Three side-effecting tools (trigger a run, retry a run, end a user's sessions) need a separate token ability.

Success is operational: once this ships, routine checks and actions no longer need a shell. That is the precondition for retiring the temporary root SSH key and disabling `PasswordAuthentication` (an operator task recorded in docs, not performed by this feature).

## Non-Goals

- No shell, SQL, filesystem, log-file, `.env` or arbitrary artisan access. No configuration, deployment, role or account changes (role granting stays `app:make-admin`).
- No product-facing or Copilot MCP (screener/instrument tools for end users). The AI Copilot is out of scope.
- No OAuth authorization server (Passport). Personal access tokens only (Decision 2).
- No new Admin REST capabilities: tools mirror existing endpoints, plus the read-only `health` tool.
- No MCP resources, prompts, elicitation or streaming notifications. Tools only.
- No retirement of the root key or sshd changes. That is a documented operator follow-up.

## Job Story

When I need to check whether last night's pipeline ran, why instruments failed, who is signed in, or need to retry failures or end a compromised account's sessions,
I want to ask my MCP client and have it call authorized Chartiko tools,
so I can operate production without a root shell and every action is attributable and auditable.

## Users And Permissions

- **Admin with an MCP token**: read tools with ability `mcp:read`; write tools need `mcp:read` and `mcp:write`. The token's owner must still be an Admin on every request (the `admin` middleware runs per call), so demoting the owner disables the token immediately.
- **Admin in the SPA**: creates, lists and revokes their own MCP tokens and sees the MCP audit log in a new Admin page.
- **Registered User**: `403` on every MCP and token endpoint, even with a token they somehow obtained.
- **Visitor/no token**: `401`.
- A browser session cookie is **not** accepted on the MCP endpoint. Only a bearer token is, so an Admin's logged-in browser cannot be driven cross-site.

## Domain Decisions (explicit)

1. **Library.** `laravel/mcp` (verified on Packagist: v1.0.2 requires `illuminate/* ^13.0`, PHP ^8.2) over Streamable HTTP. Pin `^1.0` and confirm the class/attribute names below against the installed version before coding.
2. **Credential.** Sanctum personal access tokens (`personal_access_tokens` already migrated; add `HasApiTokens` to `User`). Every token has: a name, abilities from `{mcp:read, mcp:write}` (`mcp:read` always included), and a **mandatory expiry**, 30 days by default and at most 90 (`config('admin.mcp_token_max_days')`). The plaintext is shown once and stored hashed by Sanctum, and `last_used_at` is kept. Limit: 5 active tokens per Admin.
3. **Endpoint path.** `POST /api/mcp/admin`. Nginx routes only `^/(api|sanctum)/` to PHP (`deploy/nginx.conf`), so a path under `/api/` needs no Nginx change. The route group is `['mcp.token', 'auth:sanctum', 'admin', 'throttle:mcp']`. `mcp.token` (new middleware) rejects requests without `Authorization: Bearer …` (401) and requests authenticated by session cookie, so `currentAccessToken()` must be a `PersonalAccessToken`.
4. **Abilities enforcement.** A server-side check per tool, not only in the tool list: a write tool called with a read-only token returns a tool error `ability_required` and is audited as `denied`. Write tools are still listed so the client can see what exists, and their descriptions state the required ability.
5. **One implementation, two adapters.** Query, validation and side-effect logic moves from the Admin controllers into services that both the REST controllers and the MCP tools call:
   - `App\Services\Admin\IngestionRunAdmin` (`list`, `show`, `trigger`, `retry`, payload shaping, `ValidationException` semantics),
   - `App\Services\Admin\UserOverview` (`summary`, `search`, `detail`, `revokeSessions`),
   - the existing `SessionDirectory` and `LoginEventRecorder`, plus a static `ActivityController::presentEvent` moved to `App\Services\Admin\ActivityPresenter`.

   The REST responses must stay byte-identical. The existing Admin tests are the regression net.
6. **Error mapping.** `ValidationException` returns a tool error with `{code: "validation", errors: {field: [messages]}}`. A missing entity returns `{code: "not_found"}`. An ability failure returns `{code: "ability_required"}`. Any other `Throwable` returns `{code: "internal"}` with no message text, and is logged with the exception class only.
7. **Write-tool safety.** Each write tool requires `confirm: true` in its arguments (schema `const: true`) and carries the MCP annotations `destructiveHint`/`idempotentHint` as appropriate. `end_user_sessions` refuses `user_id` equal to the token owner (`validation`: use the SPA to manage your own sessions), because a token has no current session to protect.
8. **Rate limits.** Named limiter `mcp`, keyed by token id: 60 calls/min in total and 6 write calls/min. Exceeding a limit returns `429`, or a tool error `rate_limited` for the write sub-limit.
9. **Audit.** New table `mcp_audit_events` with columns `id, user_id, token_id, tool, outcome (ok|validation|not_found|ability_required|rate_limited|internal), arguments (json), duration_ms, ip_address, created_at`. `arguments` keeps only allowlisted scalar keys (`run_id`, `user_id`, `universe`, `page`, `per_page`, `event`, `search` length only, never the text). The model is `MassPrunable` after `config('admin.activity_retention_days')` (90) and is added to the existing `model:prune` schedule. Token creation and revocation are audited in the same table (`tool` = `token.created` / `token.revoked`). Bearer values and response bodies are never stored or logged.
10. **Output bounds.** Lists use the existing `per_page_default`/`per_page_max` (25/100). `run_log` pages run items. Tools return structured JSON content, and nothing exposes password hashes, remember tokens, session ids or payloads (only the existing opaque `ref`), token hashes, job payloads, exception traces or config values.
11. **Health inputs.** The `health` tool reports only what the app can read without privileges:
    - release id (basename of the resolved `base_path()`),
    - app env and debug flag (boolean only),
    - DB: driver, a `select 1` round-trip in ms, and whether the configured port is the PgBouncer port 6432,
    - queue: pending `jobs` count and the age of the oldest; `failed_jobs` count, newest `failed_at` and its job `displayName` only,
    - data freshness: latest ingestion run summary, latest `daily_bars.date`, latest `indicator_snapshots.date`, signal count on that date,
    - scheduler: last finished `ingestion-pipeline` time inferred from the newest run,
    - engine: reachability using the same probe as `deploy.sh` (`GET {engine.url}/docs`, 2 s timeout), or a dedicated health route if the engine already exposes one,
    - last backup.

    The backup directory is root-only (`0700`), so `deploy/backup-postgresql.sh` additionally writes a status file `shared/storage/app/ops/backup-status.json` (`database`, `finished_at`, `bytes`, `ok`; owner `deploy:www-data`, mode `0640`, no data). `health` reports its age, or `unknown` when it is absent.

## Tools

| Tool | Ability | Arguments | Source |
|---|---|---|---|
| `ingestion_status` | read | — | latest run + data freshness (subset of `health`) |
| `list_runs` | read | `limit` 1–100 (20) | `GET /api/admin/ingestion/runs` |
| `run_log` | read | `run_id`, `status?` (`failed`/…), `page`, `per_page` | `GET /api/admin/ingestion/runs/{run}` (items paged) |
| `users_summary` | read | — | `GET /api/admin/users/summary` |
| `list_users` | read | `search?`, `page`, `per_page` | `GET /api/admin/users` |
| `user_detail` | read | `user_id` | `GET /api/admin/users/{user}` |
| `active_sessions` | read | `page`, `per_page` | `GET /api/admin/sessions` |
| `activity_log` | read | `event?`, `user_id?`, `page`, `per_page` | `GET /api/admin/activity` |
| `health` | read | — | Decision 11 |
| `trigger_run` | write | `universe?` (default `config('ingestion.universe')`), `confirm` | `POST /api/admin/ingestion/runs` |
| `retry_run` | write | `run_id`, `confirm` | `POST /api/admin/ingestion/runs/{run}/retry` |
| `end_user_sessions` | write | `user_id`, `confirm` | `DELETE /api/admin/users/{user}/sessions` |

## Acceptance Scenarios

### Scenario 1: Authentication and authorization
Given no token, a Registered User's token, a session cookie only, an expired token and a revoked token,
When each calls `tools/list` on `/api/mcp/admin`,
Then the responses are `401`, `403`, `401`, `401` and `401`, and an Admin token with `mcp:read` gets the 12 tools.

### Scenario 2: Read tools match the REST API
Given seeded runs, users, sessions and login events,
When an Admin token calls each read tool,
Then the payloads equal the corresponding REST responses (same fields and ordering, paged), and none contains a password hash, session id, token hash or secret value.

### Scenario 3: Write tools need the write ability and confirmation
Given a read-only token, `trigger_run` returns `ability_required` and creates no run, and `confirm` missing or false is a schema error.
Given a read+write token and `confirm: true`, `trigger_run` creates a `queued` run and dispatches `RunIngestionJob` (asserted with `Queue::fake`).

### Scenario 4: Same validation as REST
An unknown universe returns `validation` on `universe`. Retrying an unfinished run, or one without failures, returns `validation` on `run`. Unknown run or user ids return `not_found`. `end_user_sessions` on the token owner returns `validation`. On another user it deletes their sessions, returns `revoked: n`, and writes the existing `session_revoked` login event with the Admin as actor.

### Scenario 5: Every call is audited without secrets
Given any tool call (ok, validation, not_found, ability_required, rate_limited),
Then one `mcp_audit_events` row records user, token, tool, outcome and allowlisted arguments, and no row, log line or response contains the bearer token. Rows older than the retention period are pruned by `model:prune`.

### Scenario 6: Token lifecycle in the Admin area
Given an Admin on `/admin/mcp`,
When they create a token (name, write on/off, expiry ≤ 90 days),
Then the plaintext is shown once with a copy button and a ready `claude mcp add` command, and the list shows name, abilities, created, last used and expiry. Revoking a token makes the next call `401`. A sixth active token or an expiry over 90 days returns `422`. Registered Users get `403` from the token API.

### Scenario 7: Rate limiting
61 calls in a minute on one token return `429` on the 61st. A 7th write call in a minute returns `rate_limited` and has no side effect.

### Scenario 8: Health
`health` returns every field in Decision 11. A missing backup status file reports `unknown` and an unreachable engine reports `reachable: false`; neither fails the tool.

### Scenario 9: Real client smoke
Claude Code connected with `claude mcp add --transport http chartiko-admin https://www.chartiko.com/api/mcp/admin --header "Authorization: Bearer <token>"` lists the tools and runs `health` and `list_runs`. A local smoke with MCP Inspector against `php artisan serve` is acceptable before production.

## Repository Research

### Files Inspected
- `routes/api.php`: Admin group `['auth:sanctum','admin']->prefix('admin')`, endpoints mirrored above, and the prefixed throttle convention (`throttle:max,decay,prefix`).
- `app/Http/Controllers/Admin/{IngestionRunController,UserController,SessionController,ActivityController}.php`: logic lives in the controllers today (payload shaping, validation, `ValidationException` messages); `Concerns/PaginatesAdminLists` handles page bounds.
- `app/Services/Admin/{SessionDirectory,LoginEventRecorder}.php`: opaque session `ref`, revocation and its activity record.
- `app/Http/Middleware/EnsureUserIsAdmin.php`, `bootstrap/app.php`: the `admin` alias.
- `app/Models/User.php`: no `HasApiTokens` yet. `app/Models/LoginEvent.php` is the `MassPrunable` pattern.
- `config/sanctum.php`: `expiration` null, so expiry is set per token. `config/admin.php`: retention 90 days, page sizes.
- `routes/console.php`: `model:prune` for `LoginEvent`/`AiCompanyCardGeneration` daily.
- `deploy/nginx.conf`: only `/api` and `/sanctum` reach PHP. `deploy/backup-postgresql.sh`: root-only backup directory, no status file.
- `frontend/src/router.tsx`, `frontend/src/pages/admin/*`: Admin sub-pages pattern (`AdminLayout`, users, sessions, activity).
- `composer.json`: no MCP library. `composer show -a laravel/mcp`: v1.0.2 supports Laravel 13.

### Existing Patterns To Follow
- Server-side authorization through route middleware, with the SPA guard as UI only. Ids are checked in code so a non-admin gets 403 before a 404.
- Prefixed named throttles, es/en message catalogs (`lang/{es,en}`), `MassPrunable` retention, and offline tests (`Http::fake`, `Queue::fake`).

### Current Gaps
- No token issuance, no MCP dependency, no shared Admin services, no audit table for API actions, no backup status readable by the app, no engine health probe in PHP.

## Technical Approach

1. `composer require laravel/mcp:^1.0`. Add `HasApiTokens` to `User`.
2. Extract the services in Decision 5 and refactor the four controllers to use them. Run the Admin test suite unchanged before adding anything else.
3. Migration and model `McpAuditEvent` (MassPrunable). Add it to the `model:prune` call.
4. `App\Mcp\Servers\AdminOpsServer` registers the 12 tools in `App\Mcp\Tools\Admin\*`. A small base class or trait handles the ability check, the `confirm` guard, error mapping (Decision 6) and auditing (Decision 9). Read tools get the read-only annotation; write tools get the destructive (`end_user_sessions`) or non-idempotent (`trigger_run`, `retry_run`) annotations.
5. `routes/ai.php` (or the file `laravel/mcp` publishes) registers `Mcp::web('/api/mcp/admin', AdminOpsServer::class)` with the middleware from Decision 3. Add the `mcp.token` middleware and the `mcp` rate limiter.
6. Token API inside the Admin group: `GET/POST /api/admin/mcp/tokens`, `DELETE /api/admin/mcp/tokens/{id}` (own tokens only, 404 for others). Its config lives in `config/admin.php`: `mcp_token_default_days` 30, `mcp_token_max_days` 90, `mcp_tokens_per_admin` 5.
7. SPA `AdminMcpPage` (`/admin/mcp`): token form, one-time secret panel, list with revoke, and the last 50 audit rows. es/en catalogs.
8. `HealthReport` service (Decision 11) and the backup status file write in `deploy/backup-postgresql.sh`.
9. Docs and runbook (below).

## Expected File Changes
- `composer.json`/`composer.lock`, `app/Models/User.php`, `config/admin.php`, `routes/api.php`, `routes/console.php`, `routes/ai.php` (create, or as published), `bootstrap/app.php` (middleware alias): modify.
- `app/Services/Admin/{IngestionRunAdmin,UserOverview,ActivityPresenter,HealthReport}.php`: create. The four Admin controllers: modify (thin).
- `app/Mcp/Servers/AdminOpsServer.php`, `app/Mcp/Tools/Admin/*.php`, `app/Http/Middleware/RequireMcpToken.php`, `app/Http/Controllers/Admin/McpTokenController.php`, `app/Models/McpAuditEvent.php`, migration: create.
- `frontend/src/pages/admin/AdminMcpPage.tsx` (+ test), `router.tsx`, `AdminLayout.tsx`, API client, `frontend/src/i18n/*`: modify/create.
- `deploy/backup-postgresql.sh`, `deploy/README.md`: modify. `docs/admin-ops-mcp.md`: create (connect, issue/revoke, tool list, root-key retirement checklist).
- Tests: `tests/Feature/Mcp/*` (auth, each tool, abilities, validation, audit, rate limit, health), `tests/Feature/Admin/McpTokenApiTest.php`; existing Admin tests unchanged.

## Visual Design Impact
- UI involved: yes, the new Admin sub-page `/admin/mcp` (form, one-time secret, table, audit list) at 1280 and 390 px.
- Design source: `DESIGN.md` and the existing Admin users/sessions pages. No new design artifact; reuse table, button and alert components.

## Durable Documentation Impact
- `ARCHITECTURE.md`: update. Add an "Admin Operations MCP" section covering the endpoint, middleware order, abilities, services shared with REST, audit and health sources.
- `CONSTRAINTS.md`: update. MCP is bearer-only, has no shell/SQL/config tools, tools must reuse Admin services, audit is required, expiry is mandatory, and secrets are never output.
- `AGENTS.md`: update. Add one scope line: an Admin operations MCP exists (not the AI Copilot).
- `docs/user-and-access-model.md`: update with tokens and abilities. `docs/risks-and-open-questions.md`: update with token theft, the root-key retirement follow-up and Nginx/`/api` exposure.
- `PROGRESS.md`, `feature_list.json`: status and evidence. Privacy policy: not needed for new data subjects (Admin-only operational logs), but run `web-legal-compliance-eu` to confirm that audit IPs fall under the existing activity-log retention wording.

## Implementation Plan
1. Baseline `.\init.ps1`.
2. Service extraction and controller refactor, then the existing Admin tests must pass.
3. Dependency, `HasApiTokens`, token API and tests.
4. Audit model, MCP server and middleware, read tools and tests.
5. Write tools, rate limits and tests.
6. Health and backup status file.
7. SPA page and Vitest tests, then Playwright/Edge QA.
8. Local Inspector or Claude Code smoke, docs, `.\init.ps1`, then the PR with CI on SQLite and PostgreSQL.
9. After deploy: create a production token, run the Claude Code smoke, and record evidence. Do not retire the root key in this feature.

## Implementation Tasks
- [ ] Extract `IngestionRunAdmin`, `UserOverview`, `ActivityPresenter` and thin the controllers; existing Admin tests green.
- [ ] `laravel/mcp`, `HasApiTokens`, `config/admin.php` MCP keys.
- [ ] `McpTokenController` + routes + tests (create/list/revoke, max 5, expiry ≤ 90, own tokens only, 403/401).
- [ ] `McpAuditEvent` + migration + prune registration.
- [ ] `RequireMcpToken` middleware, `mcp` limiter, route registration.
- [ ] `AdminOpsServer` + 9 read tools + 3 write tools with abilities, `confirm`, annotations and error mapping.
- [ ] `HealthReport` + backup status file in `backup-postgresql.sh`.
- [ ] `AdminMcpPage` + catalogs + tests.
- [ ] Docs listed above; `docs/admin-ops-mcp.md` runbook.

## Verification Plan
- `php artisan test`: new MCP and token suites plus all existing tests. CI runs SQLite and `test-pgsql` (through PgBouncer).
- Vitest + lint + build via `.\init.ps1` (exit 0; the script is not changed and starts no server).
- Tool-level tests use the `laravel/mcp` testing helpers (acting as a user with a token). Also include at least one real HTTP JSON-RPC test against `/api/mcp/admin` for the middleware chain (401/403/cookie rejection).
- Secret absence: assert that no response, audit row or captured log contains the plaintext token, `password`, a session id or `APP_KEY`.
- Browser QA (Playwright + Edge) of `/admin/mcp` at es/en, 1280/390 px: one-time secret, revoke, Visitor and Registered User blocked.
- Smoke with MCP Inspector or Claude Code locally, and Claude Code against production after deploy.

## Evidence To Capture
- Test counts per suite, CI run ids (SQLite + PostgreSQL), `.\init.ps1` output.
- Proof that the REST responses are unchanged after the refactor (existing tests green, plus a diff-free snapshot of one response per endpoint).
- Smoke transcript (tool names and `health` fields, no token), audit rows for it, and the 401 after revoke.

## Validator Checklist
- [ ] Bearer token only; 401/403 contract; owner demotion and revoke take effect immediately.
- [ ] Mandatory expiry ≤ 90 days, max 5 tokens, plaintext shown once, own tokens only.
- [ ] Write tools need `mcp:write` + `confirm: true`, have the same validation as REST, and do not let the owner end their own sessions.
- [ ] No shell/SQL/config/secret exposure; outputs bounded; no session ids or token hashes.
- [ ] Every call audited with allowlisted arguments; retention pruning registered.
- [ ] REST Admin API unchanged; services shared, not duplicated.
- [ ] Docs, runbook and the root-key retirement checklist present; `init.ps1` and both CI jobs green.

## Key Implementation Risks
- **Token theft is equivalent to Admin operational access.** Mitigated by expiry, separate write ability, rate limits, audit and revocation. Document that tokens must be kept in the MCP client's secret storage, not in the repo.
- **The refactor can break the REST API.** Do it first, as its own step, gated by the existing tests.
- **`laravel/mcp` is young (1.x).** API names may differ from this spec; pin the version and adapt names, not the decisions.
- **Write tools driven by an AI assistant.** `confirm`, annotations and the per-minute write limit keep accidental loops bounded. The client should still be configured to require approval for write tools.
- **Size.** This spec sits at the upper limit for one feature (one new data model plus two flows). If the implementer finds it too large, split the SPA token page into a follow-up and issue tokens through an `app:mcp-token` artisan command instead.
