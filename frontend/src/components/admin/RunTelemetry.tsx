import type { ReactNode } from 'react'
import { useI18n } from '../../i18n/useI18n.ts'
import type { IngestionRunDetail, IngestionRunSummary } from '../../lib/api.ts'
import RunStatusBadge from './RunStatusBadge.tsx'

function formatDuration(run: IngestionRunDetail | null, runningLabel: string): string {
  if (!run || !run.started_at) {
    return '—'
  }

  if (!run.finished_at) {
    return runningLabel
  }

  const started = new Date(run.started_at).getTime()
  const finished = new Date(run.finished_at).getTime()

  if (!Number.isFinite(started) || !Number.isFinite(finished) || finished < started) {
    return '—'
  }

  const totalSeconds = Math.round((finished - started) / 1000)
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60

  return minutes > 0 ? `${minutes}m ${seconds}s` : `${seconds}s`
}

interface TileProps {
  label: string
  children: ReactNode
  hint: string
}

function Tile({ label, children, hint }: TileProps) {
  return (
    <div className="rounded-md border-2 border-outline bg-surface-bright p-4 shadow-[2px_2px_0px_#1a1a1a]">
      <p className="font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
        {label}
      </p>
      <div className="mt-2 flex items-baseline gap-2">{children}</div>
      <p className="mt-1 font-mono text-[11px] text-on-surface-variant">{hint}</p>
    </div>
  )
}

interface RunTelemetryProps {
  run: IngestionRunDetail | null
  runs: IngestionRunSummary[]
}

/**
 * Real telemetry for the selected run (never fake proxy/worker/cache widgets):
 * current status, scope, success/failure split and wall-clock duration.
 */
export default function RunTelemetry({ run, runs }: RunTelemetryProps) {
  const { t, formatDateTime } = useI18n()
  const processed = run ? run.succeeded + run.failed : null

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <Tile
        label={t('admin.tileSelectedRun')}
        hint={run ? t('admin.tileStarted', { date: formatDateTime(run.started_at) }) : t('admin.tileSelectRun')}
      >
        {run ? (
          <>
            <span className="font-headline text-2xl font-black text-on-surface">#{run.id}</span>
            <RunStatusBadge status={run.status} />
          </>
        ) : (
          <span className="font-headline text-2xl font-black text-on-surface-variant">—</span>
        )}
      </Tile>

      <Tile label={t('admin.tileSymbols')} hint={t('admin.tileRunsRecorded', { count: runs.length })}>
        <span className="font-headline text-2xl font-black text-on-surface">
          {run ? run.total : '—'}
        </span>
      </Tile>

      <Tile
        label={t('admin.tileProgress')}
        hint={
          run
            ? t('admin.tileProgressHint', { succeeded: run.succeeded, failed: run.failed })
            : t('admin.tileProgressEmpty')
        }
      >
        <span className="font-headline text-2xl font-black text-gain">
          {processed ?? '—'}
        </span>
        <span className="font-mono text-sm text-on-surface-variant">/</span>
        <span className="font-headline text-2xl font-black text-secondary">
          {run ? run.total : '—'}
        </span>
      </Tile>

      <Tile
        label={t('admin.tileDuration')}
        hint={run ? t('admin.tileFinished', { date: formatDateTime(run.finished_at) }) : t('admin.tileNoData')}
      >
        <span className="font-headline text-2xl font-black text-on-surface">
          {formatDuration(run, t('admin.durationRunning'))}
        </span>
      </Tile>
    </div>
  )
}
