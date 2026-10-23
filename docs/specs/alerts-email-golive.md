# Feature Implementation Spec: Turn on the daily alert email in production

## Source Feature

- `id`: `alerts-email-golive`
- `area`: `ops`
- `depends_on`: `alerts-email-digest` (accepted)
- `status`: `not_started` (planned 2026-10-21 at the user's request: "dejalo como feature a implementar")
- `source`: `feature_list.json`

## Goal

Activate the already-built daily alert email (`alerts-email-digest`, which ships off) in production with a free EU sending provider. The proposed provider is **Brevo**: a French company, free plan of about 300 emails/day (check the current limit), SMTP. No application code changes are expected, only configuration, DNS and verification.

Corrected 2026-10-23 by the `auth-email-verification` planning (`docs/specs/auth-email-verification.md`): the same go-live also switches on email verification for new accounts, which ships dormant behind `AUTH_EMAIL_VERIFICATION_ENABLED` and shares this transport. The provider is now named once through `MAIL_PROVIDER` / `MAIL_PROVIDER_LOCATION` (the digest falls back to them), and one sender serves account and alert emails. Turn verification on only once `auth-email-verification` is accepted; until then the extra variable is harmless.

## Non-Goals

- No code changes to the digest, no marketing email, no other providers in parallel.
- No new receiving mailbox: replies go through Cloudflare Email Routing like `contacto@`.

## Current State (2026-10-21)

- DNS for `chartiko.com` is on **Cloudflare**. MX records point to Cloudflare Email Routing (receive only).
- SPF: `v=spf1 include:_spf.mx.cloudflare.net ~all`. There is no `_dmarc` record.
- Production has `MAIL_MAILER=log`, so nothing is sent. The queue worker `alphapulse-queue.service` is running.

## Steps

### Owner (account and secrets; never pasted into the chat)
1. Create a Brevo account and accept its DPA.
2. Add the domain `chartiko.com` in Brevo. Copy the TXT verification record and the DKIM records into Cloudflare DNS, with CNAME records set to "DNS only".
3. Edit the existing SPF record (do not add a second one): `v=spf1 include:_spf.mx.cloudflare.net include:spf.brevo.com ~all`.
4. Add DMARC: TXT `_dmarc` = `v=DMARC1; p=none; rua=mailto:contacto@chartiko.com`.
5. Authenticate the domain in Brevo and create the sender `no-reply@chartiko.com` (replies go to `contacto@chartiko.com` through the Reply-To header, which Cloudflare Email Routing already forwards). This replaces `alertas@`; if the owner prefers `alertas@`, only `MAIL_FROM_ADDRESS` changes.
6. In Brevo's transactional settings, turn off open tracking and click tracking: the privacy texts promise emails without tracking pixels or tracking links.
7. Generate an SMTP key and note the SMTP login.

### Implementer
1. Write `deploy/configure-mail-env.py` (or an equivalent PowerShell entry point), modelled on `deploy/configure-ai-env.py`.
   - It prompts on the server for the SMTP login and key without echoing them.
   - It writes these to `shared/.env`:
     - `MAIL_MAILER=smtp`, `MAIL_HOST=smtp-relay.brevo.com`, `MAIL_PORT=587`, `MAIL_USERNAME`, `MAIL_PASSWORD`;
     - `MAIL_FROM_ADDRESS=no-reply@chartiko.com`, `MAIL_FROM_NAME=Chartiko`, `MAIL_REPLY_TO_ADDRESS=contacto@chartiko.com`;
     - `MAIL_PROVIDER="Brevo"`, `MAIL_PROVIDER_LOCATION="UE (Francia)"` (they replace `ALERT_EMAIL_PROVIDER*`, which fall back to them; remove any old `ALERT_EMAIL_PROVIDER*` lines);
     - `ALERT_EMAIL_DIGEST_ENABLED=true`;
     - `AUTH_EMAIL_VERIFICATION_ENABLED=true` (only once `auth-email-verification` is accepted).
   - It never prints a value.
2. Bump `config/legal.php` `updated_at` in the same deploy, because the privacy policy starts showing the email paragraphs (digest and account email) and the provider.
3. Deploy. Run `php artisan config:cache` as `deploy` with `umask 007`, then `systemctl restart alphapulse-queue` (root step, confirmed with the user).

## Verification

- `GET /api/legal` returns `email_digest: {provider: "Brevo", provider_location: "UE (Francia)"}`, and `/privacidad` shows the email paragraphs in es and en.
- With an owner account: the Portal box appears unticked, ticking it stores the opt-in, and the next `alerts:evaluate` (or a manual run) delivers one email.
- Check the received email:
  - it lands in the inbox, not spam;
  - SPF, DKIM and DMARC are all `pass` in the headers;
  - it renders in Gmail and Outlook;
  - it has the `List-Unsubscribe` and `List-Unsubscribe-Post` headers;
  - the unsubscribe page and Gmail's one-click unsubscribe both work.
- Check that `laravel.log` contains no address, and that the `alert_email_digests` row has `sent_at`.
- Received emails have no tracking pixel, and their links point straight at `www.chartiko.com` (not a Brevo redirect).
- Email verification (when enabled): `GET /api/legal` returns `account_email` with Brevo; register a new test account, which sees only the "Confirma tu correo" screen; the confirmation email arrives in the inbox with SPF/DKIM/DMARC `pass`, From `no-reply@`, Reply-To `contacto@`; its link opens `/verificar-correo/...` and confirms the account; resend works. Delete the test account afterwards from `/portal/cuenta`.
- Rollback: `ALERT_EMAIL_DIGEST_ENABLED=false` and/or `AUTH_EMAIL_VERIFICATION_ENABLED=false` (pending accounts get full access at once), `config:cache`, restart the worker.

## Risks

- Deliverability on a new domain: keep the volume low, and tighten DMARC to `quarantine` only after a few clean weeks.
- Free-plan daily cap: if it is reached, the job retries and then logs. Monitor the counts in the Brevo panel. Confirmation emails share the cap with the digest; registration is throttled per IP (`auth-email-verification`).
- Secrets: the SMTP key lives only in `shared/.env` on the server.
