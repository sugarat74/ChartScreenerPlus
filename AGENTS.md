# Project Agent Instructions

This repository contains **Chartiko** (repository directory `ChartScreenPlus`), published at `https://www.chartiko.com`: a web application that screens stocks on technical indicators and signals, and lets users inspect Candidates on an interactive chart. The data pipeline remains end-of-day (EOD); public UI must not display EOD/end-of-day labels outside the Admin tab. This presentation rule does not add intraday data.

## What We Are Building

A screener over a Universe (starting with the S&P 500) that applies multi-criteria technical filters and returns a ranked Candidate list. Data is EOD only (never intraday). The system scrapes daily quotes, stores Daily Bars, computes Indicator Snapshots and detects deterministic Signals (moving-average crosses, trend alignment, RVOL/volume, RSI, MACD, Pivot Breakout). An Admin controls data ingestion from a panel. Registered Users can save Screeners and Watchlists. An AI Copilot (chat) is planned but out of MVP scope; an AI-generated Company Card per Instrument is in scope.

Target stack: **Laravel** (API, auth, admin) + **Python engine** (scraping, indicators, signals) + **React SPA** (TypeScript + Vite, styled with Tailwind).

## Environment

- Development OS: **Windows** (repository at `C:\laragon\www\ChartScreenPlus`, served via Laragon).
- Default shell: **PowerShell 5.1**. Write commands and scripts in PowerShell-compatible form.
- Do not rely on `bash`-only syntax (`&&`, `chmod`, `rm -rf`, heredocs). Use PowerShell equivalents: chain with `;` or `cmd1; if ($?) { cmd2 }`, and use `Remove-Item`, `New-Item`, `Copy-Item`, `Test-Path`.
- Use backslash paths (`.\scripts\...`) and quote any path that contains spaces.
- The standard startup/verification script is `.\init.ps1`. If Git Bash is available, `bash init.sh` is an acceptable fallback, but PowerShell is the default.
- Runtime: **PHP 8.4.8** (Laragon) with **Laravel 13.x**, **Composer 2.10.3**. Local dev/test database is **SQLite** (`pdo_sqlite` enabled). `php` and `composer` must be on PATH.
- Frontend: the React SPA lives in **`frontend/`** (React 19 + Vite + Tailwind 4, Node v22). Run it with `npm --prefix frontend run dev`; it is separate from Laravel's root Vite assets. Unit/component tests run with `npm --prefix frontend run test` (Vitest + jsdom), also part of `.\init.ps1`.
- Languages: the SPA and the API support Spanish and English. User-facing text belongs in `frontend/src/i18n/messages/*.ts` and `lang/<locale>/*.php`, never hardcoded; see `docs/specs/app-multilanguage.md`.
- Auth dev flow: the SPA uses Laravel Sanctum first-party session cookies + CSRF (no API tokens). Run Laravel on port 8000; the Vite dev server proxies `/api` and `/sanctum` to it, and the dev SPA origins come from `SANCTUM_STATEFUL_DOMAINS` in `.env`.
- Engine: the Python engine lives in **`engine/`** (Python 3.10 + FastAPI). Run it with `engine\.venv\Scripts\python.exe -m app`; its tests run via `.\init.ps1`.

## Commands

Run the read-only session-start context hook before choosing work; `init.ps1` remains the full startup and verification gate.

```powershell
.\scripts\harness-context.ps1  # branch, progress, ready features, blockers and open risks
.\init.ps1                     # standard startup and verification gate
```

`alphapulse/` is the reference prototype only. Its commands exist but must not be wired into product work:

```powershell
cd alphapulse; npm install; npm run dev    # Express + Vite on :3000
npm run lint                               # tsc --noEmit
```

`init.ps1` runs the Laravel, SPA and engine checks. The context hook only reads repository state and never replaces those checks.

## Read First

- `CONTEXT.md` — domain language. Use these terms exactly.
- `docs/build-brief.md` — problem, users, goals, non-goals, MVP slice, validation.
- `docs/domain-model.md` — core entities, relationships and states.
- `docs/risks-and-open-questions.md` — current risks and unresolved decisions.

Read only when relevant:

- `docs/user-and-access-model.md` — when touching users, roles, permissions, ownership or revocation.
- `docs/technical-discovery.md` — when touching stack, integrations, data, auth, deployment or operations.
- `DESIGN.md` — when touching any UI. It is the visual source of truth.
- `alphapulse/` — the React prototype that defines the look and feel and intended screens. It is a **design/intent reference only**: its fake data and Express server are not the production base.

