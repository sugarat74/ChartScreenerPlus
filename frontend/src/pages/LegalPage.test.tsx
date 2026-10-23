// @vitest-environment jsdom
import { cleanup, render, screen, within } from '@testing-library/react'
import { RouterProvider, createMemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthProvider } from '../auth/AuthContext.tsx'
import AppFooter from '../components/AppFooter.tsx'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { resetLegalInfoCache } from '../legal/useLegalInfo.ts'
import type { LegalInfo, LegalOwner } from '../lib/api.ts'
import LegalPage from './LegalPage.tsx'
import RegisterPage from './RegisterPage.tsx'

const RETENTION = { sign_in_activity_days: 90, session_minutes: 120, backup_days: 14, server_log_days: 14, notification_days: 90, ai_card_log_days: 90, ai_screener_log_days: 90 }

/** No owner data: only the cookie policy is published. */
const NONE: LegalInfo = { privacy_published: false, notice_published: false, owner: null, updated_at: '2026-10-10', retention: RETENTION }

/** Name + email only (the production target): privacy published, notice withheld. */
const PRIVACY_ONLY_OWNER: LegalOwner = { name: 'Owner Test', email: 'privacy@example.test', tax_id: null, address: null, registry: null }
const PRIVACY_ONLY: LegalInfo = { ...NONE, privacy_published: true, owner: PRIVACY_ONLY_OWNER }

/** Every field configured: both pages published. */
const ALL: LegalInfo = {
  ...NONE,
  privacy_published: true,
  notice_published: true,
  owner: { ...PRIVACY_ONLY_OWNER, tax_id: '00000000T', address: 'Calle Prueba 1, 28000 Madrid', registry: null },
}

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } })
}

