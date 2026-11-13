import { useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react'
import type { KeyboardEvent, ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { NavigationType, UNSAFE_LocationContext } from 'react-router'
import LockIcon from './registered-only/LockIcon.tsx'

/** Shared look of every header tab (enabled, registered-only, not available yet). */
export const TAB_CLASS = 'whitespace-nowrap border-b-2 px-4 py-2.5 font-headline text-xs font-bold uppercase tracking-wider transition-colors'
export const TAB_ACTIVE_CLASS = 'border-outline bg-surface-container text-on-surface'
export const TAB_IDLE_CLASS = 'border-transparent text-on-surface-variant hover:bg-surface-bright hover:text-on-surface'

/**
 * Makes the sign-in links of a disabled header tab return to the tab's own
 * route (`/login?next=/portal`) instead of the page the Visitor is on
 * (guest-disabled-nav). The registered-only popover builds its links from
 * `useLocation()`, so this provides the tab's route as the location for that
 * subtree only. Rendered only around a disabled tab: an enabled `NavLink`
 * must read the real location for its active state.
 */
export function ReturnTo({ path, children }: { path: string; children: ReactNode }) {
  const value = useMemo(
    () => ({ location: { pathname: path, search: '', hash: '', state: null, key: 'default' }, navigationType: NavigationType.Pop }),
    [path],
  )

  return <UNSAFE_LocationContext.Provider value={value}>{children}</UNSAFE_LocationContext.Provider>
}

/**
 * A header tab that signing in would not unlock (the Market Context page while
 * the owner's go-live flag is off, or a session without the ability): focusable,
 * `aria-disabled`, described by the reason, and on click, tap, Enter or Space a
 * small non-modal bubble with the same reason and no sign-in link (domain
 * decision 1: no promise login cannot keep). Focus stays on the tab; Escape,
 * blur, an outside press, scroll or resize close the bubble.
 */
export function UnreleasedNavTab({ label, reason, title }: { label: string; reason: string; title: string }) {
  const reasonId = useId()
  const [anchor, setAnchor] = useState<HTMLElement | null>(null)

  const toggle = (element: HTMLElement) => setAnchor((current) => (current === null ? element : null))

  return (
    <>
      <a
        role="link"
        tabIndex={0}
        aria-disabled="true"
        aria-describedby={reasonId}
        title={title}
        onClick={(event) => {
          event.preventDefault()
          toggle(event.currentTarget)
        }}
        onKeyDown={(event: KeyboardEvent<HTMLElement>) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault()
            toggle(event.currentTarget)
          } else if (event.key === 'Escape') {
            setAnchor(null)
          }
        }}
        onBlur={() => setAnchor(null)}
        className={`${TAB_CLASS} ${TAB_IDLE_CLASS} cursor-not-allowed opacity-60`}
      >
        {label}
      </a>
      <span id={reasonId} hidden className="sr-only">
        {reason}
      </span>
      {anchor !== null ? <ReasonBubble anchor={anchor} reason={reason} onClose={() => setAnchor(null)} /> : null}
    </>
  )
}

function ReasonBubble({ anchor, reason, onClose }: { anchor: HTMLElement; reason: string; onClose: () => void }) {
  const ref = useRef<HTMLDivElement>(null)
  const closeRef = useRef(onClose)
  useLayoutEffect(() => {
    closeRef.current = onClose
  })

  useLayoutEffect(() => {
    const element = ref.current
    if (element === null) {
      return
    }
    // Fixed and portalled: the nav row scrolls horizontally and would clip it. Clamped inside 390 px.
    const margin = 8
    const rect = anchor.getBoundingClientRect()
    const width = Math.min(288, window.innerWidth - margin * 2)
    element.style.width = `${width}px`
    element.style.left = `${Math.max(margin, Math.min(rect.left, window.innerWidth - width - margin))}px`
    element.style.top = `${rect.bottom + 6}px`
  }, [anchor])

  useEffect(() => {
    const close = () => closeRef.current()
    const onPointerDown = (event: Event) => {
      if (!anchor.contains(event.target as Node)) {
        close()
      }
    }
    document.addEventListener('pointerdown', onPointerDown)
    window.addEventListener('scroll', close, true)
    window.addEventListener('resize', close)

    return () => {
      document.removeEventListener('pointerdown', onPointerDown)
      window.removeEventListener('scroll', close, true)
      window.removeEventListener('resize', close)
    }
  }, [anchor])

  return createPortal(
    <div
      ref={ref}
      role="status"
      className="fixed z-50 rounded-md border-2 border-outline bg-surface-bright p-3 text-on-surface shadow-[2px_2px_0px_var(--color-shadow)]"
    >
      <p className="flex items-start gap-1.5 text-xs text-on-surface-variant">
        <LockIcon className="mt-0.5 h-3.5 w-3.5" />
        <span>{reason}</span>
      </p>
    </div>,
    document.body,
  )
}
