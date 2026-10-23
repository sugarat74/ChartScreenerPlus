import type { ReactNode } from 'react'
import { useI18n } from '../../i18n/useI18n.ts'
import type { MarketBreadth } from '../../lib/api.ts'
import HistoryPanel from './HistoryPanel.tsx'
import MarketCard, { NoData } from './MarketCard.tsx'
import { dateLine, percent } from './marketText.ts'
import { TrendChip } from './SectorTable.tsx'

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3 border-b border-outline-variant py-1.5 last:border-b-0">
      <dt className="text-xs text-on-surface-variant">{label}</dt>
      <dd className="text-right font-mono text-sm font-bold text-on-surface">{children}</dd>
    </div>
  )
}

/** S&P 500 Breadth: share above SMA50/SMA200, new highs/lows, advancers/decliners. */
export default function BreadthCard({ breadth }: { breadth: MarketBreadth | null }) {
  const i18n = useI18n()
  const { t, formatInteger } = i18n
  const pct = (value: number | null) => (value === null ? t('common.empty') : percent(i18n, value, 1))

  return (
    <MarketCard
      title={t('marketContext.breadth.title')}
      dateLine={breadth ? dateLine(i18n, breadth.date) : null}
      stale={breadth?.stale ?? false}
      meaning={t('marketContext.breadth.meaning')}
      sources={[t('marketContext.sourceChartiko')]}
      history={
        <HistoryPanel
          options={[
            {
              metric: 'pct_above_sma200',
              label: t('marketContext.history.metrics.pct_above_sma200'),
              reference: [40, 60],
              formatValue: (value) => percent(i18n, value, 1),
            },
          ]}
        />
      }
    >
      {breadth === null ? (
        <NoData />
      ) : (
        <dl>
          <Row label={t('marketContext.breadth.above50')}>{pct(breadth.pct_above_sma50)}</Row>
          <Row label={t('marketContext.breadth.above200')}>{pct(breadth.pct_above_sma200)}</Row>
          <Row label={t('marketContext.breadth.newHighs')}>{formatInteger(breadth.new_highs)}</Row>
          <Row label={t('marketContext.breadth.newLows')}>{formatInteger(breadth.new_lows)}</Row>
          <Row label={t('marketContext.breadth.advancers')}>{formatInteger(breadth.advancers)}</Row>
          <Row label={t('marketContext.breadth.decliners')}>{formatInteger(breadth.decliners)}</Row>
          <Row label={t('marketContext.breadth.members')}>{formatInteger(breadth.members)}</Row>
          <Row label={t('marketContext.breadth.spyTrend')}>
            <TrendChip trend={breadth.spy_trend} />
          </Row>
        </dl>
      )}
    </MarketCard>
  )
}
