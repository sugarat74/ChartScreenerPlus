// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { RouterProvider, createMemoryRouter, useLocation } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../../auth/AuthContext.tsx'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import AdminPage from '../AdminPage.tsx'
import AdminActivityPage from './AdminActivityPage.tsx'
import AdminLayout from './AdminLayout.tsx'
import AdminSessionsPage from './AdminSessionsPage.tsx'
import AdminUsersPage from './AdminUsersPage.tsx'

type Route = (url: string, init: RequestInit) => unknown

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

const page = (data: unknown[]) => ({ data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length } })

function mockApi(role: 'admin' | 'user', route: Route) {
  const calls: Array<{ url: string; method: string }> = []
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: string, init: RequestInit = {}) => {
      const method = init.method ?? 'GET'
      calls.push({ url: input, method })
      if (input === '/api/user') {
        return json({ user: { id: 1, name: 'Root', email: 'root@example.com', role } })
      }
      if (input === '/sanctum/csrf-cookie') {
        return new Response(null, { status: 204 })
      }
      if (input.startsWith('/api/admin/ingestion/runs')) {
        return json({ runs: [] })
      }
      return json(route(input, init))
    }),
  )
  return calls
}

function LocationProbe() {
  const location = useLocation()
  return <p data-testid="location">{`${location.pathname}${location.search}`}</p>
}

function renderAdmin(path: string) {
  const router = createMemoryRouter(
    [
      {
        path: '/admin',
        element: (
          <>
            <AdminLayout />
            <LocationProbe />
          </>
        ),
        children: [
          { index: true, element: <AdminPage /> },
          { path: 'users', element: <AdminUsersPage /> },
          { path: 'sessions', element: <AdminSessionsPage /> },
          { path: 'activity', element: <AdminActivityPage /> },
        ],
      },
    ],
    { initialEntries: [path] },
  )
  return render(
    <I18nProvider>
      <AuthProvider>
        <RouterProvider router={router} />
      </AuthProvider>
    </I18nProvider>,
  )
}

const device = { browser: 'Chrome', os: 'Windows' }

beforeEach(() => {
  window.localStorage.clear()
  Object.defineProperty(window.navigator, 'languages', { value: ['en-US'], configurable: true })
})

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('Admin users and sessions screens', () => {
  it('shows a restricted state to non-admins and never calls the admin user API', async () => {
    const calls = mockApi('user', () => ({}))
    renderAdmin('/admin/users')

    expect(await screen.findByText('Restricted access')).toBeTruthy()
    expect(calls.some((call) => call.url.startsWith('/api/admin/'))).toBe(false)
  })

  it('protects the current session and ends another one after confirmation', async () => {
    let listed = 0
    const calls = mockApi('admin', (url) => {
      if (url.startsWith('/api/admin/sessions/')) {
        return { revoked: 1 }
      }
      listed += 1
      return page([
        { ref: 'a'.repeat(40), user_id: 1, ip_address: '10.0.0.1', device, last_activity_at: '2026-10-07T10:00:00+00:00', is_current: true, user: { id: 1, name: 'Root', email: 'root@example.com', role: 'admin' } },
        { ref: 'b'.repeat(40), user_id: 2, ip_address: '10.0.0.2', device, last_activity_at: '2026-10-07T09:00:00+00:00', is_current: false, user: { id: 2, name: 'Ana', email: 'ana@example.com', role: 'user' } },
      ])
    })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    renderAdmin('/admin/sessions')

    expect(await screen.findByText('This session')).toBeTruthy()
    const endButtons = screen.getAllByRole('button', { name: 'End session' })
    expect(endButtons).toHaveLength(1)
    expect(screen.getAllByText('Chrome · Windows')).toHaveLength(2)

    fireEvent.click(endButtons[0])

    await waitFor(() => expect(screen.getByText('1 session(s) ended.')).toBeTruthy())
    expect(calls).toContainEqual({ url: `/api/admin/sessions/${'b'.repeat(40)}`, method: 'DELETE' })
    await waitFor(() => expect(listed).toBe(2))
  })

  it('does nothing when the confirmation is declined', async () => {
    const calls = mockApi('admin', () =>
      page([
        { ref: 'b'.repeat(40), user_id: 2, ip_address: null, device: { browser: null, os: null }, last_activity_at: '2026-10-07T09:00:00+00:00', is_current: false, user: null },
      ]),
    )
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    renderAdmin('/admin/sessions')

    fireEvent.click(await screen.findByRole('button', { name: 'End session' }))
    expect(screen.getByText('Unknown · Unknown')).toBeTruthy()
    expect(calls.some((call) => call.method === 'DELETE')).toBe(false)
  })

  it('shows summary tiles and searches users through the URL', async () => {
    const calls = mockApi('admin', (url) => {
      if (url === '/api/admin/users/summary') {
        return {
          summary: {
            users_total: 12,
            admins_total: 1,
            users_new_7d: 3,
            users_new_30d: 5,
            users_active_24h: 4,
            sessions_active: 6,
            failed_logins_24h: 2,
            activity_retention_days: 90,
          },
        }
      }
      return page([
        { id: 2, name: 'Ana', email: 'ana@example.com', role: 'user', created_at: '2026-10-01T10:00:00+00:00', last_login_at: null, last_activity_at: null, active_sessions_count: 1, saved_screeners_count: 2, watchlist_count: 3 },
      ])
    })
    renderAdmin('/admin/users')

    expect(await screen.findByText('History kept for 90 days')).toBeTruthy()
    expect(screen.getByText('12')).toBeTruthy()
    expect(await screen.findByRole('link', { name: 'Ana' })).toBeTruthy()

    fireEvent.change(screen.getByLabelText('Search by name or email'), { target: { value: 'ana' } })
    fireEvent.click(screen.getByRole('button', { name: 'Search' }))

    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/admin/users?q=ana'))
    await waitFor(() => expect(calls.some((call) => call.url === '/api/admin/users?search=ana&page=1')).toBe(true))
  })

  it('offers the admin section tabs', async () => {
    mockApi('admin', () => page([]))
    renderAdmin('/admin/activity')

    const nav = await screen.findByRole('navigation', { name: 'Administration sections' })
    expect(nav.textContent).toContain('Ingestion')
    expect(nav.textContent).toContain('Users')
    expect(nav.textContent).toContain('Sessions')
    expect(screen.getByRole('link', { name: 'Activity' }).getAttribute('aria-current')).toBe('page')
    expect(await screen.findByText('No recorded activity.')).toBeTruthy()
  })
})
