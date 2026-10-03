import type { IngestionRunSummary } from '../../lib/api.ts'
import RunStatusBadge from './RunStatusBadge.tsx'

function formatTimestamp(iso: string | null): string {
  if (!iso) {
    return '—'
  }

  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString('es-ES')
}

interface RunHistoryTableProps {
  runs: IngestionRunSummary[]
  selectedRunId: number | null
  loading: boolean
  error: string | null
  retryingId: number | null
  onSelect: (id: number) => void
  onRetry: (id: number) => void
  onRefresh: () => void
}

/**
 * Run ledger table. A row can be inspected; a finished run with failures can
 * be retried (the API decides retryability and returns 422 otherwise).
 */
export default function RunHistoryTable({
  runs,
  selectedRunId,
  loading,
  error,
  retryingId,
  onSelect,
  onRetry,
  onRefresh,
}: RunHistoryTableProps) {
  return (
    <section className="flex min-h-0 flex-col rounded-md border-2 border-outline bg-surface-bright shadow-[2px_2px_0px_#1a1a1a]">
      <header className="flex flex-wrap items-center justify-between gap-3 border-b-2 border-outline px-4 py-3">
        <div>
          <h3 className="font-headline text-sm font-black uppercase tracking-wider text-on-surface">
            Historial de ejecuciones
          </h3>
          <p className="font-mono text-[11px] text-on-surface-variant">
              Registro real de consultas EOD
          </p>
        </div>
        <button
          type="button"
          onClick={onRefresh}
          disabled={loading}
          className="rounded-md border-2 border-outline bg-surface-bright px-3 py-1.5 font-headline text-[11px] font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px disabled:cursor-not-allowed disabled:opacity-70"
        >
          {loading ? 'Actualizando…' : 'Actualizar'}
        </button>
      </header>

      {error ? (
        <p
          role="alert"
          className="border-b-2 border-secondary bg-secondary-container px-4 py-2 font-mono text-[11px] text-on-secondary-container"
        >
          {error}
        </p>
      ) : null}

      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-left">
          <thead>
            <tr className="border-b-2 border-outline bg-surface-container font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface-variant">
              <th scope="col" className="px-4 py-2">Ejecución</th>
              <th scope="col" className="px-4 py-2">Estado</th>
              <th scope="col" className="px-4 py-2">Universo</th>
              <th scope="col" className="px-4 py-2">Inicio</th>
              <th scope="col" className="px-4 py-2 text-right">Total</th>
              <th scope="col" className="px-4 py-2 text-right">Éxitos</th>
              <th scope="col" className="px-4 py-2 text-right">Fallos</th>
              <th scope="col" className="px-4 py-2 text-right">Acciones</th>
            </tr>
          </thead>
          <tbody>
            {runs.length === 0 ? (
              <tr>
                <td colSpan={8} className="px-4 py-6 text-center font-mono text-xs text-on-surface-variant">
                  {loading ? 'Cargando ejecuciones…' : 'Sin ejecuciones todavía.'}
                </td>
              </tr>
            ) : (
              runs.map((run) => {
                const retryable = run.status === 'partial' || run.status === 'failed'
                const selected = run.id === selectedRunId

                return (
                  <tr
                    key={run.id}
                    className={[
                      'border-b border-outline-variant font-mono text-xs',
                      selected ? 'bg-surface-container' : 'bg-surface-bright',
                    ].join(' ')}
                  >
                    <td className="px-4 py-2 font-bold text-on-surface">#{run.id}</td>
                    <td className="px-4 py-2"><RunStatusBadge status={run.status} /></td>
                    <td className="px-4 py-2 text-on-surface-variant">
                      {run.universe?.slug ?? '—'}
                    </td>
                    <td className="px-4 py-2 text-on-surface-variant">
                      {formatTimestamp(run.started_at)}
                    </td>
                    <td className="px-4 py-2 text-right text-on-surface">{run.total}</td>
                    <td className="px-4 py-2 text-right text-gain">{run.succeeded}</td>
                    <td className="px-4 py-2 text-right text-secondary">{run.failed}</td>
                    <td className="px-4 py-2">
                      <div className="flex justify-end gap-2">
                        <button
                          type="button"
                          onClick={() => onSelect(run.id)}
                          className="rounded-[4px] border-2 border-outline bg-surface-bright px-2 py-1 font-headline text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a] transition-transform hover:-translate-y-px"
                        >
                          Ver
                        </button>
                        {retryable ? (
                          <button
                            type="button"
                            onClick={() => onRetry(run.id)}
                            disabled={retryingId === run.id}
                            className="rounded-[4px] border-2 border-outline bg-primary-container px-2 py-1 font-headline text-[10px] font-bold uppercase tracking-wider text-on-primary-container shadow-[1px_1px_0px_#1a1a1a] transition-transform hover:-translate-y-px disabled:cursor-not-allowed disabled:opacity-70"
                          >
                            {retryingId === run.id ? 'Reintentando…' : 'Reintentar'}
                          </button>
                        ) : null}
                      </div>
                    </td>
                  </tr>
                )
              })
            )}
          </tbody>
        </table>
      </div>
    </section>
  )
}
