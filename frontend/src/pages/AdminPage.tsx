import { useEffect, useState } from 'react'
import type { FormEvent, ReactNode } from 'react'
import { Link } from 'react-router'
import { useAuth } from '../auth/useAuth.ts'
import RunHistoryTable from '../components/admin/RunHistoryTable.tsx'
import RunLogStream from '../components/admin/RunLogStream.tsx'
import RunStatusBadge from '../components/admin/RunStatusBadge.tsx'
import RunTelemetry from '../components/admin/RunTelemetry.tsx'
import type { LogFilter } from '../components/admin/logFilter.ts'
import { ApiError, adminIngestionApi, isTerminalRunStatus } from '../lib/api.ts'
import type { IngestionRunDetail, IngestionRunSummary } from '../lib/api.ts'
import { LOGIN_ROUTE } from '../nav.ts'

/** Poll cadence for a non-terminal run (queued/running). */
const POLL_INTERVAL_MS = 2500

function messageFor(error: unknown): string {
  if (error instanceof ApiError) {
    const firstValidation = Object.values(error.errors)[0]?.[0]
    return firstValidation ?? error.message
  }

  return 'No se pudo contactar con el servidor. Inténtalo de nuevo.'
}

interface AdminNoticeProps {
  eyebrow: string
  title: string
  message: string
  children?: ReactNode
}

function AdminNotice({ eyebrow, title, message, children }: AdminNoticeProps) {
  return (
    <section className="mx-auto flex w-full max-w-lg flex-col gap-4">
      <span className="w-fit rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a]">
        {eyebrow}
      </span>
      <h2 className="font-headline text-3xl font-black tracking-tight uppercase text-on-surface">
        {title}
      </h2>
      <p className="text-sm text-on-surface-variant">{message}</p>
      {children ? (
        <div className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]">
          {children}
        </div>
      ) : null}
    </section>
  )
}

/**
 * Admin ingestion panel.
 *
 * Backed entirely by the real run ledger: trigger a run, poll its status while
 * it is non-terminal, inspect its per-instrument log and retry only the failed
 * instruments. The guard below is a UI affordance only — every request is
 * authorized server-side by `auth:sanctum` + `admin`.
 */
