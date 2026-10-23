/**
 * Shape of the legal texts (docs/specs/legal-compliance-eu.md). Every sentence
 * must match the verified processing and storage inventory in that spec.
 *
 * Placeholders, filled by `LegalPage` from `GET /api/legal`:
 * `{ownerName}` `{ownerEmail}` (plain text, never a mailto link),
 * `{ownerTaxId}` `{ownerAddress}` (legal notice only: they are null until the
 * notice is published, so the privacy policy must not use them;
 * docs/specs/legal-privacy-publication.md),
 * `{activityDays}` `{sessionMinutes}` `{backupDays}` `{logDays}` `{notificationDays}`
 * `{aiCardLogDays}` `{aiScreenerLogDays}`,
 * `{emailProvider}` `{emailProviderLocation}` `{emailDigestDays}` (only in
 * `emailDigest` paragraphs, rendered while the daily alert email is active;
 * the first two also in `accountEmail` paragraphs, rendered while account
 * emails are sent),
 * `{cookiesLink}` `{privacyLink}` (in-app links),
 * `{fredTermsLink}` (external link to the FRED® API Terms of Use; only in
 * `marketContext` texts).
 */
export type LegalSection = {
  heading: string
  paragraphs?: string[]
  /**
   * Paragraphs about the daily alert email (alerts-email-digest), shown only
   * while `GET /api/legal` reports it active and names its provider.
   */
  emailDigest?: string[]
  /**
   * Paragraphs about the account emails (auth-email-verification: the address
   * confirmation), shown only while `GET /api/legal` reports `account_email`
   * and names its provider.
   */
  accountEmail?: string[]
  items?: string[]
  /**
   * FRED® clauses of the Market Context (market-context-ui), shown only while
   * `GET /api/legal` reports `market_context` on: appended to `items` as list
   * items when the section has items, otherwise to its paragraphs.
   */
  marketContext?: string[]
}

export type LegalDocument = {
  title: string
  intro: string
  sections: LegalSection[]
}

export type CookieRow = {
  name: string
  type: string
  purpose: string
  duration: string
  category: string
}

export type LegalTexts = {
  privacy: LegalDocument
  notice: LegalDocument
  cookies: LegalDocument & { rows: CookieRow[] }
}
