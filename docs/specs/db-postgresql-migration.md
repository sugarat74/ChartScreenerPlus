# Feature Implementation Spec: Move production persistence from SQLite to PostgreSQL

## Source Feature

- `id`: `db-postgresql-migration`
- `area`: `operations`
- `depends_on`: `db-schema-market-data` (`accepted`)
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Run Chartiko in production on a local PostgreSQL server instead of the SQLite file at `/var/www/alphapulse/shared/database/database.sqlite`, and move the existing production data without loss. `docs/technical-discovery.md` already names PostgreSQL as the preferred relational database; SQLite was the MVP shortcut. The VPS is expected to host further Chartiko-like projects, so the database must support concurrent writers (ingestion) and readers (web/API), per-project isolation (one database + one role per project), and standard backups.

Laravel is the only database owner (`InstrumentIngestor`, `ComputeIndicators`; the Python engine receives and returns data over HTTP via `EngineClient` and has no database driver), so the change is confined to Laravel configuration, CI, deployment scripts and a one-off data copy. Local development and the `init.ps1` gate keep SQLite.

## Non-Goals

- No schema redesign, new tables, new indexes or Postgres-only features (`JSONB`, `DISTINCT ON`, BRIN, partitioning, materialized views, TimescaleDB). Portability first; optimizations are later features.
- No change to the Python engine, the frontend, or any API contract.
- No replication, dedicated database VPS or managed database (documented as follow-ups). PgBouncer **is** in scope (Decision 10) so the shared-server pattern is ready before a second project lands.
- No change to the local dev/test database (`CONSTRAINTS.md`: SQLite for local development and tests) and no change to `init.ps1`.
- No backfill of Indicator Snapshots/Signals; that remains its own operational task.
- No hosting of other projects; this feature only prepares the shared server pattern.

## Job Story

When the VPS starts hosting several Chartiko-like projects and ingestion writes while users read,
I want Chartiko on a real database server with its own database and role,
so writes do not lock readers, each project is isolated, and backups follow one standard procedure.

## Users And Permissions

- Operator (root console): installs PostgreSQL, creates the `chartiko` role/database, runs the cut-over.
- `deploy` user: runs releases; reads DB credentials only through `shared/.env` (640, `deploy:www-data`).
- `www-data` (PHP-FPM, queue worker, scheduler cron): connects as the `chartiko` role over `127.0.0.1`.
- No end-user behavior changes. Existing sessions are invalidated once at cut-over (users log in again).

## Domain Decisions (explicit)

