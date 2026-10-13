import { useEffect, useState } from 'react'
import { useI18n } from '../../i18n/useI18n.ts'
import { apiErrorMessage, forecastApi } from '../../lib/api.ts'
import type { ForecastReportGroup } from '../../lib/api.ts'

const percent = (value: number | null) => value === null ? '—' : `${(value * 100).toFixed(1)}%`

export default function ForecastReportPanel() {
  const { t } = useI18n()
  const [groups, setGroups] = useState<ForecastReportGroup[]>([])
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    let active = true
    void forecastApi.report().then((result) => {
      if (active) setGroups(result)
    }).catch((caught) => {
      if (active) setError(apiErrorMessage(caught, t('common.networkError')))
    })
    return () => { active = false }
  }, [t])

  return (
    <section className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]">
      <h2 className="font-headline text-lg font-black uppercase">{t('forecast.adminTitle')}</h2>
      {error ? <p role="alert">{error}</p> : null}
      {!error && groups.length === 0 ? <p className="mt-2 text-sm">{t('forecast.adminEmpty')}</p> : null}
      {groups.length > 0 ? <div className="mt-3 overflow-x-auto"><table className="w-full text-left text-xs"><thead><tr>
        <th className="p-2">{t('forecast.horizon')}</th><th className="p-2">{t('forecast.adminModel')}</th><th className="p-2 text-right">{t('forecast.adminSample')}</th><th className="p-2 text-right">{t('forecast.adminPending')}</th><th className="p-2 text-right">{t('forecast.adminCoverage')}</th><th className="p-2 text-right">{t('forecast.adminAccuracy')}</th><th className="p-2 text-right">{t('forecast.adminCoveredAccuracy')}</th><th className="p-2 text-right">{t('forecast.adminBrier')}</th><th className="p-2">{t('forecast.adminCalibration')}</th>
      </tr></thead><tbody>{groups.map((group) => <tr key={`${group.source}-${group.horizon}-${group.model_version}-${group.question_version}`} className="border-t border-outline-variant">
        <td className="p-2">{group.source === 'historical' ? t('forecast.adminHistorical') : t('forecast.adminProspective')} · {group.horizon}</td>
        <td className="p-2 font-mono">{group.model_version}</td><td className="p-2 text-right font-mono">{group.sample}</td><td className="p-2 text-right font-mono">{group.pending}</td><td className="p-2 text-right font-mono">{percent(group.coverage)}</td><td className="p-2 text-right font-mono">{percent(group.directional_accuracy)}</td><td className="p-2 text-right font-mono">{percent(group.covered_accuracy)}</td><td className="p-2 text-right font-mono">{group.brier?.toFixed(3) ?? '—'}</td><td className="p-2 font-mono">{group.calibration.map((bucket) => `${bucket.bucket * 20}–${(bucket.bucket + 1) * 20}%: ${t('forecast.adminMeanConfidence')} ${percent(bucket.mean_confidence)} · ${t('forecast.adminAccuracy')} ${percent(bucket.accuracy)} (${bucket.count})`).join(', ') || '—'}</td>
      </tr>)}</tbody></table></div> : null}
    </section>
  )
}
