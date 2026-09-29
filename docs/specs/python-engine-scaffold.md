# Feature Implementation Spec: Scaffold the Python engine

## Source Feature

- `id`: `python-engine-scaffold`
- `area`: `bootstrap`
- `depends_on`: `[]`
- `status`: `not_started`
- `source`: `feature_list.json`

## Goal

Create the Python service that will own scraping, indicator computation and signal detection. After this feature, a developer can create a virtual environment, run the engine, get a healthy JSON response from `/health`, and run its test suite.

This is the third bootstrap slice (Laravel + React SPA + Python engine) and it fixes the Laravel <-> engine boundary as an HTTP service.

## Non-Goals

- No scraping, indicator math or signal detection (those are `ingestion-scraper-eod`, `indicators-compute`, `signals-detect`).
- No database access or persistence.
- No Laravel integration, HTTP client, queue wiring or CORS.
- No auth, rate limiting or Docker/container setup.
- No scheduler.

## Job Story

When I start engine work on ChartScreenPlus,
I want a runnable FastAPI service with a health check and a test suite,
so I can add scraping and indicator logic on a real foundation instead of a bare script.

## Users And Permissions

- Developer (local): can create the venv, run the service and run tests. No end-user roles exist yet.

## Acceptance Scenarios

### Scenario 1: Environment and dependencies install

Given a clean checkout,
When the developer creates `engine/.venv` and installs `requirements-dev.txt`,
Then FastAPI, uvicorn, pytest and httpx install successfully on Python 3.10.

### Scenario 2: Health endpoint responds

Given the engine is running,
When a client requests `GET /health`,
Then the response is HTTP 200 with a JSON body naming the service and an `ok` status.

### Scenario 3: Test suite runs green

Given the venv is active,
When the developer runs the engine test suite,
Then the health test passes and the command exits 0.

### Scenario 4: The standard harness gate covers the engine

Given the scaffolded engine,
When the developer runs `.\init.ps1`,
Then it runs the Laravel and SPA checks plus the engine tests (creating the venv if missing), exits 0, and does not start a long-running server.

## Repository Research

### Files Inspected

- `feature_list.json` — selected feature and ordering.
- `PROGRESS.md` — next-ready features and past sessions.
- `AGENTS.md` — Windows/PowerShell rules; `.\init.ps1` startup path.
- `docs/technical-discovery.md` — engine responsibilities and the Laravel <-> engine boundary open question.
- `ARCHITECTURE.md` — runtime surfaces; the engine is listed as "planned".
- `CONSTRAINTS.md` — runtime and harness rules.
- `init.ps1` — current gate (Laravel + SPA).
- `docs/risks-and-open-questions.md` — data-source and boundary questions.

### Environment Findings (probed, not assumed)

- Python **3.10.6** at `C:\laragon\bin\python\python-3.10\python.exe`, on PATH; pip 26.1.1.
- No `uv` / `poetry`; the Windows `py` launcher exists.
- `pytest` is not installed globally (it belongs in the venv).
- `engine/` does not exist yet.

### Existing Patterns To Follow

- One directory per runtime surface; `frontend/` is the precedent for an isolated toolchain.
- PowerShell 5.1-compatible commands; on Windows call `.venv\Scripts\python.exe` directly instead of relying on venv activation.
- `init.ps1` is the non-blocking repo gate and must not start long-running servers.
- `alphapulse/` is a reference only and must not be touched.

### Current Gaps

- No Python project, no venv, no engine package, no tests.
- `ARCHITECTURE.md` lists the engine as planned but does not describe it.
- The Laravel <-> engine boundary was an open question; this spec resolves it as HTTP.

## Technical Approach

