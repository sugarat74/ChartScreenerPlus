# Feature Implementation Spec: Jev forecast and AI company card only for signed-in users

## Source Feature

- `id`: `premium-panels-auth-only`
- `area`: `frontend` (SPA presentation only; durable docs)
- `depends_on`: `pronostico-jev`, `ai-company-card`, `chart-interactive`, `candidate-list-ranking`, `auth-registration-login`, `app-multilanguage` (all `accepted`)
- `status`: `not_started` (planned 2026-10-15 on `origin/main` 012334a)
- `source`: `feature_list.json`

## Goal

User request (2026-10-15): "el pronóstico jev y el análisis con IA solo deben salir para gente logueada". Today both chart panels render for a Visitor with a sign-in link, and every Candidate row shows a "Ficha IA" link. After this feature a Visitor sees **none** of them, not even a sign-in invitation. Registered Users and Admins see them exactly as today. The API already returns `401` to guests and does not change.

## Non-Goals

- No change to any API route, middleware, throttle, payload or test of the Laravel side.
- No page-level auth gate, redirect or login prompt on `/` (Screener) or `/instruments/:ticker` (chart).
- No change to the watchlist "Seguir" toggle or the Saved Screeners panel (they keep their sign-in links).
- No new "premium" or paid tier, role or permission; "signed in" means any authenticated account.
- No change to the content or behaviour of the panels for signed-in users (feature `company-fundamentals-sec` changes the card later).

## Job Story

When I browse Chartiko without an account,
I want the screen to show only what I can use,
so I am not shown AI features that only work after signing in.

## Users And Permissions

- Visitor: Screener and chart work as today; no Jev panel, no Company Card panel, no "Ficha IA" row link, no sign-in text for them. API `401` unchanged.
- Registered User / Admin: unchanged (both panels and the row link).

## Acceptance Scenarios

### Scenario 1: Visitor on the chart
Given a Visitor (no session) on `/instruments/NVDA`
When the page has loaded
Then the chart, Signals and Patterns panels render; there is no element with the forecast title, no `#ai-company-card` section and no sign-in link from either panel; no request to `/forecast` or `/company-card` is made.

### Scenario 2: Visitor on the Screener
Given a Visitor on `/`
When the Candidate list renders
Then each row keeps the ticker link, exchange and company, but has no "Ficha IA"/"AI card" link; the page makes no request beyond today's (`GET /api/user` from `AuthProvider` and `GET /api/screener`).

### Scenario 3: Auth still resolving
Given `AuthProvider` status `loading`
When the chart page or the Screener renders
Then neither panel nor the row link is rendered (no flash), and they appear only if the status becomes `ready` with a user.

### Scenario 4: Signed-in user
Given a Registered User (or Admin)
When they open the Screener and a chart
Then the row link, the Jev panel and the Company Card panel render and behave exactly as before (existing Vitest cases for signed-in behaviour still pass unchanged apart from setup).

### Scenario 5: Deep link
Given the URL `/instruments/NVDA#ai-company-card`
When a Visitor opens it, the chart page works and nothing scrolls or errors;
When a signed-in user opens it, the page scrolls to the panel once it is rendered, also when the session resolves after the chart data.

### Scenario 6: Sign-out / sign-in while on the page
Given a signed-in user on a chart
When they sign out from the header
Then both panels disappear without a reload or navigation; signing in and returning shows them again.

### Scenario 7: API unchanged
Given a guest
When it calls `POST /api/instruments/NVDA/forecast`, `GET`/`POST /api/instruments/NVDA/company-card`
Then the responses are still `401` (existing feature tests stay green).

## Repository Research

### Files Inspected

