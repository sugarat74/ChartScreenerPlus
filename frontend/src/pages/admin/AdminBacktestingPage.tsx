import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { useI18n } from '../../i18n/useI18n.ts'
import type { MessageKey } from '../../i18n/translate.ts'
import { apiErrorMessage, forecastBacktestApi } from '../../lib/api.ts'
import type { ForecastBacktestRun } from '../../lib/api.ts'

const reasonKey: Record<string, MessageKey> = {
  missing_target_bar: 'backtesting.missingTarget',
  insufficient_data: 'backtesting.insufficientData',
  calendar_unavailable: 'backtesting.calendarUnavailable',
  provider_unavailable: 'backtesting.providerUnavailable',
  provider_not_configured: 'backtesting.providerUnavailable',
  invalid_provider_response: 'backtesting.providerUnavailable',
  busy: 'backtesting.providerUnavailable',
  internal_error: 'backtesting.internalError',
  worker_failed: 'backtesting.internalError',
}

export default function AdminBacktestingPage() {
  const { t } = useI18n()
  const [tickerText, setTickerText] = useState('AAPL, MSFT')
  const [bars, setBars] = useState(30)
  const [runs, setRuns] = useState<ForecastBacktestRun[]>([])
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [run, setRun] = useState<ForecastBacktestRun | null>(null)
  const [starting, setStarting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    void forecastBacktestApi.list().then((list) => {
      if (!active) return
      setRuns(list)
      setSelectedId((current) => current ?? list[0]?.id ?? null)
    }).catch((caught) => { if (active) setError(apiErrorMessage(caught, t('common.networkError'))) })
    return () => { active = false }
  }, [t])

  useEffect(() => {
    if (selectedId === null) return
    let active = true
    const refresh = () => {
      void forecastBacktestApi.get(selectedId).then((result) => {
        if (!active) return
        setRun(result)
        if (result.status === 'completed') {
          window.clearInterval(timer)
          void forecastBacktestApi.list().then((list) => { if (active) setRuns(list) })
        }
      }).catch((caught) => { if (active) setError(apiErrorMessage(caught, t('common.networkError'))) })
    }
    refresh()
    const timer = window.setInterval(refresh, 3000)
    return () => { active = false; window.clearInterval(timer) }
  }, [selectedId, t])

  async function start(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const tickers = tickerText.split(/[\s,;]+/).map((value) => value.trim().toUpperCase()).filter(Boolean)
    if (tickers.length < 1 || tickers.length > 5 || new Set(tickers).size !== tickers.length || bars < 1 || bars > 100) {
      setError(t('backtesting.invalidSelection'))
      return
    }
    if (!window.confirm(t('backtesting.confirm').replace('{count}', String(tickers.length * bars)))) return
    setStarting(true)
    setError(null)
    try {
      const created = await forecastBacktestApi.start(tickers, bars)
      setSelectedId(created.id)
      setRun(created)
      setRuns((current) => [created, ...current])
    } catch (caught) {
      setError(apiErrorMessage(caught, t('common.networkError')))
    } finally {
      setStarting(false)
    }
  }

  const accuracy = run?.accuracy === null || run === null ? '—' : `${(run.accuracy * 100).toFixed(1)}%`

  return <div className="flex flex-col gap-5">
    <section className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]">
      <h2 className="font-headline text-xl font-black uppercase">{t('backtesting.title')}</h2>
      <p className="mt-2 text-sm text-on-surface-variant">{t('backtesting.description')}</p>
      <form onSubmit={(event) => void start(event)} className="mt-4 flex flex-wrap items-end gap-4">
        <label className="flex min-w-64 flex-1 flex-col gap-1 text-xs font-bold" htmlFor="backtest-tickers">{t('backtesting.tickers')}
          <input id="backtest-tickers" className="rounded border-2 border-outline bg-surface-bright p-2 font-mono text-sm" value={tickerText} onChange={(event) => setTickerText(event.target.value)} required />
        </label>
        <label className="flex flex-col gap-1 text-xs font-bold" htmlFor="backtest-bars">{t('backtesting.bars')}
          <input id="backtest-bars" className="w-28 rounded border-2 border-outline bg-surface-bright p-2 font-mono text-sm" type="number" min="1" max="100" value={bars} onChange={(event) => setBars(Number(event.target.value))} required />
        </label>
        <button className="rounded border-2 border-outline bg-primary-container px-4 py-2 font-headline text-xs font-bold uppercase shadow-[2px_2px_0px_#1a1a1a] disabled:opacity-50" type="submit" disabled={starting || runs.some((item) => item.status !== 'completed')}>
          {starting ? t('common.loading') : t('backtesting.start')}
        </button>
      </form>
      <p className="mt-3 text-xs text-on-surface-variant">{t('backtesting.costNote')}</p>
      {error ? <p className="mt-3 text-sm text-secondary" role="alert">{error}</p> : null}
    </section>
    {runs.length > 0 ? <section className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]">
      <label className="flex flex-col gap-1 text-xs font-bold" htmlFor="backtest-run">{t('backtesting.runs')}
        <select id="backtest-run" className="mt-1 rounded border-2 border-outline bg-surface-bright p-2 font-mono text-sm" value={selectedId ?? ''} onChange={(event) => setSelectedId(Number(event.target.value))}>
          {runs.map((item) => <option key={item.id} value={item.id}>#{item.id} · {item.tickers.join(', ')} · {item.bars_requested} · {item.status}</option>)}
        </select>
      </label>
      {run ? <>
        <div className="mt-4 flex flex-wrap gap-4 font-mono text-sm">
          <span>{t('backtesting.progress')}: {run.processed + run.skipped}/{run.total}</span>
          <span>{t('backtesting.correct')}: {run.correct}/{run.processed}</span>
          <strong>{t('backtesting.accuracy')}: {accuracy}</strong>
          <span>{t('backtesting.skipped')}: {run.skipped}</span>
        </div>
        <p className="mt-2 text-xs text-on-surface-variant">{t('backtesting.denominator')}</p>
        <div className="mt-4 max-h-[32rem] overflow-auto"><table className="w-full min-w-[620px] text-left font-mono text-xs"><thead><tr>
          <th className="p-2">{t('backtesting.ticker')}</th><th className="p-2">{t('backtesting.reference')}</th><th className="p-2">{t('backtesting.target')}</th><th className="p-2">{t('backtesting.predicted')}</th><th className="p-2">{t('backtesting.observed')}</th><th className="p-2">{t('backtesting.result')}</th>
        </tr></thead><tbody>{run.cases?.map((item) => <tr className="border-t border-outline-variant" key={`${item.ticker}-${item.as_of_date}`}>
          <td className="p-2">{item.ticker}</td><td className="p-2">{item.as_of_date}</td><td className="p-2">{item.target_date ?? '—'}</td><td className="p-2">{item.predicted_class ?? '—'}</td><td className="p-2">{item.observed_class ?? '—'}</td><td className="p-2">{item.status === 'completed' ? (item.predicted_class === item.observed_class ? t('backtesting.hit') : t('backtesting.miss')) : item.status === 'queued' ? t('backtesting.queued') : t(reasonKey[item.reason ?? ''] ?? 'backtesting.omitted')}</td>
        </tr>)}</tbody></table></div>
      </> : null}
    </section> : null}
  </div>
}