## MVP Scope Guard

In scope: EOD ingestion of the S&P 500, Indicator Snapshots, deterministic Signals, Screener with multi-criteria filters, ranked Candidate list, interactive chart, Saved Screeners and Watchlist for Registered Users, Admin panel for ingestion.

Also in scope since 2026-10-10 (explicit user decision), as specified in `docs/specs/`: geometric Chartist Patterns detected by deterministic rules (`chart-patterns-detect`, `chart-patterns-ui`, calibrated by `chart-patterns-calibration`; never targets or advice; since 2026-10-21, explicit user request, also the second batch of `chart-patterns-extended`: triple top/bottom, inverse cup with handle, bear flag and pennants) and Alerts with in-app notifications and an opt-in email digest (`alerts-engine`, `alerts-ui`, `alerts-email-digest`; evaluated once per daily run, never real-time). Alerts on chartist patterns of Watchlist Instruments (`alerts-patterns`, kind `watchlist_pattern`) are in scope too, in-app only.

Also in scope since 2026-10-15 (explicit user decision): the AI Company Card (`ai-company-card`), a structured, on-demand sheet per Instrument generated by an OpenAI-compatible provider for Registered Users, shared and cached, labelled as AI-generated, unverified and not advice; no chat, news retrieval, price targets or ratings.

Also in scope since 2026-10-15 (explicit user decision): verified company fundamentals computed from SEC EDGAR filings for the AI Company Card (`company-fundamentals-sec`): only this public source (10-K/10-Q XBRL companyfacts), only for the card (not Screener filters, alerts or public endpoints), refreshed by a separate opt-in daily command.

Also in scope since 2026-10-19 (explicit user request): the natural-language Screener (`ai-screener-natural-language`): a Registered User's stand-alone free-text request is translated by the same AI provider into the existing Screener filters only (strict schema, no chat, memory, new filters or advice); the text is never stored or logged.

Explicitly out of scope (do not build without an explicit request): intraday/real-time data, order execution/broker, backtesting, other fundamentals data feeds and news, real-time or push/SMS notifications, non-US markets, native apps, AI Copilot (chat or free-text AI), paid plans.

## Startup Workflow

Before writing code:

1. Confirm the working directory with `Get-Location` (should be `C:\laragon\www\ChartScreenPlus`).
2. Run `.\scripts\harness-context.ps1` to see the current branch, local changes, recorded verification, dependency-ready work and open risks.
3. Read `PROGRESS.md` and `feature_list.json` for the full evidence and pick the first ready unfinished feature in list order, unless the user requested a specific task. A `passing` feature needs independent validation; dependencies are ready only when `accepted`.
4. Run `.\init.ps1`.
5. If baseline verification fails, fix the baseline before starting new feature work.

## Working Rules

- Work on one feature at a time.
- Do not mark a feature complete just because code was added.
- Keep changes inside the selected feature scope unless a blocker requires a narrow supporting fix.
- Do not silently change verification rules during implementation.
- Enforce authorization server-side; hiding UI is not access control.
- Never commit secrets (API keys, credentials) to the repository.
- Keep scripts and commands **PowerShell 5.1-compatible**; if a shell script is added, provide a PowerShell entry point for Windows.
- Update durable repo artifacts instead of relying on chat summaries.

## Required Artifacts

- `feature_list.json` — source of truth for feature state.
  Status lifecycle: `not_started` -> `in_progress` -> `passing` (implemented and self-verified) -> `accepted` (an independent validator returned accept).
  `passing` is not done. A feature in `depends_on` is only ready when it is `accepted`.
- `PROGRESS.md` — current verified state and lightweight session log.
- `init.ps1` — standard Windows (PowerShell) startup and verification path. `init.sh` is the optional cross-platform/Git Bash equivalent.

> Harness status: active. Use `scripts/harness-context.ps1` for a quick session summary and `init.ps1` for the full verification path. See `PROGRESS.md` for the current verified state.

## Definition Of Done

A feature is done only when all are true:

- target behavior is implemented,
- required verification actually ran,
- evidence is recorded in `feature_list.json` or `PROGRESS.md`,
- the repository remains restartable from the standard startup path,
- relevant docs are updated if product behavior, domain rules, API or verification changed.

## End Of Session

Before ending a session:

1. Update `PROGRESS.md`.
2. Update `feature_list.json`.
3. Record unresolved risks or blockers.
4. Leave the repo clean enough for the next session to run `.\init.ps1` immediately.
