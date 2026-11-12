import { useEffect, useLayoutEffect, useRef } from 'react'
import { createPortal } from 'react-dom'
import LockIcon from './LockIcon.tsx'
import RegisteredOnlyActions from './RegisteredOnlyActions.tsx'

/**
 * Non-modal reason bubble anchored to a disabled control. Portalled to <body>
 * with fixed positioning (an overflow-x-auto parent would clip an absolute
 * child), placed from the anchor rect, flipped above near the viewport bottom
 * and clamped inside the viewport (390 px). It does not trap focus: Escape,
 * an outside press, a scroll/resize or focus leaving it close it, and Escape
 * returns focus to the control.
 */
export default function RegisteredOnlyPopover({
  anchor,
  reason,
  state,
  onClose,
}: {
  anchor: HTMLElement
  reason: string
  state: 'guest' | 'pending'
  onClose: () => void
}) {
  const ref = useRef<HTMLDivElement>(null)

  useLayoutEffect(() => {
    const element = ref.current
    if (element === null) {
      return
    }
    const margin = 8
    const rect = anchor.getBoundingClientRect()
    const width = Math.min(288, window.innerWidth - margin * 2)
    element.style.width = `${width}px`
    const left = Math.max(margin, Math.min(rect.left, window.innerWidth - width - margin))
    const height = element.offsetHeight
    const below = rect.bottom + 6
    const top = below + height > window.innerHeight - margin && rect.top - 6 - height >= margin ? rect.top - 6 - height : below
    element.style.left = `${left}px`
    element.style.top = `${Math.max(margin, top)}px`
  }, [anchor])

  useEffect(() => {
    ref.current?.querySelector<HTMLElement>('a')?.focus()

    let timer: ReturnType<typeof setTimeout> | undefined
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        onClose()
        anchor.focus()
      }
      if (event.key === 'Tab' && ref.current?.contains(event.target as Node)) {
        // Tabbing out through the document wrap blurs with no relatedTarget: check where focus landed.
        timer = setTimeout(() => {
          if (ref.current !== null && !ref.current.contains(document.activeElement)) {
            onClose()
          }
        }, 0)
      }
    }
    const onPointerDown = (event: Event) => {
      const target = event.target as Node
      if (!ref.current?.contains(target) && !anchor.contains(target)) {
        onClose()
      }
    }
    document.addEventListener('keydown', onKeyDown)
    document.addEventListener('pointerdown', onPointerDown)
    window.addEventListener('scroll', onClose, true)
    window.addEventListener('resize', onClose)

    return () => {
      clearTimeout(timer)
      document.removeEventListener('keydown', onKeyDown)
      document.removeEventListener('pointerdown', onPointerDown)
      window.removeEventListener('scroll', onClose, true)
      window.removeEventListener('resize', onClose)
    }
  }, [anchor, onClose])

  return createPortal(
    <div
      ref={ref}
      role="status"
      onBlur={(event) => {
        const next = event.relatedTarget as Node | null
        if (next !== null && !event.currentTarget.contains(next)) {
          onClose()
        }
      }}
      className="fixed z-50 flex flex-col gap-2 rounded-md border-2 border-outline bg-surface-bright p-3 text-on-surface shadow-[2px_2px_0px_var(--color-shadow)]"
    >
      <p className="flex items-start gap-1.5 text-xs text-on-surface-variant">
        <LockIcon className="mt-0.5 h-3.5 w-3.5" />
        <span>{reason}</span>
      </p>
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
        <RegisteredOnlyActions state={state} />
      </div>
    </div>,
    document.body,
  )
}
