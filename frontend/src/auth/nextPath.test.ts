import { describe, expect, it } from 'vitest'
import { loginHref, nextPath, registerHref } from './nextPath.ts'

describe('nextPath', () => {
  it.each([
    '/portal',
    '/instruments/NVDA#ai-company-card',
    '/?q=1&sector=tech',
    '/mercado',
    '/portal/cuenta?tab=a#b',
  ])('keeps the relative path %s', (value) => {
    expect(nextPath(value)).toBe(value)
  })

  it.each([
    ['//evil.com', 'protocol-relative host'],
    ['///evil.com', 'triple slash'],
    ['https://x.example', 'https scheme'],
    ['http://x.example/a', 'http scheme'],
    ['javascript:alert(1)', 'javascript scheme'],
    ['data:text/html,x', 'data scheme'],
    ['/\\evil.com', 'backslash host'],
    ['\\evil.com', 'backslash start'],
    ['portal', 'no leading slash'],
    ['', 'empty'],
    ['/login', 'login'],
    ['/login?next=/x', 'login with query'],
    ['/LOGIN', 'login upper case'],
    ['/login/', 'login trailing slash'],
    ['/register#x', 'register'],
    ['/olvide-contrasena', 'forgot password'],
    ['/restablecer-contrasena?token=a', 'reset password'],
    ['/a\nb', 'control character'],
    ['/a\tb', 'tab'],
  ])('falls back to home for %s (%s)', (value) => {
    expect(nextPath(value)).toBe('/')
  })

  it('falls back to home for absent values', () => {
    expect(nextPath(null)).toBe('/')
    expect(nextPath(undefined)).toBe('/')
  })

  it('caps the length of a return path', () => {
    const ok = `/${'a'.repeat(2047)}`
    expect(nextPath(ok)).toBe(ok)
    expect(nextPath(`${ok}a`)).toBe('/')
  })

  it('does not reject paths that merely start like an auth route', () => {
    expect(nextPath('/login-help')).toBe('/login-help')
  })
})

describe('login and register links', () => {
  it('encodes the return path', () => {
    expect(loginHref('/instruments/NVDA#ai-company-card')).toBe('/login?next=%2Finstruments%2FNVDA%23ai-company-card')
    expect(registerHref('/?q=1')).toBe('/register?next=%2F%3Fq%3D1')
  })

  it('drops an unsafe or home return path', () => {
    expect(loginHref('//evil.com')).toBe('/login')
    expect(loginHref('/')).toBe('/login')
    expect(registerHref(null)).toBe('/register')
  })
})
