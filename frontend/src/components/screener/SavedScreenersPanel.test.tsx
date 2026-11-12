// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from '../../auth/context.ts'
import type { AuthContextValue } from '../../auth/context.ts'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import { EMPTY_SCREENER_FILTERS } from '../../lib/screenerFilters.ts'
import { savedScreenersApi } from '../../lib/api.ts'
import SavedScreenersPanel from './SavedScreenersPanel.tsx'

const base: AuthContextValue = {
  user: null,
  status: 'ready',
  login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn(),
}
const member = { id: 1, name: 'Test', email: 'test@example.com', role: 'user' as const }

function panel(auth: AuthContextValue) {
  return (
    <AuthContext.Provider value={auth}>
      <I18nProvider>
        <MemoryRouter initialEntries={['/?rsi_min=40']}>
          <SavedScreenersPanel filters={EMPTY_SCREENER_FILTERS} onApply={vi.fn()} />
        </MemoryRouter>
      </I18nProvider>
    </AuthContext.Provider>
  )
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
  window.localStorage.clear()
})

describe('SavedScreenersPanel registered-only states (guest-disabled-screener)', () => {
  it('shows a Visitor the dimmed summary row with the reason, not expandable, and makes no request', () => {
    const list = vi.spyOn(savedScreenersApi, 'list')
    const { container } = render(panel(base))

    const row = screen.getByRole('button', { name: 'Screeners guardados' })
    expect(row.getAttribute('aria-disabled')).toBe('true')
    expect(row.hasAttribute('disabled')).toBe(false)
    expect(row.className).toContain('opacity-60')
    expect(screen.getByText('Disponible al iniciar sesión', { selector: 'span.flex' })).toBeTruthy()
    expect(container.querySelector('details')).toBeNull()
    expect(screen.queryByRole('textbox')).toBeNull()
    expect(list).not.toHaveBeenCalled()
  })

  it('opens the sign-in popover with the current Screener URL as next', () => {
    const list = vi.spyOn(savedScreenersApi, 'list')
    render(panel(base))

    fireEvent.click(screen.getByRole('button', { name: 'Screeners guardados' }))
    expect(screen.getByRole('link', { name: 'Iniciar sesión' }).getAttribute('href')).toBe('/login?next=%2F%3Frsi_min%3D40')
    expect(list).not.toHaveBeenCalled()
  })

  it('keeps the row without reason or popover while the session loads', () => {
    const list = vi.spyOn(savedScreenersApi, 'list')
    render(panel({ ...base, status: 'loading' }))

    const row = screen.getByRole('button', { name: 'Screeners guardados' })
    expect(row.getAttribute('aria-disabled')).toBe('true')
    fireEvent.click(row)
    expect(screen.queryByRole('link')).toBeNull()
    expect(screen.queryByText('Disponible al iniciar sesión')).toBeNull()
    expect(list).not.toHaveBeenCalled()
  })

  it('shows a pending account the confirm-email reason and makes no request', () => {
    const list = vi.spyOn(savedScreenersApi, 'list')
    render(panel({ ...base, user: { ...member, email_verification_pending: true } }))

    expect(screen.getByText('Confirma tu email para usarlo', { selector: 'span.flex' })).toBeTruthy()
    expect(list).not.toHaveBeenCalled()
  })

  it('becomes the real disclosure and starts its request only when signed in', async () => {
    const list = vi.spyOn(savedScreenersApi, 'list').mockResolvedValue([])
    const { container } = render(panel({ ...base, user: member }))

    expect(container.querySelector('details')).not.toBeNull()
    expect(screen.queryByText('Disponible al iniciar sesión')).toBeNull()
    await screen.findByText('0 screeners')
    expect(list).toHaveBeenCalledTimes(1)
  })

  it('flips from disabled to the real panel when the session appears', async () => {
    const list = vi.spyOn(savedScreenersApi, 'list').mockResolvedValue([])
    const view = render(panel(base))
    expect(list).not.toHaveBeenCalled()

    view.rerender(panel({ ...base, user: member }))
    await screen.findByText('0 screeners')
    expect(list).toHaveBeenCalledTimes(1)
  })
})
