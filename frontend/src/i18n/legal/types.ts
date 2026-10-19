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
 * `{cookiesLink}` `{privacyLink}` (in-app links).
 */
export type LegalSection = {
  heading: string
  paragraphs?: string[]
  items?: string[]
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
