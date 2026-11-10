import { useEffect, useState } from 'react'
import { useI18n } from '../../i18n/useI18n.ts'
import { marketEventsApi } from '../../lib/api.ts'
import type { MarketEvent, MarketEventsResponse } from '../../lib/api.ts'
import { CARD_CLASS, Chip } from './MarketCard.tsx'

const NAMES = {
  fed: 'marketEvents.names.fed', ecb: 'marketEvents.names.ecb', cpi: 'marketEvents.names.cpi',
  employment: 'marketEvents.names.employment', gdp: 'marketEvents.names.gdp',
  pce: 'marketEvents.names.pce', retail: 'marketEvents.names.retail',
} as const
const SELECT = 'min-w-0 rounded border-2 border-outline bg-surface px-2 py-1 font-mono text-xs text-on-surface'
type State = { status: 'loading' } | { status: 'error' } | { status: 'ready'; data: MarketEventsResponse }

export default function MarketEventsCalendar({ onFredUsage }: { onFredUsage?: (used: boolean) => void }) {
  const { t, formatMarketDate } = useI18n()
  const [state, setState] = useState<State>({ status: 'loading' })
  const [days, setDays] = useState<14 | 30 | 90>(14)
  const [kind, setKind] = useState('all')
  const [region, setRegion] = useState('all')
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    let active = true
    const controller = new AbortController()
    marketEventsApi.list(days, controller.signal).then(
      (data) => { if (active) setState({ status: 'ready', data }) },
      () => { if (active) setState({ status: 'error' }) },
    )
    return () => { active = false; controller.abort() }
  }, [days, attempt])

  const usesFred = state.status === 'ready' && state.data.events.some((event) => event.kind === 'macro_release')
  useEffect(() => { onFredUsage?.(usesFred) }, [onFredUsage, usesFred])

  function changeDays(value: string) {
    const next = Number(value)
    if (next === 14 || next === 30 || next === 90) {
      setState({ status: 'loading' })
      setDays(next)
    }
  }

  function list(events: MarketEvent[], title: string) {
    return <div className="min-w-0">
      <h3 className="mb-2 font-headline text-sm font-bold uppercase text-on-surface">{title}</h3>
      {events.length === 0 ? <p className="text-sm text-on-surface-variant">{t('marketEvents.empty')}</p> : (
        <ul className="flex flex-col divide-y divide-outline-variant">
          {events.map((event) => <li key={event.id} className="flex min-w-0 flex-col gap-1 py-3">
            <div className="flex flex-wrap items-center gap-2">
              <time dateTime={event.date} className="font-mono text-xs font-bold text-on-surface">{formatMarketDate(event.date)}</time>
              <Chip>{t(event.region === 'US' ? 'marketEvents.us' : 'marketEvents.eu')}</Chip>
              <Chip>{t(`marketEvents.status.${event.status}`)}</Chip>
              {event.stale ? <span className="font-mono text-xs font-bold text-secondary" title={t('marketEvents.staleHint')}>{t('marketContext.stale')}</span> : null}
            </div>
            <p className="text-sm font-bold text-on-surface">{t(NAMES[event.code])}</p>
            <a href={event.source_url} target="_blank" rel="noopener noreferrer" className="break-words font-mono text-[11px] text-on-surface-variant underline underline-offset-2">{t('marketContext.source')}: {event.source}</a>
            <p className="font-mono text-[11px] text-on-surface-variant">{event.checked_at ? t('marketEvents.checked', { date: formatMarketDate(event.checked_at.slice(0, 10)) }) : t('marketEvents.unchecked')}</p>
          </li>)}
        </ul>
      )}
    </div>
  }

  let content
  if (state.status === 'loading') {
    content = <p role="status" className="text-sm text-on-surface-variant">{t('marketEvents.loading')}</p>
  } else if (state.status === 'error') {
    content = <div role="alert" className="flex flex-wrap items-center gap-3 text-sm text-secondary">
      {t('marketEvents.error')}
      <button type="button" className={SELECT} onClick={() => { setState({ status: 'loading' }); setAttempt((v) => v + 1) }}>{t('marketContext.retry')}</button>
    </div>
  } else {
    const { data } = state
    const filtered = data.events.filter((e) => (kind === 'all' || kind === e.kind) && (region === 'all' || region === e.region))
    const pending = Object.values(data.sources).filter((s) => s.checked_at === null).length
    content = <>
      <p className="text-xs text-on-surface-variant">{
        !data.macro_enabled ? t('marketEvents.disabled') : !data.macro_configured ? t('marketEvents.unconfigured') : pending > 0 ? t('marketEvents.notRefreshed') : Object.values(data.sources).some((s) => s.stale) ? t('marketEvents.sourceStale') : t('marketEvents.datesOnly')
      }</p>
      <div className="grid min-w-0 grid-cols-1 gap-6 md:grid-cols-2">
        {list(filtered.filter((e) => e.date >= data.as_of), t('marketEvents.upcoming'))}
        {list(filtered.filter((e) => e.date < data.as_of).reverse(), t('marketEvents.recent'))}
      </div>
    </>
  }

  return <section aria-labelledby="market-events-title" className={CARD_CLASS}>
    <h2 id="market-events-title" className="font-headline text-lg font-black uppercase text-on-surface">{t('marketEvents.title')}</h2>
    <p className="text-sm text-on-surface-variant">{t('marketEvents.intro')}</p>
    <div className="flex flex-wrap gap-3">
      <label className="flex flex-col gap-1 text-xs text-on-surface-variant">{t('marketEvents.horizon')}
        <select className={SELECT} value={days} onChange={(e) => changeDays(e.target.value)}>{([14, 30, 90] as const).map((n) => <option key={n} value={n}>{t('marketEvents.days', { count: n })}</option>)}</select>
      </label>
      <label className="flex flex-col gap-1 text-xs text-on-surface-variant">{t('marketEvents.kind')}
        <select className={SELECT} value={kind} onChange={(e) => setKind(e.target.value)}>
          <option value="all">{t('marketEvents.all')}</option><option value="central_bank">{t('marketEvents.banks')}</option><option value="macro_release">{t('marketEvents.macro')}</option>
        </select>
      </label>
      <label className="flex flex-col gap-1 text-xs text-on-surface-variant">{t('marketEvents.region')}
        <select className={SELECT} value={region} onChange={(e) => setRegion(e.target.value)}>
          <option value="all">{t('marketEvents.all')}</option><option value="US">{t('marketEvents.us')}</option><option value="EU">{t('marketEvents.eu')}</option>
        </select>
      </label>
    </div>
    {content}
    <details className="text-xs text-on-surface-variant"><summary className="cursor-pointer font-bold">{t('marketContext.meaning')}</summary><p className="mt-2">{t('marketEvents.meaning')}</p></details>
  </section>
}
