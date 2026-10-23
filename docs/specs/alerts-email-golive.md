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

- Laravel's `brevo` mailer sends only the text part of existing mailables to
  `POST /v3/smtp/email`, sends `contactPixelTrackingConsent=false` for every
  recipient and forwards the digest's `List-Unsubscribe` and
  `List-Unsubscribe-Post` headers.
- A live Brevo HTML test on 2026-10-23 rewrote both Chartiko links to a
  `sendibt2.com/tr/cl/` tracking host despite pixel consent `false`.
  A text-only test preserved the direct Chartiko URL and showed no tracking
  domain or image in Brevo's stored copy. A later test of the **received**
  source proved that Brevo converts `textContent` to HTML and adds an opening
  pixel. The stored copy was insufficient evidence. Sending is disabled until
  a received message meets the no-tracking requirement.
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

1. Offline transport tests cover the text-only payload, unsubscribe headers,
   pixel consent and a redacted failure. The deploy script's missing-key,
   installed-key and bad-key cases are verified. `./init.ps1` passes.
2. Production stays dormant until a release contains the transport. Install
   the API key from the owner's local file via the script's stdin mode and
   verify `mail.default=brevo`, the provider/location and both flags false.
3. Run `php artisan mail:test` to the owner's address. Brevo must report
   delivery; inspect received headers for SPF, DKIM and DMARC and the body
   for direct links without a tracking pixel.
4. Review Brevo's DPA and transactional tracking configuration. The API
   payload opts each recipient out of pixel tracking, but that did not stop
   HTML link rewriting. The received text-only message must confirm direct
   links and no HTML/pixel part.
5. Set both flags true only after the test above, bump `config/legal.php`
   `updated_at`, rebuild config and restart the queue/FPM. Verify `/api/legal`
   names Brevo / UE. Register a throwaway account and test confirmation,
   resend, digest delivery and one-click unsubscribe.
6. Rollback: run `configure-mail-env.py --flags-off --apply`. This leaves the
   API key installed but stops both features immediately.

## Current risks

- Brevo adds an open tracking pixel to mail received from its API even when
  `textContent` is the only body and `contactPixelTrackingConsent=false`.
  Direct test headers `X-Mailin-Track-Open: 0` and `X-Mailin-Track-Click: 0`
  did not suppress it. The account/digest flags were rolled back to false and
  the deploy default is false. The tracking configuration must actually remove
  the pixel, or the owner must review a changed privacy/consent approach,
  before sending resumes.
- The temporary mailbox reported SPF/DKIM false and provided no
  Authentication-Results. The raw source included aligned `d=chartiko.com`
  and `s=brevo1`, but independent DKIM verification of the mailbox's exported
  source found a body hash mismatch. Confirm authentication with a reliable
  recipient before go-live. Brevo's DNS authentication API reports both DKIM
  records and DMARC active; this alone does not prove a received message passes.
- Brevo states its data storage is in the EU; the legal text says `UE` rather
  than one country. Its DPA is incorporated into its terms; account-level
  acceptance should be retained.
- PRs 61, 62 and 63 passed CI including PostgreSQL and deployed. The final
  full `./init.ps1` gate passed before activation. Production configuration
  and `/api/legal` confirm both flags active and Brevo/UE on 2026-10-23.
- A disposable live registration, resend, signed verification, opt-in,
  digest and unsubscribe passed against a temporary receiving inbox. The
  Chartiko account and mailbox were deleted after the test.
