import type { IngestionRunDetail, IngestionRunItem } from '../../lib/api.ts'
import { LOG_FILTERS } from './logFilter.ts'
import type { LogFilter } from './logFilter.ts'

const ITEM_CHIP: Record<IngestionRunItem['status'], string> = {
  processing: 'border-primary-container bg-primary-container text-on-primary-container',
  success: 'border-gain bg-gain text-primary',
  failed: 'border-secondary bg-secondary-container text-on-secondary-container',
}

function itemLineColor(item: IngestionRunItem): string {
  if (item.status === 'failed') {
    return 'text-secondary-container'
  }

  return item.status === 'success' ? 'text-surface-bright' : 'text-primary-container'
}

interface RunLogStreamProps {
  run: IngestionRunDetail | null
  filter: LogFilter
  onFilterChange: (filter: LogFilter) => void
  loading: boolean
  error: string | null
}

/**
 * Polled per-instrument log for the selected run. There is no push/SSE: the
 * parent refreshes the run detail and this view renders the current items.
 */
export default function RunLogStream({
  run,
  filter,
  onFilterChange,
  loading,
  error,
}: RunLogStreamProps) {
  const items = (run?.items ?? []).filter((item) => {
    if (filter === 'SUCCESS') {
      return item.status === 'success'
    }
    if (filter === 'FAILED') {
      return item.status === 'failed'
    }
    if (filter === 'PROCESSING') {
      return item.status === 'processing'
    }
    return true
  })

  return (
    <section className="flex min-h-0 flex-col overflow-hidden rounded-md border-2 border-outline bg-primary shadow-[2px_2px_0px_#1a1a1a]">
      <header className="flex flex-wrap items-center justify-between gap-3 border-b border-outline-variant bg-primary px-4 py-2.5">
        <div className="flex items-center gap-2">
          <span className="font-headline text-sm font-black uppercase tracking-wider text-on-primary">
            Log de la ejecución
          </span>
          {run ? (
            <span className="font-mono text-[11px] text-surface-dim">#{run.id}</span>
          ) : null}
          {loading ? (
            <span className="font-mono text-[10px] uppercase tracking-wider text-surface-dim">
              Actualizando…
            </span>
          ) : null}
        </div>

        <div className="flex items-center gap-1.5">
          {LOG_FILTERS.map((option) => (
            <button
              key={option}
              type="button"
              onClick={() => onFilterChange(option)}
              className={[
                'rounded-[4px] border px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider transition-colors',
                filter === option
                  ? 'border-primary-container bg-primary-container text-on-primary-container'
                  : 'border-outline-variant bg-primary text-surface-dim hover:text-on-primary',
              ].join(' ')}
            >
              {option}
            </button>
          ))}
        </div>
      </header>

      {error ? (
        <p role="alert" className="border-b border-secondary bg-secondary-container px-4 py-2 font-mono text-[11px] text-on-secondary-container">
          {error}
        </p>
      ) : null}

      <div className="max-h-96 min-h-40 flex-1 overflow-y-auto px-4 py-3">
        {run === null ? (
          <p className="font-mono text-xs text-surface-dim">
            Selecciona una ejecución para ver su log.
          </p>
        ) : items.length === 0 ? (
          <p className="font-mono text-xs text-surface-dim">
            {run.status === 'queued'
              ? 'Ejecución en cola; inicia el worker para comenzar las consultas.'
              : 'Sin líneas que coincidan con el filtro.'}
          </p>
        ) : (
          <ul className="flex flex-col gap-1.5">
            {items.map((item) => (
              <li key={item.id} className="flex items-start gap-3 py-0.5 font-mono text-xs">
                <span
                  className={`shrink-0 rounded-[4px] border px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider ${ITEM_CHIP[item.status]}`}
                >
                  {item.status}
                </span>
                <span className="shrink-0 font-bold text-on-primary">
                  {item.ticker ?? '—'}
                </span>
                {item.status !== 'processing' ? (
                  <span className={`shrink-0 ${item.status === 'success' ? 'text-gain' : 'text-secondary-container'}`}>
                    {item.bars_stored} barras
                  </span>
                ) : null}
                {item.message ? (
                  <span className={`break-words ${itemLineColor(item)}`}>{item.message}</span>
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </div>
    </section>
  )
}
