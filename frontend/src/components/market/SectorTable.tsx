import { useState } from 'react'
import { Link } from 'react-router'
import { useI18n } from '../../i18n/useI18n.ts'
import type { MarketContext, MarketSectorRow } from '../../lib/api.ts'
import HistoryPanel from './HistoryPanel.tsx'
import MarketCard, { Chip, NoData } from './MarketCard.tsx'
import { TREND_ICON, dateLine, signedPercent, signedPp, trendClass, percent } from './marketText.ts'

type Period = 'week' | 'month' | 'quarter'

const PERIOD_FIELD: Record<Period, 'ret_5' | 'ret_20' | 'ret_60'> = {
  week: 'ret_5',
  month: 'ret_20',
  quarter: 'ret_60',
}

const PERIODS: readonly Period[] = ['week', 'month', 'quarter']

function signClass(value: number | null): string {
  if (value === null || value === 0) return 'text-on-surface'
  return value > 0 ? 'text-gain' : 'text-secondary'
}

export function TrendChip({ trend }: { trend: MarketSectorRow['trend'] }) {
  const { t } = useI18n()
  if (trend === null) {
    return <Chip className="text-on-surface-variant">{t('marketContext.trend.unknown')}</Chip>
  }
  return (
    <Chip className={trendClass(trend)}>
      <span aria-hidden="true">{TREND_ICON[trend]}</span>
      {t(`marketContext.trend.${trend}`)}
    </Chip>
  )
}

/**
 * The 11 sector ETFs ranked by 3-month relative strength vs SPY. The period
 * control switches only the return column (relative strength is stored for
 * 20 and 60 sessions and the rank uses 60). Rows link to the ETF's chart.
 */
export default function SectorTable({ sectors }: { sectors: MarketContext['sectors'] }) {
  const i18n = useI18n()
  const { t } = i18n
  const [period, setPeriod] = useState<Period>('week')
  const rows = sectors?.rows ?? []
  const field = PERIOD_FIELD[period]

  const sectorName = (row: MarketSectorRow) =>
    row.sector_key === null ? row.ticker : t(`marketContext.sectors.names.${row.sector_key}`)

  return (
    <MarketCard
      title={t('marketContext.sectors.title')}
      dateLine={sectors ? dateLine(i18n, sectors.date) : null}
      stale={sectors?.stale ?? false}
      meaning={t('marketContext.sectors.meaning')}
      sources={[t('marketContext.sourceChartiko')]}
      history={
        rows.length > 0 ? (
          <HistoryPanel
            selectLabel={t('marketContext.sectors.historySector')}
            options={rows.map((row) => ({
              metric: `sector_rs_60:${row.ticker}` as const,
              label: `${row.ticker} · ${sectorName(row)}`,
              chartLabel: t('marketContext.history.metrics.sector_rs_60', { ticker: row.ticker }),
              reference: [0],
              formatValue: (value: number) => signedPp(i18n, value),
            }))}
          />
        ) : null
      }
    >
      {rows.length === 0 ? (
        <NoData />
      ) : (
        <>
          <div role="group" aria-label={t('marketContext.sectors.periodAria')} className="flex flex-wrap gap-1">
            {PERIODS.map((value) => (
              <button
                key={value}
                type="button"
                aria-pressed={period === value}
                onClick={() => setPeriod(value)}
                className={`rounded-[4px] border-2 border-outline px-2.5 py-1 font-headline text-[11px] font-bold uppercase tracking-wide focus:outline-2 focus:outline-offset-2 focus:outline-outline ${
                  period === value ? 'bg-primary-container text-on-primary-container' : 'bg-surface-bright text-on-surface'
                }`}
              >
                {t(`marketContext.sectors.period.${value}`)}
              </button>
            ))}
          </div>
          <div
            role="region"
            aria-label={t('marketContext.sectors.tableAria')}
            tabIndex={0}
            className="overflow-x-auto rounded-[4px] border border-outline-variant focus:outline-2 focus:outline-offset-2 focus:outline-outline"
          >
            <table className="w-full min-w-[40rem] text-left text-xs">
              <thead className="border-b-2 border-outline bg-surface-container font-mono text-[10px] uppercase text-on-surface-variant">
                <tr>
                  <th scope="col" className="px-2 py-1.5 text-right">{t('marketContext.sectors.columns.rank')}</th>
                  <th scope="col" className="px-2 py-1.5">{t('marketContext.sectors.columns.sector')}</th>
                  <th scope="col" className="px-2 py-1.5 text-right">
                    {t('marketContext.sectors.columns.return')} ({t(`marketContext.sectors.period.${period}`)})
                  </th>
                  <th scope="col" className="px-2 py-1.5 text-right">{t('marketContext.sectors.columns.rs')}</th>
                  <th scope="col" className="px-2 py-1.5">{t('marketContext.sectors.columns.trend')}</th>
                  <th scope="col" className="px-2 py-1.5 text-right">{t('marketContext.sectors.columns.breadth')}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-outline-variant">
                {rows.map((row) => {
                  const ret = row[field]
                  return (
                    <tr key={row.ticker} className="hover:bg-surface-container">
                      <td className="px-2 py-1.5 text-right font-mono">{row.rs_rank ?? t('common.empty')}</td>
                      <th scope="row" className="px-2 py-1.5 font-normal">
                        <Link
                          to={`/instruments/${row.ticker}`}
                          aria-label={t('marketContext.sectors.rowLinkAria', { ticker: row.ticker })}
                          className="group flex flex-col focus:outline-2 focus:outline-offset-2 focus:outline-outline"
                        >
                          <span className="font-bold text-on-surface group-hover:underline">{sectorName(row)}</span>
                          <span className="font-mono text-[10px] text-on-surface-variant">{row.ticker}</span>
                        </Link>
                      </th>
                      <td className={`px-2 py-1.5 text-right font-mono font-bold ${signClass(ret)}`}>
                        {ret === null ? t('common.empty') : signedPercent(i18n, ret)}
                      </td>
                      <td className={`px-2 py-1.5 text-right font-mono ${signClass(row.rs_60)}`}>
                        {row.rs_60 === null ? t('common.empty') : signedPp(i18n, row.rs_60)}
                      </td>
                      <td className="px-2 py-1.5">
                        <TrendChip trend={row.trend} />
                      </td>
                      <td className="px-2 py-1.5 text-right font-mono">
                        {row.breadth_200 === null ? t('common.empty') : percent(i18n, row.breadth_200, 1)}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </>
      )}
    </MarketCard>
  )
}