- `frontend/src/components/chart/ForecastPanel.tsx:10-81` — `useAuth()` at `:11`; section always rendered; guest link at `:54-56`; form and results only for `user !== null`.
- `frontend/src/components/chart/CompanyCardPanel.tsx:41-296` — `useAuth()` at `:42`; section with title, explanation and warning always rendered (`:118-137`); guest link `:139-143`; reads only when `signedIn` (`:59-62`).
- `frontend/src/components/screener/CandidateTable.tsx:1-8,72-78` — docblock says the table has no `useAuth`; the "Ficha IA" link is unconditional.
- `frontend/src/components/screener/CandidateResults.tsx:134` — the only place that mounts `CandidateTable`.
- `frontend/src/pages/InstrumentChartPage.tsx:1-15,233-242,374-375` — page docblock (anonymous, auth-aware children); hash scroll effect depends only on `[panelsReady, hash]`; panel mounts.
- `frontend/src/pages/ScreenerPage.tsx:1-9` — "Anonymous by design — no `useAuth`".
- `frontend/src/auth/AuthContext.tsx:12-39` — the provider fetches `GET /api/user` once at app start (`main.tsx:12`) and exposes `{user, status}`; `frontend/src/auth/context.ts:78` exports `AuthContext` (default `null`); `useAuth.ts:8-14` throws outside a provider.
- `frontend/src/components/screener/SavedScreenersPanel.tsx:54` — precedent: an auth-aware component on the Screener already reads `useAuth`, allowed by `CONSTRAINTS.md:104`.
- `frontend/src/components/chart/PatternPanel.test.tsx:95` and `CompanyCardPanel.test.tsx:205-224` — render `CandidateTable` **without** an `AuthProvider`; `ForecastPanel.test.tsx:24-34` and `CompanyCardPanel.test.tsx:83-90` assert the guest sign-in link (must change).
- `frontend/src/i18n/messages/{es,en}.ts` — `forecast.signIn` (`en.ts:26`) and `companyCard.signIn` (`en.ts:56`) become unused.
- `CONSTRAINTS.md:48,59,104`, `ARCHITECTURE.md:192,196,219`, `DESIGN.md:137,141`, `docs/user-and-access-model.md:41-42`, `docs/specs/ai-company-card.md:35,42,79`, `specs/pronostico-jev/spec.md:21`, `specs/pronostico-jev/design.md:5` — wording that describes the Visitor sign-in link.
- `frontend/src/i18n/guide/{es,en}.ts` — the user guide does not mention either panel (no change needed).

### What CONSTRAINTS Actually Forbids

- `CONSTRAINTS.md:48`: the Screener "MUST stay anonymous: no `useAuth` **gate**, no authentication redirect and no login prompt". `:104` allows an auth-aware panel on `/` to call `useAuth` internally. Reading the session inside a sub-component to hide an affordance is therefore not a gate; it adds no request, redirect or prompt.
- `CONSTRAINTS.md:59`: the chart page itself must not gate on auth; the forecast and card panels "MAY read the session (via `useAuth` inside the component) and MUST offer a Visitor only a plain sign-in link". The last clause must change to "render nothing for a Visitor" (wording below).

### Existing Patterns To Follow

- Auth read inside the component, never in the page; `status === 'ready'` before deciding Visitor UI (`ForecastPanel.tsx:54`, `WatchlistButton.tsx:21`).
- Tests wrap components in `AuthContext.Provider` with a fake value (`ForecastPanel.test.tsx:24`).

## Technical Approach

