/**
 * "Contexto de mercado" strip of the chart page (market-context-patterns): the
 * stock's sector ETF, the sector's trend and relative-strength rank and the
 * Market Regime, each from the latest session, with a link to /mercado.
 * Descriptive only (icon + text, never colour alone).
 *
 * Access comes from `useMarketContextAccess` (the server enforces it): nothing
 * while it loads or for a hidden user, a dashed sign-in box for a Visitor once
 * the owner's go-live flag is on, and the data for an allowed user. A failed
 * request shows a retry and never affects the chart.
 */

import { useId } from 'react'
import { Link } from 'react-router'
import type { MarketContextAccess } from '../../auth/useMarketContextAccess.ts'
import Interpolate from '../../i18n/Interpolate.tsx'
import { useI18n } from '../../i18n/useI18n.ts'
import type { InstrumentMarketContext } from '../../lib/api.ts'
import { MARKET_ROUTE } from '../../nav.ts'
import { CARD_CLASS, Chip, StaleBadge } from '../market/MarketCard.tsx'
import MarketSignInBox from '../market/MarketSignInBox.tsx'
import { REGIME_LABEL_STYLE, TREND_ICON, dateLine, trendClass } from '../market/marketText.ts'
import { TrendChip } from '../market/SectorTable.tsx'
import type { InstrumentMarketContextState } from './useInstrumentMarketContext.ts'

const LINK_CLASS =
  'font-mono text-xs font-bold text-on-surface underline underline-offset-4 focus:outline-2 focus:outline-offset-2 focus:outline-outline'

const BUTTON_CLASS =
  'rounded-md border-2 border-outline bg-surface-bright px-3 py-1 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_var(--color-shadow)]'

/**
 * The three compact chips shown next to each chartist pattern: sector trend,
 * sector relative-strength rank and the Market Regime (text with icons).
 */
export function ContextChips({ context }: { context: InstrumentMarketContext }) {
  const { t } = useI18n()
  const { sector, regime } = context

  return (
    <>
      {sector === null ? (
        <Chip className="text-on-surface-variant">{t('marketContext.strip.noSector')}</Chip>
      ) : (
        <>
          {sector.trend === null ? (
            <Chip className="text-on-surface-variant">
              {t('screener.sectorContext.summaryTrend', { trend: t('marketContext.trend.unknown') })}
            </Chip>
          ) : (
            <Chip className={trendClass(sector.trend)}>
              <span aria-hidden="true">{TREND_ICON[sector.trend]}</span>
              {t('screener.sectorContext.summaryTrend', { trend: t(`marketContext.trend.${sector.trend}`) })}
            </Chip>
          )}
          <Chip>
            {sector.rs_rank === null
              ? t('marketContext.strip.rankUnknown')
              : t('marketContext.strip.rank', { rank: sector.rs_rank, of: sector.rs_rank_of })}
          </Chip>
        </>
      )}
      {regime === null ? (
        <Chip className="text-on-surface-variant">{t('marketContext.strip.regimeUnknown')}</Chip>
      ) : (
        <Chip className={REGIME_LABEL_STYLE[regime.regime].className}>
          <span aria-hidden="true">{REGIME_LABEL_STYLE[regime.regime].icon}</span>
          {t('marketContext.strip.regime', { label: t(`marketContext.regime.labels.${regime.regime}`) })}
        </Chip>
      )}
    </>
  )
}

