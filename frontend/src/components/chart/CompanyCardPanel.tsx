/**
 * Company Card panel on the chart page (docs/specs/ai-company-card.md,
 * docs/specs/company-fundamentals-sec.md).
 *
 * Signed-in only, like `ForecastPanel`: it reads the already bootstrapped
 * session itself and renders nothing (no request, no sign-in prompt) for a
 * Visitor or while auth is loading; the page stays anonymous
 * (docs/specs/premium-panels-auth-only.md). It owns the `#ai-company-card`
 * deep-link scroll, done once when it first renders signed in, so it works
 * whichever of the chart data or the session resolves first.
 *
 * Two clearly separated blocks: first the AI-written "3-month outlook"
 * (labelled AI, unverified, not advice; the stance is a neutral scenario chip,
 * never gain/loss colors), then the "Key metrics" computed by Chartiko from
 * SEC 10-K/10-Q filings, each with its period or the reason it is missing.
 * Opening the panel only reads (`GET`); the provider is called only when the
 * user presses generate. Errors never auto-retry. The metrics render even
 * when there is no AI card or the provider is disabled.
 */

import { useEffect, useRef, useState } from 'react'
import { useLocation } from 'react-router'
import { useSignedIn } from '../../auth/useSignedIn.ts'
import { useI18n, useTranslateRef } from '../../i18n/useI18n.ts'
import { ApiError, FUNDAMENTAL_METRICS, apiErrorMessage, companyCardApi } from '../../lib/api.ts'
import type { CompanyCard, FundamentalMetric, FundamentalMetricKey, Fundamentals } from '../../lib/api.ts'

export const COMPANY_CARD_ANCHOR = 'ai-company-card'

type LoadState =
  | { status: 'loading' }
  | { status: 'ready'; card: CompanyCard | null; available: boolean; fundamentals: Fundamentals | null }
  | { status: 'error'; message: string }

type ActionState = { key: string; generating: boolean; error: string | null; reused: boolean }

const RATIO_METRICS: ReadonlySet<FundamentalMetricKey> = new Set(['pe_ttm', 'net_debt_to_ebitda'])
const MILLION_METRICS: ReadonlySet<FundamentalMetricKey> = new Set(['market_cap_millions', 'revenue_ttm_millions', 'ebitda_millions'])

const BUTTON_CLASS =
  'rounded-md border-2 border-outline bg-primary-container px-4 py-2 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:translate-y-0 disabled:opacity-60'

const BADGE_CLASS =
  'rounded border border-outline bg-primary-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase text-on-primary-container'

function SubHeading({ children }: { children: string }) {
  return (
    <h4 className="font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">{children}</h4>
  )
}