1. **Non-throwing session hook.** Add `frontend/src/auth/useSignedIn.ts`: `useSignedIn(): boolean` = `useContext(AuthContext)`; returns `true` only when the context exists, `status === 'ready'` and `user !== null`; returns `false` when there is no provider (so presentational tests and any future provider-less render stay safe). It never fetches and never navigates. Document it as the read-only way for anonymous surfaces to hide signed-in affordances.
2. **Panels return `null` for Visitors.** In `ForecastPanel` and `CompanyCardPanel`, compute `signedIn` (`useSignedIn()` or `useAuth()` + same rule) and `return null` before rendering the section when not signed in. Keep hooks unconditional (call every hook before the early return) to respect the Rules of Hooks. Remove the guest `<Link>` branches and the `LOGIN_ROUTE` imports.
3. **Row link only for signed-in users.** `CandidateTable` stays presentational: new optional prop `showCompanyCardLink?: boolean` (default `false`); `CandidateResults` passes `showCompanyCardLink={useSignedIn()}`. Update the `CandidateTable` docblock (the Screener page still has no auth gate).
4. **Deep-link scroll moves into the panel.** Remove the scroll effect from `InstrumentChartPage` (`:233-242`) and add it to `CompanyCardPanel`: when the panel first renders signed-in and `useLocation().hash === '#ai-company-card'`, call `scrollIntoView({block: 'start'})` once (ref guard). Since the panel is mounted only in the page's ready branch (`:375`), this works whichever of chart data or session resolves first. For a Visitor nothing is rendered, so nothing scrolls. `COMPANY_CARD_ANCHOR` stays exported for the row link.
5. **Catalogs.** Delete `forecast.signIn` and `companyCard.signIn` from `es.ts`/`en.ts` (types enforce parity). Keep `companyCard.candidateLink*`.
6. **Docs** as listed below. No backend change.

## Expected File Changes

- `frontend/src/auth/useSignedIn.ts` — create.
- `frontend/src/components/chart/ForecastPanel.tsx`, `CompanyCardPanel.tsx` — modify (early `null`, scroll effect in the card panel).
- `frontend/src/components/screener/CandidateTable.tsx`, `CandidateResults.tsx` — modify (prop + hook).
- `frontend/src/pages/InstrumentChartPage.tsx` — modify (remove scroll effect, update docblock).
- `frontend/src/i18n/messages/{es,en}.ts` — remove two keys.
- Tests: `frontend/src/auth/useSignedIn.test.tsx` (create), `ForecastPanel.test.tsx`, `CompanyCardPanel.test.tsx`, `PatternPanel.test.tsx` (if the prop default changes its expectations), new `frontend/src/components/screener/CandidateResults.test.tsx` or extend the existing `CandidateTable` describe.
- Docs: `CONSTRAINTS.md`, `ARCHITECTURE.md`, `DESIGN.md`, `docs/user-and-access-model.md`, `docs/specs/ai-company-card.md` (note), `specs/pronostico-jev/spec.md` + `design.md` (note), `feature_list.json`, `PROGRESS.md`.

## Visual Design Impact

- UI involved: yes; source `DESIGN.md`. No new component or token. Visitor chart page ends after `PatternPanel`; Visitor Candidate rows lose one line of height. Signed-in UI unchanged.
- New design artifact required: no.

## Durable Documentation Impact

- `CONSTRAINTS.md:48` — append: "Auth-aware affordances inside the Screener (the Saved Screeners panel, the Candidate-row 'Ficha IA' link) **MAY** read the already bootstrapped session from `AuthContext` (`useAuth`/`useSignedIn` inside the component) but **MUST NOT** fetch, redirect or prompt."
- `CONSTRAINTS.md:59` — replace the panel sentence with: "the watchlist toggle offers a Visitor only a plain sign-in link; the Jev forecast panel and the AI Company Card panel **MUST** render nothing for a Visitor or while auth is loading (no sign-in prompt); never a redirect or modal." Keep the reason, adding "they are signed-in-only features (2026-10-15 decision)".
- `ARCHITECTURE.md` Screener UI (`:192`, "only auth-aware affordance" must also list the row link) and AI Company Card SPA bullet (`:219`) and Chart UI entry (`:196`): Visitors do not see the panels or the row link; the card panel owns the hash scroll.
- `DESIGN.md:137,141`: "Guests see a sign-in link" → "Not rendered for Visitors"; the row link is shown only to signed-in users.
- `docs/user-and-access-model.md:41-42`: replace "the chart offers a sign-in link" with "the panels are not shown to Visitors".
- `docs/specs/ai-company-card.md` decisions 2 / Users And Permissions and `specs/pronostico-jev/spec.md:21`, `design.md:5`: add a dated note "Superseded 2026-10-15 by `premium-panels-auth-only`: Visitors see no panel and no row link."
- `AGENTS.md`, `CONTEXT.md`: not needed.

