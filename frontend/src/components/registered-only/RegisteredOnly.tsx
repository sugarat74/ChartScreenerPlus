import { cloneElement, createElement, Fragment, isValidElement, useCallback, useId, useState } from 'react'
import type { KeyboardEvent, MouseEvent, ReactElement, ReactNode } from 'react'
import type { MarketContextAccess } from '../../auth/useMarketContextAccess.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import type { MessageKey } from '../../i18n/translate.ts'
import RegisteredOnlyPopover from './RegisteredOnlyPopover.tsx'
import { useResolvedState } from './useRegisteredOnlyState.ts'

interface ChildProps {
  className?: string | ((state: Record<string, boolean>) => string | undefined)
  children?: ReactNode | ((state: Record<string, boolean>) => ReactNode)
  title?: string
  href?: string
  to?: unknown
  tabIndex?: number
  'aria-describedby'?: string
  [key: string]: unknown
}

const NAV_STATE = { isActive: false, isPending: false, isTransitioning: false }
/** Router-link props that mean nothing on the plain anchor a disabled link becomes. */
const LINK_ONLY_PROPS = ['to', 'replace', 'state', 'relative', 'reloadDocument', 'preventScrollReset', 'viewTransition', 'prefetch', 'end', 'caseSensitive']

/**
 * An inline registered-only control (button, link, chip, tab, trigger) shown
 * to everyone, usable once the session is a verified one
 * (docs/specs/guest-disabled-registered-options.md). Enabled it is the child,
 * untouched, with no wrapper. Otherwise the child is dimmed and
 * `aria-disabled` but NOT natively disabled (it stays focusable and
 * hoverable): click, submit and Enter/Space are swallowed and open a popover
 * with the reason and sign-in links; the reason is also always in the DOM as
 * `sr-only` text referenced by `aria-describedby`.
 */
export default function RegisteredOnly({
  children,
  reason,
  variant = 'popover',
  tabbable = true,
  access,
}: {
  children: ReactElement<ChildProps>
  /** Message key overriding the default reason of the state. */
  reason?: MessageKey
  /** `inline`: no popover, only the described, dimmed control (the surface states the reason). */
  variant?: 'popover' | 'inline'
  /** `false` only for repeated per-row controls: out of the tab order. */
  tabbable?: boolean
  /** The Market Context seam; see `useResolvedState`. */
  access?: MarketContextAccess
}) {
  const state = useResolvedState(access)
  const { t } = useI18n()
  const reasonId = useId()
  const [anchor, setAnchor] = useState<HTMLElement | null>(null)
  const close = useCallback(() => setAnchor(null), [])

  if (state === 'hidden') {
    return null
  }
  if (state === 'enabled' || !isValidElement(children)) {
    return children
  }

  const loading = state === 'loading'
  const reasonText = loading ? '' : t(reason ?? (state === 'pending' ? 'registeredOnly.reasonPending' : 'registeredOnly.reasonGuest'))
  const props = children.props
  const open = (element: HTMLElement) => {
    if (!loading && variant === 'popover') {
      setAnchor(element)
    }
  }
  const swallow = (event: { preventDefault: () => void; stopPropagation: () => void }) => {
    event.preventDefault()
    event.stopPropagation()
  }

  const disabledProps: Record<string, unknown> = {
    'aria-disabled': 'true',
    'aria-busy': loading ? 'true' : undefined,
    'aria-describedby': loading ? props['aria-describedby'] : [props['aria-describedby'], reasonId].filter(Boolean).join(' '),
    tabIndex: tabbable ? (props.tabIndex ?? 0) : -1,
    title: props.title ?? (reasonText === '' ? undefined : reasonText),
    onClick: (event: MouseEvent<HTMLElement>) => {
      swallow(event)
      open(event.currentTarget)
    },
    onSubmit: swallow,
    onKeyDown: (event: KeyboardEvent<HTMLElement>) => {
      if (event.key === 'Enter' || event.key === ' ') {
        swallow(event)
        open(event.currentTarget)
      }
    },
    onKeyUp: (event: KeyboardEvent<HTMLElement>) => {
      if (event.key === ' ') {
        swallow(event)
      }
    },
  }
  const dimmed = (className: string | undefined) => `${className ?? ''} cursor-not-allowed opacity-60 hover:translate-y-0`.trim()

  let control: ReactElement
  if (props.to !== undefined || typeof props.href === 'string') {
    // A link without a destination: a plain anchor that is focusable and announced as a disabled link.
    const rest: Record<string, unknown> = { ...props }
    for (const key of [...LINK_ONLY_PROPS, 'href', 'className', 'children']) {
      delete rest[key]
    }
    const className = typeof props.className === 'function' ? props.className(NAV_STATE) : props.className
    const content = typeof props.children === 'function' ? props.children(NAV_STATE) : props.children
    control = createElement('a', { ...rest, ...disabledProps, role: 'link', className: dimmed(className) }, content)
  } else {
    const className = typeof props.className === 'string' ? props.className : undefined
    control = cloneElement(children, { ...disabledProps, className: dimmed(className) })
  }

  return (
    <Fragment>
      {control}
      {loading ? null : (
        <span id={reasonId} className="sr-only">
          {reasonText}
        </span>
      )}
      {anchor !== null && !loading ? (
        <RegisteredOnlyPopover anchor={anchor} reason={reasonText} state={state === 'pending' ? 'pending' : 'guest'} onClose={close} />
      ) : null}
    </Fragment>
  )
}
