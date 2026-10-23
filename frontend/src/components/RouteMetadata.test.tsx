// @vitest-environment jsdom
import { cleanup, render } from '@testing-library/react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, expect, it } from 'vitest'
import { I18nProvider } from '../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../i18n/locales.ts'
import RouteMetadata from './RouteMetadata.tsx'

const HOME_TITLE = 'Chartiko | Screener de análisis técnico de acciones'

function show(path: string) {
  render(
    <I18nProvider>
      <MemoryRouter initialEntries={[path]}>
        <RouteMetadata />
      </MemoryRouter>
    </I18nProvider>,
  )
}

const robots = () => document.querySelector<HTMLMetaElement>('meta[name="robots"]')?.content
const canonical = () => document.querySelector<HTMLLinkElement>('link[rel="canonical"]')

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => {
  cleanup()
  window.localStorage.clear()
  document.head.innerHTML = ''
})

it('the bare home is titled, indexed and canonical', () => {
  show('/')

  expect(document.title).toBe(HOME_TITLE)
  expect(robots()).toBe('index, follow')
  expect(canonical()?.href).toBe('https://www.chartiko.com/')
})

it('the home with Screener filters keeps the home title but is not indexed', () => {
  show('/?rsi_max=30&price_above_sma200=1')

  expect(document.title).toBe(HOME_TITLE)
  expect(robots()).toBe('noindex, follow')
  expect(canonical()).toBeNull()
})

it('an unknown path is still titled as not found', () => {
  show('/nope')

  expect(document.title).toBe('Página no encontrada | Chartiko')
})

it('the Market page is titled and not indexed', () => {
  show('/mercado')

  expect(document.title).toBe('Contexto de mercado | Chartiko')
  expect(robots()).toBe('noindex, follow')
  expect(canonical()).toBeNull()
})

it('the email confirmation page is titled by prefix and not indexed', () => {
  show(`/verificar-correo/7/${'a'.repeat(40)}`)

  expect(document.title).toBe('Confirmar email | Chartiko')
  expect(robots()).toBe('noindex, follow')
  expect(canonical()).toBeNull()
})
