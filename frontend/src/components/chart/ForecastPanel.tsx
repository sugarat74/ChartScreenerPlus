import { useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { useSignedIn } from '../../auth/useSignedIn.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import { forecastApi, apiErrorMessage } from '../../lib/api.ts'
import type { Forecast } from '../../lib/api.ts'

/**
 * Experimental Jev forecast panel on the chart page. Signed-in only: it reads
 * the already bootstrapped session itself and renders nothing for a Visitor or
 * while auth is loading (no sign-in prompt; docs/specs/premium-panels-auth-only.md).
 */
export default function ForecastPanel({ ticker }: { ticker: string }) {
  const signedIn = useSignedIn()
  const { t, formatMarketDate } = useI18n()
  const [horizon, setHorizon] = useState(1)
  const [minimum, setMinimum] = useState(60)
  const [forecast, setForecast] = useState<Forecast | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const requestGeneration = useRef(0)

  useEffect(() => () => { requestGeneration.current += 1 }, [])

  function invalidateResult() {
    requestGeneration.current += 1
    setForecast(null)
    setError(null)
    setLoading(false)
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const generation = ++requestGeneration.current
    setLoading(true)
    setError(null)
    setForecast(null)
    try {
      const result = await forecastApi.predict(ticker, horizon, minimum)
      if (generation === requestGeneration.current) setForecast(result)
    } catch (caught) {
      if (generation === requestGeneration.current) setError(apiErrorMessage(caught, t('common.networkError')))
    } finally {
      if (generation === requestGeneration.current) setLoading(false)
    }
  }

  if (!signedIn) {
    return null
  }

  const label = forecast?.signal === 'up' ? t('forecast.up') : forecast?.signal === 'down' ? t('forecast.down') : t('forecast.noSignal')

  return (
    <section className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_var(--color-shadow)]" aria-label={t('forecast.title')}>
      <div className="flex flex-wrap items-center gap-3">
        <h2 className="font-headline text-lg font-black uppercase">{t('forecast.title')}</h2>
        <span className="rounded border border-outline bg-primary-container px-2 py-0.5 font-mono text-[10px] uppercase">{t('forecast.experimental')}</span>
      </div>
      <p className="mt-2 text-sm text-on-surface-variant">{t('forecast.explanation')}</p>
      <form className="mt-4 flex flex-wrap items-end gap-3" onSubmit={(event) => void submit(event)}>
        <label className="flex flex-col gap-1 text-xs font-bold" htmlFor="forecast-horizon">{t('forecast.horizon')}
          <select id="forecast-horizon" className="rounded border-2 border-outline bg-surface-bright p-2" value={horizon} onChange={(event) => { invalidateResult(); setHorizon(Number(event.target.value)) }}>
            <option value={1}>{t('forecast.oneSession')}</option><option value={5}>{t('forecast.fiveSessions')}</option>
          </select>
        </label>
        <label className="flex flex-col gap-1 text-xs font-bold" htmlFor="forecast-minimum">{t('forecast.minimum')}
          <input id="forecast-minimum" className="w-28 rounded border-2 border-outline bg-surface-bright p-2" type="number" min="0" max="100" step="1" required value={minimum} onChange={(event) => { invalidateResult(); setMinimum(Number(event.target.value)) }} />
        </label>
        <button className="rounded border-2 border-outline bg-primary-container px-4 py-2 font-headline text-xs font-bold uppercase shadow-[2px_2px_0px_var(--color-shadow)] disabled:opacity-60" type="submit" disabled={loading}>{loading ? t('common.loading') : t('forecast.analyze')}</button>
      </form>
      {error ? <p role="alert" className="mt-3 text-sm text-secondary">{error}</p> : null}
      {forecast ? (
        <div className="mt-4 border-t border-outline pt-3 text-sm">
          <p className="font-bold">{label}</p>
          <p>{t('forecast.up')}: {(forecast.probabilities.up * 100).toFixed(1)}% · {t('forecast.down')}: {(forecast.probabilities.down * 100).toFixed(1)}% · {t('forecast.flat')}: {(forecast.probabilities.flat * 100).toFixed(1)}%</p>
          <p className="mt-1 text-xs text-on-surface-variant">{formatMarketDate(forecast.as_of_date)} → {formatMarketDate(forecast.target_date)} · {forecast.model_version}</p>
          <p className="mt-2 text-xs text-on-surface-variant">{t('forecast.disclaimer')}</p>
        </div>
      ) : null}
    </section>
  )
}
