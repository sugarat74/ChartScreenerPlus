# Feature Implementation Spec: Registered-only options visible but disabled for Visitors (umbrella)

## Source Features

This umbrella spec covers four features, implemented in this order. Each one ships, is verified and is accepted on its own. All are `frontend` (SPA presentation only; no backend diff).

| Order | `id` | `area` | `depends_on` | Summary |
| --- | --- | --- | --- | --- |
| 1 | `guest-disabled-primitive` | frontend | `premium-panels-auth-only`, `auth-email-verification`, `app-multilanguage`, `ui-dark-mode` | Shared `RegisteredOnly` primitive, session-state hook, `next` return path on sign-in, catalog keys, doc updates |
| 2 | `guest-disabled-screener` | frontend | `guest-disabled-primitive`, `saved-screeners`, `ai-screener-natural-language`, `market-context-patterns` | Natural-language box, Saved Screeners, "Ficha IA" row link and sector-context filter shown disabled |
| 3 | `guest-disabled-chart` | frontend | `guest-disabled-primitive`, `pronostico-jev`, `ai-company-card`, `watchlist` | Jev forecast, AI Company Card, Watchlist toggle and Market Context strip shown disabled |
| 4 | `guest-disabled-nav` | frontend | `guest-disabled-primitive`, `market-context-ui`, `user-portal` | Header tabs «Mercado» and «Portal» as focusable disabled entries; `/mercado` and `/portal` entry behaviour |

- `status`: planned 2026-11-12 on `origin/main` 15a500c (planning only). `guest-disabled-primitive` accepted; `guest-disabled-screener` implemented 2026-11-12 (rows 1-4 of the inventory; evidence in `feature_list.json` and `PROGRESS.md`).
- `source`: `feature_list.json`. Owner request (2026-11-12): "quiero que las opciones que solo estén disponibles para usuarios registrados puedan verse pero deshabilitadas y que se activen una vez el usuario esta logueado".

## Goal

Today a Visitor either does not see a registered-only option at all (Jev forecast, AI Company Card, "Ficha IA" row link: owner decision 2026-10-15, `premium-panels-auth-only`) or sees an ad-hoc dashed sign-in box or sign-in link whose look and wording differ per surface. The owner now wants a Visitor to **see** every option that only a Registered User can use, **dimmed and disabled**, with a short reason and a way to sign in, and to see it **come alive** as soon as the session exists, without reloading.

This request supersedes, for presentation only, the 2026-10-15 "Visitors see nothing" decision. Which options are registered-only, the API `401`/`404` answers and every access rule stay as they are.

## Non-Goals

- No change to any API route, middleware, ability, throttle, payload or backend test. Hiding or disabling UI is not access control; the server keeps answering `401` (guest) and `404`/`403` as today.
- No new tier, role, permission or paid plan. "Registered" means any authenticated account whose email is confirmed when verification is required.
- No fake or sample data shown inside a disabled option (no mock regime, forecast or card): a disabled option shows its real static chrome (title, explanation, controls) and never numbers.
- No Admin-only option becomes visible: the «Admin» tab, Admin pages, Admin universe preview, Market Context Admin status stay hidden for everyone who is not an Admin.
- No page-level auth gate, redirect or modal on `/` or `/instruments/:ticker` (`CONSTRAINTS.md`: Screener and chart stay anonymous). No cross-tab session sync and no polling for a session created elsewhere (see Open Decisions).
- No new feature of the options themselves (no new filters, alerts, AI behaviour).

## Job Story

When I browse Chartiko without an account,
I want to see which tools exist and are reserved for registered users, dimmed and with a clear way to sign in,
so I understand what I get by registering, and the same controls just work once I am logged in.

## Users And Permissions

| Actor | Sees | Can use |
| --- | --- | --- |
| Visitor | Every registered-only option from the inventory below, dimmed, `aria-disabled`, with a one-line reason and a sign-in link | Nothing of them; activating one explains why (popover or inline note) and offers `/login?next=<here>` |
| Registered User, email pending confirmation | The existing "confirm your email" notice replaces every routed page except legal, Account and verify pages (`AppLayout`, D7). Header tabs outside the gate show the reason "Confirma tu email" | Nothing (API `verified` middleware answers 409/403 as today) |
| Registered User (verified) / Admin | The same options enabled, exactly as today | Everything as today |
| Admin-only surfaces | Admin only; hidden for others | Unchanged |

