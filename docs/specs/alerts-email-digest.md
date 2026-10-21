# Feature Implementation Spec: Alerts — daily email digest

## Source Feature

- `id`: `alerts-email-digest`
- `area`: `backend` + `ops`
- `depends_on`: `alerts-engine`, `alerts-ui`, `legal-compliance-eu`
- `status`: implemented 2026-10-21 at the user's request ("ok realiza alerts-email-digest"). Shipped **off**: production sending still waits for the owner's mailbox and SMTP/API provider, and is enabled by configuration only (see Implementation Findings).
- `source`: `feature_list.json`

## Goal

Registered Users who opt in receive **one email per day** summarizing the Notifications created by that day's `alerts:evaluate`, with links to the inbox and charts, and a one-click unsubscribe. Sending is queued, provider-agnostic (Laravel mail with SMTP settings from env) and never blocks the pipeline.

## Non-Goals

- No marketing or newsletter emails; no tracking pixels or click tracking.
- No per-alert instant emails, push or SMS.
- No password-reset/verification emails (separate concern).
- No choice of provider in code: configuration only.

## Job Story

When my alerts fire after the daily update,
I want a short email listing them,
so I notice relevant setups even if I don't open Chartiko that day.

## Users And Permissions

- Registered User: opt in/out from the Portal (default **off**); unsubscribe link works without signing in (signed URL).
- Admin: no access to other users' preferences.

## Domain Decisions

