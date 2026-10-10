/**
 * Shape of the legal texts (docs/specs/legal-compliance-eu.md). Every sentence
 * must match the verified processing and storage inventory in that spec.
 *
 * Placeholders, filled by `LegalPage` from `GET /api/legal`:
 * `{ownerName}` `{ownerTaxId}` `{ownerAddress}` `{ownerEmail}` (mailto link),
 * `{activityDays}` `{sessionMinutes}` `{backupDays}` `{logDays}` `{notificationDays}`,
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
