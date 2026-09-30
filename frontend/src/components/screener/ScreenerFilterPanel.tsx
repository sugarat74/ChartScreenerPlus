/**
 * Screener filter controls. Toggles/selects commit to the URL immediately;
 * the numeric RSI inputs keep a local draft and commit on blur/Enter so typing
 * never fires a request per keystroke. Token-only styling (DESIGN.md).
 */

import type { KeyboardEvent } from 'react'
import type { ScreenerFilters, ScreenerMaCross, ScreenerSignalType } from '../../lib/api.ts'
import {
  MIN_RVOL_PRESETS,
  SCREENER_SIGNAL_TYPES,
  parseRsiInput,
  signalLabel,
} from '../../lib/screenerFilters.ts'

const CHIP_BASE =
  'rounded-[4px] border-2 px-2.5 py-1 font-mono text-[11px] font-bold uppercase tracking-wider transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none'

function chipClass(selected: boolean): string {
  return selected
    ? `${CHIP_BASE} border-outline bg-primary text-on-primary shadow-[1px_1px_0px_#ffcc00]`
    : `${CHIP_BASE} border-outline-variant bg-surface-container text-on-surface-variant`
}

const LEGEND_CLASS =
  'font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant'

const NUMBER_INPUT_CLASS =
  'w-24 rounded-md border-2 border-outline bg-surface-bright px-3 py-2 font-mono text-sm text-on-surface shadow-[2px_2px_0px_#1a1a1a] focus:border-outline focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none'

const MA_CROSS_OPTIONS: ReadonlyArray<{ value: ScreenerMaCross | null; label: string }> = [
  { value: null, label: 'Cualquiera' },
  { value: 'bullish', label: 'Alcista' },
  { value: 'bearish', label: 'Bajista' },
]

function toDraft(value: number | null): string {
  return value === null ? '' : String(value)
}

interface ScreenerFilterPanelProps {
  filters: ScreenerFilters
  activeCount: number
  onChange: (patch: Partial<ScreenerFilters>) => void
  onClear: () => void
}