export default function CompanyCardPanel({ ticker }: { ticker: string }) {
  const signedIn = useSignedIn()
  const { hash } = useLocation()
  const { t, locale, formatDecimal, formatChangePercent, formatDateTime, formatMarketDate } = useI18n()
  const tRef = useTranslateRef()
  const [reloadToken, setReloadToken] = useState(0)
  // Every settled read/generate is keyed by ticker, language and retry, so a
  // stale response never renders and a key change shows loading again.
  const [loaded, setLoaded] = useState<{ key: string; state: LoadState } | null>(null)
  const [action, setAction] = useState<ActionState | null>(null)
  const requestKey = `${ticker}|${locale}|${reloadToken}`
  const currentKey = useRef(requestKey)
  const sectionRef = useRef<HTMLElement>(null)
  const scrolled = useRef(false)

  useEffect(() => {
    currentKey.current = requestKey
  }, [requestKey])

  // Entry from a Candidate row: bring the panel into view once, when it first
  // renders for a signed-in user. Navigation only; nothing is generated.
  useEffect(() => {
    if (!signedIn || scrolled.current || hash !== `#${COMPANY_CARD_ANCHOR}`) {
      return
    }
    scrolled.current = true
    sectionRef.current?.scrollIntoView({ block: 'start' })
  }, [signedIn, hash])

  // Read the stored card and fundamentals for this ticker and language; never generates.
  useEffect(() => {
    if (!signedIn) {
      return
    }
    let active = true
    companyCardApi
      .get(ticker)
      .then((payload) => {
        if (active) {
          setLoaded({
            key: requestKey,
            state: { status: 'ready', card: payload.card, available: payload.available, fundamentals: payload.fundamentals ?? null },
          })
        }
      })
      .catch((caught: unknown) => {
        if (active) setLoaded({ key: requestKey, state: { status: 'error', message: apiErrorMessage(caught, tRef.current('common.networkError')) } })
      })
    return () => {
      active = false
      currentKey.current = ''
    }
  }, [ticker, requestKey, signedIn, tRef])

  const load: LoadState = loaded !== null && loaded.key === requestKey ? loaded.state : { status: 'loading' }
  const activeAction = action !== null && action.key === requestKey ? action : null
  const generating = activeAction?.generating ?? false

  async function generate() {
    const key = requestKey
    const previous = load
    setAction({ key, generating: true, error: null, reused: false })
    try {
      const result = await companyCardApi.generate(ticker)
      if (currentKey.current !== key) return
      setLoaded({ key, state: { status: 'ready', card: result.card, available: true, fundamentals: result.fundamentals ?? null } })
      setAction({ key, generating: false, error: null, reused: result.reused })
    } catch (caught) {
      if (currentKey.current !== key) return
      if (caught instanceof ApiError && caught.code === 'provider_not_configured' && previous.status === 'ready') {
        setLoaded({ key, state: { ...previous, available: false } })
      }
      setAction({ key, generating: false, error: apiErrorMessage(caught, t('common.networkError')), reused: false })
    }
  }

  function metricValue(key: FundamentalMetricKey, metric: FundamentalMetric, currency: string): string {
    if (metric.value === null) {
      return t('common.empty')
    }
    if (RATIO_METRICS.has(key)) {
      return `${formatDecimal(metric.value)}x`
    }
    if (MILLION_METRICS.has(key)) {
      return t('companyCard.millions', { value: formatDecimal(metric.value), currency }).trim()
    }
    if (key === 'eps_diluted_ttm') {
      return t('companyCard.perShare', { value: formatDecimal(metric.value), currency })
    }
    if (key === 'revenue_growth_yoy_percent') {
      return formatChangePercent(metric.value)
    }
    return `${formatDecimal(metric.value)}%`
  }

  function metricDetail(metric: FundamentalMetric): string {
    if (metric.value === null || metric.period === null) {
      return metric.reason !== null ? t(`companyCard.reason.${metric.reason}`) : t('common.empty')
    }
    return t(`companyCard.period.${metric.period.kind}`, {
      date: formatMarketDate(metric.period.end),
      form: metric.period.form ?? '',
    }).replace(/ · $/, '')
  }

  if (!signedIn) {
    return null
  }

  const ready = load.status === 'ready' ? load : null
  const card = ready?.card ?? null
  const available = ready?.available ?? false
  const fundamentals = ready?.fundamentals ?? null
  const content = card?.content ?? null

  return (
    <section
      ref={sectionRef}
      id={COMPANY_CARD_ANCHOR}
      aria-labelledby="ai-company-card-title"
      className="scroll-mt-24 rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]"
    >
      <h2 id="ai-company-card-title" className="font-headline text-lg font-black uppercase">
        {t('companyCard.title')}
        {content !== null ? (
          <span className="ml-2 font-mono text-xs font-bold text-on-surface-variant">{content.company.ticker}</span>
        ) : null}
      </h2>
      <p className="mt-2 text-sm text-on-surface-variant">{t('companyCard.explanation')}</p>

      {load.status === 'loading' ? (
        <p role="status" className="mt-4 font-mono text-xs uppercase tracking-wider text-on-surface-variant">
          {t('companyCard.loading')}
        </p>
      ) : null}

      {load.status === 'error' ? (
        <div className="mt-4 flex flex-wrap items-center gap-3">
          <p role="alert" className="text-sm text-secondary">{load.message}</p>
          <button type="button" className={BUTTON_CLASS} onClick={() => setReloadToken((token) => token + 1)}>
            {t('common.retry')}
          </button>
        </div>
      ) : null}

      {/* 1. The AI-written 3-month outlook, first and prominent. */}
      <div data-testid="company-card-outlook" className="mt-4 flex min-w-0 flex-col gap-3 border-t-2 border-outline pt-4 text-sm break-words">
        <div className="flex flex-wrap items-center gap-3">
          <h3 className="font-headline text-xl font-black text-on-surface">{t('companyCard.outlookTitle')}</h3>
          <span className={BADGE_CLASS}>{t('companyCard.badge')}</span>
        </div>
        <p
          data-testid="company-card-warning"
          className="rounded-[4px] border border-outline-variant bg-surface-container px-2 py-1.5 font-mono text-[11px] font-bold text-on-surface"
        >
          {t('companyCard.warning')}
        </p>

        {ready !== null ? (
          <div className="flex flex-col gap-3">
            {card === null ? <p className="text-on-surface">{t('companyCard.empty', { ticker })}</p> : null}
            {card !== null && !card.fresh ? <p className="font-bold text-on-surface">{t('companyCard.stale')}</p> : null}
            {!available ? <p className="text-on-surface-variant">{t('companyCard.notAvailable')}</p> : null}
            {available && (card === null || !card.fresh) ? (
              <div className="flex flex-wrap items-center gap-3">
                <button type="button" className={BUTTON_CLASS} disabled={generating} onClick={() => void generate()}>
                  {card === null ? t('companyCard.generate') : t('companyCard.regenerate')}
                </button>
                {generating ? (
                  <span role="status" className="text-xs text-on-surface-variant">{t('companyCard.generating')}</span>
                ) : null}
              </div>
            ) : null}
            {activeAction?.error ? <p role="alert" className="text-secondary">{activeAction.error}</p> : null}
            {activeAction?.reused ? <p className="text-xs text-on-surface-variant">{t('companyCard.reused')}</p> : null}
          </div>
        ) : null}

        {content !== null ? (
          <>
            <div className="flex flex-wrap items-center gap-2">
              <span
                data-testid="company-card-stance"
                className="rounded-[4px] border-2 border-outline bg-surface-container px-2 py-0.5 font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface"
              >
                {t('companyCard.stance', { stance: t(`companyCard.stances.${content.outlook.stance}`) })}
              </span>
              <span className="font-mono text-[11px] text-on-surface-variant">
                {t('companyCard.confidence', { level: t(`companyCard.confidenceLevels.${content.outlook.confidence}`) })}
              </span>
            </div>
            <p className="text-on-surface">{content.outlook.summary}</p>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="flex flex-col gap-1">
                <SubHeading>{t('companyCard.macro')}</SubHeading>
                <p className="text-on-surface">{content.outlook.macro_context}</p>
              </div>
              <div className="flex flex-col gap-1">
                <SubHeading>{t('companyCard.sectorContext')}</SubHeading>
                <p className="text-on-surface">{content.outlook.sector_context}</p>
              </div>
              <div className="flex flex-col gap-1">
                <SubHeading>{t('companyCard.drivers')}</SubHeading>
                <ul className="list-disc pl-5 text-on-surface">
                  {content.outlook.drivers.map((item, index) => <li key={index}>{item}</li>)}
                </ul>
              </div>
              <div className="flex flex-col gap-1">
                <SubHeading>{t('companyCard.risks')}</SubHeading>
                <ul className="list-disc pl-5 text-on-surface">
                  {content.outlook.risks.map((item, index) => <li key={index}>{item}</li>)}
                </ul>
              </div>
            </div>
          </>
        ) : null}
      </div>

      {/* 2. Verified metrics computed from SEC filings. */}
      {ready !== null ? (
        <div data-testid="company-card-metrics" className="mt-4 flex min-w-0 flex-col gap-2 border-t-2 border-outline pt-4 text-sm break-words">
          <div className="flex flex-wrap items-center gap-3">
            <h3 className="font-headline text-base font-black text-on-surface">{t('companyCard.metricsTitle')}</h3>
            {fundamentals !== null && fundamentals.period_end !== null ? (
              <span
                data-testid="company-card-source"
                className="rounded border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase text-on-surface"
              >
                {t('companyCard.sourceBadge')}
              </span>
            ) : null}
          </div>
          {fundamentals === null ? (
            <p className="text-on-surface-variant">{t('companyCard.fundamentalsUnavailable')}</p>
          ) : fundamentals.period_end === null ? (
            <p className="text-on-surface-variant">{t('companyCard.fundamentalsNoData')}</p>
          ) : (
            <>
              <dl className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {FUNDAMENTAL_METRICS.map((key) => {
                  const metric = fundamentals.metrics[key]
                  return (
                    <div
                      key={key}
                      data-testid={`company-card-metric-${key}`}
                      className="flex min-w-0 flex-col rounded-[4px] border border-outline-variant bg-surface-container px-2 py-1.5"
                    >
                      <div className="flex flex-wrap items-baseline justify-between gap-x-2">
                        <dt className="min-w-0 text-xs font-bold text-on-surface-variant">{t(`companyCard.metric.${key}`)}</dt>
                        <dd className="shrink-0 font-mono text-sm font-bold text-on-surface">{metricValue(key, metric, fundamentals.currency)}</dd>
                      </div>
                      <span className="font-mono text-[10px] text-on-surface-variant">{metricDetail(metric)}</span>
                    </div>
                  )
                })}
              </dl>
              {fundamentals.pending_filing !== null ? (
                <p data-testid="company-card-pending" className="text-xs font-bold text-on-surface">
                  {t('companyCard.pendingFiling', {
                    form: fundamentals.pending_filing.form ?? '',
                    date: formatMarketDate(fundamentals.pending_filing.filed_at),
                  })}
                </p>
              ) : null}
              <p className="font-mono text-[11px] text-on-surface-variant">
                {fundamentals.price !== null
                  ? t('companyCard.fundamentalsSource', {
                      price: formatMarketDate(fundamentals.price.date),
                      updated: formatDateTime(fundamentals.fetched_at),
                    })
                  : t('companyCard.fundamentalsSourceNoPrice', { updated: formatDateTime(fundamentals.fetched_at) })}
              </p>
              <p className="text-[11px] text-on-surface-variant">{t('companyCard.fundamentalsNote')}</p>
            </>
          )}
        </div>
      ) : null}

      {/* 3. AI business description, limitations, sources and generation footer. */}
      {card !== null && content !== null ? (
        <article className="mt-4 flex min-w-0 flex-col gap-4 border-t-2 border-outline pt-4 text-sm break-words">
          <div className="flex flex-col gap-1">
            <div className="flex flex-wrap items-center gap-2">
              <h3 className="font-headline text-base font-black text-on-surface">
                {content.company.name} <span className="font-mono text-xs font-bold text-on-surface-variant">{content.company.ticker}</span>
              </h3>
              <span className={BADGE_CLASS}>{t('companyCard.badge')}</span>
            </div>
            <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-0.5 text-xs">
              <dt className="font-bold text-on-surface-variant">{t('companyCard.sector')}</dt>
              <dd className="text-on-surface">{content.company.sector ?? t('common.empty')}</dd>
              <dt className="font-bold text-on-surface-variant">{t('companyCard.industry')}</dt>
              <dd className="text-on-surface">{content.company.industry ?? t('common.empty')}</dd>
            </dl>
          </div>

          {content.company.description !== null ? (
            <div className="flex flex-col gap-1">
              <SubHeading>{t('companyCard.business')}</SubHeading>
              <p className="text-on-surface">{content.company.description}</p>
            </div>
          ) : null}

          <div className="flex flex-col gap-1">
            <SubHeading>{t('companyCard.limitations')}</SubHeading>
            <p className="text-on-surface-variant">{content.limitations}</p>
          </div>

          {content.sources.length > 0 ? (
            <div className="flex flex-col gap-1">
              <SubHeading>{t('companyCard.sources')}</SubHeading>
              <ul className="list-disc pl-5 text-xs text-on-surface-variant">
                {content.sources.map((item, index) => <li key={index}>{item}</li>)}
              </ul>
            </div>
          ) : null}

          <footer className="flex flex-col gap-0.5 border-t border-outline-variant pt-2 font-mono text-[11px] text-on-surface-variant">
            <span>{t('companyCard.generatedAt', { date: formatDateTime(card.generated_at), model: card.model })}</span>
            <span>
              {card.context_mode === 'identity+technical' && card.context_as_of_date !== null
                ? t('companyCard.contextTechnical', { date: formatMarketDate(card.context_as_of_date) })
                : t('companyCard.contextIdentity')}
            </span>
            {card.fundamentals_period_end ? (
              <span>{t('companyCard.contextFundamentals', { date: formatMarketDate(card.fundamentals_period_end) })}</span>
            ) : null}
          </footer>
        </article>
      ) : null}
    </section>
  )
}