export default function AdminPage() {
  const { user, status: authStatus } = useAuth()
  const isAdmin = user?.role === 'admin'

  const [runs, setRuns] = useState<IngestionRunSummary[]>([])
  const [runsLoading, setRunsLoading] = useState(true)
  const [runsError, setRunsError] = useState<string | null>(null)
  const [reloadToken, setReloadToken] = useState(0)

  const [selectedRunId, setSelectedRunId] = useState<number | null>(null)
  const [selectedRun, setSelectedRun] = useState<IngestionRunDetail | null>(null)
  const [detailError, setDetailError] = useState<string | null>(null)
  const [detailToken, setDetailToken] = useState(0)

  const [universe, setUniverse] = useState('sp500')
  const [triggering, setTriggering] = useState(false)
  const [triggerError, setTriggerError] = useState<string | null>(null)
  const [retryingId, setRetryingId] = useState<number | null>(null)
  const [logFilter, setLogFilter] = useState<LogFilter>('ALL')

  // Fetch the run history (also re-runs after a trigger/retry or a refresh).
  useEffect(() => {
    if (!isAdmin) {
      return
    }

    let active = true

    void (async () => {
      try {
        const list = await adminIngestionApi.listRuns()
        if (active) {
          setRuns(list)
          setRunsError(null)
          setRunsLoading(false)
        }
      } catch (error) {
        if (active) {
          setRunsError(messageFor(error))
          setRunsLoading(false)
        }
      }
    })()

    return () => {
      active = false
    }
  }, [isAdmin, reloadToken])

  // Fetch the selected run's detail (also re-runs on each poll tick).
  useEffect(() => {
    if (!isAdmin || selectedRunId === null) {
      return
    }

    let active = true

    void (async () => {
      try {
        const detail = await adminIngestionApi.getRun(selectedRunId)
        if (active) {
          setSelectedRun(detail)
          setDetailError(null)
        }
      } catch (error) {
        if (active) {
          setDetailError(messageFor(error))
        }
      }
    })()

    return () => {
      active = false
    }
  }, [isAdmin, selectedRunId, detailToken])

  const selectedStatus = selectedRun?.status ?? null

  // Poll while the selected run is non-terminal; the interval stops as soon as
  // the status becomes terminal (the effect's dependency changes).
  useEffect(() => {
    if (!isAdmin || selectedRunId === null || selectedStatus === null) {
      return
    }

    if (isTerminalRunStatus(selectedStatus)) {
      return
    }

    const timer = window.setInterval(() => {
      setDetailToken((token) => token + 1)
      setReloadToken((token) => token + 1)
    }, POLL_INTERVAL_MS)

    return () => window.clearInterval(timer)
  }, [isAdmin, selectedRunId, selectedStatus])

  function reloadRuns() {
    setRunsLoading(true)
    setReloadToken((token) => token + 1)
  }

  async function handleTrigger(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setTriggering(true)
    setTriggerError(null)

    try {
      const trimmed = universe.trim()
      const run = await adminIngestionApi.triggerRun(trimmed === '' ? undefined : trimmed)
      setSelectedRun(null)
      setSelectedRunId(run.id)
      setRunsLoading(true)
      setReloadToken((token) => token + 1)
    } catch (error) {
      setTriggerError(messageFor(error))
    } finally {
      setTriggering(false)
    }
  }

  async function handleRetry(id: number) {
    setRetryingId(id)
    setTriggerError(null)

    try {
      const run = await adminIngestionApi.retryRun(id)
      setSelectedRun(null)
      setSelectedRunId(run.id)
      setRunsLoading(true)
      setReloadToken((token) => token + 1)
    } catch (error) {
      setTriggerError(messageFor(error))
    } finally {
      setRetryingId(null)
    }
  }

  if (authStatus === 'loading') {
    return (
      <AdminNotice
        eyebrow="Admin"
        title="Comprobando sesión"
        message="Verificando tus permisos de administrador."
      />
    )
  }

  if (user === null) {
    return (
      <AdminNotice
        eyebrow="Admin"
        title="Inicia sesión"
        message="El panel de consultas EOD requiere una sesión con rol de administrador."
      >
        <Link
          to={LOGIN_ROUTE}
          className="inline-block rounded-md border-2 border-outline bg-primary-container px-4 py-2 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px"
        >
          Iniciar sesión
        </Link>
      </AdminNotice>
    )
  }

  if (!isAdmin) {
    return (
      <AdminNotice
        eyebrow="Admin"
        title="Acceso restringido"
        message="Tu cuenta no tiene permisos de administrador para controlar las consultas EOD."
      />
    )
  }

  const bannerRun = selectedRun ?? runs[0] ?? null

  return (
    <section className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center justify-between gap-4 rounded-md border-2 border-outline bg-primary p-4 text-on-primary shadow-[4px_4px_0px_#ffcc00]">
        <div className="flex items-center gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded border-2 border-primary-container bg-primary-container font-headline text-lg font-black text-on-primary-container">
            ▮
          </span>
          <div>
            <h2 className="font-headline text-lg font-black uppercase tracking-wide text-on-primary">
              Panel de control: consultas EOD
            </h2>
            <p className="font-mono text-[11px] text-surface-dim">
              Inicia y sigue ejecuciones reales del registro de consultas.
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          {bannerRun ? (
            <>
              <span className="font-mono text-[11px] text-surface-dim">#{bannerRun.id}</span>
              <RunStatusBadge status={bannerRun.status} />
            </>
          ) : (
            <span className="font-mono text-[11px] text-surface-dim">Sin actividad</span>
          )}
        </div>
      </div>

      <RunTelemetry run={selectedRun} runs={runs} />

      <form
        onSubmit={handleTrigger}
        className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]"
      >
        <div className="flex flex-wrap items-end gap-4">
          <div className="flex min-w-60 flex-1 flex-col gap-1.5">
            <label
              htmlFor="ingestion-universe"
              className="font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant"
            >
              Universo objetivo
            </label>
            <input
              id="ingestion-universe"
              name="universe"
              value={universe}
              onChange={(event) => setUniverse(event.target.value)}
              className="rounded-md border-2 border-outline bg-surface-bright px-3 py-2 font-mono text-sm text-on-surface shadow-[2px_2px_0px_#1a1a1a] focus:border-outline focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
            />
          </div>

          <button
            type="submit"
            disabled={triggering}
            className="rounded-md border-2 border-outline bg-primary-container px-5 py-2.5 font-headline text-xs font-bold uppercase tracking-wider text-on-primary-container shadow-[3px_3px_0px_#1a1a1a] transition-transform hover:-translate-y-px disabled:cursor-not-allowed disabled:opacity-70"
          >
            {triggering ? 'Iniciando…' : 'Iniciar consulta ahora'}
          </button>
        </div>

        {triggerError ? (
          <p
            role="alert"
            className="mt-3 rounded-[4px] border-2 border-secondary bg-secondary-container px-3 py-2 font-mono text-xs text-on-secondary-container"
          >
            {triggerError}
          </p>
        ) : null}

        <p className="mt-3 font-mono text-[11px] text-on-surface-variant">
          Inicia consultas EOD y actualiza el registro; no ejecuta el pipeline completo de indicadores y señales.
        </p>
      </form>

      <div className="grid gap-6 lg:grid-cols-5">
        <div className="lg:col-span-2">
          <RunHistoryTable
            runs={runs}
            selectedRunId={selectedRunId}
            loading={runsLoading}
            error={runsError}
            retryingId={retryingId}
            onSelect={setSelectedRunId}
            onRetry={handleRetry}
            onRefresh={reloadRuns}
          />
        </div>
        <div className="lg:col-span-3">
          <RunLogStream
            run={selectedRun}
            filter={logFilter}
            onFilterChange={setLogFilter}
            loading={selectedStatus === 'queued' || selectedStatus === 'running'}
            error={detailError}
          />
        </div>
      </div>
    </section>
  )
}
