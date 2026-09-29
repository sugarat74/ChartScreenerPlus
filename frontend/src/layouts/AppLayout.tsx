import { Outlet } from 'react-router'
import AppHeader from '../components/AppHeader.tsx'

/**
 * Shell frame: sticky header plus the routed surface inside the centred
 * `max-w-7xl` column defined in DESIGN.md.
 */
export default function AppLayout() {
  return (
    <div className="flex min-h-screen flex-col bg-surface text-on-surface">
      <AppHeader />
      <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6">
        <Outlet />
      </main>
    </div>
  )
}
