// @vitest-environment jsdom
import { cleanup, render, screen, within } from '@testing-library/react'
import { RouterProvider, createMemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../auth/AuthContext.tsx'
import AppFooter from '../components/AppFooter.tsx'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { resetLegalInfoCache } from '../legal/useLegalInfo.ts'
import type { LegalInfo } from '../lib/api.ts'
import LegalPage from './LegalPage.tsx'
import RegisterPage from './RegisterPage.tsx'

const OWNER = {
  name: 'Owner Test',
  tax_id: '00000000T',
  address: 'Calle Prueba 1, 28000 Madrid',
  email: 'privacy@example.test',
  registry: null,
}

function legalInfo(published: boolean): LegalInfo {
  return {
    published,
    owner: published ? OWNER : null,
    updated_at: '2026-10-10',
    retention: { sign_in_activity_days: 90, session_minutes: 120, backup_days: 14, server_log_days: 14, notification_days: 90 },
  }
}

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function mockApi(info: LegalInfo) {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: string) => {
      if (input === '/api/legal') return json(info)
      if (input === '/api/user') return json({ message: 'Unauthenticated.' }, 401)
      return json({})
    }),
  )
}

function renderAt(path: string) {
  const router = createMemoryRouter(
    [
      {
        path: '/',
        element: <AppFooter />,
      },
      { path: '/privacidad', element: <><LegalPage kind="privacy" /><AppFooter /></> },
      { path: '/aviso-legal', element: <LegalPage kind="notice" /> },
      { path: '/cookies', element: <LegalPage kind="cookies" /> },
      { path: '/register', element: <RegisterPage /> },
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

beforeEach(() => {
  resetLegalInfoCache()
  window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es')
})

afterEach(() => {
  cleanup()
  vi.unstubAllGlobals()
  window.localStorage.clear()
})

describe('legal pages while the owner data is missing', () => {
  beforeEach(() => mockApi(legalInfo(false)))

  it('footer shows the disclaimer and links only the cookie policy', async () => {
    renderAt('/')
    const nav = await screen.findByRole('navigation', { name: 'Información legal' })
    expect(within(nav).getByRole('link', { name: 'Cookies' })).toBeTruthy()
    expect(within(nav).queryByRole('link', { name: 'Privacidad' })).toBeNull()
    expect(within(nav).queryByRole('link', { name: 'Aviso legal' })).toBeNull()
    expect(screen.getByText(/No es asesoramiento de inversión/)).toBeTruthy()
  })

  it('privacy policy and legal notice are withheld, without placeholders', async () => {
    renderAt('/privacidad')
    expect(await screen.findByText('Página en preparación')).toBeTruthy()
    expect(screen.queryByText(/Responsable:/)).toBeNull()
    expect(document.body.textContent).not.toMatch(/\{owner|PENDIENTE/)
    cleanup()

    renderAt('/aviso-legal')
    expect(await screen.findByText('Página en preparación')).toBeTruthy()
  })

  it('cookie policy is published and lists the real storage with configured durations', async () => {
    renderAt('/cookies')
    const table = await screen.findByRole('table')
    for (const name of ['alphapulse-session', 'XSRF-TOKEN', 'chartiko.locale']) {
      expect(within(table).getByText(name)).toBeTruthy()
    }
    expect(within(table).getAllByText('120 minutos')).toHaveLength(2)
  })

  it('registration shows the basic first layer without linking the unpublished policy', async () => {
    renderAt('/register')
    const notice = await screen.findByTestId('register-privacy')
    expect(notice.textContent).toMatch(/al menos 14 años/)
    expect(within(notice).queryByRole('link')).toBeNull()
  })
})

describe('legal pages once the owner data is configured', () => {
  beforeEach(() => mockApi(legalInfo(true)))

  it('privacy policy shows the owner, retention from the API and a mailto link', async () => {
    renderAt('/privacidad')
    expect(await screen.findByText('Responsable: Owner Test')).toBeTruthy()
    expect(screen.getByText('NIF: 00000000T')).toBeTruthy()
    expect(screen.getAllByRole('link', { name: 'privacy@example.test' })[0].getAttribute('href')).toBe(
      'mailto:privacy@example.test',
    )
    expect(document.body.textContent).toMatch(/a los 90 días/)
    expect(document.body.textContent).toMatch(/Canadá/)
    expect(document.body.textContent).toMatch(/notificaciones se borran automáticamente a los 90 días/)
    expect(document.body.textContent).not.toMatch(/\{\w+\}/)

    const nav = screen.getByRole('navigation', { name: 'Información legal' })
    expect(within(nav).getByRole('link', { name: 'Privacidad' })).toBeTruthy()
    expect(within(nav).getByRole('link', { name: 'Aviso legal' })).toBeTruthy()
  })

  it('legal notice includes the investment disclaimer', async () => {
    renderAt('/aviso-legal')
    expect(await screen.findByText('Titular: Owner Test')).toBeTruthy()
    expect(screen.getByRole('heading', { name: 'No es asesoramiento de inversión' })).toBeTruthy()
  })

  it('registration first layer names the controller and links the policy', async () => {
    renderAt('/register')
    const notice = await screen.findByTestId('register-privacy')
    await within(notice).findByRole('link', { name: 'política de privacidad' })
    expect(within(notice).getByRole('link', { name: 'condiciones de uso' }).getAttribute('href')).toBe('/aviso-legal')
    expect(notice.textContent).toMatch(/Responsable: Owner Test/)
    expect(notice.textContent).toMatch(/al menos 14 años/)
  })

  it('renders the English texts when English is selected', async () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    renderAt('/privacidad')
    expect(await screen.findByText('Controller: Owner Test')).toBeTruthy()
    expect(screen.getByRole('heading', { level: 1, name: 'Privacy policy' })).toBeTruthy()
  })
})
