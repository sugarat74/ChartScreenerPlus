/**
 * Pure helpers of the market hours strip (market-hours-status). The server
 * sends each market's static schedule as UTC instants; the status is derived
 * here from the clock, so it flips at the boundary without a refetch. A
 * refetch is only needed once the displayed boundary has passed with nothing
 * newer to show (`needsRefresh`). No market data is involved.
 */

import type { MarketStatus } from './api.ts'

export type MarketPhase =
  | { open: true; closesAt: number }
  | { open: false; opensAt: number | null; stale: boolean }

function instant(iso: string | null): number | null {
  if (iso === null) {
    return null
  }
  const value = Date.parse(iso)
  return Number.isNaN(value) ? null : value
}

/**
 * The state of a market at `now` (epoch ms). Open inside today's session;
 * otherwise closed, with the next open from today's session (not started yet)
 * or the payload's `next_open_at`. `stale` is true when that next open is
 * already in the past, which means the payload no longer describes today and
 * must be refetched.
 */
export function marketPhase(market: MarketStatus, now: number): MarketPhase {
  const opens = instant(market.opens_at)
  const closes = instant(market.closes_at)

  if (opens !== null && closes !== null && now >= opens && now < closes) {
    return { open: true, closesAt: closes }
  }
  if (opens !== null && now < opens) {
    return { open: false, opensAt: opens, stale: false }
  }

  const next = instant(market.next_open_at)
  return { open: false, opensAt: next, stale: next !== null && next <= now }
}

/** Whether any market's payload no longer covers `now`. */
export function needsRefresh(markets: readonly MarketStatus[], now: number): boolean {
  return markets.some((market) => {
    const phase = marketPhase(market, now)
    return !phase.open && phase.stale
  })
}

function dayKey(timeMs: number, timeZone: string | undefined): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(timeMs)
}

/**
 * A boundary in the viewer's timezone: the time alone when it falls on the
 * viewer's current day, otherwise with a short weekday ("lun 15:30").
 */
export function formatBoundary(locale: string, timeMs: number, now: number, timeZone?: string): string {
  const sameDay = dayKey(timeMs, timeZone) === dayKey(now, timeZone)
  return new Intl.DateTimeFormat(locale, {
    timeZone,
    ...(sameDay ? {} : { weekday: 'short' }),
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).format(timeMs)
}