1. **Location and runtime: `engine/`**, targeting Python 3.10 (installed). Keep it isolated like `frontend/`.
2. **Service: FastAPI + uvicorn.** App factory in `engine/app/main.py` exposing `app`; `/health` returns `{"status": "ok", "service": "alphapulse-engine", "version": "<pkg version>"}`.
3. **Dependencies via venv + requirements files** (no uv/poetry): `engine/requirements.txt` (fastapi, uvicorn[standard]) and `engine/requirements-dev.txt` (`-r requirements.txt` + pytest, httpx, ruff). Pin exact versions in both files.
4. **Package layout.** `engine/app/` is the Python package rooted at `engine/` (distinct from Laravel's PHP `app/`); add `engine/app/__init__.py` and `engine/app/__main__.py` so `python -m app` runs uvicorn for local dev.
5. **Tests.** `engine/tests/test_health.py` uses FastAPI's `TestClient` to assert `/health` returns 200 and the expected JSON.
6. **Tooling config.** `engine/pyproject.toml` holds `[tool.pytest.ini_options]` and `[tool.ruff]` config (no packaging required).
7. **Ignore files.** `engine/.gitignore` ignores `.venv/`, `__pycache__/`, `.pytest_cache/`, `.ruff_cache/`.
8. **Extend `init.ps1`.** When `engine/requirements.txt` exists: create `engine/.venv` and install `requirements-dev.txt` if the venv is missing, then run the engine tests with the venv Python. Keep it non-blocking and never start the server.
9. **Record the boundary** in `ARCHITECTURE.md`: Laravel will call the engine over HTTP in later features.

## Expected File Changes

New `engine/` project:

- `engine/requirements.txt`, `engine/requirements-dev.txt` — create; pinned deps.
- `engine/pyproject.toml` — create; pytest + ruff config.
- `engine/app/__init__.py`, `engine/app/main.py`, `engine/app/__main__.py` — create; FastAPI app, `/health`, dev runner.
- `engine/tests/test_health.py` — create; health test.
- `engine/.gitignore` — create; ignore venv/caches.

Modified by the implementer:

- `init.ps1` — modify; add the engine test step to the gate.
- `ARCHITECTURE.md` — update; engine surface and the HTTP boundary.
- `CONSTRAINTS.md` — update; Python 3.10 / venv / engine rules.
- `AGENTS.md` — update; mention `engine/` and how to run/verify it.
- `PROGRESS.md` and `feature_list.json` — update with evidence.

Not touched: `alphapulse/`, `frontend/`, Laravel root files.

## Visual Design Impact

- UI involved: no. `DESIGN.md` is not applicable to this slice.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — add the Python engine runtime surface and state the Laravel -> engine HTTP boundary (replacing the "planned" note).
- `CONSTRAINTS.md`: update — MUST rules: engine lives in `engine/`, targets Python 3.10, uses venv + requirements files, is a FastAPI HTTP service; tests run via the repo gate.
- `AGENTS.md`: update — one line for `engine/` location and commands.
- `DESIGN.md`: not needed — no UI.
- Other docs: `PROGRESS.md` and `feature_list.json` — update with evidence.

## Key Implementation Risks

- **Windows venv invocation** — do not rely on `activate`; call `engine\.venv\Scripts\python.exe` directly. Run commands from `engine/` as the working directory so `app` imports resolve.
- **Python 3.10 compatibility** — confirm the chosen FastAPI/pydantic/uvicorn versions support 3.10 and pin them.
- **Gate weight** — creating a venv and installing deps the first time is slow; keep the gate to install-once + `pytest` and never start uvicorn.
- **Name collision** — `engine/app/` is a Python package, not Laravel's PHP `app/`; keep engine commands scoped to `engine/`.
- **Port choice** — pick a free port for the smoke (e.g. 8090) to avoid Laravel (8000) and the SPA (5173); always stop the server and its child process.

## Implementation Plan

1. Create `engine/` with the requirements files, `pyproject.toml` and `.gitignore`.
2. Implement the FastAPI app with `/health` and the `__main__` dev runner.
3. Add the health test.
4. Create the venv, install deps, run the tests.
5. Smoke the service: run uvicorn on a free port, `GET /health` returns 200 JSON, then stop it.
6. Extend `init.ps1` with the engine test step.
7. Update `ARCHITECTURE.md`, `CONSTRAINTS.md`, `AGENTS.md`, `PROGRESS.md`, `feature_list.json`.

## Implementation Tasks

- [ ] Create `engine/requirements.txt` and `engine/requirements-dev.txt` with pinned versions.
- [ ] Create `engine/pyproject.toml` (pytest + ruff) and `engine/.gitignore`.
- [ ] Implement `engine/app/main.py` (`/health`) and `engine/app/__main__.py`.
- [ ] Add `engine/tests/test_health.py`.
- [ ] Create `engine/.venv`, install `requirements-dev.txt`, run `python -m pytest -q` → pass.
- [ ] Smoke: run the service on a free port; `GET /health` → 200 JSON; stop the server/child.
- [ ] Extend `init.ps1` with the engine test step (venv create + install if missing).
- [ ] Update `ARCHITECTURE.md` and `CONSTRAINTS.md`; add the `engine/` line to `AGENTS.md`.
- [ ] Update `PROGRESS.md` and `feature_list.json`.

## Verification Plan

- `python -m venv engine/.venv` then `engine\.venv\Scripts\python.exe -m pip install -r engine/requirements-dev.txt` → completes.
- `engine\.venv\Scripts\python.exe -m pytest -q` (cwd `engine/`) → tests pass, exit 0.
- Dev smoke: start `engine\.venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8090` (cwd `engine/`), `Invoke-WebRequest http://127.0.0.1:8090/health` returns 200 with the expected JSON; stop the server and its child process and confirm the port is released.
- `.\init.ps1` → Laravel + SPA + engine tests, exit 0, no server started.
- Persistent E2E: none exists and there is no user flow; pytest + a health smoke are sufficient for a scaffold. Record that no E2E harness exists.
- Startup script rule: `init.ps1` executes the non-blocking gate and must not start long-running processes.

## Evidence To Capture

- `python --version` (3.10.6) and the pinned dependency versions.
- `pytest -q` output (tests pass, exit 0).
- `/health` smoke result (HTTP 200 + JSON body) and confirmation the server/child were stopped.
- `.\init.ps1` output showing Laravel + SPA + engine checks and exit 0.
- Confirmation that `alphapulse/`, `frontend/` and Laravel root files were not modified.

## Validator Checklist

- [ ] Implementation stays within this feature's scope (no scraping/indicators/signals/DB/Laravel integration).
- [ ] Acceptance scenarios pass.
- [ ] Verification evidence is present.
- [ ] Persistent E2E coverage was added/updated when the feature has an observable user/API flow and an E2E harness exists, or the spec explains why it is not needed.
- [ ] `feature_list.json` and `PROGRESS.md` were updated correctly.
- [ ] No unrelated product behavior or extra feature work was added.
- [ ] The engine lives in `engine/` as a FastAPI service on Python 3.10 with a venv + requirements files.
- [ ] `init.ps1` runs the engine tests and starts no server.
