# Feature: outbound email through Brevo

## Decision (2026-10-23)

The owner replaced Bird with Brevo. `alerts-email-golive` uses Brevo's
transactional HTTP API because the supplied credential is an API key, not an
SMTP key. The Brevo MCP token is not used by the application. This decision
supersedes the earlier Bird and SMTP plans for this feature.

## Scope

- Send account confirmation and opt-in daily alert email from
  `no-reply@chartiko.com`, with `contacto@chartiko.com` as Reply-To.
- Keep both features dormant until production delivery and legal text are
  verified. `AUTH_EMAIL_VERIFICATION_ENABLED` and
  `ALERT_EMAIL_DIGEST_ENABLED` turn on together after that gate.
- No marketing mail, chat, attachments, tracking pixels, link tracking,
  alternate inbound mail routing or SPF change.

## DNS and provider evidence

- `chartiko.com` added and authenticated in Brevo on 2026-10-23.
- Cloudflare: apex `brevo-code` TXT, `brevo1._domainkey` and
  `brevo2._domainkey` CNAME, one `_dmarc` TXT with `p=none` and Brevo's
  aggregate report address. Bird's DKIM and `send` CNAME were removed.
- Cloudflare Email Routing MX, its DKIM record and apex SPF remain intact.
- Brevo sender `no-reply@chartiko.com` created with `dkimError=false` and
  `spfError=false`. A direct API test was accepted and Brevo subsequently
  reported `delivered`. This does not prove inbox placement or final headers.

## Transport contract

- Laravel's `brevo` mailer converts the existing HTML/text mailables to
  `POST /v3/smtp/email`, sends `contactPixelTrackingConsent=false` for every
  recipient and forwards the digest's `List-Unsubscribe` and
  `List-Unsubscribe-Post` headers.
- It rejects attachments, which neither current mail flow uses. It never
  includes the provider response body, email addresses or signed links in a
  transport error. A non-201 result fails the queued job.
- `deploy/configure-mail-env.py` writes `MAIL_MAILER=brevo`, From, Reply-To,
  `MAIL_PROVIDER=Brevo`, `MAIL_PROVIDER_LOCATION=UE` and both feature flags.
  It preserves an installed `BREVO_API_KEY` without printing it. Without
  that key it selects `log` and keeps both flags false. It removes retired
  Bird SMTP settings from the active environment. Owner mode reads the API
  key on stdin, makes a private backup, writes atomically and can rebuild
  config and restart the queue/FPM with `--apply`.
- The CI deploy calls the non-secret configuration step on every release.
  Never put either supplied credential in GitHub or this repository.

## Acceptance and verification

1. Offline transport tests cover the payload, the unsubscribe headers,
   pixel consent and a redacted failure. The deploy script's missing-key,
   installed-key and bad-key cases are verified. `./init.ps1` passes.
2. Production stays dormant until a release contains the transport. Install
   the API key from the owner's local file via the script's stdin mode and
   verify `mail.default=brevo`, the provider/location and both flags false.
3. Run `php artisan mail:test` to the owner's address. Brevo must report
   delivery; inspect received headers for SPF, DKIM and DMARC and the body
   for direct links without a tracking pixel.
4. Review Brevo's transactional tracking configuration and DPA. The API
   payload opts each recipient out of pixel tracking; the received message
   must confirm that links are not rewritten.
5. Set both flags true only after the test above, bump `config/legal.php`
   `updated_at`, rebuild config and restart the queue/FPM. Verify `/api/legal`
   names Brevo / UE. Register a throwaway account and test confirmation,
   resend, digest delivery and one-click unsubscribe.
6. Rollback: run `configure-mail-env.py --flags-off --apply`. This leaves the
   API key installed but stops both features immediately.

## Current risks

- Brevo's `delivered` event does not show the inbox's authentication results.
- Tracking and data-processing terms require account-level review before
  enabling user mail. Brevo states its storage is in the EU (France, Germany
  and Belgium); the legal text therefore says `UE` rather than one country.
- The full repository startup gate had one Vitest pagination timeout on the
  initial run, which passed when rerun alone. A complete green run is required
  before deployment.
