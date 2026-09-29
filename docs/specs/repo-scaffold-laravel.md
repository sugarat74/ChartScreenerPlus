# Feature Implementation Spec: Scaffold the Laravel application

## Source Feature

- `id`: `repo-scaffold-laravel`
- `area`: `bootstrap`
- `depends_on`: `[]`
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Create the Laravel application that anchors the AlphaPulse/ChartScreenPlus backend. After this feature, a developer can start the Laravel app from the repository root, the framework boots, and its test suite runs.

This is the first bootstrap slice of the target stack (Laravel + Python engine + React SPA). It must not build product behavior beyond a bootable, testable Laravel skeleton.

## Non-Goals

- No domain models, migrations, API routes or product UI (those are `db-schema-market-data`, `screener-api`, `app-shell-navigation`, etc.).
- No domain database models or migrations (deferred to `db-schema-market-data`). Laravel's framework tables (`users`, `cache`, `jobs`, `sessions`) are migrated so the app is bootable.
- No React SPA scaffolding (`repo-scaffold-frontend`); Laravel's default Vite assets are incidental.
- No authentication (`auth-registration-login`).
- No styling of the Laravel welcome page to resemble `DESIGN.md`; it is temporary scaffolding.
- No Python engine work (`python-engine-scaffold`).

## Job Story

When I sit down to start product work on ChartScreenPlus,
I want a working Laravel backend skeleton at the repository root with a runnable test suite,
so I can build the market-data and API features on a real framework instead of an empty repo.

## Users And Permissions

- Developer (local): can install dependencies, run the app and run tests. No end-user roles exist yet.

## Acceptance Scenarios

### Scenario 1: Framework boots and reports its version

Given a clean checkout with dependencies installed,
When the developer runs `php artisan --version`,
Then Laravel prints a `13.x` version and exits successfully.

### Scenario 2: Test suite runs green

Given the scaffolded Laravel app,
When the developer runs `php artisan test`,
Then the default example tests pass and the command exits 0.

### Scenario 3: The app serves HTTP

Given the app is bootable,
When the developer starts `php artisan serve` and requests the root URL,
Then the response is HTTP 200; the server is then stopped.

### Scenario 4: The standard harness gate works

Given the scaffolded app,
When the developer runs `.\init.ps1`,
Then it executes the non-blocking checks (`artisan --version`, `artisan test`), exits 0, and does not start a long-running server.

## Repository Research

### Files Inspected

- `feature_list.json` — selected feature metadata and ordering.
- `PROGRESS.md` — harness state; app is pre-bootstrap.
- `AGENTS.md` — Windows/PowerShell rules and startup workflow (`.\init.ps1`).
- `docs/technical-discovery.md` — target stack, deployment and constraints.
- `docs/build-brief.md` — MVP slice context.
- `.gitignore` — already contains Laravel entries (`/vendor/`, `/public/build`, `.env`, `.phpunit.result.cache`), implying Laravel is intended at the repository root.
- `init.ps1` — current informational pre-bootstrap gate.
- `.opencode/skills/feature-spec/references/spec-template.md` — spec structure.

### Environment Findings (probed, not assumed)

- No `composer.json`, `artisan`, `app/`, `routes/` or `vendor/` exist yet.
- `php` is on PATH and works: **PHP 8.4.8** at `C:\laragon\bin\php\php-8.4.8-Win32-vs17-x64\php.exe`.
- The latest stable `laravel/laravel` is **v13.10.1**, whose `require` is `php: ^8.3` — satisfied by PHP 8.4.8.
- Composer **2.10.3** is installed and on PATH (`C:\ProgramData\ComposerSetup\bin\composer.phar`); no PHP 8.4 deprecation noise.
- Required PHP extensions present: `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`, `pcre`, `pdo_mysql`, `session`, `tokenizer`, `xml`, `PDO`.
- `pdo_sqlite` and `sqlite3` are **enabled** (verified: `php -r "echo extension_loaded('pdo_sqlite') ? 'yes' : 'no';"` → `yes`).
- Node/npm available; Python 3.10 at `C:\laragon\bin\python\python-3.10`.

### Existing Patterns To Follow

- Laravel lives at the repository root (per `.gitignore` layout).
- Commands must be PowerShell 5.1-compatible; no `&&`, `chmod`, `rm -rf`.
- `alphapulse/` is a UI reference only and must not be touched.
- One feature at a time; the harness gate is `.\init.ps1`.

### Current Gaps

- Missing Laravel skeleton, `vendor/`, and application bootstrap files.
- No application database is configured yet (deferred to `db-schema-market-data`); SQLite is available.
- No `docs/specs/` directory (created by this spec).

## Technical Approach

