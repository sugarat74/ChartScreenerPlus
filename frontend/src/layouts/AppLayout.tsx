import { Outlet } from 'react-router'
import AppFooter from '../components/AppFooter.tsx'
import AppHeader from '../components/AppHeader.tsx'
import RouteMetadata from '../components/RouteMetadata.tsx'
import { UnreadNotificationsProvider } from '../notifications/UnreadNotificationsProvider.tsx'

/**
 * Shell frame: sticky header, the routed surface inside the centred
 * `max-w-7xl` column defined in DESIGN.md, and the legal footer. The unread
 * notification count is shared by the header bell and the inbox.
 */
export default function AppLayout() {
  return (
    <UnreadNotificationsProvider>
      <div className="flex min-h-screen flex-col bg-surface text-on-surface">
        <RouteMetadata />
        <AppHeader />
        <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6">
          <Outlet />
        </main>
        <AppFooter />
      </div>
    </UnreadNotificationsProvider>
  )
}
