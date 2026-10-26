/**
 * Screener universe selector (universe-selector-ui). Fed by `GET /api/universes`
 * (already ordered and already limited to what the caller may see), it shows
 * each universe's name (a proper noun, as sent by the API), its Listing Market
 * and its latest data date. It renders nothing unless more than one universe is
 * available, so while only the S&P 500 is public the Screener looks unchanged.
 *
 * A segmented control from `sm` up and a native select on phones (no page
 * overflow at 390 px); both report the same choice and the page owns the URL.
 */

import { useI18n } from '../../i18n/useI18n.ts'
import type { ScreenerUniverse } from '../../lib/api.ts'

interface UniverseSelectorProps {
  universes: readonly ScreenerUniverse[]
  /** The slug of the universe being screened. */
  selected: string
  onSelect: (universe: ScreenerUniverse) => void
}

const SELECT_ID = 'screener-universe-select'

export default function UniverseSelector({ universes, selected, onSelect }: UniverseSelectorProps) {
  const { t, formatMarketDate } = useI18n()

  if (universes.length < 2) {
    return null
  }

  const marketLabel = (universe: ScreenerUniverse) => t(`screener.universeSelector.markets.${universe.market}`)
  const dateLabel = (universe: ScreenerUniverse) =>
    universe.latest_date === null
      ? t('screener.universeSelector.noData')
      : t('screener.universeSelector.dataAsOf', { date: formatMarketDate(universe.latest_date) })

  return (
    <div className="flex flex-col gap-1.5">
      <span
        id="screener-universe-label"
        className="font-mono text-[11px] font-bold uppercase tracking-wider text-on-surface-variant"
      >
        {t('screener.universeSelector.label')}
      </span>

      <div
        role="group"
        aria-labelledby="screener-universe-label"
        className="hidden w-fit max-w-full flex-wrap overflow-hidden rounded-md border-2 border-outline shadow-[2px_2px_0px_var(--color-shadow)] sm:flex"
      >
        {universes.map((universe, index) => {
          const active = universe.slug === selected
          return (
            <button
              key={universe.slug}
              type="button"
              aria-pressed={active}
              onClick={() => onSelect(universe)}
              className={[
                'flex flex-col items-start gap-0.5 px-3 py-1.5 text-left transition-colors focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none',
                index > 0 ? 'border-l-2 border-outline' : '',
                active ? 'bg-primary text-on-primary' : 'bg-surface-bright text-on-surface hover:bg-surface-container',
              ].join(' ')}
            >
              <span className="font-headline text-xs font-black uppercase tracking-wide">{universe.name}</span>
              <span className={`font-mono text-[10px] ${active ? 'text-on-primary' : 'text-on-surface-variant'}`}>
                {marketLabel(universe)} · {dateLabel(universe)}
              </span>
            </button>
          )
        })}
      </div>

      <div className="sm:hidden">
        <label htmlFor={SELECT_ID} className="sr-only">
          {t('screener.universeSelector.selectLabel')}
        </label>
        <select
          id={SELECT_ID}
          value={selected}
          onChange={(event) => {
            const universe = universes.find((candidate) => candidate.slug === event.target.value)
            if (universe !== undefined) {
              onSelect(universe)
            }
          }}
          className="w-full rounded-md border-2 border-outline bg-surface-bright px-3 py-2 font-mono text-xs text-on-surface shadow-[2px_2px_0px_var(--color-shadow)] focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none"
        >
          {universes.map((universe) => (
            <option key={universe.slug} value={universe.slug}>
              {t('screener.universeSelector.option', {
                name: universe.name,
                market: marketLabel(universe),
                date: dateLabel(universe),
              })}
            </option>
          ))}
        </select>
      </div>
    </div>
  )
}