1. **Use the latest Laravel (13.x).** PHP 8.4.8 satisfies Laravel 13's `php: ^8.3`. Use `laravel/laravel:^13.0`.
2. **Scaffold without clobbering the repo.** `composer create-project` requires an empty directory, so create the skeleton in a temporary directory, then copy it into the repository root while preserving existing files.
   - Use `--no-scripts` for a deterministic scaffold. (`pdo_sqlite` is enabled, so Laravel's `post-create-project-cmd` — `key:generate`, `touch database/database.sqlite`, `artisan migrate --graceful` — would also be safe, but `--no-scripts` avoids surprises.)
   - Preserve: `README.md`, `.gitignore`, `IDEA.txt`, `AGENTS.md`, `CONTEXT.md`, `DESIGN.md`, `PROGRESS.md`, `feature_list.json`, `init.ps1`, `docs/`, `alphapulse/`, `.opencode/`, `.agents`.
   - Exclude from the copy: skeleton `README.md`, `.gitignore`, `vendor/`, `node_modules/` (run `composer install` in the repo root instead).
3. **Environment setup for commands.** `php` is on PATH (8.4.8), `pdo_sqlite` is enabled, and Composer 2.10.3 is available.
4. **Key generation.** With `--no-scripts`, `.env` may not exist: copy `.env.example` to `.env` and run `php artisan key:generate`. Do **not** run `artisan migrate` in this slice.
5. **Framework database only.** Create `database/database.sqlite` and run Laravel's default migrations. The skeleton's default `SESSION_DRIVER=database` needs the `sessions` table, so `/` returns 500 without it. Do not add domain migrations (`db-schema-market-data`).
6. **Convert `init.ps1` into a real non-blocking gate** for the new baseline (version + tests), never starting a server.

## Expected File Changes

Root-level Laravel skeleton (created by the scaffold, paths provisional until installed):

- `composer.json`, `composer.lock` — create; Laravel 13 dependencies.
- `artisan` — create; framework entry point.
- `app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `storage/`, `tests/` — create; Laravel 13 skeleton.
- `vendor/` — create; git-ignored.
- `package.json`, `vite.config.js`, `resources/js/app.js`, `resources/css/app.css` — create; Laravel default Vite assets.
- `phpunit.xml`, `.editorconfig`, `.env.example`, `.env` — create; `.env` git-ignored.
- `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` — create; default tests used by Scenario 2.

Modified by the implementer:

- `init.ps1` — modify; from informational pre-bootstrap to a real non-blocking gate.
- `.gitignore` — modify; ignore the local SQLite file (`/database/*.sqlite*`).
- `database/database.sqlite` — create; local DB, git-ignored.
- `PROGRESS.md` — modify; record verified state and evidence.
- `feature_list.json` — modify; set `repo-scaffold-laravel` evidence and status.
- `CONSTRAINTS.md` — create; durable environment/runtime constraints (see below).
- `AGENTS.md` — modify; add the PHP 8.4 / Laravel 13 environment note.

Not overwritten: `README.md`, `.gitignore` (existing entries already cover Laravel).

## Visual Design Impact

- UI involved: no (product UI is not touched).
- Design source: `DESIGN.md` — not applicable to this slice.
- Screens or states affected: none.
- New design artifact required: no.
- Note: Laravel's default welcome page is temporary scaffolding and must not be styled to imitate `DESIGN.md`.

## Durable Documentation Impact

- `ARCHITECTURE.md`: not needed — no new architectural boundaries beyond what `docs/technical-discovery.md` already records (single-framework scaffold).
- `CONSTRAINTS.md`: create — record durable MUST rules: "Dev PHP is 8.4.8; target the latest Laravel 13.x", "SQLite (`pdo_sqlite`) is enabled for local development and tests".
- `AGENTS.md`: update — add the PHP 8.4 / Laravel 13 note to the Environment section (repo-wide operating rule).
- Other docs: `PROGRESS.md` and `feature_list.json` — update with evidence.

## Key Implementation Risks

- **create-project scripts vs SQLite** — Laravel 13's `post-create-project-cmd` touches `database/database.sqlite` and runs `artisan migrate --graceful`. Scaffolding with `--no-scripts` skips that, so the implementer creates `database/database.sqlite` and runs the framework migrations explicitly; otherwise `/` returns 500 because the default `SESSION_DRIVER=database` has no `sessions` table.
- **Clobbering existing repo files** — the root is not empty (`AGENTS.md`, `docs/`, `alphapulse/`, `.opencode/`, the `.agents` junction, `feature_list.json`, `PROGRESS.md`, `init.ps1`). Mitigation: temp-dir scaffold + selective copy; never overwrite `README.md` or `.gitignore`; verify afterwards.
- **Vendor size / Windows long paths** — copying `vendor/` and `node_modules/` is large and deep. Mitigation: exclude them from the copy and run `composer install` in the repo root.
- **`.agents` junction** — it points to `.opencode`; do not copy into it and do not follow it during recursive copies.
- **Tests and the database** — the default example tests do not touch the DB; `pdo_sqlite` is enabled, so DB-backed tests can use Laravel's default SQLite.
- **No E2E harness** — none exists and there is no user-visible behavior yet; scaffold coverage relies on feature/unit tests plus `init.ps1`.

## Implementation Plan

1. Confirm `php -v` reports 8.4.8, `pdo_sqlite` is enabled, and `composer --version` is 2.10.3.
2. Create the latest Laravel 13 skeleton in a temp directory with `--no-scripts`.
3. Copy the skeleton into the repository root, preserving existing files (exclude skeleton `README.md`, `.gitignore`, `vendor/`, `node_modules/`), then run `composer install` in the root.
4. Copy `.env.example` to `.env` and run `php artisan key:generate`.
5. Create `database/database.sqlite` and run the framework migrations (`php artisan migrate --force`).
6. Verify: `php artisan --version`, `php artisan test`, and a temporary `php artisan serve` smoke check on `/up` and `/`.
7. Update `init.ps1`, `CONSTRAINTS.md`, `AGENTS.md`, `PROGRESS.md` and `feature_list.json`.

## Implementation Tasks

- [ ] Confirm `php -v` reports 8.4.8, `composer --version` is 2.10.3, and `pdo_sqlite` is enabled.
- [ ] Remove any stale `$env:TEMP\csp-laravel` directory, then run `composer create-project laravel/laravel:^13.0 "$env:TEMP\csp-laravel" --no-scripts`.
- [ ] Copy the skeleton into the repo root excluding `README.md`, `.gitignore`, `vendor/` and `node_modules/`; confirm no existing repo file was overwritten; run `composer install` in the root.
- [ ] Copy `.env.example` to `.env` and run `php artisan key:generate`.
- [ ] Create `database/database.sqlite` and run `php artisan migrate --force` (framework tables only).
- [ ] Confirm `php artisan --version` reports `13.x` and `php artisan test` passes.
- [ ] Rewrite `init.ps1` as a non-blocking gate (version + tests); it must not start a server.
- [ ] Create `CONSTRAINTS.md` with the PHP 8.4 / Laravel 13 pin and the SQLite-enabled note.
- [ ] Update the `AGENTS.md` Environment section.
- [ ] Update `PROGRESS.md` and `feature_list.json`.

## Verification Plan

- `php artisan --version` → prints `Laravel Framework 13.x`.
- `php artisan test` → default example tests pass, exit 0.
- `pdo_sqlite` is loaded; `php artisan test` uses Laravel's default SQLite; the framework migrations create `users`/`cache`/`jobs`/`sessions` and no domain tables are added in this slice.
- Temporary smoke: `php artisan serve` in the background, then `Invoke-WebRequest http://127.0.0.1:8000/up -UseBasicParsing` returns 200 and `/` also returns 200 (Laravel 11+ ships the `/up` health route); then stop the server and its child process (do not leave it running).
- `.\init.ps1` → runs the non-blocking checks, prints a result, exits 0, and does not start a long-running process.
- Persistent E2E: none exists in this repo and there is no user-visible product behavior yet; feature/unit tests plus the harness gate are sufficient for a scaffold. E2E will be introduced when user-facing flows exist (e.g. `chart-interactive`).
- Startup script rule: `init.ps1` executes the non-blocking gate for the current repo state and must not start long-running processes.

## Evidence To Capture

- Output of `php -v` and `php artisan --version` (PHP 8.4.8, Laravel 13.x).
- Confirmation that `pdo_sqlite`/`sqlite3` are enabled (`php -r ...`).
- `php artisan test` summary showing passing tests.
- `php artisan migrate --force` output (framework tables migrated) and HTTP 200 for both `/up` and `/`.
- The temporary serve smoke result (HTTP 200).
- `.\init.ps1` output showing the gate executed and exited 0.
- Confirmation that `README.md` and `.gitignore` were not overwritten.

## Validator Checklist

- [ ] Implementation stays within this feature's scope.
- [ ] Acceptance scenarios pass.
- [ ] Verification evidence is present.
- [ ] Persistent E2E coverage was added/updated when the feature has an observable user/API flow and an E2E harness exists, or the spec explains why it is not needed.
- [ ] `feature_list.json` and `PROGRESS.md` were updated correctly.
- [ ] No unrelated product behavior or extra feature work was added.
- [ ] Laravel is 13.x and `init.ps1` does not start a server.
- [ ] `README.md` and `.gitignore` were preserved.
