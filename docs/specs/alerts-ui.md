# Feature Implementation Spec: Alerts — Portal configuration and notification inbox (UI)

## Source Feature

- `id`: `alerts-ui`
- `area`: `frontend`
- `depends_on`: `alerts-engine`, `user-portal`, `app-shell-navigation`, `app-multilanguage`
- `status`: `not_started` (planned 2026-10-10)
- `source`: `feature_list.json`

## Goal

Give Registered Users the screens for the Alerts built in `alerts-engine`: turn on "notify me of new Candidates" for each Saved Screener, choose which Signal types to be told about for the Watchlist, pause/delete Alerts, and read Notifications from a header bell with an unread count and an inbox page. Spanish and English.

## Non-Goals

- No backend changes beyond what `alerts-engine` exposes (if a field is missing, stop and amend that spec).
- No email preferences (that is `alerts-email-digest`), no browser push, no sounds.
- No live updates (WebSockets/polling faster than page navigation): the unread count refreshes on navigation and on a manual refresh.

## Job Story

When I log in after the daily update,
I want to see at a glance whether my alerts fired and what changed,
so I can open the relevant charts straight away.

## Users And Permissions

- Registered User/Admin: their own Alerts and Notifications only. The UI hides the bell for Visitors; the API enforces `401`/`404`.

## Acceptance Scenarios

### Scenario 1: Enable an alert on a Saved Screener
Given I have a Saved Screener "Breakouts",
When I toggle "Avisarme de nuevos Candidates" in the Portal,
Then `POST /api/alerts` is sent with that screener and the toggle shows "activa"; toggling off pauses it (`PATCH active=false`); deleting the screener removes the toggle.

### Scenario 2: Watchlist signal alert
When I open "Alertas de mi Watchlist", select `golden_cross` and `pivot_breakout_rvol` and save,
Then one `watchlist_signal` Alert exists with those types; with none selected the save button is disabled.

### Scenario 3: Bell and inbox
Given 3 unread notifications,
Then the header shows a bell with "3" (accessible name "Notificaciones, 3 sin leer"); `/portal/notificaciones` lists them newest first with date, kind, screener name and tickers linking to `/instruments/{ticker}`; "Marcar todas como leídas" clears the count.

### Scenario 4: Limits and errors
Creating an 11th Alert shows the API `422` message inline; network errors show the existing retry pattern; an empty inbox shows a helpful empty state explaining when alerts are evaluated (after the daily update, without the word "EOD").

### Scenario 5: Visitors, languages, mobile
Visitors see no bell and get the login prompt on `/portal/notificaciones`; English labels everywhere; at 390 px no horizontal overflow and the bell stays reachable.

## Repository Research

### Files Inspected

- `frontend/src/pages/PortalPage.tsx`, `frontend/src/components/portal/PortalSavedScreeners.tsx`, `frontend/src/components/watchlist/WatchlistTable.tsx` — Portal sections, RequireAuth, error/retry pattern.
- `frontend/src/components/AppHeader.tsx` — user pill, language selector, sign-out (bell goes next to the user pill).
- `frontend/src/lib/api.ts` — `request`, `ensureCsrfCookie`, typed API objects.
- `frontend/src/router.tsx`, `frontend/src/nav.ts`, `frontend/src/components/RouteMetadata.tsx` — routes and metadata (noindex for private routes).
- `frontend/src/i18n/messages/*.ts`, `DESIGN.md`.

### Current Gaps

- No alerts/notifications API client, no bell, no inbox route, no alert controls.

## Technical Approach

1. `alertsApi` and `notificationsApi` in `frontend/src/lib/api.ts` (types mirror the `alerts-engine` API; writes call `ensureCsrfCookie`).
2. `useUnreadNotifications()` hook: fetches `unread_count` for signed-in users on mount and route change; exposes `refresh()`.
3. `NotificationBell` in `AppHeader` (button-link to the inbox; count badge with text, capped "9+").
4. Portal: an "Alertas" section — per Saved Screener toggle (in `PortalSavedScreeners`) and a `WatchlistAlertForm` (checkbox list of the 9 Signal types with existing labels, pause/delete).
5. Route `/portal/notificaciones` → `NotificationsPage` (paginated list, mark one/all read, ticker links), metadata `noindex`.
6. i18n strings es/en; reuse Signal labels.

## Expected File Changes

- Create: `frontend/src/components/NotificationBell.tsx`, `frontend/src/hooks/useUnreadNotifications.ts` (or under `frontend/src/lib/`), `frontend/src/components/portal/WatchlistAlertForm.tsx`, `frontend/src/pages/NotificationsPage.tsx`, tests for each.
- Modify: `frontend/src/lib/api.ts`, `frontend/src/components/AppHeader.tsx`, `frontend/src/components/portal/PortalSavedScreeners.tsx`, `frontend/src/pages/PortalPage.tsx`, `frontend/src/router.tsx`, `frontend/src/nav.ts`, `frontend/src/components/RouteMetadata.tsx`, `frontend/src/i18n/messages/{es,en}.ts`.

## Visual Design Impact

- UI involved: yes; source `DESIGN.md`.
- States: bell (no unread / unread / 9+), toggle (off/active/paused/saving/error), watchlist form (none selected/disabled save/saved), inbox (loading/empty/list/error/all read), mobile header.
- New design artifact: no; reuse header pill, chips, cards and table styles.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — SPA routes (`/portal/notificaciones`) and header bell.
- `CONSTRAINTS.md`: not needed unless a new rule emerges (the API rules live in `alerts-engine`).
- `AGENTS.md`: not needed.

## Implementation Plan

1. API clients + hook. 2. Bell in header. 3. Portal toggles and watchlist form. 4. Inbox page + route + metadata. 5. i18n, tests, browser QA.

## Implementation Tasks

- [ ] Typed clients for alerts/notifications.
- [ ] Unread-count hook and bell with accessible name.
- [ ] Saved Screener alert toggle with pause/delete.
- [ ] Watchlist signal-type alert form.
- [ ] Inbox page with pagination and mark-as-read.
- [ ] es/en strings, Vitest tests, browser QA evidence.

## Verification Plan

- `npm --prefix frontend run test -- --run`, `lint`, `build`; `.\init.ps1` exit 0.
- Real browser (Playwright + Edge, local dev, QA user): create both alert kinds, run `php artisan alerts:evaluate` after seeding a new as-of, see the bell count, open the inbox, follow a ticker link, mark all read; es/en; 390 px; Visitor sees no bell.

## Evidence To Capture

- Vitest names, screenshots (Portal alerts, bell with count, inbox), Playwright results, `init.ps1`.

## Key Implementation Risks

- Stale unread counts (acceptable: refresh on navigation).
- Header crowding on mobile: icon-only bell with accessible name.
- Wording that implies advice or real-time data.

## Validator Checklist

- [ ] All scenarios pass in a real browser; Visitor has no bell; ownership respected via API.
- [ ] es/en complete; DESIGN.md states covered; no EOD wording; mobile OK.
- [ ] No backend changes outside `alerts-engine`'s contract; tests, `init.ps1`, docs, evidence updated.
