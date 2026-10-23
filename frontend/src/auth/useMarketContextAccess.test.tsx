// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { resetLegalInfoCache } from '../legal/useLegalInfo.ts'
import { legalApi } from '../lib/api.ts'
import type { AuthUser, LegalInfo } from '../lib/api.ts'
import { AuthContext } from './context.ts'
import type { AuthContextValue } from './context.ts'
import { useMarketContextAccess } from './useMarketContextAccess.ts'

vi.mock('../lib/api.ts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../lib/api.ts')>()
  return { ...actual, legalApi: { info: vi.fn() } }
})

const LEGAL: LegalInfo = {
  privacy_published: true,
  notice_published: false,
  owner: null,
  updated_at: '2026-10-23',
  retention: { sign_in_activity_days: 90, session_minutes: 120, backup_days: 14, server_log_days: 14, notification_days: 90 },
}

const USER: AuthUser = { id: 1, name: 'Ana', email: 'ana@example.test', role: 'user' }

function Probe() {
  const access = useMarketContextAccess()
  return <p data-testid="access">{`${access.status}|${access.preview ? 'preview' : 'live'}`}</p>
}

function show(auth: Partial<AuthContextValue> | null) {
  const value: AuthContextValue | null = auth === null
    ? null
    : { user: null, status: 'ready', login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn(), ...auth }
  return render(
    <AuthContext.Provider value={value}>
      <Probe />
    </AuthContext.Provider>,
  )
}

const access = () => screen.getByTestId('access').textContent

function legal(flag: boolean | undefined | 'pending' | 'error') {
  if (flag === 'pending') {
    vi.mocked(legalApi.info).mockReturnValue(new Promise(() => {}))
  } else if (flag === 'error') {
    vi.mocked(legalApi.info).mockRejectedValue(new Error('down'))
  } else {
    vi.mocked(legalApi.info).mockResolvedValue(flag === undefined ? LEGAL : { ...LEGAL, market_context: flag })
  }
}

beforeEach(() => resetLegalInfoCache())
afterEach(() => {
  cleanup()
  vi.clearAllMocks()
})

describe('useMarketContextAccess (market-context-patterns access matrix)', () => {
  it('is loading while the session bootstraps', () => {
    legal(true)
    show({ status: 'loading' })
    expect(access()).toBe('loading|live')
  })

  it('a user with the capability is allowed; a preview only while the flag is off', async () => {
    legal(true)
    show({ user: { ...USER, capabilities: { market_context: true } } })
    await waitFor(() => expect(legalApi.info).toHaveBeenCalled())
    await waitFor(() => expect(access()).toBe('allowed|live'))
    cleanup()

    resetLegalInfoCache()
    legal(false)
    show({ user: { ...USER, role: 'admin', capabilities: { market_context: true } } })
    await waitFor(() => expect(access()).toBe('allowed|preview'))
  })

  it('an allowed user is not a preview before the legal info is known', () => {
    legal('pending')
    show({ user: { ...USER, capabilities: { market_context: true } } })
    expect(access()).toBe('allowed|live')
  })

  it('a user without the capability, or with it absent, is hidden (never by role)', async () => {
    legal(true)
    show({ user: { ...USER, capabilities: { market_context: false } } })
    await waitFor(() => expect(legalApi.info).toHaveBeenCalled())
    expect(access()).toBe('hidden|live')
    cleanup()

    show({ user: { ...USER, role: 'admin' } })
    expect(access()).toBe('hidden|live')
  })

  it('a Visitor gets the sign-in prompt only while the flag is on', async () => {
    legal(true)
    show({ user: null })
    await waitFor(() => expect(access()).toBe('signIn|live'))
    cleanup()

    resetLegalInfoCache()
    legal(false)
    show({ user: null })
    await waitFor(() => expect(access()).toBe('hidden|live'))
    cleanup()

    resetLegalInfoCache()
    legal(undefined)
    show({ user: null })
    await waitFor(() => expect(access()).toBe('hidden|live'))
  })

  it('a Visitor waits for the legal info, and a failed one hides everything', async () => {
    legal('pending')
    show({ user: null })
    expect(access()).toBe('loading|live')
    cleanup()

    resetLegalInfoCache()
    legal('error')
    show({ user: null })
    await waitFor(() => expect(access()).toBe('hidden|live'))
  })

  it('outside an AuthProvider it behaves as a Visitor and never throws', async () => {
    legal(true)
    show(null)
    await waitFor(() => expect(access()).toBe('signIn|live'))
  })
})
