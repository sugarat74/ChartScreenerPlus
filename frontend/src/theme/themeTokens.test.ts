/// <reference types="node" />
import { readFileSync } from 'node:fs'
import { expect, it } from 'vitest'

// Raw sources, read through Vite's glob import.
const SOURCES = import.meta.glob<string>(
  ['/src/**/*.{ts,tsx}', '!/src/**/*.test.{ts,tsx}', '/index.html'],
  { query: '?raw', import: 'default', eager: true },
)
// Vitest does not load .css contents, so the stylesheet is read from disk.
const CSS = readFileSync(new URL('../index.css', import.meta.url), 'utf8')

// A literal ink color in a class name would stay dark in the dark theme
// (ui-dark-mode): hard shadows use var(--color-shadow) and accents a token.
const THEME_BLIND_CLASS = /(?:shadow|accent|bg|text|border|stroke|fill)-\[[^\]]*#1a1a1a[^\]]*\]/

it('no class name hardcodes the light ink color', () => {
  const offenders = Object.entries(SOURCES)
    .filter(([, source]) => THEME_BLIND_CLASS.test(source))
    .map(([path]) => path)

  expect(Object.keys(SOURCES)).toContain('/index.html')
  expect(offenders).toEqual([])
})

it('the dark theme redefines every light color token except the brand yellow', () => {
  const dark = CSS.match(/:root\[data-theme='dark'\]\s*\{([^}]*)\}/)?.[1] ?? ''
  const light = CSS.match(/@theme\s*\{([^}]*)\}/)?.[1] ?? ''
  const tokens = (block: string) => [...block.matchAll(/(--color-[a-z-]+):/g)].map((match) => match[1])

  const shared = ['--color-primary-container', '--color-on-primary-container']
  const missing = tokens(light).filter((token) => !shared.includes(token) && !tokens(dark).includes(token))

  expect(tokens(light).length).toBeGreaterThan(20)
  expect(missing).toEqual([])
  expect(tokens(dark)).toContain('--color-shadow')
})
