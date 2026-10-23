import type { ReactNode } from 'react'
import { useI18n } from '../../i18n/useI18n.ts'
import type { CentralBankCalendarInfo, CentralBankMeeting, MarketMacro } from '../../lib/api.ts'
import MarketCard, { NoData, StaleBadge } from './MarketCard.tsx'
import { dateLine, percent } from './marketText.ts'

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-3 border-b border-outline-variant py-1.5 last:border-b-0">
      <dt className="min-w-0 text-xs text-on-surface-variant">{label}</dt>
      <dd className="flex flex-col items-end gap-0.5 text-right font-mono text-xs text-on-surface">{children}</dd>
    </div>
  )
}

/** Fed range and ECB deposit rate, last move, last and next meeting, official calendar. */
export default function CentralBanksCard({ macro }: { macro: MarketMacro }) {
  const i18n = useI18n()
  const { t, formatNumber, formatMarketDate } = i18n
  const { fed, ecb } = macro.central_banks
  const level = (value: number | null) => (value === null ? t('common.empty') : formatNumber(value, 2))
  const range = (lower: number | null, upper: number | null) =>
    t('marketContext.units.percent', { value: `${level(lower)}–${level(upper)}` })
  const meeting = (value: CentralBankMeeting | null) =>
    value === null
      ? t('marketContext.centralBanks.noMeeting')
      : t('marketContext.centralBanks.meetingDates', {
          start: formatMarketDate(value.start),
          decision: formatMarketDate(value.decision),
        })
  const direction = (value: 'raise' | 'cut') =>
    value === 'raise' ? t('marketContext.centralBanks.raise') : t('marketContext.centralBanks.cut')
  const calendar = (info: CentralBankCalendarInfo) => (
    <a
      href={info.source_url}
      target="_blank"
      rel="noopener noreferrer"
      className="font-mono text-[10px] text-on-surface-variant underline underline-offset-2 hover:text-on-surface"
    >
      {t('marketContext.centralBanks.calendarSource', { date: formatMarketDate(info.checked_at) })}
    </a>
  )

  const fedHasValue = fed !== null && fed.upper.value !== null
  const ecbHasValue = ecb !== null && ecb.deposit_rate.value !== null

  return (
    <MarketCard
      title={t('marketContext.centralBanks.title')}
      meaning={t('marketContext.centralBanks.meaning')}
      sources={macro.sources.central_banks}
    >
      {!fedHasValue && !ecbHasValue && (fed !== null || ecb !== null) ? (
        <NoData />
      ) : (
        <div className="flex flex-col gap-4">
          <div>
            <h3 className="font-headline text-xs font-bold uppercase tracking-wide text-on-surface">{t('marketContext.centralBanks.fed')}</h3>
            {fed === null ? (
              <p className="font-mono text-[11px] text-on-surface-variant">{t('marketContext.sourceDisabled')}</p>
            ) : (
              <dl>
                <Row label={t('marketContext.centralBanks.fedRange')}>
                  <span className="text-sm font-bold">
                    {fed.upper.value === null ? t('marketContext.noReading') : range(fed.lower.value, fed.upper.value)}
                  </span>
                  {fed.upper.as_of !== null ? (
                    <span className="flex flex-wrap items-center justify-end gap-1 text-[10px] text-on-surface-variant">
                      {dateLine(i18n, fed.upper.as_of)}
                      {fed.upper.stale ? <StaleBadge /> : null}
                    </span>
                  ) : null}
                </Row>
                <Row label={t('marketContext.centralBanks.lastChange')}>
                  {fed.last_change === null
                    ? t('marketContext.centralBanks.noChange')
                    : t('marketContext.centralBanks.changeFromTo', {
                        direction: direction(fed.last_change.direction),
                        date: formatMarketDate(fed.last_change.effective_date),
                        from: range(fed.last_change.from.lower, fed.last_change.from.upper),
                        to: range(fed.last_change.to.lower, fed.last_change.to.upper),
                      })}
                </Row>
                <Row label={t('marketContext.centralBanks.lastMeeting')}>{meeting(fed.last_meeting)}</Row>
                <Row label={t('marketContext.centralBanks.nextMeeting')}>{meeting(fed.next_meeting)}</Row>
              </dl>
            )}
            {fed !== null ? calendar(fed.calendar) : null}
          </div>
          <div>
            <h3 className="font-headline text-xs font-bold uppercase tracking-wide text-on-surface">{t('marketContext.centralBanks.ecb')}</h3>
            {ecb === null ? (
              <p className="font-mono text-[11px] text-on-surface-variant">{t('marketContext.sourceDisabled')}</p>
            ) : (
              <dl>
                <Row label={t('marketContext.centralBanks.ecbRate')}>
                  <span className="text-sm font-bold">
                    {ecb.deposit_rate.value === null ? t('marketContext.noReading') : percent(i18n, ecb.deposit_rate.value)}
                  </span>
                  {ecb.deposit_rate.as_of !== null ? (
                    <span className="flex flex-wrap items-center justify-end gap-1 text-[10px] text-on-surface-variant">
                      {dateLine(i18n, ecb.deposit_rate.as_of)}
                      {ecb.deposit_rate.stale ? <StaleBadge /> : null}
                    </span>
                  ) : null}
                </Row>
                <Row label={t('marketContext.centralBanks.lastChange')}>
                  {ecb.last_change === null
                    ? t('marketContext.centralBanks.noChange')
                    : t('marketContext.centralBanks.changeFromTo', {
                        direction: direction(ecb.last_change.direction),
                        date: formatMarketDate(ecb.last_change.effective_date),
                        from: t('marketContext.units.percent', { value: level(ecb.last_change.from) }),
                        to: t('marketContext.units.percent', { value: level(ecb.last_change.to) }),
                      })}
                </Row>
                <Row label={t('marketContext.centralBanks.lastMeeting')}>{meeting(ecb.last_meeting)}</Row>
                <Row label={t('marketContext.centralBanks.nextMeeting')}>{meeting(ecb.next_meeting)}</Row>
              </dl>
            )}
            {ecb !== null ? calendar(ecb.calendar) : null}
          </div>
        </div>
      )}
    </MarketCard>
  )
}
