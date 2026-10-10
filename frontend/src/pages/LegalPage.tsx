import { Link } from 'react-router'
import type { ReactNode } from 'react'
import Interpolate from '../i18n/Interpolate.tsx'
import { LEGAL_TEXTS } from '../i18n/legal/index.ts'
import type { CookieRow, LegalDocument } from '../i18n/legal/types.ts'
import { useI18n } from '../i18n/useI18n.ts'
import { useLegalInfo } from '../legal/useLegalInfo.ts'
import type { LegalInfo } from '../lib/api.ts'
import { COOKIES_ROUTE, PRIVACY_ROUTE } from '../nav.ts'

export type LegalPageKind = 'privacy' | 'notice' | 'cookies'

/** Pages that identify the owner are withheld until the owner data is configured. */
const NEEDS_OWNER: Record<LegalPageKind, boolean> = { privacy: true, notice: true, cookies: false }

const LINK_CLASS = 'font-bold text-on-surface underline'

/**
 * Public legal pages (docs/specs/legal-compliance-eu.md). Texts come from the
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
  } else if (NEEDS_OWNER[kind] && !state.info.published) {
    body = (
      <div className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]">
        <h2 className="font-headline text-lg font-bold">{t('legal.pendingTitle')}</h2>
        <p className="mt-2 text-sm leading-6 text-on-surface-variant">{t('legal.pendingBody')}</p>
      </div>
    )
  } else {
    const values = placeholderValues(state.info, t('legal.cookiesLink'), t('legal.privacyLink'))
    body = (
      <>
        <p className="text-base leading-7 text-on-surface-variant">{document.intro}</p>
        {kind === 'cookies' ? <CookieTable rows={texts.cookies.rows} values={values} label={document.title} /> : null}
        {document.sections.map((section) => (
          <section key={section.heading} className="flex flex-col gap-2">
            <h2 className="font-headline text-xl font-bold">{section.heading}</h2>
            {section.paragraphs?.map((paragraph) => (
              <p key={paragraph} className="text-sm leading-6 text-on-surface-variant">
                <Interpolate template={paragraph} values={values} />
              </p>
            ))}
            {section.items ? (
              <ul className="list-disc space-y-1 pl-5 text-sm leading-6 text-on-surface-variant">
                {section.items.map((item) => (
                  <li key={item}>
                    <Interpolate template={item} values={values} />
                  </li>
                ))}
              </ul>
            ) : null}
          </section>
        ))}
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

function placeholderValues(info: LegalInfo, cookiesLabel: string, privacyLabel: string): Record<string, ReactNode> {
  const owner = info.owner
  return {
    ...(owner
      ? {
          ownerName: owner.name,
          ownerTaxId: owner.tax_id,
          ownerAddress: owner.address,
          ownerEmail: (
            <a href={`mailto:${owner.email}`} className={LINK_CLASS}>
              {owner.email}
            </a>
          ),
        }
      : {}),
    activityDays: info.retention.sign_in_activity_days,
    sessionMinutes: info.retention.session_minutes,
    backupDays: info.retention.backup_days,
    logDays: info.retention.server_log_days,
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
  }
}