Backend enforcement (verified by reading `routes/api.php`): inside `auth:sanctum` and `verified` groups are `POST /instruments/{t}/forecast`, `GET`/`POST /instruments/{t}/company-card`, `POST /screener/interpret`, `GET`/`POST`/`DELETE /watchlist*`, `/alerts*`, `/notifications*`, `/screeners*`, and, under `can:view-market-context`, `/market-context`, `/market-context/history`, `/instruments/{t}/market-context` (404 without access). Account routes are `auth:sanctum` only. `/admin/*` is `auth:sanctum` + `admin` (guest 401, user 403). Public: `/screener`, `/universes`, `/instruments/{t}`, `/instruments/{t}/indicators`, `/markets/status`, `/legal`, auth and password routes.

## Domain Decisions

1. **Disabled means "would be enabled by logging in".** A Visitor sees the disabled state only for an option that sign-in (plus email confirmation) really unlocks. An option that stays unavailable after login (market rule `availability.forecast/company_card === false` for non-US stocks, `market_context` flag off, provider not configured, Admin-only) is **not** shown disabled; it stays hidden or shows today's "not available" wording. This avoids a promise the product cannot keep.
2. **Server is the only gate.** The primitive never calls an API, never decides authorization and never throws outside an `AuthProvider` (a render without a provider is a Visitor). The disabled state has no effect on the request path: a registered-only API is requested only when the option is `enabled`.
3. **Real control, disabled; not a fake.** The enabled and the disabled render share one component tree and the same dimensions so nothing shifts when the session appears. Fetching effects inside each component stay gated on the session as in `premium-panels-auth-only` (`useSignedIn`).
4. **Access for Market Context keeps its single seam** (`useMarketContextAccess`): `allowed` = enabled; `signIn` (Visitor while `MARKET_CONTEXT_PUBLIC=true`) = disabled with the sign-in reason; `hidden` (flag off, or a user without access) = not disabled-visible (see inventory rows 7-11); `loading` = shell in loading state. The new hook `useRegisteredOnly` is the seam for everything else and must not duplicate the market rules.
5. **Return path.** Sign-in links carry `?next=<current path + search + hash>`; `LoginPage` and `RegisterPage` navigate there after success (today they always go to `/`). `next` is accepted only if it is a relative path starting with a single `/`, not `//`, no scheme, not one of the auth routes (`/login`, `/register`, reset routes); otherwise `/`. Implemented once as `nextPath()` helpers in `frontend/src/auth/`. This also serves `RequireAuth` (a Visitor on `/portal` goes to `/login?next=/portal`) and fulfils `docs/user-and-access-model.md:84` ("preserve the attempted action when feasible"): the location and the URL query (Screener filters live in the URL) are preserved; typed text is not stored (disabled controls cannot take input).
6. **Activation without reload.** The primitive subscribes to `AuthContext` through the hook, so `login`, `register` and `setCurrentUser` re-render every `RegisteredOnly` on the page. In the same SPA the user signs in on `/login` and returns via `next`, so the page remounts enabled; where a login happens without navigation (none today) the controls still flip in place. Logout flips them back to disabled without navigation. A login in **another tab** does not reach this tab (the provider fetches `GET /api/user` once and there is no storage or focus listener); see Open Decision O6.
7. **Email-pending accounts.** `useEmailVerificationPending` is the seam: pending = `pending` state (disabled, reason "Confirma tu email para usar esto", link to the Account page where the resend lives, which is gate-exempt). Inside the gated shell no routed registered-only option renders at all, so the state matters only for the header tabs.
8. **One tab, one reason.** Tab labels and the headings of disabled panels stay readable at normal contrast; only controls are dimmed. The reason text is never dimmed.

## The shared primitive

Module `frontend/src/components/registered-only/` (names are the contract; internals free):

