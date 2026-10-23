import { Link } from 'react-router'
import type { ReactNode } from 'react'
import Interpolate from '../i18n/Interpolate.tsx'
import { LEGAL_TEXTS } from '../i18n/legal/index.ts'
import type { CookieRow, LegalDocument } from '../i18n/legal/types.ts'
import { useI18n } from '../i18n/useI18n.ts'
import { useLegalInfo } from '../legal/useLegalInfo.ts'
import type { LegalInfo } from '../lib/api.ts'
import { FRED_TERMS_URL } from '../components/market/MarketLegalFooter.tsx'
import { COOKIES_ROUTE, PRIVACY_ROUTE } from '../nav.ts'

export type LegalPageKind = 'privacy' | 'notice' | 'cookies'

/**
 * Pages that identify the owner are withheld until their own gate opens: the
 * privacy policy needs name + email, the legal notice also tax id + address.
 * The cookie policy needs no owner data and is always shown.
 */
function isPublished(kind: LegalPageKind, info: LegalInfo): boolean {
  if (kind === 'privacy') return info.privacy_published === true
  if (kind === 'notice') return info.notice_published === true
  return true
}

const LINK_CLASS = 'font-bold text-on-surface underline'

/**
 * Public legal pages (docs/specs/legal-compliance-eu.md,
 * docs/specs/legal-privacy-publication.md). Texts come from the
 * typed legal catalogs; owner identity and retention figures from
 * `GET /api/legal`, so nothing is shown as a placeholder.
 */
export default function LegalPage({ kind }: { kind: LegalPageKind }) {
  const { locale, t, formatMarketDate } = useI18n()
  const state = useLegalInfo()
  const texts = LEGAL_TEXTS[locale]
  const document: LegalDocument = texts[kind]

  let body: ReactNode
  if (state.status === 'loading') {
    body = <p className="text-sm text-on-surface-variant">{t('common.loading')}</p>
  } else if (state.status === 'error') {
    body = (
      <p role="alert" className="text-sm text-on-surface-variant">
        {t('legal.loadError')}
      </p>
    )
  } else if (!isPublished(kind, state.info)) {
    body = (
      <div className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_var(--color-shadow)]">
        <h2 className="font-headline text-lg font-bold">{t('legal.pendingTitle')}</h2>
        <p className="mt-2 text-sm leading-6 text-on-surface-variant">{t('legal.pendingBody')}</p>
      </div>
    )
  } else {
    const values = placeholderValues(state.info, t('legal.cookiesLink'), t('legal.privacyLink'), t('marketContext.legal.fredTerms'))
    const marketContext = state.info.market_context === true
    body = (
      <>
        <p className="text-base leading-7 text-on-surface-variant">{document.intro}</p>
        {kind === 'cookies' ? <CookieTable rows={texts.cookies.rows} values={values} label={document.title} /> : null}
        {document.sections.map((section) => {
          const fred = marketContext ? (section.marketContext ?? []) : []
          const items = section.items ? [...section.items, ...fred] : undefined
          const paragraphs = [
            ...(section.paragraphs ?? []),
            ...(state.info.account_email ? (section.accountEmail ?? []) : []),
            ...(state.info.email_digest ? (section.emailDigest ?? []) : []),
            ...(section.items ? [] : fred),
          ]
          return (
          <section key={section.heading} className="flex flex-col gap-2">
            <h2 className="font-headline text-xl font-bold">{section.heading}</h2>
            {paragraphs.map((paragraph) => (
              <p key={paragraph} className="text-sm leading-6 text-on-surface-variant">
                <Interpolate template={paragraph} values={values} />
              </p>
            ))}
            {items ? (
              <ul className="list-disc space-y-1 pl-5 text-sm leading-6 text-on-surface-variant">
                {items.map((item) => (
                  <li key={item}>
                    <Interpolate template={item} values={values} />
                  </li>
                ))}
              </ul>
            ) : null}
          </section>
          )
        })}
        {kind === 'notice' && state.info.owner?.registry ? (
          <p className="text-sm text-on-surface-variant">
            {t('legal.registry', { registry: state.info.owner.registry })}
          </p>
        ) : null}
        <p className="border-t border-outline-variant pt-4 text-xs text-on-surface-variant">
          {t('legal.updated', { date: formatMarketDate(state.info.updated_at) })}
        </p>
      </>
    )
  }

  return (
    <article className="mx-auto flex w-full max-w-3xl flex-col gap-6 py-6">
      <h1 className="font-headline text-3xl font-black tracking-tight uppercase sm:text-4xl">{document.title}</h1>
      {body}
    </article>
  )
}

