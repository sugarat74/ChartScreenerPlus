import { useEffect, useState } from 'react'
import BreadthCard from '../components/market/BreadthCard.tsx'
import CentralBanksCard from '../components/market/CentralBanksCard.tsx'
import LeadingCard from '../components/market/LeadingCard.tsx'
import LiquidityCard from '../components/market/LiquidityCard.tsx'
import { CARD_CLASS } from '../components/market/MarketCard.tsx'
import MarketLegalFooter from '../components/market/MarketLegalFooter.tsx'
import { dateLine } from '../components/market/marketText.ts'
import RatesCard from '../components/market/RatesCard.tsx'
import RegimeCard from '../components/market/RegimeCard.tsx'
import SectorTable from '../components/market/SectorTable.tsx'
import { useI18n } from '../i18n/useI18n.ts'
import { ApiError, marketContextApi } from '../lib/api.ts'
import type { MarketContext } from '../lib/api.ts'

type PageState =
  | { status: 'loading' }
  | { status: 'error' }
  | { status: 'unavailable' }
  | { status: 'ready'; data: MarketContext }

/**
 * `/mercado` (market-context-ui): the Market Regime with its reasons, the
 * sectors, Breadth and the macro cards, each with its own date and source.
 * Descriptive only. The API answers 404 until the owner makes the page public
 * (Admins get a preview with a banner).
 */
export default function MarketPage() {
  const i18n = useI18n()
  const { t } = i18n
  const [state, setState] = useState<PageState>({ status: 'loading' })
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    let active = true
    marketContextApi.latest().then(
      (data) => {
        if (active) setState({ status: 'ready', data })
      },
      (error: unknown) => {
        if (!active) return
        setState(error instanceof ApiError && error.status === 404 ? { status: 'unavailable' } : { status: 'error' })
      },
    )
    return () => {
      active = false
    }
  }, [attempt])

  function retry() {
    setState({ status: 'loading' })
    setAttempt((value) => value + 1)
  }

  let body
  if (state.status === 'loading') {
    body = <p className="font-mono text-sm text-on-surface-variant">{t('common.loading')}</p>
  } else if (state.status === 'unavailable') {
    body = (
      <div className={CARD_CLASS}>
        <p className="font-headline text-lg font-bold text-on-surface">{t('marketContext.unavailable')}</p>
      </div>
    )
  } else if (state.status === 'error') {
    body = (
      <div role="alert" className="flex flex-wrap items-center gap-3 rounded-md border-2 border-secondary bg-secondary-container p-4 text-sm text-on-secondary-container">
        {t('marketContext.loadError')}
        <button
          type="button"
          onClick={retry}
          className="rounded-md border-2 border-outline bg-surface-bright px-3 py-1 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_var(--color-shadow)]"
        >
          {t('marketContext.retry')}
        </button>
      </div>
    )
  } else {
    const { data } = state
    body = (
      <>
        {data.regime ? (
          <p className="font-mono text-xs text-on-surface-variant">{dateLine(i18n, data.regime.date)}</p>
        ) : null}
        {data.public ? null : (
          <p
            role="note"
            className="rounded-md border-2 border-dashed border-outline bg-primary-container px-3 py-2 font-mono text-xs font-bold text-on-primary-container"
          >
            {t('marketContext.previewBanner')}
          </p>
        )}
        <RegimeCard regime={data.regime} />
        <SectorTable sectors={data.sectors} />
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
          <BreadthCard breadth={data.breadth} />
          {data.macro ? (
            <>
              <RatesCard macro={data.macro} />
              <LiquidityCard macro={data.macro} />
              <CentralBanksCard macro={data.macro} />
              <LeadingCard macro={data.macro} />
            </>
          ) : (
            <div className={CARD_CLASS}>
              <p className="font-mono text-xs text-on-surface-variant">{t('marketContext.macroUnavailable')}</p>
            </div>
          )}
        </div>
        <MarketLegalFooter hasMacro={data.macro !== null} />
      </>
    )
  }

  return (
    <article className="mx-auto flex w-full max-w-7xl min-w-0 flex-col gap-4 py-2">
      <header className="flex flex-col gap-1">
        <h1 className="font-headline text-3xl font-black uppercase tracking-tight text-on-surface sm:text-4xl">
          {t('marketContext.title')}
        </h1>
        <p className="max-w-3xl text-sm text-on-surface-variant">{t('marketContext.intro')}</p>
      </header>
      {body}
    </article>
  )
}