1. `useRegisteredOnly(): { state: 'loading' | 'guest' | 'pending' | 'enabled' }` in `frontend/src/auth/useRegisteredOnly.ts`. `enabled` = `useSignedIn()` and not `useEmailVerificationPending()`; `loading` = provider present and `status === 'loading'`; no provider = `guest`. Pure read of the bootstrapped session: no fetch, no navigation.
2. `<RegisteredOnly>` (inline controls: button, link, chip, tab, toolbar trigger, row link). Props: `children` (a single element, the real control), `reason?` (message key override), `variant?: 'popover' | 'inline'` (default `popover`), `tabbable?: boolean` (default `true`; `false` only for repeated per-row controls), `access?` (optional `MarketContextAccess` for the Market Context entries, mapping `signIn` to guest, `loading` to loading, `hidden` to render nothing, `allowed` to enabled). When `enabled` it renders `children` untouched (zero wrapper DOM, zero layout effect). When not, it clones the child with `aria-disabled="true"`, `aria-describedby` (the reason element), a dimmed class (`opacity-60 cursor-not-allowed`, no hover lift, the focus ring unchanged), and swallows `click`, `submit` and Enter/Space (`preventDefault`, `stopPropagation`), with `href`/`to` removed. The element is **not** given the native `disabled` attribute, so it stays focusable and hoverable.
3. `<RegisteredOnlyPanel>` (blocks: Jev panel, Company Card, Saved Screeners, NL box, Market Context strip). Same state logic; renders the real panel shell, a `fieldset`-like container with `aria-disabled="true"`, inner fields natively `disabled` (so a form does not add a dozen tab stops), a visible **inline reason row** (lock icon + text + sign-in link) at the position the old guest link occupied, and one focusable element: that link. `children` receives `{ disabled: boolean }` as a render prop so panels can pass `disabled` to their inputs and skip their effects.
4. Reason UI decision. Hover-only tooltips are rejected (not reachable by touch, not announced, disappear on focus loss). Blocks use the **inline reason row** (always visible, no interaction needed; works at 390 px). Compact controls use a **popover** opened by click, tap, Enter or Space on the disabled control (and shown briefly on hover/focus as a native `title` fallback only): `role="status"` non-modal bubble anchored to the control, containing the reason and a «Iniciar sesión» link (`/login?next=`) and a «Crear cuenta» link; closes on Escape, outside click or blur of the bubble; does not trap focus; clamped inside the viewport at 390 px. The reason is also always in the DOM as `sr-only` text referenced by `aria-describedby`, so a screen reader announces "button, dimmed, Disponible al iniciar sesión" without opening anything.
5. Reason copy (catalog keys `registeredOnly.*` in `es.ts`/`en.ts`, type-checked parity): `reason.guest` "Disponible al iniciar sesión" / "Sign in to use this"; `reason.pending` "Confirma tu email para usarlo" / "Confirm your email to use this"; `reason.unreleased` "Aún no disponible" / "Not available yet" (Market Context flag off); `signIn` "Iniciar sesión" / "Sign in"; `createAccount`; per-feature reasons may override (e.g. "Inicia sesión para generar la ficha").
6. Icon: a small inline SVG lock, `aria-hidden`, `currentColor`; meaning is also in text (never colour or icon alone).
7. Tokens: existing design tokens only (`bg-surface-container`, `border-outline-variant`, `text-on-surface-variant`); reason text uses full-contrast `text-on-surface-variant` outside the dimmed element (>= 4.5:1 in light and dark). No new colour.
8. Loading state: while auth is `loading` the shell renders in the disabled look with an empty reason (reserved height, `aria-busy`), so a signed-in user does not see a "sign in" prompt flash and nothing shifts.
9. Keyboard rules: Tab reaches the disabled inline control (it is not natively disabled); Enter/Space opens the popover; Escape closes it and returns focus to the control. Block panels expose exactly one tab stop (the sign-in link). Repeated per-row controls (`tabbable={false}`, `tabIndex={-1}`) are exempt from the tab order to avoid 25+ dead stops, stay clickable and readable by a screen reader's virtual cursor, and the same reason is offered once in the table caption/toolbar.
10. Tests: Vitest for the primitive and the hook (states table, no wrapper when enabled, handlers swallowed, `aria-disabled` + `aria-describedby`, popover open/close/Escape, no native `disabled`, no provider = guest, pending state, flips to enabled when the context changes without remount of the surrounding page).

## Inventory of registered-only options

Legend: **VD** = visible disabled (this spec), **H** = stays hidden, **L** = stays a real link/redirect. "Seam" is the code that implements the change; line numbers are from `origin/main` 15a500c.