function CookieTable({ rows, values, label }: { rows: CookieRow[]; values: Record<string, ReactNode>; label: string }) {
  const { t } = useI18n()
  return (
    <div
      role="region"
      aria-label={label}
      tabIndex={0}
      className="overflow-x-auto rounded-md border-2 border-outline bg-surface-bright focus:outline-2 focus:outline-offset-2 focus:outline-outline"
    >
      <table className="w-full min-w-[40rem] text-left text-sm">
        <thead className="border-b-2 border-outline bg-surface-container font-mono text-xs uppercase">
          <tr>
            <th scope="col" className="px-3 py-2">{t('legal.columnName')}</th>
            <th scope="col" className="px-3 py-2">{t('legal.columnType')}</th>
            <th scope="col" className="px-3 py-2">{t('legal.columnPurpose')}</th>
            <th scope="col" className="px-3 py-2">{t('legal.columnDuration')}</th>
            <th scope="col" className="px-3 py-2">{t('legal.columnCategory')}</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-outline-variant">
          {rows.map((row) => (
            <tr key={row.name}>
              <th scope="row" className="px-3 py-2 font-mono text-xs">{row.name}</th>
              <td className="px-3 py-2">{row.type}</td>
              <td className="px-3 py-2">{row.purpose}</td>
              <td className="px-3 py-2"><Interpolate template={row.duration} values={values} /></td>
              <td className="px-3 py-2">{row.category}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function placeholderValues(
  info: LegalInfo,
  cookiesLabel: string,
  privacyLabel: string,
  fredTermsLabel: string,
): Record<string, ReactNode> {
  const owner = info.owner
  const emailProvider = info.account_email ?? info.email_digest ?? null
  return {
    ...(owner
      ? {
          ownerName: owner.name,
          // Plain, selectable text: never a mailto link (owner decision, anti-scraping).
          ownerEmail: (
            <span translate="no" className="font-bold break-all text-on-surface">
              {owner.email}
            </span>
          ),
          ...(owner.tax_id !== null ? { ownerTaxId: owner.tax_id } : {}),
          ...(owner.address !== null ? { ownerAddress: owner.address } : {}),
        }
      : {}),
    activityDays: info.retention.sign_in_activity_days,
    sessionMinutes: info.retention.session_minutes,
    backupDays: info.retention.backup_days,
    logDays: info.retention.server_log_days,
    notificationDays: info.retention.notification_days,
    // Older deployments omit it; 90 is the configured default.
    aiCardLogDays: info.retention.ai_card_log_days ?? 90,
    aiScreenerLogDays: info.retention.ai_screener_log_days ?? 90,
    ...(info.email_digest ? { emailDigestDays: info.retention.email_digest_days ?? 90 } : {}),
    // One sending provider for account emails (auth-email-verification) and
    // the daily alert email; either may be active on its own.
    ...(emailProvider
      ? {
          emailProvider: emailProvider.provider,
          emailProviderLocation: emailProvider.provider_location,
        }
      : {}),
    cookiesLink: (
      <Link to={COOKIES_ROUTE} className={LINK_CLASS}>
        {cookiesLabel}
      </Link>
    ),
    privacyLink: (
      <Link to={PRIVACY_ROUTE} className={LINK_CLASS}>
        {privacyLabel}
      </Link>
    ),
    fredTermsLink: (
      <a href={FRED_TERMS_URL} target="_blank" rel="noopener noreferrer" className={LINK_CLASS}>
        {fredTermsLabel}
      </a>
    ),
  }
}