function mockApi(info: unknown) {
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
      { path: '/aviso-legal', element: <><LegalPage kind="notice" /><AppFooter /></> },
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

function expectNoMailtoOrPlaceholders() {
  expect(document.querySelector('a[href^="mailto:"]')).toBeNull()
  expect(document.body.textContent).not.toMatch(/\{\w+\}/)
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
  beforeEach(() => mockApi(NONE))

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
    for (const name of ['alphapulse-session', 'XSRF-TOKEN', 'chartiko.locale', 'chartiko.theme']) {
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

  it('a response with only the retired `published` flag withholds both pages', async () => {
    mockApi({ published: true, owner: ALL.owner, updated_at: '2026-10-10', retention: RETENTION })
    renderAt('/privacidad')
    expect(await screen.findByText('Página en preparación')).toBeTruthy()
    const nav = screen.getByRole('navigation', { name: 'Información legal' })
    expect(within(nav).queryByRole('link', { name: 'Privacidad' })).toBeNull()
    expect(within(nav).queryByRole('link', { name: 'Aviso legal' })).toBeNull()
  })
})

describe('privacy published with name + email only (production target)', () => {
  beforeEach(() => mockApi(PRIVACY_ONLY))

  it('privacy policy names the controller and shows the email as plain text', async () => {
    renderAt('/privacidad')
    expect(await screen.findByText('Responsable: Owner Test')).toBeTruthy()
    expect(screen.getByText(/^Contacto y protección de datos:/).textContent).toBe('Contacto y protección de datos: privacy@example.test')
    expect(document.body.textContent).toMatch(/escribiendo a privacy@example\.test/)
    expect(screen.queryByRole('link', { name: 'privacy@example.test' })).toBeNull()
    expect(document.body.textContent).not.toMatch(/NIF|Domicilio/)
    expect(document.body.textContent).toMatch(/a los 90 días/)
    expect(document.body.textContent).toMatch(/Canadá/)
    expectNoMailtoOrPlaceholders()

    const nav = screen.getByRole('navigation', { name: 'Información legal' })
    expect(within(nav).getByRole('link', { name: 'Privacidad' })).toBeTruthy()
    expect(within(nav).getByRole('link', { name: 'Cookies' })).toBeTruthy()
    expect(within(nav).queryByRole('link', { name: 'Aviso legal' })).toBeNull()
  })

  it('legal notice stays withheld', async () => {
    renderAt('/aviso-legal')
    expect(await screen.findByText('Página en preparación')).toBeTruthy()
    expect(screen.queryByText(/Titular:/)).toBeNull()
  })

  it('registration first layer names the controller and links only the policy', async () => {
    renderAt('/register')
    const notice = await screen.findByTestId('register-privacy')
    const policy = await within(notice).findByRole('link', { name: 'política de privacidad' })
    expect(policy.getAttribute('href')).toBe('/privacidad')
    expect(within(notice).getAllByRole('link')).toHaveLength(1)
    expect(notice.textContent).toMatch(/Responsable: Owner Test/)
    expect(notice.textContent).toMatch(/al menos 14 años/)
    expect(notice.textContent).not.toMatch(/condiciones de uso|aceptas/)
    expect(notice.textContent).not.toMatch(/\{\w+\}/)
  })

  it('renders the English privacy policy and register layer without tax id or address', async () => {
    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    renderAt('/privacidad')
    expect(await screen.findByText('Controller: Owner Test')).toBeTruthy()
    expect(screen.getByRole('heading', { level: 1, name: 'Privacy policy' })).toBeTruthy()
    expect(document.body.textContent).toMatch(/by writing to privacy@example\.test/)
    expect(document.body.textContent).not.toMatch(/Tax ID|Address/)
    expectNoMailtoOrPlaceholders()
    const nav = screen.getByRole('navigation', { name: 'Legal information' })
    expect(within(nav).getByRole('link', { name: 'Privacy' })).toBeTruthy()
    expect(within(nav).queryByRole('link', { name: 'Legal notice' })).toBeNull()
    cleanup()

    renderAt('/register')
    const notice = await screen.findByTestId('register-privacy')
    await within(notice).findByRole('link', { name: 'privacy policy' })
    expect(within(notice).getAllByRole('link')).toHaveLength(1)
    expect(notice.textContent).toMatch(/Controller: Owner Test/)
    expect(notice.textContent).not.toMatch(/accept|terms of use/)
  })
})

describe('both pages published once every owner field is configured', () => {
  beforeEach(() => mockApi(ALL))

  it('footer links privacy, legal notice and cookies', async () => {
    renderAt('/')
    const nav = await screen.findByRole('navigation', { name: 'Información legal' })
    await within(nav).findByRole('link', { name: 'Privacidad' })
    expect(within(nav).getByRole('link', { name: 'Aviso legal' })).toBeTruthy()
    expect(within(nav).getByRole('link', { name: 'Cookies' })).toBeTruthy()
  })

  it('privacy policy still has no tax id or address lines', async () => {
    renderAt('/privacidad')
    expect(await screen.findByText('Responsable: Owner Test')).toBeTruthy()
    expect(document.body.textContent).not.toMatch(/NIF|Domicilio|00000000T/)
    expectNoMailtoOrPlaceholders()
  })

  it('legal notice shows tax id, address, email as text and the investment disclaimer', async () => {
    renderAt('/aviso-legal')
    expect(await screen.findByText('Titular: Owner Test')).toBeTruthy()
    expect(screen.getByText('NIF: 00000000T')).toBeTruthy()
    expect(screen.getByText('Domicilio: Calle Prueba 1, 28000 Madrid')).toBeTruthy()
    expect(screen.getByText(/^Email de contacto:/).textContent).toBe('Email de contacto: privacy@example.test')
    expect(screen.getByRole('heading', { name: 'No es asesoramiento de inversión' })).toBeTruthy()
    expectNoMailtoOrPlaceholders()
  })

  it('registration first layer links the policy and the terms', async () => {
    renderAt('/register')
    const notice = await screen.findByTestId('register-privacy')
    await within(notice).findByRole('link', { name: 'política de privacidad' })
    expect(within(notice).getByRole('link', { name: 'condiciones de uso' }).getAttribute('href')).toBe('/aviso-legal')
    expect(notice.textContent).toMatch(/Responsable: Owner Test/)
    expect(notice.textContent).toMatch(/aceptas las condiciones de uso/)
  })
})

describe('daily alert email in the privacy policy (alerts-email-digest)', () => {
  it('describes the email and names its provider only while the feature is active', async () => {
    mockApi(PRIVACY_ONLY)
    renderAt('/privacidad')
    await screen.findByRole('heading', { name: 'Alertas y notificaciones' })
    expect(document.body.textContent).not.toMatch(/resumen diario por email/)
    cleanup()
    resetLegalInfoCache()

    mockApi({
      ...PRIVACY_ONLY,
      retention: { ...RETENTION, email_digest_days: 90 },
      email_digest: { provider: 'Brevo', provider_location: 'UE' },
    })
    renderAt('/privacidad')
    await screen.findByRole('heading', { name: 'Alertas y notificaciones' })
    expect(document.body.textContent).toMatch(/resumen diario por email \(está desactivado si no lo eliges\)/)
    expect(document.body.textContent).toMatch(/se borra a los 90 días/)
    expect(document.body.textContent).toMatch(/se envían a Brevo, el proveedor de envío de correo/)
    expect(document.body.textContent).toMatch(/Ubicación del tratamiento: UE/)
    expectNoMailtoOrPlaceholders()
  })
})

describe('"what we do not do" in the privacy policy (alerts-email-golive)', () => {
  it('no longer says alert emails are not sent, in Spanish and English', async () => {
    mockApi(PRIVACY_ONLY)
    renderAt('/privacidad')
    const es = (await screen.findByRole('heading', { name: 'Lo que no hacemos' })).nextElementSibling
    expect(es?.textContent).toMatch(/no enviamos comunicaciones comerciales, no elaboramos perfiles/)
    expect(es?.textContent).not.toMatch(/emails de alertas/)
    cleanup()
    resetLegalInfoCache()

    window.localStorage.setItem(LOCALE_STORAGE_KEY, 'en')
    mockApi(PRIVACY_ONLY)
    renderAt('/privacidad')
    const en = (await screen.findByRole('heading', { name: 'What we do not do' })).nextElementSibling
    expect(en?.textContent).toMatch(/send no marketing communications, and do no profiling/)
    expect(en?.textContent).not.toMatch(/alert emails/)
  })
})

describe('account emails in the privacy policy and the register form (auth-email-verification)', () => {
  it('describes the confirmation email and names its provider only while account emails are sent', async () => {
    mockApi(PRIVACY_ONLY)
    renderAt('/privacidad')
    await screen.findByRole('heading', { name: 'Cuenta de usuario' })
    expect(document.body.textContent).not.toMatch(/confirmar que la dirección es tuya/)
    expect(document.body.textContent).not.toMatch(/Los emails de tu cuenta/)
    cleanup()
    resetLegalInfoCache()

    mockApi({ ...PRIVACY_ONLY, account_email: { provider: 'Brevo', provider_location: 'UE' } })
    renderAt('/privacidad')
    await screen.findByRole('heading', { name: 'Cuenta de usuario' })
    expect(document.body.textContent).toMatch(/un enlace que caduca a las 24 horas/)
    expect(document.body.textContent).toMatch(/Los emails de tu cuenta \(como la confirmación de tu dirección\) se envían a través de Brevo/)
    expect(document.body.textContent).toMatch(/Ubicación del tratamiento: UE/)
    // The daily alert email paragraphs stay off.
    expect(document.body.textContent).not.toMatch(/resumen diario por email/)
    expectNoMailtoOrPlaceholders()
  })

  it('shows the confirmation hint on the register form only while account emails are sent', async () => {
    mockApi(PRIVACY_ONLY)
    renderAt('/register')
    await screen.findByTestId('register-privacy')
    expect(screen.queryByTestId('register-verify-hint')).toBeNull()
    cleanup()
    resetLegalInfoCache()

    mockApi({ ...PRIVACY_ONLY, account_email: { provider: 'Brevo', provider_location: 'UE' } })
    renderAt('/register')
    expect((await screen.findByTestId('register-verify-hint')).textContent).toBe(
      'Después de crear la cuenta te enviaremos un email para confirmar tu dirección.',
    )
  })
})

describe('FRED clauses in the legal notice (market-context-ui)', () => {
  it('shows the terms item and the sources paragraph only while the Market Context is public', async () => {
    mockApi({ ...ALL, market_context: false })
    renderAt('/aviso-legal')
    await screen.findByRole('heading', { name: 'Condiciones de uso' })
    expect(document.body.textContent).not.toMatch(/FRED/)
    cleanup()
    resetLegalInfoCache()

    mockApi({ ...ALL, market_context: true })
    renderAt('/aviso-legal')
    const terms = await screen.findByRole('link', { name: 'Condiciones de uso de la API de FRED®' })
    expect(terms.getAttribute('href')).toBe('https://fred.stlouisfed.org/docs/api/terms_of_use.html')
    expect(terms.getAttribute('rel')).toBe('noopener noreferrer')
    // The clause is a list item of "Condiciones de uso".
    expect(terms.closest('li')?.textContent).toBe(
      'Los datos macroeconómicos de la página Mercado proceden de la API FRED® del Banco de la Reserva Federal de St. Louis. Al usar Chartiko aceptas quedar vinculado por las Condiciones de uso de la API de FRED®.',
    )
    expect(screen.getByText(/Chartiko usa la API FRED® pero no está respaldado ni certificado/).tagName).toBe('P')
    expectNoMailtoOrPlaceholders()
  })
})
