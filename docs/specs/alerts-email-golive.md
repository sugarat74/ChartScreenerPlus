# Feature: outbound email through Brevo

## Decision (2026-10-23)

The owner replaced Bird with Brevo. `alerts-email-golive` uses Brevo's
transactional HTTP API because the supplied credential is an API key, not an
SMTP key. The Brevo MCP token is not used by the application. This decision
supersedes the earlier Bird and SMTP plans for this feature.

## Owner direction (2026-10-24)

The owner first requested delivery without recipient tracking, then explicitly
accepted Brevo's opening pixel and requested activation of both account
confirmation and the daily alert digest. Brevo's `contactPixelTrackingConsent`
remains `false`; Chartiko does not request individual open/click tracking.
The privacy policy and both first-layer notices must disclose the pixel. The
received text links remain direct. The provider may still process opening
events; this must not be described as tracking-free. A privacy lawyer should
review the basis for Brevo's pixel before a wider public launch.

Provider documentation reviewed: [Brevo API consent behavior](https://developers.brevo.com/changelog/2026/7/21),
[Mailjet tracking controls](https://documentation.mailjet.com/hc/en-us/articles/51109335532571-Email-Tracking-Pixels-and-Consent-CNIL-and-Garante-Guidance),
[Mailjet EU storage](https://documentation.mailjet.com/hc/en-us/articles/360042712274-Where-is-my-personal-data-stored)
and [Mailjet plan limits](https://www.mailjet.com/es/es-tarifas/).

## Scope

- Send account confirmation and opt-in daily alert email from
  `no-reply@chartiko.com`, with `contacto@chartiko.com` as Reply-To.
- Keep both features dormant until production delivery and legal text are
  verified. `AUTH_EMAIL_VERIFICATION_ENABLED` and
  `ALERT_EMAIL_DIGEST_ENABLED` turn on together after that gate.
- No marketing mail, chat, attachments, application-added pixels, link
  rewriting, alternate inbound mail routing or SPF change. Brevo adds its
  opening pixel to the received message with the owner's approval.

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
  pixel. The stored copy was insufficient evidence. The owner accepted that
  pixel on 2026-10-24 and the public texts now disclose it.
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
3. Run `php artisan mail:test` to an accessible address. Brevo must report
   delivery; inspect received headers for SPF, DKIM and DMARC and the body
   for direct links. The received opening pixel is disclosed.
4. Review Brevo's DPA and transactional tracking configuration. The API
   payload sets per-recipient tracking consent to false; do not claim that
   this removes the pixel. Check received links for direct Chartiko URLs.
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
  the pixel. The owner accepted it for this activation; the residual consent
  question needs professional review, and Chartiko must not use opening
  events for profiling or personalization.
- The temporary mailbox reported SPF/DKIM false and provided no
  Authentication-Results. The raw source included aligned `d=chartiko.com`
  and `s=brevo1`, but independent DKIM verification of the mailbox's exported
  source found a body hash mismatch. A later production test message sent to
  `contacto@chartiko.com` was forwarded by Cloudflare Email Routing to its
  verified destination (`delivered` event, SPF and DKIM `pass`, DMARC `none`,
  2026-10-24 11:10:04 UTC). This confirms authentication at Cloudflare's
  receiving edge; destination inbox placement was not inspected.
- Brevo states its data storage is in the EU; the legal text says `UE` rather
  than one country. Its DPA is incorporated into its terms; account-level
  acceptance should be retained.
- PRs 61, 62 and 63 passed CI including PostgreSQL and deployed. The final
  full `./init.ps1` gate passed before activation. Production configuration
  and `/api/legal` confirm both flags active and Brevo/UE on 2026-10-23.
- A disposable live registration, resend, signed verification, opt-in,
  digest and unsubscribe passed against a temporary receiving inbox. The
  Chartiko account and mailbox were deleted after the test.
- PR 67 passed CI and PostgreSQL and deployed on 2026-10-24. Both production
  flags are true, `/api/legal` exposes Brevo/UE and the revised date, and a
  fresh disposable registration received its confirmation with the Brevo pixel.
  The signed link verified and the digest preference could be enabled. The
  temporary Chartiko account and mailbox were deleted. No new mail job failed.