1. **Server.** Distro PostgreSQL (≥ 16) on the same VPS, listening only on `127.0.0.1`/Unix socket. Firewall unchanged (no 5432 exposure). Administration from a workstation goes through an SSH tunnel only.
2. **Isolation.** Database `chartiko`, owned by role `chartiko` (login, password, no superuser/createdb/createrole). `REVOKE ALL ON DATABASE chartiko FROM PUBLIC`; `REVOKE CREATE ON SCHEMA public FROM PUBLIC`. This is the template for every future project.
3. **Everything moves.** `DB_CONNECTION=pgsql` covers business tables **and** the `database` session, cache and queue drivers (`.env.example`: `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` are all `database`). No split-brain between SQLite and Postgres.
4. **Data copy.** Schema comes from `php artisan migrate --force` against Postgres (not from a dump translation). Business data is copied by a new artisan command `db:copy-sqlite-to-pgsql {--source=} {--dry-run}` that reads the SQLite file through a temporary `sqlite_source` connection, copies tables in foreign-key order in chunks inside one transaction, resets every Postgres sequence to `max(id)`, and verifies per-table row counts. Copied tables: `users`, `universes`, `instruments`, `instrument_universe`, `daily_bars`, `indicator_snapshots`, `signals`, `ingestion_runs`, `ingestion_run_items`, `watchlist_items`, `saved_screeners`, `personal_access_tokens`. Not copied (transient, recreated empty): `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`, `migrations` (written by `migrate`).
5. **Type strictness.** SQLite stores loosely; Postgres rejects bad values. The copy must fail loudly (abort the transaction) on any value that does not fit the column (over-length strings, non-dates, non-numeric decimals, `0/1` booleans are cast explicitly). A `--dry-run` reports the offending rows without writing.
6. **Cut-over is a maintenance window, not a deploy.** Sequence: `php artisan down` → stop cron entry, queue and engine → refuse if `jobs` is non-empty or an Ingestion Run is truly active (same rules as `remediate-permissions.sh`, including its stale-ledger handling) → private SQLite backup + `integrity_check` → switch `.env` → `migrate --force` → copy → verify counts → `config:cache` → start services → `php artisan up`. The SQLite file is kept read-only as rollback source for at least 30 days.
7. **Rollback.** Restore the backed-up `.env` (SQLite), `config:cache`, restart services. Any data written to Postgres after cut-over is lost on rollback; this is accepted and stated in the runbook.
8. **CI.** The existing `test` job keeps SQLite. A new job runs `php artisan test` against a `postgres` service container (same major version as production). Deploy requires both jobs.
9. **Backups.** Daily `pg_dump -Fc` by a root cron, private directory (`0700`, root), 14-day local retention, plus an off-VPS copy target documented (configured by the operator; credentials never committed). A restore into a scratch database is part of verification. Backups connect directly to PostgreSQL, not through PgBouncer.
10. **PgBouncer (connection pooling).** Every PHP-FPM worker, the queue worker and each scheduler run opens its own connection; with several projects on one VPS that multiplies against PostgreSQL's `max_connections`, and each Postgres connection is a full process. PgBouncer sits between the apps and PostgreSQL:
    - **Topology.** Distro `pgbouncer` listening only on `127.0.0.1:6432`; PostgreSQL stays on `127.0.0.1:5432`. Runtime traffic (PHP-FPM, queue worker, scheduler) uses `DB_PORT=6432`. Administrative traffic goes **direct to 5432**: `migrate` in `deploy.sh`, the copy command, the cut-over script and `pg_dump`. The direct port is selected by overriding `DB_PORT=5432` in the process environment for those commands (Laravel's dotenv does not override variables already set), before `config:cache` in `deploy.sh`.
    - **Pool mode `transaction`.** It gives the real benefit (a server connection is held only during a transaction). Laravel is compatible under these conditions, which the implementation must enforce and test:
      - `pgsql` connection `options` set `PDO::ATTR_EMULATE_PREPARES => true`, so no server-side prepared statement outlives a transaction. Do not depend on PgBouncer's `max_prepared_statements` (version-dependent); emulation works on every version.
      - No session state: no `SET` outside a transaction, no `LISTEN/NOTIFY`, no session-level advisory locks, no temporary tables across transactions. Current code uses none: the `database` cache lock and the `database` queue (`FOR UPDATE SKIP LOCKED` inside a transaction) are row-based. Re-check at implementation time with a search for `pg_advisory`, `LISTEN`, `SET `, `TEMPORARY`.
      - Laravel's Postgres `search_path`/timezone settings are sent on connect; set `search_path` and `timezone` for the role in PostgreSQL (`ALTER ROLE chartiko SET ...`) as well so they do not depend on a session `SET` reaching the same server connection.
    - **Authentication.** `auth_type = scram-sha-256`, `auth_file` `/etc/pgbouncer/userlist.txt` holding the SCRAM verifier (copied from `pg_authid`, never the plain password), owner `postgres`, mode `0640`, group `postgres`. `admin_users`/`stats_users` = `postgres` only. No `trust`/`any` auth.
    - **Isolation.** One `[databases]` entry per project (`chartiko = host=127.0.0.1 port=5432 dbname=chartiko`), so a new project is one line + one role. Pools are per `(database, user)`.
    - **Sizing (starting values, documented, tuned by evidence).** `max_client_conn = 200`, `default_pool_size = 10`, `reserve_pool_size = 2`, `max_db_connections = 20` per database, PostgreSQL `max_connections = 100` leaving headroom for direct admin sessions. `server_reset_query` empty (correct for transaction mode). `server_idle_timeout = 600`.
    - **Failure mode.** PgBouncer is a hard dependency at runtime: if it is down, the site is down. It runs under systemd with `Restart=on-failure`, is part of the post-deploy/post-cut-over health check, and its status/`SHOW POOLS` are in the runbook. Rollback of PgBouncer alone is `DB_PORT=5432` + `config:cache` (direct connection) without touching data.

## Acceptance Scenarios

### Scenario 1: The test suite passes on PostgreSQL

Given a CI job with a PostgreSQL service and `DB_CONNECTION=pgsql`,
When `php artisan test` runs,
Then every existing test passes with no test skipped for the driver.

### Scenario 2: The local gate is unchanged

Given a developer machine without PostgreSQL,
When `.\init.ps1` runs,
Then it exits `0` using in-memory SQLite exactly as before.

### Scenario 3: Data copy is complete and exact

Given a SQLite file with users, bars, snapshots, signals, runs, watchlists and saved screeners,
When `db:copy-sqlite-to-pgsql` runs into a freshly migrated Postgres database,
Then per-table row counts match, sampled rows (decimals, dates, booleans, JSON criteria) are equal, and inserting a new row in each copied table receives an id greater than the copied maximum.

### Scenario 4: Bad source data aborts the copy

Given a source row whose value violates the Postgres column (for example a string longer than its `varchar` length),
When the copy runs,
Then it aborts with the table, primary key and column named, and the target database contains no partially copied data. `--dry-run` reports the same row without writing.

### Scenario 5: Production cut-over

Given the runbook steps in Decision 6,
When the operator runs the cut-over,
Then the site returns, `/api` screener and NVDA instrument endpoints return `200`, guest-protected endpoints still return `401`, an Admin can log in, the queue worker processes a job, `schedule:list` shows `ingestion-pipeline`, and production row counts match the pre-cut-over SQLite counts (68,136 Daily Bars at last check).

### Scenario 6: Isolation and exposure

Given the production server,
When checked,
Then PostgreSQL listens only on loopback, the `chartiko` role is not superuser and cannot connect to other databases, and `PUBLIC` has no connect/create rights on `chartiko`.

### Scenario 7: The application works through PgBouncer in transaction mode

Given the CI PostgreSQL job also starts a PgBouncer service in `transaction` mode and the tests connect through it with emulated prepares,
When `php artisan test` runs,
Then every test passes; and in production `SHOW POOLS` on the PgBouncer admin console shows the `chartiko` pool serving clients while `pg_stat_activity` shows at most `max_db_connections` server connections for the `chartiko` role.

### Scenario 8: Direct and pooled paths are separated

Given a release deploy,
When `deploy.sh` runs,
Then `migrate --force` connects on port `5432` (direct) and the cached runtime configuration uses port `6432`; PgBouncer listens only on loopback and refuses a login with a wrong password.

### Scenario 9: Backup is restorable

Given the backup cron has run,
When the newest dump is restored into a scratch database,
Then restore succeeds and its row counts match production.

## Repository Research

### Files Inspected

- `config/database.php`, `.env.example` — `DB_CONNECTION=sqlite`; sessions, cache and queue use the `database` driver.
- `config/queue.php` — batching/failed job connections follow `DB_CONNECTION`.
- `phpunit.xml` — forces `DB_CONNECTION=sqlite` (in-memory); CI Postgres job must override it via environment.
- `database/migrations/*` — 16 migrations, schema builder only; `2026_09_29_170000_add_role_to_users_table.php` has a SQLite-specific index drop ordering that is harmless on Postgres (verify in CI).
- `app/Http/Controllers/ScreenerController.php:324` — the only raw SQL (`whereRaw` correlated `max(date)` subquery); standard SQL, portable.
- `app/Services/Ingestion/InstrumentIngestor.php`, `app/Console/Commands/ComputeIndicators.php` — Eloquent upserts keyed on unique `(instrument_id, date)`; supported by the Postgres grammar (`ON CONFLICT`).
- `app/Models/SavedScreener.php` — JSON criteria cast (stored as `json` column; text in SQLite).
- `deploy/deploy.sh:8-18` — refuses to deploy without the SQLite file and symlinks it into each release; must become driver-aware.
- `deploy/setup-server.sh` — installs `sqlite3`/`php-sqlite3`, creates the SQLite file; must add `postgresql` + `php-pgsql`.
- `deploy/remediate-permissions.sh` — SQLite-only remediation; stays as historical tooling, its stale-ledger/queue checks are reused by the cut-over.
- `.github/workflows/ci-deploy.yml` — single `test` job with `pdo_sqlite`; deploy `needs: test`.
- `CONSTRAINTS.md:13-19`, `ARCHITECTURE.md:202` — SQLite rules for local dev/tests.
- `docs/technical-discovery.md:24` — "PostgreSQL preferred".

### Existing Patterns To Follow

- Commands in `app/Console/Commands`, config via `env(key, default)`.
- Root-only operational scripts in `deploy/` with private backups, explicit checks and an automatic rollback path (`remediate-permissions.sh`, `update-nginx-metadata.sh`).
- Evidence recorded with exact counts, never secret values.

### Current Gaps

- No `pdo_pgsql`/`php-pgsql` locally, in CI or on the VPS; no Postgres server; no copy tooling; deploy script hard-requires SQLite; no database backup job.

## Technical Approach

1. **CI**: add a `test-pgsql` job (`services: postgres` and a `pgbouncer` container in `transaction` mode, `extensions: pdo_pgsql`, env `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=6432`) running `php artisan test` through PgBouncer; migrations run with `DB_PORT=5432`. Make `deploy` need `[test, test-pgsql]`. Fix any test or migration that only passed on SQLite.
1b. **`config/database.php`**: `pgsql` connection `options` add `PDO::ATTR_EMULATE_PREPARES => true` when `DB_PGSQL_EMULATE_PREPARES` (default `true`) is set; no change to the `sqlite` connection.
2. **Copy command** `app/Console/Commands/CopySqliteToPgsql.php` (Decisions 4–5) with tests: copy between two in-memory/temporary connections in CI's Postgres job; a SQLite-only unit test for the dry-run validator.
3. **Deploy**: `deploy/deploy.sh` only checks/symlinks the SQLite file when `DB_CONNECTION=sqlite` (read from the shared `.env` without printing secrets), runs `migrate` with `DB_PORT=5432` when the driver is `pgsql`, and adds a PgBouncer reachability check to the health check; `deploy/setup-server.sh` installs `postgresql pgbouncer php-pgsql` and documents role/database creation.
3b. **PgBouncer config**: `deploy/pgbouncer.ini` template (Decision 10) and `deploy/pgbouncer-add-database.sh` (root) that adds a project's `[databases]` line and SCRAM `userlist.txt` entry, validates, and reloads PgBouncer with backup/restore on failure.
4. **Cut-over script** `deploy/migrate-to-postgresql.sh` (root, Decision 6), with `--dry-run`, private backups, pre-flight refusals, count verification and automatic rollback on failure.
5. **Backups** `deploy/backup-postgresql.sh` + `/etc/cron.d` entry (Decision 9).
6. **Docs**: `deploy/README.md` runbook (install, role, PgBouncer add-project/`SHOW POOLS`/bypass, cut-over, rollback, backup/restore, SSH-tunnel admin), `ARCHITECTURE.md`, `CONSTRAINTS.md` (local SQLite stays; production is PostgreSQL; CI runs both), `docs/risks-and-open-questions.md`.

## Expected File Changes

- `.github/workflows/ci-deploy.yml` — modify.
- `app/Console/Commands/CopySqliteToPgsql.php` — create; tests under `tests/Feature/`.
- `config/database.php` — modify; `pgsql` emulated prepares (env-driven) and, if needed, a `sqlite_source` connection (env-driven, unused by default).
- `deploy/deploy.sh`, `deploy/setup-server.sh`, `deploy/README.md` — modify.
- `deploy/migrate-to-postgresql.sh`, `deploy/backup-postgresql.sh`, `deploy/pgbouncer.ini`, `deploy/pgbouncer-add-database.sh` — create.
- `.env.example` — modify; commented `pgsql` block.
- `ARCHITECTURE.md`, `CONSTRAINTS.md`, `docs/risks-and-open-questions.md`, `PROGRESS.md`, `feature_list.json` — modify.

Explicitly **not** changed: `engine/`, `frontend/`, `alphapulse/`, `init.ps1`, `phpunit.xml` defaults, existing migrations' intent.

## Key Implementation Risks

- **Sequences after explicit-id inserts** — forgetting `setval` makes the next insert collide. Covered by Scenario 3.
- **Silent SQLite data** — over-length strings or malformed dates surface only at copy time. Mitigated by `--dry-run` against a copy of production SQLite before the window.
- **Case-sensitive comparisons** — Postgres `=`/`LIKE` are case-sensitive; current code has no `LIKE` search, but ticker/email lookups must be checked in the Postgres CI job.
- **Ordering without `ORDER BY`** — SQLite tends to return insertion order; any test relying on that will fail on Postgres and must be fixed with explicit ordering.
- **Cut-over during ingestion** — prevented by the pre-flight refusals and stopping the cron entry first.
- **Rollback data loss** — writes after cut-over are not copied back; keep the window short and verify before reopening.
- **Credentials** — the Postgres password lives only in `shared/.env`; never in the repo, CI logs or evidence. `userlist.txt` holds only SCRAM verifiers and is private to `postgres`.
- **Transaction pooling pitfalls** — server-side prepared statements or any session state (`SET`, advisory locks, `LISTEN`, temp tables) break or leak across clients. Mitigated by emulated prepares, role-level settings and running the whole suite through PgBouncer in CI.
- **Migrations through the pooler** — DDL works in transaction mode, but long migrations would pin a pooled connection and lock timeouts behave differently; migrations always go direct to 5432.
- **PgBouncer as single point of failure** — health check covers it; bypass to 5432 is a config-only rollback.
- **Pool exhaustion** — undersized `default_pool_size` queues requests (latency, not errors) during ingestion; tune from `SHOW POOLS` (`cl_waiting`, `maxwait`) evidence.

## Verification Plan

- CI: both `test` (SQLite) and `test-pgsql` jobs green on the PR.
- Local: `.\init.ps1` exit 0, unchanged.
- Copy command: dry-run and real run against a copy of production SQLite in a scratch Postgres database; per-table counts and sequence check recorded.
- Production: Scenarios 5, 6, 7 and 8 checks after cut-over; Scenario 9 restore test.

## Evidence To Capture

- CI run ids for both test jobs and the deploy.
- Per-table row counts before (SQLite) and after (Postgres), without data contents.
- `ss -ltnp` showing 5432 and 6432 on loopback only; role attributes (`\du chartiko`).
- `SHOW POOLS` / `SHOW CONFIG` (pool mode, sizes) and `pg_stat_activity` server-connection count for `chartiko`, without credentials.
- Backup file listing (names/sizes/modes) and restore counts.
- Confirmation `engine/`, `frontend/`, `init.ps1` unchanged.

## Implementation Notes (2026-10-06)

Deviations from the plan above, each keeping the decision's intent:

- **Direct vs pooled routing uses Laravel 13's native pooled-connection support** instead of overriding `DB_PORT=5432` in `deploy.sh`. The `pgsql` connection gets `direct => [host, port]` when `DB_DIRECT_PORT` is set; the framework then marks it pooled, forces `PDO::ATTR_EMULATE_PREPARES`, binds booleans as `'true'/'false'`, and resolves `migrate`, `db:wipe`, `db:show`, `db:table` and `php artisan db` to `pgsql::direct`. No `DB_PGSQL_EMULATE_PREPARES` flag is needed. `deploy.sh` keeps a single `php artisan migrate --force`.
- **Pooled health check** in `deploy.sh` is `php artisan db:monitor --databases=pgsql` (it queries the default, pooled connection; `db:show` would go direct). It runs after `config:cache` and before `current` is switched.
- **Role/database creation is scripted**, not only documented: `deploy/postgresql-project.sql` (psql, password via `\getenv PROJECT_DB_PASSWORD`, never argv) wrapped by `deploy/postgresql-add-project.sh`, which also registers the PgBouncer pool (replacing the planned `pgbouncer-add-database.sh`). It additionally revokes `CONNECT` on `postgres`/`template1` from `PUBLIC` so a project role cannot reach maintenance databases.
- **PgBouncer base config** is installed by `deploy/install-pgbouncer.sh` (template `deploy/pgbouncer.ini` + `deploy/pgbouncer-hba.conf`; projects in `%include`d `/etc/pgbouncer/databases.ini`). `auth_type = hba`: SCRAM for `127.0.0.1`, `peer` for the `postgres` OS user on the admin console, so `SHOW POOLS` needs no superuser password.
- **CI exercises the production scripts**: `test-pgsql` installs the runner's distro PgBouncer through `install-pgbouncer.sh`, provisions through `postgresql-add-project.sh`, runs the whole suite through 6432 (migrations on 5432) and then checks `SHOW POOLS`, `pool_mode`, loopback-only listener, wrong-password rejection, role attributes and that the role cannot connect to `postgres`.
- **Cut-over keeps `shared/.env` on SQLite until the copy is committed**: `migrate` and the copy run as `deploy` with the PostgreSQL settings in the process environment and `APP_CONFIG_CACHE` pointed at a missing file. A failure before the switch resumes on SQLite with nothing to undo; a failure after it restores `.env` and the cached config automatically. The copy reads a private `.backup` snapshot, not the live file. `--dry-run` migrates the still-unused PostgreSQL database (schema only) and validates the snapshot.
- **Copy tests**: the six copy scenarios need PostgreSQL and are skipped on SQLite (they run in `test-pgsql`); the refusal scenarios and the value validator (`tests/Unit/PostgresValueNormalizerTest.php`) run everywhere. A SQLite-only duplicate (`'2026-09-03'` vs `'2026-09-03 00:00:00'` under a unique key) aborts with PostgreSQL's own key message; `--dry-run` validates values, not cross-row uniqueness.
- **PostgreSQL version**: CI pins `postgres:18`, assuming Ubuntu 26.04's distro package; confirm with `psql --version` on the VPS before cut-over and align the image.

## Validator Checklist

- [ ] Scope respected: no schema redesign, no Postgres-only features, no engine/frontend change.
- [ ] Local gate and `CONSTRAINTS.md` SQLite rule intact; CI also proves PostgreSQL.
- [ ] Copy is transactional, verified by counts, resets sequences, and fails loudly on bad data.
- [ ] Cut-over refuses active ingestion/queued jobs, keeps a private SQLite backup, and has a tested rollback.
- [ ] PostgreSQL bound to loopback; `chartiko` role least-privilege; no secret in repo or evidence.
- [ ] Backups run, are private, and a restore was tested.
- [ ] Runtime traffic goes through PgBouncer (`transaction` mode, loopback, SCRAM); migrations, copy and backups go direct; the suite passes through PgBouncer in CI with emulated prepares; no session-state usage in code.
- [ ] Docs, `PROGRESS.md` and `feature_list.json` updated.
