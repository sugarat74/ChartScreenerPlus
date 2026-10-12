# Feature Implementation Spec: Alerts on chartist patterns

## Source Feature

- `id`: `alerts-patterns`
- `area`: `backend` + `frontend`
- `depends_on`: `alerts-engine`, `alerts-ui`, `chart-patterns-detect` (`alerts-ui` not started yet)
- `status`: `not_started` (planned 2026-10-12, deferred until patterns are accepted — they now are)
- `source`: `feature_list.json`

## Goal

Let a Registered User be told when a chartist pattern **appears** on an Instrument of their Watchlist or **becomes confirmed** (breakout), for the pattern types they choose. Screener alerts already cover patterns indirectly: a Saved Screener with a pattern filter notifies new Candidates through `screener_new_candidates`.

## Non-Goals

- No new alert channel (in-app only here; email is `alerts-email-digest`).
- No alerts on pattern invalidation or disappearance, no price-level alerts.
- No change to pattern detection (calibration is `chart-patterns-calibration`).

## Design

1. **Alert rule.** Extend the existing `watchlist_signal` alert with optional `pattern_types` (subset of the four types) and `pattern_event` (`appears` | `confirmed`, default `confirmed`), or add a kind `watchlist_pattern` with those fields. Decision at implementation time; prefer the separate kind if it keeps validation and the UI simpler. Limits and ownership rules of `alerts-engine` apply unchanged.
2. **State and diff.** `last_state` stores, per followed ticker, the active pattern types with their status. Notify:
   - `appears`: a chosen type that was not active before;
   - `confirmed`: a chosen type whose status is `confirmed` now and was not confirmed (or absent) before.
   Baseline-without-notify, once per as-of date and the 50-item cap work as today.
3. **Notification items** use `reason` = `pattern:<type>:<event>` (e.g. `pattern:double_bottom:confirmed`) and include the `breakout_level` for the chart link; the SPA renders the text.
4. **UI** (`alerts-ui` must exist): the Watchlist alert form gets a "Patrones" group with the four type chips and the appears/confirmed choice; notification rows show the pattern label and status and link to the chart, where the pattern is drawn.
5. **Pipeline.** No change: `alerts:evaluate` already runs after `patterns:detect` and `screener:refresh`.

## Acceptance

- Appears and confirmed events notify once; a pattern that stays active does not notify again; disappearance never notifies.
- Validation: unknown types or events `422`; ownership and limits as in `alerts-engine`.
- es/en texts; tests for state diff, baseline, cap, API validation and UI form; `.\init.ps1` and CI pass.

## Durable Docs

- `ARCHITECTURE.md` and `CONSTRAINTS.md` (Alerts sections), `docs/specs/alerts-engine.md` findings, privacy texts unchanged (same processing).