## Implementation Plan

1. Add `useSignedIn` with tests (no provider → false; loading → false; ready+null → false; ready+user → true).
2. Change both panels (early `null`; scroll in card panel) and their tests.
3. Change `CandidateTable`/`CandidateResults` and tests; remove unused catalog keys.
4. Remove the page scroll effect; update docblocks.
5. Update docs; run gate; real-browser QA; record evidence.

## Implementation Tasks

- [ ] `useSignedIn.ts` + `useSignedIn.test.tsx`.
- [ ] `ForecastPanel`: hooks first, `if (!signedIn) return null`; drop guest link/`LOGIN_ROUTE`.
- [ ] `CompanyCardPanel`: same; add hash scroll effect with a once-guard; drop guest link.
- [ ] `InstrumentChartPage`: delete scroll effect and now-unused imports; docblock.
- [ ] `CandidateTable` prop `showCompanyCardLink`; `CandidateResults` passes `useSignedIn()`.
- [ ] Remove `forecast.signIn`, `companyCard.signIn` from both catalogs.
- [ ] Update tests: Visitor/loading render `container` empty and no API call (`forecastApi.predict`, `companyCardApi.get` not called); signed-in cases unchanged; row link absent for Visitor/loading/no provider, present for a user; scroll called once for a signed-in user with the hash (mock `scrollIntoView`), never for a Visitor.
- [ ] Docs listed above; `feature_list.json` evidence and `PROGRESS.md`.

## Verification Plan

- `npm --prefix frontend run test` — new/updated cases pass; `npm --prefix frontend run lint` (tsc + oxlint) and `build` clean.
- `php artisan test --filter="Forecast|CompanyCard"` — unchanged and green (guest `401`).
- `.\init.ps1` — exit 0.
- E2E: no persistent E2E harness exists (Vitest + PHPUnit only), as for `ai-company-card`; cover with the real-browser QA below.
- Real-browser QA (Playwright/Edge against `php artisan serve` + `npm --prefix frontend run dev`, throwaway SQLite DB with a few seeded bars, `AI_CARD_ENABLED=false` is fine):
  1. Visitor `/`: rows without "Ficha IA"; network shows no `/company-card` or `/forecast` call.
  2. Visitor `/instruments/AAPL` and `/instruments/AAPL#ai-company-card`: chart renders, no panels, no console errors, no scroll jump.
  3. Throttle the `/api/user` response (route delay) and confirm no panel flash during loading.
  4. Sign in: rows show the link; click → chart scrolls to the card panel; both panels render; sign out from the header → panels disappear without navigation.
  5. 390 px width: no horizontal overflow on both pages.
  6. `curl` guest `POST /api/instruments/AAPL/forecast` and `GET /api/instruments/AAPL/company-card` → `401`.

## Evidence To Capture

- Vitest totals and names of the Visitor/loading/no-provider/scroll tests; `init.ps1` totals.
- QA notes or screenshots for Visitor and signed-in states (both pages), plus the no-flash check.

## Risks

- **Rules of Hooks:** an early `return null` placed before a hook breaks React; tests in StrictMode catch it.
- **Scroll timing:** if the card panel renders before layout settles the scroll may land slightly off; acceptable (same as today).
- **Discoverability:** Visitors no longer learn that the features exist; the user explicitly chose this. The user guide could mention them later (not in scope).

## Validator Checklist

- [ ] No page-level `useAuth` in `ScreenerPage`/`InstrumentChartPage`; no redirect/modal/prompt added; no new network request.
- [ ] Visitor and loading states render neither panel nor row link; signed-in behaviour unchanged.
- [ ] Deep link works for both audiences, including session-after-chart ordering.
- [ ] Guest API still `401`; no backend diff.
- [ ] Catalog parity kept; no hardcoded copy.
- [ ] Docs updated as listed; `feature_list.json`/`PROGRESS.md` evidence; `.\init.ps1` passes.
