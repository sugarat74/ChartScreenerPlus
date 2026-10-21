// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import ThemeSelector from '../components/ThemeSelector.tsx'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import { THEME_STORAGE_KEY } from './theme.ts'
import { ThemeProvider } from './ThemeProvider.tsx'

/** A controllable `prefers-color-scheme: dark` media query. */
function mockSystemScheme(dark: boolean) {
  const listeners = new Set<() => void>()
  const query = {
    matches: dark,
    addEventListener: (_: string, listener: () => void) => listeners.add(listener),
    removeEventListener: (_: string, listener: () => void) => listeners.delete(listener),
  }
  vi.stubGlobal('matchMedia', vi.fn(() => query))
  return {
    change(next: boolean) {
      query.matches = next
      act(() => listeners.forEach((listener) => listener()))
    },
  }
}

function show() {
  render(
    <ThemeProvider>
      <I18nProvider>
        <ThemeSelector />
      </I18nProvider>
    </ThemeProvider>,
  )
}

const theme = () => document.documentElement.dataset.theme
const pressed = (name: string) => screen.getByRole('button', { name }).getAttribute('aria-pressed')

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => {
  cleanup()
  window.localStorage.clear()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  delete document.documentElement.dataset.theme
})

it('follows the operating system until the user chooses, also when it changes', () => {
  const system = mockSystemScheme(true)
  show()

  expect(theme()).toBe('dark')
  expect(pressed('Tema oscuro')).toBe('true')
  expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBeNull()

  system.change(false)
  expect(theme()).toBe('light')
  expect(pressed('Tema claro')).toBe('true')
})

it('choosing a theme applies it at once, stores it and then ignores the operating system', () => {
  const system = mockSystemScheme(false)
  show()
  expect(screen.getByRole('group', { name: 'Cambiar tema de color' })).toBeTruthy()

  fireEvent.click(screen.getByRole('button', { name: 'Tema oscuro' }))

  expect(theme()).toBe('dark')
  expect(pressed('Tema oscuro')).toBe('true')
  expect(pressed('Tema claro')).toBe('false')
  expect(window.localStorage.getItem(THEME_STORAGE_KEY)).toBe('dark')

  system.change(false)
  expect(theme()).toBe('dark')
})

it('a stored choice wins over the operating system', () => {
  mockSystemScheme(true)
  window.localStorage.setItem(THEME_STORAGE_KEY, 'light')
  show()

  expect(theme()).toBe('light')
})

it('an invalid stored value falls back to the operating system', () => {
  mockSystemScheme(true)
  window.localStorage.setItem(THEME_STORAGE_KEY, 'sepia')
  show()

  expect(theme()).toBe('dark')
})

it('blocked storage still lets the user switch the theme for this visit', () => {
  mockSystemScheme(false)
  vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
    throw new Error('blocked')
  })
  vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
    throw new Error('blocked')
  })
  show()
  expect(theme()).toBe('light')

  fireEvent.click(screen.getByRole('button', { name: 'Dark theme' }))

  expect(theme()).toBe('dark')
})
