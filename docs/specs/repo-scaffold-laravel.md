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
- No database configuration or `migrate` (deferred to `db-schema-market-data`).
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
Then Laravel prints a `10.x` version and exits successfully.

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
- `php` is **not** resolvable on PATH: PATH points to `C:\laragon\bin\php\php-8.4.8-Win32-vs17-x64`, which does not exist.
- The installed PHP is **8.1.10**: `C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe`.
- Composer 2.5.1 works when PHP is on PATH (`C:\ProgramData\ComposerSetup\bin\composer.phar`).
- Required PHP extensions present: `openssl`, `pdo_mysql`, `mbstring`, `fileinfo`, `curl`, `tokenizer`.
- `pdo_sqlite` and `sqlite3` are **not** enabled.
- Node/npm available; Python 3.10 at `C:\laragon\bin\python\python-3.10`.

### Existing Patterns To Follow

- Laravel lives at the repository root (per `.gitignore` layout).
- Commands must be PowerShell 5.1-compatible; no `&&`, `chmod`, `rm -rf`.
- `alphapulse/` is a UI reference only and must not be touched.
- One feature at a time; the harness gate is `.\init.ps1`.

### Current Gaps

- Missing Laravel skeleton, `vendor/`, and application bootstrap files.
- `php` not on PATH and the configured Laravel-style PHP version is stale/missing.
- `pdo_sqlite` unavailable, so DB-dependent tests cannot use the default in-memory SQLite; there is no configured database yet.
- No `docs/specs/` directory (created by this spec).

## Technical Approach

1. **Pin Laravel 10.** PHP 8.1.10 cannot run Laravel 11+ (requires PHP >= 8.2). Use `laravel/laravel:^10.0`.
2. **Scaffold without clobbering the repo.** `composer create-project` requires an empty directory, so create the skeleton in a temporary directory, then copy it into the repository root while preserving existing files.
   - Preserve: `README.md`, `.gitignore`, `IDEA.txt`, `AGENTS.md`, `CONTEXT.md`, `DESIGN.md`, `PROGRESS.md`, `feature_list.json`, `init.ps1`, `docs/`, `alphapulse/`, `.opencode/`, `.agents`.
   - Exclude the skeleton's `README.md` and `.gitignore` from the copy.
3. **Environment setup for commands.** Prepend the real PHP directory to PATH for the session, or invoke `php.exe` by full path. Document this because `php` is otherwise missing.
4. **Key generation.** `create-project` produces a `.env`; otherwise copy `.env.example` to `.env` and run `php artisan key:generate`.
5. **No database configuration** in this slice. Leave `.env` DB settings at the framework default; do not run `migrate`.
6. **Convert `init.ps1` into a real non-blocking gate** for the new baseline (version + tests), never starting a server.

## Expected File Changes

Root-level Laravel skeleton (created by the scaffold, paths provisional until installed):

- `composer.json`, `composer.lock` — create; Laravel 10 dependencies.
- `artisan` — create; framework entry point.
- `app/`, `bootstrap/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `storage/`, `tests/` — create; Laravel 10 skeleton.
- `vendor/` — create; git-ignored.
- `package.json`, `vite.config.js`, `resources/js/app.js`, `resources/css/app.css` — create; Laravel default Vite assets.
- `phpunit.xml`, `.editorconfig`, `.env.example`, `.env` — create; `.env` git-ignored.
- `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` — create; default tests used by Scenario 2.

Modified by the implementer:

- `init.ps1` — modify; from informational pre-bootstrap to a real non-blocking gate.
- `PROGRESS.md` — modify; record verified state and evidence.
- `feature_list.json` — modify; set `repo-scaffold-laravel` evidence and status.
- `CONSTRAINTS.md` — create; durable environment/runtime constraints (see below).
- `AGENTS.md` — modify; add the PHP/Laravel 10 environment note.

Not overwritten: `README.md`, `.gitignore` (existing entries already cover Laravel).

## Visual Design Impact

- UI involved: no (product UI is not touched).
- Design source: `DESIGN.md` — not applicable to this slice.
- Screens or states affected: none.
- New design artifact required: no.
- Note: Laravel's default welcome page is temporary scaffolding and must not be styled to imitate `DESIGN.md`.

## Durable Documentation Impact

- `ARCHITECTURE.md`: not needed — no new architectural boundaries beyond what `docs/technical-discovery.md` already records (single-framework scaffold).
- `CONSTRAINTS.md`: create — record durable MUST rules: "Dev PHP is 8.1.10, so Laravel is pinned to 10.x", "`php` is not on PATH; use `C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe`", "`pdo_sqlite` is unavailable".
- `AGENTS.md`: update — add the PHP path / Laravel 10 pin to the Environment section (repo-wide operating rule).
- Other docs: `PROGRESS.md` and `feature_list.json` — update with evidence.

## Implementation Plan

1. Probe the environment and confirm the PHP path and Composer availability; add PHP to PATH for the session.
2. Create the Laravel 10 skeleton in a temp directory.
3. Copy the skeleton into the repository root, preserving existing files (exclude skeleton `README.md` and `.gitignore`).
4. Ensure `.env` exists and the app key is generated.
5. Verify: `php artisan --version`, `php artisan test`, and a temporary `php artisan serve` smoke check.
6. Update `init.ps1`, `CONSTRAINTS.md`, `AGENTS.md`, `PROGRESS.md` and `feature_list.json`.

## Implementation Tasks

- [ ] Add the real PHP directory to PATH for the session and confirm `php -v` and Composer work.
- [ ] Run `composer create-project laravel/laravel:^10.0 "$env:TEMP\csp-laravel"`.
- [ ] Copy the skeleton into the repo root excluding skeleton `README.md` and `.gitignore`; confirm no existing repo file was overwritten.
- [ ] Ensure `.env` exists and run `php artisan key:generate`.
- [ ] Confirm `php artisan --version` reports `10.x` and `php artisan test` passes.
- [ ] Rewrite `init.ps1` as a non-blocking gate (version + tests), with a PHP-path fallback; it must not start a server.
- [ ] Create `CONSTRAINTS.md` with the PHP/Laravel pin and environment notes.
- [ ] Update the `AGENTS.md` Environment section.
- [ ] Update `PROGRESS.md` and `feature_list.json`.

## Verification Plan

- `php artisan --version` → prints `Laravel Framework 10.x`.
- `php artisan test` → default example tests pass, exit 0.
- Temporary smoke: `php artisan serve` in the background, then `Invoke-WebRequest http://127.0.0.1:8000 -UseBasicParsing` returns 200, then stop the server (do not leave it running).
- `.\init.ps1` → runs the non-blocking checks, prints a result, exits 0, and does not start a long-running process.
- Persistent E2E: none exists in this repo and there is no user-visible product behavior yet; feature/unit tests plus the harness gate are sufficient for a scaffold. E2E will be introduced when user-facing flows exist (e.g. `chart-interactive`).
- Startup script rule: `init.ps1` executes the non-blocking gate for the current repo state and must not start long-running processes.

## Evidence To Capture

- Output of `php -v` and `php artisan --version` (PHP 8.1.10, Laravel 10.x).
- `php artisan test` summary showing passing tests.
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
- [ ] Laravel is pinned to 10.x and `init.ps1` does not start a server.
- [ ] `README.md` and `.gitignore` were preserved.
