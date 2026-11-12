import type { ReactNode } from 'react'
import type { MarketContextAccess } from '../../auth/useMarketContextAccess.ts'
import { useI18n } from '../../i18n/useI18n.ts'
import type { MessageKey } from '../../i18n/translate.ts'
import LockIcon from './LockIcon.tsx'
import RegisteredOnlyActions from './RegisteredOnlyActions.tsx'
import { useResolvedState } from './useRegisteredOnlyState.ts'

/**
 * A registered-only block (a panel, a form, a strip). One component tree for
 * every state so nothing remounts or shifts when the session appears: a
 * `fieldset` that is natively `disabled` for a Visitor (its fields add no tab
 * stops), the content dimmed, and a visible inline reason row with the sign-in
 * link (the only focusable element). The reason row slot is reserved in every
 * state (empty while loading and when enabled), so the box never changes
 * between guest, loading and signed-in: nothing shifts at login. While the
 * session loads the shell keeps the same look (`aria-busy`). The row shows only
 * the sign-in link (create account lives in the popover) so the default reason
 * stays on one line at 390 px; a longer per-feature reason may wrap and must
 * reserve its own height.
 * `children` receives `{ disabled }` so the panel can skip its effects: a
 * registered-only API is requested only when `disabled` is `false`.
 */
export default function RegisteredOnlyPanel({
  children,
  reason,
  reasonPosition = 'bottom',
  className,
  access,
}: {
  children: (state: { disabled: boolean }) => ReactNode
  /** Message key overriding the default reason of the state. */
  reason?: MessageKey
  reasonPosition?: 'top' | 'bottom'
  className?: string
  /** The Market Context seam; see `useResolvedState`. */
  access?: MarketContextAccess
}) {
  const state = useResolvedState(access)
  const { t } = useI18n()

  if (state === 'hidden') {
    return null
  }

  const disabled = state !== 'enabled'
  const loading = state === 'loading'
  const text = loading ? '' : t(reason ?? (state === 'pending' ? 'registeredOnly.reasonPending' : 'registeredOnly.reasonGuest'))
  const row = (
    <div
      data-testid="registered-only-reason"
      className="flex min-h-5 min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-xs text-on-surface-variant"
    >
      {loading || !disabled ? null : (
        <>
          <LockIcon />
          <span>{text}</span>
          <RegisteredOnlyActions state={state === 'pending' ? 'pending' : 'guest'} showRegister={false} />
        </>
      )}
    </div>
  )

  return (
    <div aria-busy={loading ? 'true' : undefined} className={`flex min-w-0 flex-col gap-2 ${className ?? ''}`.trim()}>
      {reasonPosition === 'top' ? row : null}
      <fieldset disabled={disabled} aria-disabled={disabled ? 'true' : undefined} className={`m-0 min-w-0 border-0 p-0 ${disabled ? 'opacity-60' : ''}`.trim()}>
        {children({ disabled })}
      </fieldset>
      {reasonPosition === 'bottom' ? row : null}
    </div>
  )
}
