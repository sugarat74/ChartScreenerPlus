import { useI18n } from '../../i18n/useI18n.ts'
import type { MarketMacro } from '../../lib/api.ts'
import HistoryPanel from './HistoryPanel.tsx'
import MarketCard, { NoData, ReadingRow } from './MarketCard.tsx'
import { hasValue, signedPercent } from './marketText.ts'

/** Fed net liquidity (billions USD) with its 4- and 13-week changes. */
export default function LiquidityCard({ macro }: { macro: MarketMacro }) {
  const i18n = useI18n()
  const { t, formatNumber } = i18n
  const liquidity = macro.liquidity
  const empty = !(liquidity && [liquidity.net_liquidity, liquidity.change_4w_pct, liquidity.change_13w_pct].some(hasValue))
  const billions = (value: number) => t('marketContext.units.billionsUsd', { value: formatNumber(value, 0) })
  const change = (value: number) => signedPercent(i18n, value)

  return (
    <MarketCard
      title={t('marketContext.liquidity.title')}
      meaning={t('marketContext.liquidity.meaning')}
      sources={[...macro.sources.liquidity, t('marketContext.sourceComputed')]}
      history={
        <HistoryPanel
          options={[{ metric: 'net_liquidity', label: t('marketContext.history.metrics.net_liquidity'), formatValue: billions }]}
        />
      }
    >
      {empty ? (
        <NoData />
      ) : (
        <>
          <dl>
            <ReadingRow label={t('marketContext.liquidity.net')} reading={liquidity?.net_liquidity ?? null} format={billions} />
            <ReadingRow label={t('marketContext.liquidity.change4w')} reading={liquidity?.change_4w_pct ?? null} format={change} />
            <ReadingRow label={t('marketContext.liquidity.change13w')} reading={liquidity?.change_13w_pct ?? null} format={change} />
          </dl>
          <p className="font-mono text-[10px] text-on-surface-variant">{t('marketContext.liquidity.formula')}</p>
        </>
      )}
    </MarketCard>
  )
}
