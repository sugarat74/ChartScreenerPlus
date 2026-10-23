import { Outlet, useLocation } from 'react-router'
import AppFooter from '../components/AppFooter.tsx'
import AppHeader from '../components/AppHeader.tsx'
import RouteMetadata from '../components/RouteMetadata.tsx'
import VerifyEmailNotice from '../components/auth/VerifyEmailNotice.tsx'
import { useEmailVerificationPending } from '../auth/useEmailVerificationPending.ts'
import { isEmailVerificationExempt } from '../nav.ts'
import { UnreadNotificationsProvider } from '../notifications/UnreadNotificationsProvider.tsx'

/**
 * Shell frame: sticky header, the routed surface inside the centred
 * `max-w-7xl` column defined in DESIGN.md, and the legal footer. The unread
 * notification count is shared by the header bell and the inbox.
 *
 * The one email verification gate (auth-email-verification, D7): while the
 * session account still has to confirm its email, every path except the legal
 * pages, the Account page and the verify page shows the "confirm your email"
 * notice instead of the routed page. A UI affordance only: the API enforces it.
 */
export default function AppLayout() {
  const pending = useEmailVerificationPending()
  const { pathname } = useLocation()
  const gated = pending && !isEmailVerificationExempt(pathname)

  return (
    <UnreadNotificationsProvider>
      <div className="flex min-h-screen flex-col bg-surface text-on-surface">
        <RouteMetadata />
        <AppHeader />
        <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6">
          {gated ? <VerifyEmailNotice /> : <Outlet />}
        </main>
        <AppFooter />
      </div>
    </UnreadNotificationsProvider>
  )
}
