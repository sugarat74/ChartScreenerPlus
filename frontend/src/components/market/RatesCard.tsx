import { useI18n } from '../../i18n/useI18n.ts'
import type { MarketMacro } from '../../lib/api.ts'
import HistoryPanel from './HistoryPanel.tsx'
import MarketCard, { Chip, NoData, ReadingRow } from './MarketCard.tsx'
import { hasValue, percent, signedPp } from './marketText.ts'

/** Treasury yields, the 20-session change of the 10-year and the curve spreads. */
export default function RatesCard({ macro }: { macro: MarketMacro }) {
  const i18n = useI18n()
  const { t } = i18n
  const rates = macro.rates
  const curve = macro.curve
  const readings = [rates?.dgs3mo, rates?.dgs2, rates?.dgs10, rates?.dgs10_change_20, curve?.spread_10y2y, curve?.spread_10y3m]
  const empty = !readings.some(hasValue)
  const level = (value: number) => percent(i18n, value)
  const pp = (value: number) => signedPp(i18n, value)

  return (
    <MarketCard
      title={t('marketContext.rates.title')}
      meaning={t('marketContext.rates.meaning')}
      sources={[...macro.sources.rates, t('marketContext.sourceComputed')]}
      history={
        <HistoryPanel
          options={[
            { metric: 'dgs10', label: t('marketContext.history.metrics.dgs10'), formatValue: level },
            { metric: 'spread_10y3m', label: t('marketContext.history.metrics.spread_10y3m'), reference: [0], formatValue: pp },
          ]}
        />
      }
    >
      {empty ? (
        <NoData />
      ) : (
        <>
          {curve?.inverted === true ? (
            <Chip className="w-fit text-secondary">
              <span aria-hidden="true">▼</span>
              {t('marketContext.rates.inverted')}
            </Chip>
          ) : null}
          <dl>
            <ReadingRow label={t('marketContext.rates.dgs3mo')} reading={rates?.dgs3mo ?? null} format={level} />
            <ReadingRow label={t('marketContext.rates.dgs2')} reading={rates?.dgs2 ?? null} format={level} />
            <ReadingRow label={t('marketContext.rates.dgs10')} reading={rates?.dgs10 ?? null} format={level} />
            <ReadingRow label={t('marketContext.rates.change20')} reading={rates?.dgs10_change_20 ?? null} format={pp} />
            <ReadingRow label={t('marketContext.rates.spread10y2y')} reading={curve?.spread_10y2y ?? null} format={pp} />
            <ReadingRow label={t('marketContext.rates.spread10y3m')} reading={curve?.spread_10y3m ?? null} format={pp} />
          </dl>
        </>
      )}
    </MarketCard>
  )
}
