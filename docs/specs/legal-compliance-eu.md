# Spec: legal-compliance-eu — public legal pages (privacy, legal notice, cookies)

Status: accepted 2026-10-10 (implemented, not deployed); publication blocked until the owner's identifying data is configured.
Prepared with the `web-legal-compliance-eu` skill on 2026-10-10. Not legal advice: the texts
need a professional review before or shortly after publication.

## Goal

Publish the legal information a Spanish/EU visitor and Registered User must get:

- **Privacy policy** (`/privacidad`) — GDPR arts. 13–14, LOPDGDD art. 11, built only from the real
  processing inventory below.
- **Legal notice and terms of use** (`/aviso-legal`) — LSSI-CE art. 10, account terms and the
  financial-information disclaimer.
- **Cookie policy** (`/cookies`) — informative only: Chartiko stores nothing non-exempt, so no
  consent banner is needed (decision recorded below).
- A **footer** on every page with the disclaimer and links, and a **first-layer privacy notice**
  under the registration form.

## Owner data and the publication gate

The owner's identifying data is never invented and never shown as a placeholder. It comes from
`config/legal.php` (environment):

| Key | Env | Required |
|---|---|---|
| `owner.name` | `LEGAL_OWNER_NAME` | yes — name or company name |
| `owner.tax_id` | `LEGAL_OWNER_TAX_ID` | yes — NIF/CIF |
| `owner.address` | `LEGAL_OWNER_ADDRESS` | yes — postal address |
| `owner.email` | `LEGAL_CONTACT_EMAIL` | yes — contact and data-protection requests |
| `owner.registry` | `LEGAL_OWNER_REGISTRY` | no — registry/authorisation data, only if it applies |

`GET /api/legal` (public, throttled) returns `published: true` and the owner data only when all
four required values are set. While `published` is false:

- `/privacidad` and `/aviso-legal` show a "being prepared" notice instead of the text, and the
  footer and registration form do not link to them;
- the cookie policy and the financial disclaimer are still shown (they need no owner data).

To publish: set the four variables in `shared/.env`, run `php artisan config:cache`, reload
PHP-FPM. No deploy is needed.

Retention values in the texts also come from configuration, so the text cannot drift from
behaviour: sign-in activity `admin.activity_retention_days` (90), session lifetime
`session.lifetime` (120 min), backups `legal.backup_retention_days` (14, mirrors
`CHARTIKO_BACKUP_RETENTION_DAYS`) and server logs `legal.server_log_retention_days` (14, nginx
logrotate and the Laravel `daily` log channel).

## Processing inventory (verified 2026-10-10)

| Processing | Data | Purpose | Legal basis | Retention |
|---|---|---|---|---|
| User account | name, email, password hash | create and run the account | contract (6.1.b) | while the account exists; deletion on request |
| Saved Screeners and Watchlist | filters, tickers linked to the account | the requested feature | contract (6.1.b) | while the account exists |
| Alerts and in-app notifications (since 2026-10-10) | alert settings; notifications with tickers and reasons | tell the user about changes they asked to follow | contract (6.1.b) | alerts while kept; notifications 90 days |
| Sessions and sign-in activity | IP, user agent, last activity; sign-ins, failures (typed email), sign-outs, Admin revocations | security, Admin session control | legitimate interest in security (6.1.f) | sessions: lifetime; activity: 90 days |
| Server and application logs | IP, date, URL, referer, user agent, errors | operation and security | legitimate interest (6.1.f) | 14 days |
| Backups | full database copy | recovery | legitimate interest (6.1.f) | 14 days |
| Requests by email | email and message | answer rights and enquiries | legal obligation (6.1.c) / legitimate interest | while handled, then limitation periods |

No analytics, advertising, newsletters, payments, profiling or automated decisions. Market data
is fetched server-side and involves no visitor data. Recipients: hosting provider OVH as
processor. **The VPS is in Beauharnois (Quebec, Canada)**: an international transfer covered by
the EU adequacy decision for Canada (2002/2/EC), to be confirmed by the reviewer together with
OVH's DPA.

## Terminal storage (verified 2026-10-10)

| Name | Type | Purpose | Duration | Classification |
|---|---|---|---|---|
| `alphapulse-session` | first-party cookie, HttpOnly, Secure | keeps the session | session lifetime (120 min) | strictly necessary |
| `XSRF-TOKEN` | first-party cookie, Secure | CSRF protection | session lifetime (120 min) | strictly necessary |
| `chartiko.locale` | localStorage | language chosen by the user | until cleared | user-requested preference, exempt |

No third-party resources load (fonts fall back to system fonts; no CDN, maps, video or captcha).
Decision: **no consent banner**; the cookie policy is informative.

## Out of scope / findings for the owner

- **Before publishing:** delete the SQLite rollback source (`shared/database/database.sqlite`, kept until at
  least 2026-11-05) and the cut-over copies `/root/chartiko-pgsql-cutover-*/database.before.sqlite`. They hold
  users, sessions and sign-in activity outside the stated 14-day backup retention.
- Self-service account deletion and data export do not exist; rights are handled by email
  (one month). Admin tooling to delete a user is not built.
- `LOG_STACK=single` in production keeps `laravel.log` with no rotation. Set `LOG_STACK=daily`
  and `LOG_DAILY_DAYS=14` before publishing so the 14-day log claim is true.
- Lawyer review points: legal basis for sign-in activity, transfer to Canada, minimum age
  (14, LOPDGDD art. 7), terms of use, owner's address if a natural person.

## Verification

- Feature tests for `GET /api/legal`: unpublished without the four values, published with them,
  no owner data leaked while unpublished, retention values from config.
- Frontend tests: footer links depend on `published`; legal pages show the gate while
  unpublished and the owner data when published; the registration first layer links to the
  policy only when published.
- `.\init.ps1` passes; real-browser check of the three pages in es/en.
