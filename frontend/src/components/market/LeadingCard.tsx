import { useI18n } from '../../i18n/useI18n.ts'
import type { MarketMacro } from '../../lib/api.ts'
import HistoryPanel from './HistoryPanel.tsx'
import type { HistoryOption } from './HistoryPanel.tsx'
import MarketCard, { NoData, ReadingRow } from './MarketCard.tsx'
import { hasValue, signed, signedPercent } from './marketText.ts'

/**
 * Leading data: jobless claims (weekly), building permits and the OECD CLI
 * (monthly, dated by month) and the NFCI ("No disponible" while it is off).
 */
export default function LeadingCard({ macro }: { macro: MarketMacro }) {
  const i18n = useI18n()
  const { t, formatInteger, formatNumber } = i18n
  const leading = macro.leading
  const empty = !(leading && Object.values(leading).some(hasValue))
  const count = (value: number) => formatInteger(value)
  const change = (value: number) => signedPercent(i18n, value)
  const permits = (value: number) => t('marketContext.units.thousandUnits', { value: formatNumber(value, 0) })
  const index = (value: number) => t('marketContext.units.index', { value: formatNumber(value, 2) })
  const indexChange = (value: number) => signed(i18n, value)

  const options: HistoryOption[] = [
    { metric: 'icsa_4w', label: t('marketContext.history.metrics.icsa_4w'), formatValue: count },
  ]
  if (leading?.nfci) {
    options.push({ metric: 'nfci', label: t('marketContext.history.metrics.nfci'), reference: [0], formatValue: indexChange })
  }

  return (
    <MarketCard
      title={t('marketContext.leading.title')}
      meaning={t('marketContext.leading.meaning')}
      sources={macro.sources.leading}
      history={<HistoryPanel options={options} />}
    >
      {empty ? (
        <NoData />
      ) : (
        <dl>
          <ReadingRow label={t('marketContext.leading.icsa')} reading={leading?.icsa ?? null} format={count} />
          <ReadingRow label={t('marketContext.leading.icsa4w')} reading={leading?.icsa_4w ?? null} format={count} />
          <ReadingRow label={t('marketContext.leading.icsaVsMin')} reading={leading?.icsa_4w_vs_52w_min_pct ?? null} format={change} />
          <ReadingRow label={t('marketContext.leading.permit')} reading={leading?.permit ?? null} format={permits} monthly />
          <ReadingRow label={t('marketContext.leading.permitChange')} reading={leading?.permit_change_3m_pct ?? null} format={change} monthly />
          <ReadingRow label={t('marketContext.leading.oecd')} reading={leading?.oecd_cli ?? null} format={index} monthly />
          <ReadingRow label={t('marketContext.leading.oecdChange')} reading={leading?.oecd_cli_change_1m ?? null} format={indexChange} monthly />
          <ReadingRow label={t('marketContext.leading.nfci')} reading={leading?.nfci ?? null} format={indexChange} />
        </dl>
      )}
    </MarketCard>
  )
}