function ReadyStrip({ data, preview }: { data: InstrumentMarketContext; preview: boolean }) {
  const i18n = useI18n()
  const { t } = i18n
  const titleId = useId()
  const { sector, regime } = data
  const date = sector?.date ?? regime?.date ?? null
  const regimeStyle = regime === null ? null : REGIME_LABEL_STYLE[regime.regime]

  return (
    <section aria-labelledby={titleId} className={CARD_CLASS}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <h2 id={titleId} className="font-headline text-sm font-black uppercase tracking-wide text-on-surface">
            {t('marketContext.strip.title')}
          </h2>
          {preview ? (
            <span className="rounded-[4px] border border-dashed border-outline bg-primary-container px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase text-on-primary-container">
              {t('marketContext.strip.preview')}
            </span>
          ) : null}
        </div>
        {date !== null ? <p className="font-mono text-xs text-on-surface-variant">{dateLine(i18n, date)}</p> : null}
      </div>

      <ul aria-label={t('marketContext.strip.aria')} className="flex min-w-0 flex-wrap items-center gap-x-5 gap-y-2 text-sm text-on-surface">
        <li className="flex min-w-0 flex-wrap items-center gap-2">
          {sector === null ? (
            <span className="text-on-surface-variant">{t('marketContext.strip.noSector')}</span>
          ) : (
            <span>
              {sector.sector_key === null
                ? t('marketContext.strip.sector', { name: sector.etf })
                : t('marketContext.strip.sector', { name: t(`marketContext.sectors.names.${sector.sector_key}`) })}{' '}
              (
              <Link
                to={`/instruments/${sector.etf}`}
                aria-label={t('marketContext.sectors.rowLinkAria', { ticker: sector.etf })}
                className="font-mono font-bold underline underline-offset-2 focus:outline-2 focus:outline-offset-2 focus:outline-outline"
              >
                {sector.etf}
              </Link>
              )
            </span>
          )}
          {sector?.stale ? <StaleBadge /> : null}
        </li>
        {sector !== null ? (
          <li className="flex items-center gap-2">
            <span className="text-on-surface-variant">{t('marketContext.strip.sectorTrend')}</span>
            <TrendChip trend={sector.trend} />
          </li>
        ) : null}
        {sector !== null ? (
          <li className="font-mono text-xs font-bold">
            {sector.rs_rank === null
              ? t('marketContext.strip.rankUnknown')
              : t('marketContext.strip.rank', { rank: sector.rs_rank, of: sector.rs_rank_of })}
          </li>
        ) : null}
        <li className="flex flex-wrap items-center gap-2">
          {regime === null || regimeStyle === null ? (
            <span className="text-on-surface-variant">{t('marketContext.strip.regimeUnknown')}</span>
          ) : (
            <span>
              <Interpolate
                template={t('marketContext.strip.regime')}
                values={{
                  label: (
                    <span className={`font-bold ${regimeStyle.className}`}>
                      <span aria-hidden="true">{regimeStyle.icon} </span>
                      {t(`marketContext.regime.labels.${regime.regime}`)}
                    </span>
                  ),
                }}
              />
              {regime.date !== date ? (
                <span className="ml-1 font-mono text-[10px] text-on-surface-variant">
                  {t('marketContext.strip.regimeDate', { date: i18n.formatMarketDate(regime.date) })}
                </span>
              ) : null}
            </span>
          )}
          {regime?.stale ? <StaleBadge /> : null}
        </li>
      </ul>

      <Link to={MARKET_ROUTE} className={`w-fit ${LINK_CLASS}`}>
        {t('marketContext.strip.openMarket')}
      </Link>

      <footer className="flex flex-col gap-1 border-t border-outline-variant pt-2 text-[11px] leading-4 text-on-surface-variant">
        <p>{t('marketContext.legal.disclaimer')}</p>
        {regime !== null ? (
          <p lang="en" data-testid="strip-fred-notice">
            {t('marketContext.legal.fredNotice')}
          </p>
        ) : null}
      </footer>
    </section>
  )
}

export default function MarketContextStrip({
  access,
  state,
  onRetry,
}: {
  access: MarketContextAccess
  state: InstrumentMarketContextState
  onRetry: () => void
}) {
  const { t } = useI18n()

  if (access.status === 'signIn' || (access.status === 'allowed' && state.status === 'signIn')) {
    return (
      <MarketSignInBox
        title={t('marketContext.strip.signInTitle')}
        body={t('marketContext.strip.signInBody')}
        link={t('marketContext.strip.signIn')}
      />
    )
  }

  if (access.status !== 'allowed') {
    return null
  }

  switch (state.status) {
    case 'loading':
      return (
        <p role="status" className="font-mono text-xs text-on-surface-variant">
          {t('marketContext.strip.loading')}
        </p>
      )
    case 'error':
      return (
        <div
          role="alert"
          className="flex flex-wrap items-center gap-3 rounded-md border-2 border-secondary bg-secondary-container px-3 py-2 text-xs text-on-secondary-container"
        >
          {t('marketContext.strip.error')}
          <button type="button" onClick={onRetry} className={BUTTON_CLASS}>
            {t('marketContext.strip.retry')}
          </button>
        </div>
      )
    case 'ready':
      return <ReadyStrip data={state.data} preview={access.preview} />
    default:
      return null
  }
}
