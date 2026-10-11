// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import { RouterProvider, createMemoryRouter, useLocation } from 'react-router'
import { afterEach, describe, expect, it } from 'vitest'
import LegacyScreenerRedirect from './LegacyScreenerRedirect.tsx'

function CurrentLocation() {
  const { pathname, search, hash } = useLocation()
  return <output>{`${pathname}${search}${hash}`}</output>
}

afterEach(cleanup)

describe('legacy Screener links', () => {
  it('opens the homepage with filters and the hash intact, replacing the old history entry', async () => {
    const router = createMemoryRouter(
      [
        { path: '/', element: <CurrentLocation /> },
        { path: '/screener', element: <LegacyScreenerRedirect /> },
      ],
      { initialEntries: ['/screener?signal=golden_cross&sort=rsi_desc#results'] },
    )

    render(<RouterProvider router={router} />)
    await waitFor(() => {
      expect(screen.getByText('/?signal=golden_cross&sort=rsi_desc#results')).toBeTruthy()
    })
    expect(router.state.location.pathname).toBe('/')
    await router.navigate(-1)
    expect(router.state.location.pathname).toBe('/')
  })
})
