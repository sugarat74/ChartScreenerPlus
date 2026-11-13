/**
 * Small accessible tab bar for the Portal (WAI-ARIA tabs, manual-free
 * "automatic activation": arrow keys move focus and select).
 *
 * Roving tabindex: only the selected tab is in the Tab order; Left/Right wrap,
 * Home/End jump. Panels are rendered by the caller with `tabPanelId` /
 * `tabId` so they stay mounted (state and fetched data survive a switch).
 */
import { useRef } from 'react'
import type { KeyboardEvent, ReactNode } from 'react'

export interface PortalTabItem<T extends string> {
  id: T
  label: string
}

const tabId = (id: string) => `portal-tab-${id}`
const tabPanelId = (id: string) => `portal-tabpanel-${id}`

export default function PortalTabs<T extends string>({
  tabs,
  selected,
  onSelect,
  label,
}: {
  tabs: PortalTabItem<T>[]
  selected: T
  onSelect: (id: T) => void
  label: string
}) {
  const refs = useRef<Record<string, HTMLButtonElement | null>>({})

  function move(index: number) {
    const next = tabs[(index + tabs.length) % tabs.length]
    onSelect(next.id)
    refs.current[next.id]?.focus()
  }

  function onKeyDown(event: KeyboardEvent<HTMLButtonElement>, index: number) {
    switch (event.key) {
      case 'ArrowRight':
        move(index + 1)
        break
      case 'ArrowLeft':
        move(index - 1)
        break
      case 'Home':
        move(0)
        break
      case 'End':
        move(tabs.length - 1)
        break
      default:
        return
    }
    event.preventDefault()
  }

  return (
    <div role="tablist" aria-label={label} className="flex flex-wrap gap-2">
      {tabs.map((tab, index) => {
        const active = tab.id === selected
        return (
          <button
            key={tab.id}
            ref={(node) => {
              refs.current[tab.id] = node
            }}
            type="button"
            role="tab"
            id={tabId(tab.id)}
            aria-selected={active}
            aria-controls={tabPanelId(tab.id)}
            tabIndex={active ? 0 : -1}
            onClick={() => onSelect(tab.id)}
            onKeyDown={(event) => onKeyDown(event, index)}
            className={`rounded-md border-2 border-outline px-4 py-2 font-headline text-xs font-bold uppercase tracking-wider shadow-[2px_2px_0px_var(--color-shadow)] transition-transform hover:-translate-y-px focus:shadow-[4px_4px_0px_#ffcc00] focus:outline-none ${
              active
                ? 'bg-primary text-on-primary'
                : 'bg-surface-bright text-on-surface'
            }`}
          >
            {tab.label}
          </button>
        )
      })}
    </div>
  )
}

/** Panel for one tab; stays mounted and is only hidden when inactive. */
export function PortalTabPanel({
  id,
  selected,
  className,
  children,
}: {
  id: string
  selected: boolean
  className?: string
  children: ReactNode
}) {
  return (
    <div
      role="tabpanel"
      id={tabPanelId(id)}
      aria-labelledby={tabId(id)}
      hidden={!selected}
      className={className}
    >
      {children}
    </div>
  )
}
