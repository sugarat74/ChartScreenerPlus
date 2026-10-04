# Chartiko identity

- Product name: **Chartiko**; public wordmark: **CHARTIKO**.
- Production domain supplied by owner: `https://www.chartiko.com`.
- Logo: angular C and chart stroke in the established ink/yellow palette. Header
  and browser icon share `frontend/public/favicon.svg`; header links to Screener.
- Keep existing typography, warm surfaces, borders and hard shadows from DESIGN.md.
- Public labels omit EOD/end-of-day. Admin keeps its operational EOD wording.
  Session dates, closing prices and the data/scheduling contracts remain unchanged.
- Browser title, application metadata, README, current product docs and deployment
  environment examples use Chartiko. Historical specs/session logs and the prototype
  keep their historical references. VPS paths, service IDs and health IDs remain
  compatible with the existing deployment.

## Deployment handoff

Run `.\init.ps1`, publish the resulting frontend build via the configured release
workflow, and verify the root/Screener, chart, login, Portal and Admin. Public routes
must show Chartiko and no EOD labels; Admin must retain EOD. Verify favicon and
header logo match and stay readable on desktop/mobile, including keyboard focus.

Update shared environment branding to APP_NAME=Chartiko and production APP_URL/
Sanctum domains only with VPS access. Preserve the current SESSION_COOKIE explicitly
when changing APP_NAME to avoid unnecessary session invalidation. Existing service
unit descriptions can update during deployment; service names and directories stay.

These repository changes do not prove publication. Record the deployed release and
live verification after publishing; do not treat the owner's announcement of the
domain as proof that this particular build is online.