| # | Option | Where | Visitor today | Proposed treatment | Click / focus | Enabled after login by | Seam (feature) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Natural-language Screener box | `ScreenerAiQuery.tsx` above the filter panel (`/`) | Dashed box: title, `IA` badge, `guestIntro`, sign-in link (`:120-141`); nothing while auth loads | **VD panel**: the real form (text field with the example placeholder, submit button, example chips) rendered dimmed, inner fields natively disabled, inline reason + sign-in link; the box keeps its height | Only the sign-in link is focusable; no request, no placeholder typing | `useRegisteredOnly` = enabled; same component, fields become active; the `provider_not_configured` notice (existing) shows only after a real submit, so a Visitor never sees "unavailable" | `ScreenerAiQuery` (`guest-disabled-screener`) |
| 2 | Saved Screeners panel | `SavedScreenersPanel.tsx` below the filter panel (`/`) | Right-aligned link «Inicia sesión para guardar» (`:187-196`); `h-4` spacer while loading | **VD panel**: the real `<details>` summary row «Screeners guardados» with lock + reason, not expandable (`aria-disabled`, `open` forced false), the "Guardar" action not rendered inside; same row height as the signed-in summary | Summary is the one focusable element: Enter/Space opens the popover with sign-in link | Row becomes the normal disclosure; list fetch (`GET /screeners`) starts only then | `SavedScreenersPanel` (`guest-disabled-screener`) |
| 3 | «Ficha IA» link in each Candidate row | `CandidateTable.tsx:203` via `CandidateResults.tsx:129` (`showCompanyCardLink`) | Not rendered (2026-10-15) | **VD inline**, `tabbable={false}`: same text and position as the enabled link, dimmed, lock icon; `href` removed. The reason is stated once above the table in the results toolbar line ("Ficha IA: disponible al iniciar sesión") when any row shows it | Click/tap: popover with sign-in link (`next` = current Screener URL with filters) | Link becomes `Link to /instruments/T#ai-company-card` | `CandidateTable` gets `companyCardLink: 'hidden' \| 'disabled' \| 'enabled'` replacing the boolean; `CandidateResults` maps `useRegisteredOnly` (`guest-disabled-screener`) |
| 4 | Sector-context filters (`sector_trend`, `sector_rs_rank_max`) | Toolbar trigger «Sector» in `ScreenerFilterPanel.tsx` (`showSector`, `:71`); editor `SectorEditor` | Visitor while `MARKET_CONTEXT_PUBLIC=true` (`access.status === 'signIn'`): trigger shown, editor shows `MarketSignInBox`. Flag off: no trigger | **VD inline** for `signIn`: the trigger stays, dimmed with lock; opening it shows the existing disabled editor (controls natively disabled) plus the reason row; a URL carrying sector keys keeps the existing "dropped" notice. **H** for flag off / user without access (today's behaviour: no promise the login cannot keep) | Trigger focusable; Enter opens the editor with the reason (replaces the dashed box with the shared reason row) | `useMarketContextAccess` becomes `allowed` -> existing code | `ScreenerFilterPanel`/`ScreenerCriteriaEditors` consume the primitive via `access` prop (`guest-disabled-screener`) |
| 5 | Jev forecast panel | `ForecastPanel.tsx` on `/instruments/:t` | Not rendered (`if (!signedIn) return null`) | **VD panel** (real title, `experimental` badge, explanation, disabled horizon select, minimum field and yellow action button), inline reason + link; no forecast request | One tab stop (the link) | Form active; `POST /forecast` only on submit as today | `ForecastPanel` (`guest-disabled-chart`). Not shown at all when `availability.forecast === false` (domain decision 1) |
| 6 | AI Company Card panel | `CompanyCardPanel.tsx`, `id="ai-company-card"` | Not rendered | **VD panel**: title, AI badge, the "AI-generated, unverified, not advice" warning, the explanation and a disabled «Generar ficha» button; no `GET /company-card`; reason row | One tab stop. A deep link `#ai-company-card` scrolls to the disabled panel for a Visitor too (the scroll effect no longer requires a session) | Panel starts its `GET` and normal states | `CompanyCardPanel` (`guest-disabled-chart`). Hidden when `availability.company_card === false` |
| 7 | Watchlist «Seguir» | `WatchlistButton.tsx` on `/instruments/:t` (stocks, not ETFs) | Primary-coloured **link** to `/login` (`:112-118`); disabled «Comprobando…» while loading | **VD inline**: the same button, same label and size, dimmed with lock; no longer a link | Popover with sign-in link and next = the chart URL (the click-to-login shortcut becomes one extra step; see O3) | Becomes the follow/unfollow toggle; membership `GET /watchlist` only then | `WatchlistButton` (`guest-disabled-chart`) |
| 8 | Market Context strip and pattern chips | `MarketContextStrip.tsx` / `useInstrumentMarketContext.ts` on a stock chart | `signIn`: dashed `MarketSignInBox`; `hidden`/`loading`: nothing | **VD panel** for `signIn`: the strip's real frame (labels «Sector», «Tendencia», «Régimen» with `—` placeholders, no figures) dimmed + shared reason row replacing `MarketSignInBox`; **H** for `hidden` | One tab stop | `allowed` -> request starts (`GET /instruments/{t}/market-context`) | `MarketContextStrip` (`guest-disabled-chart`) |
| 9 | «Mercado» header tab | `AppHeader.tsx:121-130`, `nav.ts` (`marketContext: true`) | Always shown as a non-focusable `<span aria-disabled>` with a `title` unless `allowed` | **VD inline** via the primitive with `access`: `signIn` -> reason "Disponible al iniciar sesión" with sign-in popover; `hidden` for a Visitor (flag off) or a user without access -> shown disabled with reason `unreleased` and **no** login link (login would not unlock it); `pending` -> "Confirma tu email". Now focusable and touch-operable (the current `title` is neither) | Focusable; Enter/tap opens the popover | `allowed` -> `NavLink` to `/mercado` | `AppHeader` (`guest-disabled-nav`) |
| 10 | `/mercado` page itself | `MarketPage.tsx` | `signIn`: `MarketSignInBox` page; `hidden`: "not available yet"; no request unless allowed | **L (unchanged)**: no placeholder skeleton of regime/sector/breadth (would be fake or empty chrome of five cards, inconsistent with the "no fake data" rule); the existing sign-in box is the disabled presentation, restyled with the shared reason row and `next=/mercado` | Link only | Page renders data | `MarketPage` (`guest-disabled-nav`) |
| 11 | History charts of `/mercado` | `HistoryPanel.tsx` | Visitor branch (`:100-120`) is reachable only after a mid-session 401 | **L (unchanged)**: reuse the shared reason row for visual parity; no new behaviour | Link | -- | `HistoryPanel` (`guest-disabled-nav`, copy only) |
| 12 | «Portal» header tab | `nav.ts` `/portal` (`nested`), `PortalPage.tsx` guarded by `RequireAuth` | Visible tab; click redirects to `/login` | **VD inline** for consistency with the Mercado tab (dimmed, lock, popover with sign-in); the route itself keeps the `RequireAuth` redirect, now to `/login?next=/portal` | Focusable; popover | `NavLink` | `AppHeader` + `RequireAuth` (`guest-disabled-nav`) |
| 13 | Notification bell and `/portal/notificaciones` | `AppHeader.tsx:75-77`, `NotificationsPage.tsx` | Bell hidden for Visitors and pending accounts; page redirects | **H**: a bell for someone who has no alerts is chrome noise and implies an inbox; the capability is advertised by the Portal tab and the Saved Screeners row | -- | -- | no change |
| 14 | Alerts (Saved Screener alert toggle, Watchlist alert, Watchlist pattern alert form, email digest preference) | `components/portal/*`, `Portal*` pages | Exist only inside `/portal` and `/portal/cuenta`, both Registered-only pages; a Visitor never reaches them | **H**: no isolated alert control exists on a public page; the whole page is the gate (row 12) | -- | -- | no change |
| 15 | Account | `/portal/cuenta`, header name chip | Chip only when signed in; page redirects a Visitor | **H** (the «Iniciar sesión» button already occupies the slot) | -- | -- | no change |
| 16 | Admin tab and pages, Market Context Admin status, Admin universe preview (`/universes` unreleased slugs) | `NAV_ITEMS` `adminOnly`, `AdminLayout`, `ScreenerPage:117` | Hidden for non-admins | **H** (Admin-only is not registered-only; a user could never unlock it) | -- | -- | no change |
| 17 | Screener columns, sorts, column filters, patterns, indicators toggle, universe selector (public universes), market-hours strip, How it works | `ScreenerColumn*`, `ScreenerSortControl`, `UniverseSelector`, ... | Fully usable by a Visitor (verified: none call `useAuth`, all backed by public `GET /screener`, `/universes`, `/markets/status`) | **Out of scope**: not registered-only | -- | -- | no change |
| 18 | Stock comparison and the chart (candles, indicators, Signals, Patterns) | `chart-interactive`, `stock-comparison` | Public | **Out of scope** | -- | -- | no change |

Method note: the table was built from `routes/api.php` (every route behind `auth:sanctum`, `verified`, `can:view-market-context`, `admin`) cross-checked with every `useAuth`, `useSignedIn`, `useMarketContextAccess`, `LOGIN_ROUTE` and `RequireAuth` reference in `frontend/src` (grep, 2026-11-12). A new registered-only control added later must use the primitive; `useRegisteredOnly` is documented in `CONSTRAINTS.md` as the only allowed way to render one for a Visitor.

## Rules (all features)

1. **No request by a Visitor to a registered-only API.** A disabled option performs no `GET`/`POST` to `/forecast`, `/company-card`, `/screener/interpret`, `/screeners`, `/watchlist`, `/alerts`, `/notifications`, `/market-context*`. Network noise (a `401`) is a bug. The only requests on a Visitor page remain `GET /api/user` (AuthProvider), the public page requests and `GET /api/legal` (Market Context flag).
2. **Server-side authorization untouched**; the existing guest-`401` feature tests stay green with no backend diff.
3. **No layout shift at login.** The disabled shell and the enabled shell have the same box; the sign-in reason row occupies the slot of the enabled affordances' first line or the old guest link; the loading state reserves it. Measured in the browser pass (compare bounding boxes).
4. **Activation without reload** (domain decision 6) and **deactivation on logout** without navigation.
5. **Page-level rules of `CONSTRAINTS.md` hold**: no auth gate, redirect, modal or login prompt on `/` or the chart; a popover opened by the user's own click is not a prompt.
6. **i18n es/en** for every string through catalogs; no hardcoded copy. **Light and dark** with the existing tokens; reason text contrast >= 4.5:1. **390 px**: no horizontal overflow; popover and reason rows wrap and stay inside the viewport; touch targets >= 44 px height for the popover trigger rows where the existing control already meets it.
7. **Accessibility**: `aria-disabled` (not `disabled`) on inline controls; `aria-describedby` to a reason; visible focus ring unchanged; the popover is `role="status"`, Escape closes, no focus trap; `prefers-reduced-motion` respected (no animation required).
8. **Admin-only stays hidden**; nothing is shown disabled that login cannot unlock (domain decision 1).

## Feature Specs

### Feature 1: `guest-disabled-primitive`

Scope: the primitive, the hook, `next` return path, catalog keys, the supersession edits in the durable docs, no visible surface changes except a test page-free dev usage (none). It adds `RegisteredOnly`, `RegisteredOnlyPanel`, `useRegisteredOnly`, `nextPath` helpers, `LoginPage`/`RegisterPage`/`RequireAuth`/`RedirectSignedIn` handling of `next`, and removes nothing yet.

Acceptance scenarios:

1. Given no `AuthProvider`, loading, Visitor, pending and signed-in contexts, `useRegisteredOnly` returns `guest`, `loading`, `guest`, `pending`, `enabled` (and `guest` after logout).
2. Given `<RegisteredOnly><button/></RegisteredOnly>` for a Visitor, the button has `aria-disabled="true"`, no `disabled` attribute, is in the tab order, `aria-describedby` resolves to the reason text; clicking or pressing Enter/Space does not call its `onClick` or submit its form and opens the popover with a sign-in link whose `href` is `/login?next=<encoded current location>`; Escape closes it and focus returns to the button.
3. Given the signed-in state, the child renders with no wrapper element and unchanged props.
4. Given `loading`, the shell is dimmed with an empty reason and no sign-in link.
5. Given `/login?next=/instruments/NVDA%23ai-company-card`, signing in navigates to `/instruments/NVDA#ai-company-card`; `next=//evil.com`, `next=https://x`, `next=/login` and `next=javascript:` fall back to `/`.
6. A Visitor opening `/portal` is redirected to `/login?next=/portal`; after login the Portal opens.
7. The catalogs have the new keys in both languages (type parity) and no existing key is removed.

Verification: Vitest as listed in "The shared primitive" 10 and scenarios 1-7; `lint`, `build`; `.\init.ps1`; browser: a Visitor sees no visual change yet (primitive unused), `/login?next=` round trip works at 390 and 1440 px.

### Feature 2: `guest-disabled-screener`

Scope: rows 1-4 of the inventory on `/`.

Acceptance scenarios:

1. Visitor on `/`: the natural-language box, the Saved Screeners row and, in each Candidate row, the «Ficha IA» item are visible and dimmed with the reason; none can be activated; clicking the row item or the Saved Screeners summary opens the sign-in popover; the box shows one tab stop (the link). `GET /api/screener` and `GET /api/user` are the only API calls; no `/screeners`, `/screener/interpret` or `/company-card` request is made.
2. Visitor with `MARKET_CONTEXT_PUBLIC=true`: the «Sector» trigger is visible dimmed; its editor is read-only with the reason; with the flag off it is absent. A URL with sector keys keeps today's "dropped" notice.
3. Log in from the popover link: after the round trip to `/login?next=<the same Screener URL>` the user returns to the same filters, and all four options are active (box accepts text, Saved Screeners opens, rows link to `#ai-company-card`, sector editor works). Logging out flips them back without navigation.
4. Auth `loading`: the shells keep their height, no "sign in" text flashes for a signed-in user, Candidate rows show no flash of enabled/disabled link (the link is disabled until `ready`).
5. Existing signed-in tests pass unchanged apart from setup; the guest tests for the old sign-in links and the hidden row link are updated to the new states.

Verification: Vitest (the four components in guest, loading, pending, signed-in states; no-request assertions by mocking `screenerApi.interpret`, `savedScreenersApi`, `companyCardApi`); browser pass at 390 and 1440 px, light and dark, `es` and `en`, as a Visitor then logging in and out in the same SPA, with the network list captured to prove rule 1, and bounding boxes compared before and after login to prove no layout shift.

### Feature 3: `guest-disabled-chart`

Scope: rows 5-8 on `/instruments/:ticker`.

Acceptance scenarios:

1. Visitor on a US stock: the Jev panel, the Company Card and the Market Context strip (flag on) are rendered disabled below/above the chart in their normal positions; «Seguir» is a dimmed button. No `/forecast`, `/company-card`, `/watchlist`, `/market-context` request. Non-US stock or ETF: panels hidden as today (domain decision 1; `availability` rules unchanged).
2. Deep link `#ai-company-card`: scrolls to the disabled panel for a Visitor and to the live panel for a signed-in user, including when the session resolves after the chart data (existing ref-guarded effect is moved off the `signedIn` condition for the scroll only).
3. Log in from any reason row (`next` = the chart URL and hash): the panels become usable; `GET /company-card` and `GET /watchlist` start only then; the chart does not re-fetch or re-render its data and the scroll position is unchanged.
4. Sign out on the chart: panels dim again without navigation; an in-flight card request is ignored (existing generation guard).
5. Layout: the disabled panels have the same bounding boxes as the signed-in empty states (Company Card before generation, Jev before submit).

Verification: Vitest (`ForecastPanel`, `CompanyCardPanel`, `WatchlistButton`, `MarketContextStrip`, chart page with each auth state; the old guest-link and `return null` expectations change); the browser pass of Feature 2 on a stock, an ETF and a Spanish stock (hidden panels), plus `curl` guest `401` for the three POST/GET routes.

### Feature 4: `guest-disabled-nav`

Scope: rows 9-12.

Acceptance scenarios:

1. Visitor: «Mercado» and «Portal» tabs are focusable `aria-disabled` entries with a popover; «Mercado» shows `unreleased` and no login link when the flag is off; a signed-in user without access sees `unreleased` (as today's `marketUnavailable` title); a pending account sees the confirm-email reason with a link to the Account page.
2. Signing in flips the tabs to real links without reload; the active-tab state and the 390 px horizontal scroll of the nav row are unchanged.
3. `/mercado` as a Visitor shows the sign-in box with `next=/mercado`; as `hidden` the "not available yet" card; no request. `/portal` as a Visitor redirects to `/login?next=/portal`.
4. The tab `title` hint is kept as an extra for mouse users but is never the only channel.

Verification: Vitest for `AppHeader` (guest, pending, user, admin, flag on/off via mocked legal info), `MarketPage`, `RequireAuth`; browser pass as above including keyboard-only (Tab, Enter, Escape) and a screen-reader name check via the accessibility tree (`aria-disabled`, description).

## Visual Design Impact

UI involved: yes; source `DESIGN.md` and `alphapulse/` for tone. One new reusable pattern (disabled option with reason). The dashed border box (`border-dashed border-outline-variant bg-surface-container`) already used for sign-in boxes stays as the container language so the surfaces look related; `DESIGN.md` gets a "Registered-only options" paragraph in Feature 1. No new colour, shadow or font. Disabled = `opacity-60`, no hover lift, `cursor-not-allowed`, lock icon, reason in normal contrast.

## Durable Documentation Impact

Done in this planning PR (minimal, dated, flagged "Superseded 2026-11-12"): the statements that say Visitors must not see the Jev forecast, the Company Card or the row link (`docs/specs/premium-panels-auth-only.md`, `CONSTRAINTS.md`, `DESIGN.md`, `ARCHITECTURE.md`, `docs/user-and-access-model.md`). The supersession is **planned, not yet implemented**: until the features ship, the product still hides them. Feature 1 rewrites those sentences to the new behaviour; Features 2-4 update their surface entries (`DESIGN.md` Screener/Chart/Nav, `ARCHITECTURE.md` SPA bullets, `docs/user-and-access-model.md` capability table: Visitor column "no (disabled, sign-in link)").

## Decisions (owner) with recommended defaults

**Resolved 2026-11-12:** the owner accepted every recommended default below (O1 to O11) ("respondo si a todas las sugerencias"); none is open.

- **O1. Hidden panels become visible-disabled (Jev, Company Card, "Ficha IA").** The request implies yes and supersedes 2026-10-15. Default: yes, as specified (this is the request).
- **O2. Popover vs inline reason.** Default: inline reason row for panels, popover for compact controls, never hover-only tooltips.
- **O3. Watchlist «Seguir» for a Visitor: dimmed button + popover (one extra step) or keep the direct link to `/login`?** Default: dimmed button + popover (consistent with "visible but disabled"); the popover's primary link is one tap.
- **O4. Per-row «Ficha IA» disabled items out of the tab order (`tabbable={false}`) with the reason stated once above the table.** Default: yes, to avoid dozens of dead tab stops.
- **O5. Portal tab for Visitors: disabled with popover, or keep the plain link that redirects to sign-in.** Default: disabled with popover for consistency; the route guard stays.
- **O6. Login in another tab.** Default: not synced (the provider fetches once); the controls activate on reload or the next navigation that remounts the provider. An optional revalidation of `GET /api/user` on `visibilitychange` can be a later feature; it adds a request per tab focus and was not requested.
- **O7. Market Context while `MARKET_CONTEXT_PUBLIC=false`: Visitors see «Mercado» disabled ("Aún no disponible", no login link) and nothing else (no strip, no sector trigger).** Default: yes; showing the strip or filters disabled would advertise an unreleased feature that login cannot unlock. Alternative: hide the tab too.
- **O8. `/mercado` page for Visitors: keep the sign-in box (default) or build a static marketing placeholder of the dashboard.** Default: keep the box (no fake data).
- **O9. Notification bell and Account chip stay hidden for Visitors.** Default: hidden.
- **O10. Email-pending accounts see the existing confirm-email notice everywhere, and disabled-with-"confirm your email" only in the header tabs.** Default: yes (no change to the gate).
- **O11. After login, return to the page the user came from (`next`) instead of `/`.** Default: yes (also fixes today's always-home behaviour; same-origin relative paths only).

## Risks

- **Promise mismatch:** showing a disabled option login cannot unlock (non-US stock, flag off, AI provider not configured). Mitigated by domain decision 1 and rows 4, 8 and 9; the AI provider state is only known after a real attempt, so a signed-in user may still get the existing "not available" notice.
- **Dead tab stops and noise:** mitigated by one tab stop per panel and `tabbable={false}` rows.
- **Accidental request:** a registered-only component mounted unconditionally fetches by habit. Mitigated by gating every effect on `state === 'enabled'` and the no-request Vitest assertions plus the browser network capture.
- **Open redirect through `next`:** mitigated by the strict relative-path validator with tests.
- **Test churn:** guest-link and hidden-link assertions from `premium-panels-auth-only` change on purpose.
- **Copy drift** between old sign-in strings and new reasons: old keys that become unused are removed in the feature that stops using them (type parity enforces).

## Validator Checklist

- [ ] No backend diff; guest `401` and `404` tests unchanged and green.
- [ ] Visitor: every inventory row marked VD renders dimmed, `aria-disabled`, with reason and sign-in link, and makes no registered-only request (network capture attached).
- [ ] Admin-only and login-unlockable-only rules respected (rows 13-16 hidden; flag-off Market Context not promised).
- [ ] Login and logout in the same SPA flip every option without reload or layout shift; `next` round trip returns to the same URL; unsafe `next` falls back to `/`.
- [ ] Keyboard (Tab, Enter, Space, Escape), touch at 390 px and screen-reader name/description verified; contrast of reason text in light and dark.
- [ ] `es` and `en` catalogs in parity; no hardcoded copy; no horizontal overflow at 390 px.
- [ ] Durable docs updated as listed; `feature_list.json` and `PROGRESS.md` evidence; `.\init.ps1` passes.
