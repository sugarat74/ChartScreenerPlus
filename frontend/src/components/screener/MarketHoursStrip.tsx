/**
 * Market hours strip of the home page (market-hours-status): whether NYSE/
 * Nasdaq, Bolsa de Madrid and Xetra are open right now, with the next open or
 * close in the viewer's timezone, and a disclosure with each market's regular
 * hours, upcoming holidays and early closes.
 *
 * Static schedule and clock only: `GET /api/markets/status` is fetched once and
 * the status is derived from the clock every minute (no refetch while the
 * payload still covers the moment; one refetch when a boundary has passed with
 * nothing newer to show). It never reads market data. Open/closed is text plus
 * a filled/hollow marker, never colour alone. Renders nothing while loading,
 * when the request fails or when no market is public, so the Screener is
 * unaffected.
 */

import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { useI18n } from '../../i18n/useI18n.ts'
import { marketStatusApi } from '../../lib/api.ts'
import type { MarketStatus } from '../../lib/api.ts'
import { formatBoundary, marketPhase, needsRefresh } from '../../lib/marketHours.ts'

const TICK_MS = 60_000

interface MarketHoursStripProps {
  /** IANA timezone to format times in; the viewer's own when omitted (tests pass one). */
  timeZone?: string
}

export default function MarketHoursStrip({ timeZone }: MarketHoursStripProps) {
  const { t, locale, formatMarketDate } = useI18n()
  const [markets, setMarkets] = useState<MarketStatus[]>([])
  const [now, setNow] = useState(() => Date.now())
  const [expanded, setExpanded] = useState(false)
  const panelId = useId()
  const loading = useRef(false)

  const load = useCallback((signal?: AbortSignal) => {
    if (loading.current) {
      return
    }
    loading.current = true
    marketStatusApi
      .list(signal)
      .then((items) => {
        if (signal?.aborted !== true) {
          setMarkets(items)
        }
      })
      .catch(() => {
        // A failed request leaves the strip as it was (or hidden): never an error on the home page.
      })
      .finally(() => {
        if (signal?.aborted !== true) {
          loading.current = false
        }
      })
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    load(controller.signal)
    return () => {
      controller.abort()
      // The aborted request must not block the next mount's (StrictMode) or a later load.
      loading.current = false
    }
  }, [load])

  useEffect(() => {
    const tick = () => setNow(Date.now())
    const id = window.setInterval(tick, TICK_MS)
    document.addEventListener('visibilitychange', tick)
    return () => {
      window.clearInterval(id)
      document.removeEventListener('visibilitychange', tick)
    }
  }, [])

  const stale = needsRefresh(markets, now)
  useEffect(() => {
    if (stale) {
      load()
    }
  }, [stale, load])

  if (markets.length === 0) {
    return null
  }

  const time = (ms: number) => formatBoundary(locale, ms, now, timeZone)

  return (
    <section aria-label={t('marketHours.groupAria')} className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
        <h2 className="font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant">
          {t('marketHours.title')}
        </h2>
        <button
          type="button"
          aria-expanded={expanded}
          aria-controls={panelId}
          onClick={() => setExpanded((value) => !value)}
          className="font-mono text-xs font-bold text-on-surface underline underline-offset-4 hover:text-primary focus:outline-2 focus:outline-offset-2 focus:outline-outline"
        >
          {expanded ? t('marketHours.detailsHide') : t('marketHours.details')}
        </button>
      </div>

      <ul className="flex flex-wrap gap-2">
        {markets.map((market) => {
          const phase = marketPhase(market, now)
          const name = t(`marketHours.markets.${market.code}`)
          return (
            <li
              key={market.code}
              data-market={market.code}
              className="flex min-w-[10.5rem] flex-1 flex-col gap-0.5 rounded-md border-2 border-outline bg-surface-bright px-3 py-1.5 shadow-[2px_2px_0px_var(--color-shadow)]"
            >
              <span className="font-headline text-xs font-black uppercase tracking-wide text-on-surface">{name}</span>
              <span className="flex flex-wrap items-baseline gap-x-2 font-mono text-xs text-on-surface">
                <span className="flex items-center gap-1 font-bold">
                  <span aria-hidden="true" className={phase.open ? 'text-gain' : 'text-on-surface-variant'}>
                    {phase.open ? '●' : '○'}
                  </span>
                  {phase.open ? t('marketHours.open') : t('marketHours.closed')}
                </span>
                <span className="text-on-surface-variant">
                  {phase.open
                    ? t('marketHours.closes', { time: time(phase.closesAt) })
                    : phase.opensAt === null
                      ? t('marketHours.noNextOpen')
                      : t('marketHours.opens', { time: time(phase.opensAt) })}
                </span>
              </span>
              {phase.open && market.early_close ? (
                <span className="font-mono text-[10px] uppercase tracking-wide text-on-surface-variant">
                  {t('marketHours.earlyCloseToday')}
                </span>
              ) : null}
            </li>
          )
        })}
      </ul>

      <p className="font-mono text-[11px] text-on-surface-variant">{t('marketHours.note')}</p>

      {expanded ? (
        <div
          id={panelId}
          className="flex flex-col gap-3 rounded-md border-2 border-dashed border-outline-variant bg-surface-container p-3"
        >
          <div className="grid gap-3 md:grid-cols-3">
            {markets.map((market) => (
              <div key={market.code} className="flex min-w-0 flex-col gap-1.5">
                <h3 className="font-headline text-xs font-black uppercase tracking-wide text-on-surface">
                  {t(`marketHours.markets.${market.code}`)}
                </h3>
                <p className="font-mono text-xs text-on-surface-variant">
                  {t('marketHours.regularHours', {
                    open: market.regular_hours.open,
                    close: market.regular_hours.close,
                    zone: market.timezone,
                  })}
                </p>
                {market.calendar_covered ? null : (
                  <p className="font-mono text-xs font-bold text-on-surface">
                    {t('marketHours.notCovered', { year: market.calendar_year })}
                  </p>
                )}
                <p className="font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant">
                  {t('marketHours.upcoming')}
                </p>
                {market.upcoming.length === 0 ? (
                  <p className="font-mono text-xs text-on-surface-variant">{t('marketHours.noUpcoming')}</p>
                ) : (
                  <ul className="flex flex-col gap-1 font-mono text-xs text-on-surface">
                    {market.upcoming.map((event) => (
                      <li key={`${event.type}-${event.date}`} className="flex flex-wrap gap-x-2">
                        <span className="font-bold">{formatMarketDate(event.date)}</span>
                        <span className="text-on-surface-variant">
                          {event.type === 'holiday'
                            ? t('marketHours.holiday')
                            : t('marketHours.earlyClose', { time: event.close ?? '' })}
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            ))}
          </div>
        </div>
      ) : null}
    </section>
  )
}
