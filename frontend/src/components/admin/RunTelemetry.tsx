import type { ReactNode } from 'react'
import type { IngestionRunDetail, IngestionRunSummary } from '../../lib/api.ts'
import RunStatusBadge from './RunStatusBadge.tsx'

function formatDuration(run: IngestionRunDetail | null): string {
  if (!run || !run.started_at) {
    return '—'
  }

  if (!run.finished_at) {
    return 'En curso'
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

function formatTimestamp(iso: string | null): string {
  if (!iso) {
    return '—'
  }

  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('es-ES')
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
  const processed = run ? run.succeeded + run.failed : null

  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <Tile label="Ejecución seleccionada" hint={run ? `Inicio: ${formatTimestamp(run.started_at)}` : 'Selecciona una ejecución'}>
        {run ? (
          <>
            <span className="font-headline text-2xl font-black text-on-surface">#{run.id}</span>
            <RunStatusBadge status={run.status} />
          </>
        ) : (
          <span className="font-headline text-2xl font-black text-on-surface-variant">—</span>
        )}
      </Tile>

      <Tile label="Símbolos en la ejecución" hint={`${runs.length} ejecución(es) registradas`}>
        <span className="font-headline text-2xl font-black text-on-surface">
          {run ? run.total : '—'}
        </span>
      </Tile>

      <Tile label="Progreso de consultas" hint={run ? `${run.succeeded} éxitos / ${run.failed} fallos` : 'Resultado por instrumento'}>
        <span className="font-headline text-2xl font-black text-gain">
          {processed ?? '—'}
        </span>
        <span className="font-mono text-sm text-on-surface-variant">/</span>
        <span className="font-headline text-2xl font-black text-secondary">
          {run ? run.total : '—'}
        </span>
      </Tile>

      <Tile label="Duración" hint={run ? `Fin: ${formatTimestamp(run.finished_at)}` : 'Sin datos'}>
        <span className="font-headline text-2xl font-black text-on-surface">
          {formatDuration(run)}
        </span>
      </Tile>
    </div>
  )
}