1. **Preference** on `users`: `alert_email_opt_in_at` (nullable timestamp). Opt-in is an explicit Portal action (checkbox, not pre-ticked) recording the time.
2. **Digest unit**: one email per user per `as_of`, sent after `alerts:evaluate`, containing that run's notifications for the user (max 50 items, rest summarized). Idempotent via `alert_email_digests(user_id, as_of, sent_at)` unique `(user_id, as_of)`.
3. **Transport**: `Mail` + `ShouldQueue` mailable on the `database` queue; failures retried 3 times then logged (no pipeline failure). From address `MAIL_FROM_ADDRESS` (e.g. `alertas@chartiko.com`), `MAIL_MAILER=smtp` in production; `log` locally.
4. **Unsubscribe**: signed, expiring-never URL `GET /email/alerts/unsubscribe/{user}` (signature required) clears the opt-in and shows a confirmation page; `List-Unsubscribe` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click` headers (RFC 8058) point to a signed POST endpoint.
5. **Content**: language = user's last chosen locale (store `preferred_locale` on opt-in from Accept-Language), plain + HTML views, no EOD wording, footer disclaimer (not investment advice), link to privacy policy.
6. **Legal**: these are service notifications requested by the user, not commercial communications; privacy policy adds the processing (email address, digest content, provider as processor, retention of `alert_email_digests` 90 days). Provider DPA and transfer location must be recorded before go-live.

## Acceptance Scenarios

1. Opted-in user with 2 notifications for today's as-of → exactly one queued email listing both; re-running sends nothing more.
2. User not opted in → no email; opt-in checkbox starts unticked.
3. Unsubscribe link (valid signature) → opt-in cleared, confirmation shown in the user's language; tampered signature → `403`; one-click POST works without a session.
4. Mail transport failure → pipeline still exits successfully for data stages; the job retries and logs; no duplicate on later success.
5. Email body contains no tracking pixels, includes disclaimer and privacy link; HTML and text parts render in es and en.

## Repository Research

### Files Inspected

- `config/mail.php`, `.env.example` (`MAIL_MAILER=log`), `config/queue.php` (`database`), `deploy/README.md` (env setup), `routes/console.php`.
- `docs/specs/alerts-engine.md` — notifications source and pipeline stage.
- `docs/specs/legal-compliance-eu.md` and `frontend/src/i18n/legal/*.ts` — texts to extend.

### Current Gaps

- No mailables, mail views, preference column, digest ledger, unsubscribe routes, SMTP configuration in production, or provider decision.

## Expected File Changes

- Create: migration (users columns + `alert_email_digests`), `app/Mail/AlertDigest.php` + `resources/views/mail/alert-digest{,-text}.blade.php`, `app/Services/Alerts/DigestSender.php`, `app/Http/Controllers/AlertEmailPreferenceController.php`, `app/Http/Controllers/AlertEmailUnsubscribeController.php`, tests (`tests/Feature/AlertEmailDigestTest.php`, `AlertEmailUnsubscribeTest.php`).
- Modify: `app/Console/Commands/EvaluateAlerts.php` (dispatch digests after evaluation), `app/Models/User.php`, `routes/api.php`, `routes/web.php`, `lang/{es,en}/*.php`, frontend Portal opt-in control (`frontend/src/components/portal/*`, messages), legal texts, `.env.example`, `deploy/README.md` (SMTP env, SPF/DKIM/DMARC checklist).

## Visual Design Impact

- UI involved: small — one Portal checkbox with explanatory text, and a minimal unsubscribe confirmation page using the SPA shell style. Email templates: simple, brand colours from `DESIGN.md`, readable without images.

## Durable Documentation Impact

- `ARCHITECTURE.md`: update — mail transport, queue, unsubscribe endpoints.
- `CONSTRAINTS.md`: update — opt-in default off, one digest per user per as-of, no tracking, signed unsubscribe, never fail the pipeline.
- `deploy/README.md`: update — SMTP variables, domain authentication (SPF, DKIM, DMARC) before enabling.
- Legal texts + `docs/specs/legal-compliance-eu.md`: update — new processing and processor.
- `AGENTS.md`: update — scope guard (email alerts in scope).

## Implementation Plan

1. Schema + preference API + Portal checkbox. 2. Mailable, views, sender, ledger. 3. Hook into `alerts:evaluate`. 4. Unsubscribe (link + one-click). 5. Legal texts, docs, tests, local check with `MAIL_MAILER=log` and a local SMTP catcher.

## Implementation Tasks

- [x] Opt-in column + locale, API and Portal checkbox (unticked default).
- [x] `AlertDigest` mailable (HTML + text, es/en, disclaimer, privacy link, no tracking).
- [x] `DigestSender` with ledger idempotence and 50-item cap; queue + retries.
- [x] Signed unsubscribe GET + one-click POST + headers.
- [x] Legal and deploy docs; tests; evidence.

## Verification Plan

- `php artisan test` (`Mail::fake`/`Queue::fake`: once per as-of, opted-out skip, unsubscribe signature, transport failure isolation).
- Local manual check with a mail catcher (e.g. Mailpit) to view es/en rendering.
- Production enablement checklist (operator, after acceptance): provider credentials in `shared/.env`, SPF/DKIM/DMARC verified, test digest to an owner mailbox, privacy policy updated and published.
- `.\init.ps1` exit 0.

## Evidence To Capture

- Test output, rendered email screenshots (es/en), header dump showing `List-Unsubscribe`, `init.ps1`.

## Key Implementation Risks

- Deliverability (domain authentication) and provider choice — owner action.
- Sending to users who did not opt in — default off + test.
- Pipeline coupling — dispatch async, never fail data stages because of mail.

## Validator Checklist

- [x] Opt-in default off; one digest per user per as-of; no tracking; signed unsubscribe and one-click work.
- [x] Mail failures never break the pipeline; tests offline (`Mail::fake`).
- [x] Legal texts updated before any production send; docs and `init.ps1` OK.

## Review note — 2026-10-12

Still valid after `screener-precomputed`, `screener-pagination` and the header/homepage changes: the files and routes named above exist (the Screener now lives at `/`, which this spec does not touch). Screener alerts evaluated by `alerts-engine` read the precomputed `screener_rows`, refreshed by the pipeline before `alerts:evaluate`; nothing changes for this spec. Pattern alerts are a separate later feature: `docs/specs/alerts-patterns.md`.

## Implementation Findings

Recorded 2026-10-21.

1. **Shipped off, enabled by configuration.** There is still no provider (production has `MAIL_MAILER=log`), so the feature is code-complete but unavailable. `AlertEmailDigestAvailability::available()` requires `ALERT_EMAIL_DIGEST_ENABLED=true` **and** `ALERT_EMAIL_PROVIDER` **and** `ALERT_EMAIL_PROVIDER_LOCATION`. While it is unavailable:
   - the Portal hides the control;
   - `PUT /api/alerts/email` refuses an opt-in with `409` (opting out always works);
   - `alerts:evaluate` queues nothing;
   - a job already queued sends nothing.

   The go-live checklist is in `deploy/README.md` ("Email del resumen diario de alertas").
2. **Privacy text tied to the same switch.** The provider is not known yet, so the privacy policy cannot name it statically. `GET /api/legal` returns `email_digest: {provider, provider_location}` only while the feature is available. The new `emailDigest` paragraphs of the legal texts (es/en; sections "Alertas y notificaciones" and "Destinatarios y transferencias internacionales") render only then, with `{emailProvider}`, `{emailProviderLocation}` and `{emailDigestDays}`. No email can go out unless the policy describes it. `config/legal.php` `updated_at` must be bumped in the go-live deploy (checklist step 6).
3. **Unsubscribe under `/api`.** nginx sends only `/api` and `/sanctum` to Laravel, so the planned `/email/alerts/unsubscribe/{user}` is `/api/email/alerts/unsubscribe/{user}`:
   - GET shows a small self-contained page (`resources/views/alert-email-unsubscribed.blade.php`) in the user's language, with light/dark styling and `noindex`.
   - POST is the one-click endpoint (`204`).
   - The signature is **relative** (`signed:relative`) and the link is rooted at `APP_URL`, so it is valid from the queue worker (no request host) and behind the proxy.
4. **Job, not a queued mailable.** `SendAlertDigest` (3 tries, backoff 60/300 s) marks `sent_at` only after a successful send. It re-checks the opt-in and availability, so an unsubscribe between queueing and sending is honoured, and logs only the ledger id on final failure (no address). The ledger row is created before dispatch: with the unique `(user_id, as_of)` index, a re-run or a concurrent run queues nothing more.
5. **Notification read in PHP.** `notifications.data` is a text column, so filtering by `as_of` in SQL would need dialect-specific JSON operators. The content builder reads the user's last 7 days of `AlertTriggered` rows and filters in PHP. It sorts by creation time, then by alert id, because notification ids are UUIDs.
6. **Account data.**
   - The export includes `profile.alert_email_opt_in_at`, `profile.preferred_locale` and `alert_emails` (the ledger).
   - Deletion cascades through the foreign key.
   - The coverage allowlist test lists `alert_email_digests.user_id`.
   - The ledger is pruned by `model:prune` after 90 days (`ALERT_EMAIL_LEDGER_RETENTION_DAYS`).
7. **Wording.** `lang/{es,en}/alert_email.php` mirrors the inbox texts of the SPA (signal and pattern labels, events, "Ruptura 53,79", "y 3 más"), so the email and the inbox say the same thing. There is no EOD wording, and the footer carries the not-investment-advice disclaimer, why the email was sent, the unsubscribe link and the privacy link.
8. **Evidence.**
   - Rendered es (640 px) and en (390 px) emails viewed in Edge: no images, no horizontal overflow.
   - A raw message from the `array` transport shows `List-Unsubscribe: <https://www.chartiko.com/api/email/alerts/unsubscribe/{id}?signature=…>`, `List-Unsubscribe-Post: List-Unsubscribe=One-Click`, a localized subject and the `multipart/alternative` text and HTML parts.
   - Browser QA with the feature enabled locally (1280 es light, 390 en dark): the Portal box starts unticked, ticking stores the opt-in and the page language, there is no overflow, and the privacy policy names the configured provider and location.
   - The real server answered the signed GET (200, page in the user's language), a tampered signature (403) and the one-click POST (204), and the opt-in was cleared.
9. **Not done.** Production sending (owner action: provider, DNS authentication, env vars), and a real-inbox deliverability check (spam score, rendering in Gmail and Outlook), which needs the provider.
