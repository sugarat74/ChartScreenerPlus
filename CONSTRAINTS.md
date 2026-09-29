# Constraints

Durable MUST / MUST NOT rules for future agents working in this repository.

## Runtime

- **MUST** target **PHP 8.4.8** (Laragon) and the latest **Laravel 13.x**. Reason: this is the installed local runtime; Laravel 11+ requires PHP >= 8.2 and Laravel 13 requires PHP ^8.3.
- **MUST** keep `php` and `composer` on PATH (Laragon PHP 8.4.8, Composer 2.10.3). Reason: `init.ps1`, `artisan` and Composer scripts assume them; `self-update` under `ProgramData` needs admin.
- **MUST NOT** rely on Composer versions older than 2.x for scaffolding. Reason: old Composer emits deprecations and can fail on PHP 8.4.

## Database

- **MUST** use **SQLite** (`pdo_sqlite`, enabled) for local development and tests unless a feature explicitly requires another connection. Reason: it is the enabled default and keeps the gate DB-free of external services.
- **MUST NOT** commit the local SQLite database file; `/database/*.sqlite*` is git-ignored. Reason: it is local state, not source.
- Framework tables (`users`, `cache`, `jobs`, `sessions`) come from Laravel's default migrations. Domain schema (instruments, daily bars, snapshots, signals) is added by later features, not here.

## Harness

- **MUST** keep `init.ps1` a non-blocking gate: it runs `php artisan --version` and `php artisan test` and **MUST NOT** start long-running processes such as `php artisan serve`.
- **MUST** keep the repository restartable via `.\init.ps1` after every accepted feature.
