// @vitest-environment jsdom
import { act, cleanup, render, screen } from '@testing-library/react'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { AuthUser } from '../lib/api.ts'
import { AuthContext } from './context.ts'
import type { AuthContextValue } from './context.ts'
import { useRegisteredOnly } from './useRegisteredOnly.ts'

const base: AuthContextValue = {
  user: null,
  status: 'ready',
  login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn(),
}
const member: AuthUser = { id: 1, name: 'Test', email: 'test@example.com', role: 'user' }

function Probe() {
  return <p data-testid="state">{useRegisteredOnly().state}</p>
}

function state(auth: AuthContextValue | null): string {
  render(auth === null ? <Probe /> : <AuthContext.Provider value={auth}><Probe /></AuthContext.Provider>)
  return screen.getByTestId('state').textContent ?? ''
}

afterEach(() => cleanup())

describe('useRegisteredOnly', () => {
  it('is guest without an AuthProvider and does not throw', () => {
    expect(state(null)).toBe('guest')
  })

  it('is loading while the session bootstraps', () => {
    expect(state({ ...base, status: 'loading' })).toBe('loading')
  })

  it('is guest for a resolved Visitor', () => {
    expect(state(base)).toBe('guest')
  })

  it('is pending for an account that has to confirm its email', () => {
    expect(state({ ...base, user: { ...member, email_verification_pending: true } })).toBe('pending')
  })

  it('is enabled for a signed-in user', () => {
    expect(state({ ...base, user: member })).toBe('enabled')
  })

  it('goes from enabled to guest on logout without remounting', () => {
    let signOut: () => void = () => {}
    function Shell({ children }: { children: ReactNode }) {
      const [user, setUser] = useState<AuthUser | null>(member)
      signOut = () => setUser(null)
      return <AuthContext.Provider value={{ ...base, user }}>{children}</AuthContext.Provider>
    }
    render(<Shell><Probe /></Shell>)
    expect(screen.getByTestId('state').textContent).toBe('enabled')

    act(() => signOut())

    expect(screen.getByTestId('state').textContent).toBe('guest')
  })
})