export default function ScreenerFilterPanel({
  filters,
  activeCount,
  onChange,
  onClear,
}: ScreenerFilterPanelProps) {
  // The RSI inputs are uncontrolled drafts: the DOM holds the keystrokes and
  // only blur/Enter commits them, so typing never writes the URL. The `key`
  // re-mounts an input when the committed value changes from outside (clear,
  // back/forward, deep link) so the draft always reflects the URL.
  function toggleSignal(type: ScreenerSignalType) {
    const signals = filters.signals.includes(type)
      ? filters.signals.filter((value) => value !== type)
      : [...filters.signals, type]
    onChange({ signals })
  }

  function handleNumberKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'Enter') {
      event.currentTarget.blur()
    }
  }

  return (
    <section className="rounded-md border-2 border-outline bg-surface-bright p-5 shadow-[2px_2px_0px_#1a1a1a]">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="font-headline text-lg font-black uppercase tracking-wide text-on-surface">
            Criterios técnicos
          </h2>
          <p className="font-mono text-[11px] text-on-surface-variant">
            Los filtros se combinan con Y; las señales seleccionadas con O.
          </p>
        </div>
        <p className="font-mono text-[11px] uppercase tracking-wider text-on-surface-variant">
          {activeCount > 0 ? `${activeCount} filtro(s) activo(s)` : 'Sin filtros activos'}
        </p>
      </header>

      <div className="mt-4 flex flex-wrap gap-x-8 gap-y-5">
        <fieldset className="flex min-w-60 flex-col gap-2">
          <legend className={LEGEND_CLASS}>
            Señales técnicas{filters.signals.length > 0 ? ` (${filters.signals.length})` : ''}
          </legend>
          <div className="flex flex-wrap gap-2">
            {SCREENER_SIGNAL_TYPES.map((type) => {
              const selected = filters.signals.includes(type)
              return (
                <button
                  key={type}
                  type="button"
                  aria-pressed={selected}
                  onClick={() => toggleSignal(type)}
                  className={chipClass(selected)}
                >
                  {signalLabel(type)}
                </button>
              )
            })}
          </div>
        </fieldset>

        <fieldset className="flex flex-col gap-2">
          <legend className={LEGEND_CLASS}>RSI (14)</legend>
          <div className="flex flex-wrap items-end gap-3">
            <div className="flex flex-col gap-1">
              <label htmlFor="screener-rsi-min" className={LEGEND_CLASS}>
                Mín
              </label>
              <input
                id="screener-rsi-min"
                key={`rsi-min-${filters.rsiMin ?? 'off'}`}
                type="number"
                inputMode="numeric"
                min={0}
                max={100}
                step={1}
                defaultValue={toDraft(filters.rsiMin)}
                onBlur={(event) => onChange({ rsiMin: parseRsiInput(event.target.value) })}
                onKeyDown={handleNumberKeyDown}
                className={NUMBER_INPUT_CLASS}
              />
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="screener-rsi-max" className={LEGEND_CLASS}>
                Máx
              </label>
              <input
                id="screener-rsi-max"
                key={`rsi-max-${filters.rsiMax ?? 'off'}`}
                type="number"
                inputMode="numeric"
                min={0}
                max={100}
                step={1}
                defaultValue={toDraft(filters.rsiMax)}
                onBlur={(event) => onChange({ rsiMax: parseRsiInput(event.target.value) })}
                onKeyDown={handleNumberKeyDown}
                className={NUMBER_INPUT_CLASS}
              />
            </div>
          </div>
          <p className="font-mono text-[10px] text-on-surface-variant">Vacío = sin límite</p>
        </fieldset>

        <fieldset className="flex flex-col gap-2">
          <legend className={LEGEND_CLASS}>RVOL mínimo</legend>
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              aria-pressed={filters.minRvol === null}
              onClick={() => onChange({ minRvol: null })}
              className={chipClass(filters.minRvol === null)}
            >
              Todos
            </button>
            {MIN_RVOL_PRESETS.map((preset) => {
              const selected = filters.minRvol === preset
              return (
                <button
                  key={preset}
                  type="button"
                  aria-pressed={selected}
                  onClick={() => onChange({ minRvol: selected ? null : preset })}
                  className={chipClass(selected)}
                >
                  {preset.toFixed(1)}x
                </button>
              )
            })}
          </div>
        </fieldset>

        <fieldset className="flex flex-col gap-2">
          <legend className={LEGEND_CLASS}>Precio</legend>
          <button
            type="button"
            aria-pressed={filters.priceAboveSma200}
            onClick={() => onChange({ priceAboveSma200: !filters.priceAboveSma200 })}
            className={`w-fit ${chipClass(filters.priceAboveSma200)}`}
          >
            Precio &gt; SMA 200
          </button>
        </fieldset>

        <fieldset className="flex flex-col gap-2">
          <legend className={LEGEND_CLASS}>Cruce de medias</legend>
          <div
            role="group"
            aria-label="Cruce de medias (SMA 50 / SMA 200)"
            className="inline-flex w-fit overflow-hidden rounded-[4px] border-2 border-outline"
          >
            {MA_CROSS_OPTIONS.map((option, index) => {
              const selected = filters.maCross === option.value
              return (
                <button
                  key={option.label}
                  type="button"
                  aria-pressed={selected}
                  onClick={() => onChange({ maCross: option.value })}
                  className={[
                    'px-3 py-1.5 font-mono text-[11px] font-bold uppercase tracking-wider transition-colors focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none',
                    index > 0 ? 'border-l-2 border-outline' : '',
                    selected
                      ? 'bg-primary text-on-primary'
                      : 'bg-surface-bright text-on-surface-variant hover:bg-surface-container',
                  ].join(' ')}
                >
                  {option.label}
                </button>
              )
            })}
          </div>
        </fieldset>
      </div>

      <div className="mt-5 flex flex-wrap items-center justify-between gap-3 border-t-2 border-outline-variant pt-4">
        <p className="font-mono text-[11px] text-on-surface-variant">
          {activeCount > 0
            ? 'Filtros guardados en la URL: puedes compartir el enlace.'
            : 'Sin filtros: se muestra el ranking por defecto del universo.'}
        </p>
        <button
          type="button"
          onClick={onClear}
          disabled={activeCount === 0}
          className="rounded-md border-2 border-outline bg-surface-bright px-3 py-1.5 font-headline text-xs font-bold uppercase tracking-wider text-on-surface shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none disabled:cursor-not-allowed disabled:opacity-60"
        >
          Limpiar filtros
        </button>
      </div>
    </section>
  )
}
