// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from './context.ts'
import type { AuthContextValue } from './context.ts'
import { useSignedIn } from './useSignedIn.ts'

const base: AuthContextValue = {
  user: null,
  status: 'ready',
  login: vi.fn(), register: vi.fn(), logout: vi.fn(),
}
const user = { id: 1, name: 'Test', email: 'test@example.com', role: 'user' as const }

function Probe() {
  return <p data-testid="signed-in">{String(useSignedIn())}</p>
}

function value(auth: AuthContextValue | null): string {
  render(
    auth === null ? <Probe /> : <AuthContext.Provider value={auth}><Probe /></AuthContext.Provider>,
  )
  return screen.getByTestId('signed-in').textContent ?? ''
}

afterEach(() => cleanup())

describe('useSignedIn', () => {
  it('is false without an AuthProvider and does not throw', () => {
    expect(value(null)).toBe('false')
  })

  it('is false while auth is loading', () => {
    expect(value({ ...base, status: 'loading' })).toBe('false')
  })

  it('is false for a resolved Visitor', () => {
    expect(value({ ...base, status: 'ready', user: null })).toBe('false')
  })

  it('is true for a resolved signed-in user (Registered User or Admin)', () => {
    expect(value({ ...base, status: 'ready', user })).toBe('true')
    cleanup()
    expect(value({ ...base, status: 'ready', user: { ...user, role: 'admin' } })).toBe('true')
  })
})
