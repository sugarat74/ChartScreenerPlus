// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react'
import { useState } from 'react'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { AuthContext } from '../../auth/context.ts'
import type { AuthContextValue } from '../../auth/context.ts'
import { I18nProvider } from '../../i18n/I18nProvider.tsx'
import { LOCALE_STORAGE_KEY } from '../../i18n/locales.ts'
import type { AuthUser } from '../../lib/api.ts'
import RegisteredOnly from './RegisteredOnly.tsx'
import RegisteredOnlyPanel from './RegisteredOnlyPanel.tsx'

const base: AuthContextValue = {
  user: null,
  status: 'ready',
  login: vi.fn(), register: vi.fn(), logout: vi.fn(), setCurrentUser: vi.fn(),
}
const member: AuthUser = { id: 1, name: 'Test', email: 'test@example.com', role: 'user' }

function wrap(auth: AuthContextValue | null, ui: ReactNode, path = '/instruments/NVDA?x=1#ai-company-card') {
  const tree = (
    <I18nProvider>
      <MemoryRouter initialEntries={[path]}>{ui}</MemoryRouter>
    </I18nProvider>
  )
  return auth === null ? tree : <AuthContext.Provider value={auth}>{tree}</AuthContext.Provider>
}

beforeEach(() => window.localStorage.setItem(LOCALE_STORAGE_KEY, 'es'))
afterEach(() => { cleanup(); window.localStorage.clear() })

describe('RegisteredOnly (inline)', () => {
  it('dims a Visitor control without the native disabled attribute and describes it', () => {
    render(wrap(base, <RegisteredOnly><button type="button">Seguir</button></RegisteredOnly>))

    const button = screen.getByRole('button', { name: 'Seguir' })
    expect(button.getAttribute('aria-disabled')).toBe('true')
    expect(button.hasAttribute('disabled')).toBe(false)
    expect(button.tabIndex).toBe(0)
    expect(button.className).toContain('opacity-60')
    const reason = document.getElementById(button.getAttribute('aria-describedby') ?? '')
    expect(reason?.textContent).toBe('Disponible al iniciar sesión')
    expect(reason?.className).toContain('sr-only')
  })

  it('treats a render without an AuthProvider as a Visitor', () => {
    render(wrap(null, <RegisteredOnly><button type="button">Seguir</button></RegisteredOnly>))

    expect(screen.getByRole('button', { name: 'Seguir' }).getAttribute('aria-disabled')).toBe('true')
  })

  it('swallows click, Enter, Space and submit, and opens a popover with sign-in and create-account links', () => {
    const onClick = vi.fn()
    const onSubmit = vi.fn()
    render(wrap(base,
      <form onSubmit={onSubmit}>
        <RegisteredOnly><button type="submit" onClick={onClick}>Generar</button></RegisteredOnly>
      </form>,
    ))
    const button = screen.getByRole('button', { name: 'Generar' })

    fireEvent.click(button)
    expect(onClick).not.toHaveBeenCalled()
    expect(onSubmit).not.toHaveBeenCalled()
    const popover = screen.getByRole('status')
    const signIn = screen.getByRole('link', { name: 'Iniciar sesión' })
    expect(signIn.getAttribute('href')).toBe('/login?next=%2Finstruments%2FNVDA%3Fx%3D1%23ai-company-card')
    expect(screen.getByRole('link', { name: 'Crear cuenta' }).getAttribute('href')).toBe('/register?next=%2Finstruments%2FNVDA%3Fx%3D1%23ai-company-card')
    expect(popover.textContent).toContain('Disponible al iniciar sesión')

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.queryByRole('status')).toBeNull()
    expect(document.activeElement).toBe(button)

    for (const key of ['Enter', ' ']) {
      const proceeded = fireEvent.keyDown(button, { key })
      expect(proceeded).toBe(false)
      expect(screen.getByRole('status')).toBeTruthy()
      fireEvent.keyDown(document, { key: 'Escape' })
    }
    fireEvent.keyUp(button, { key: ' ' })
    expect(onClick).not.toHaveBeenCalled()
    expect(onSubmit).not.toHaveBeenCalled()
  })

  it('closes the popover on an outside press and when focus leaves it', () => {
    render(wrap(base, <div><RegisteredOnly><button type="button">Seguir</button></RegisteredOnly><button type="button">Otro</button></div>))

    fireEvent.click(screen.getByRole('button', { name: 'Seguir' }))
    expect(screen.getByRole('status')).toBeTruthy()
    fireEvent.pointerDown(screen.getByRole('button', { name: 'Otro' }))
    expect(screen.queryByRole('status')).toBeNull()

    fireEvent.click(screen.getByRole('button', { name: 'Seguir' }))
    fireEvent.pointerDown(screen.getByRole('status'))
    expect(screen.getByRole('status')).toBeTruthy()
    fireEvent.blur(screen.getByRole('link', { name: 'Iniciar sesión' }), { relatedTarget: screen.getByRole('button', { name: 'Otro' }) })
    expect(screen.queryByRole('status')).toBeNull()
  })

  it('renders a pending account with the confirm-email reason and an Account link', () => {
    render(wrap({ ...base, user: { ...member, email_verification_pending: true } }, <RegisteredOnly><button type="button">Seguir</button></RegisteredOnly>))
    const button = screen.getByRole('button', { name: 'Seguir' })

    expect(document.getElementById(button.getAttribute('aria-describedby') ?? '')?.textContent).toBe('Confirma tu email para usarlo')
    fireEvent.click(button)
    expect(screen.getByRole('link', { name: 'Ir a mi cuenta' }).getAttribute('href')).toBe('/portal/cuenta')
    expect(screen.queryByRole('link', { name: 'Iniciar sesión' })).toBeNull()
  })

  it('renders the child untouched, with no wrapper, when enabled', () => {
    const onClick = vi.fn()
    const { container } = render(wrap({ ...base, user: member }, <RegisteredOnly><button type="button" onClick={onClick} className="x">Seguir</button></RegisteredOnly>))

    const button = screen.getByRole('button', { name: 'Seguir' })
    expect(container.querySelectorAll('*').length).toBe(1)
    expect(container.firstElementChild).toBe(button)
    expect(button.hasAttribute('aria-disabled')).toBe(false)
    expect(button.className).toBe('x')
    fireEvent.click(button)
    expect(onClick).toHaveBeenCalledTimes(1)
  })

  it('loading: dimmed, empty reason, no sign-in link and no popover', () => {
    render(wrap({ ...base, status: 'loading' }, <RegisteredOnly><button type="button">Seguir</button></RegisteredOnly>))
    const button = screen.getByRole('button', { name: 'Seguir' })

    expect(button.getAttribute('aria-disabled')).toBe('true')
    expect(button.getAttribute('aria-busy')).toBe('true')
    expect(button.getAttribute('aria-describedby')).toBeNull()
    fireEvent.click(button)
    expect(screen.queryByRole('status')).toBeNull()
    expect(screen.queryByRole('link')).toBeNull()
  })

  it('tabbable false takes the control out of the tab order but keeps it clickable', () => {
    render(wrap(base, <RegisteredOnly tabbable={false}><button type="button">Ficha</button></RegisteredOnly>))
    const button = screen.getByRole('button', { name: 'Ficha' })

    expect(button.tabIndex).toBe(-1)
    fireEvent.click(button)
    expect(screen.getByRole('status')).toBeTruthy()
  })

  it('inline variant shows no popover and a custom reason overrides the default', () => {
    render(wrap(base, <RegisteredOnly variant="inline" reason="registeredOnly.reasonUnreleased"><button type="button">Seguir</button></RegisteredOnly>))
    const button = screen.getByRole('button', { name: 'Seguir' })

    expect(document.getElementById(button.getAttribute('aria-describedby') ?? '')?.textContent).toBe('Aún no disponible')
    fireEvent.click(button)
    expect(screen.queryByRole('status')).toBeNull()
  })

  it('turns a link into a focusable, destination-less disabled anchor', () => {
    render(wrap(base, <RegisteredOnly><a href="/instruments/NVDA#ai-company-card" className="ficha">Ficha IA</a></RegisteredOnly>))
    const link = screen.getByRole('link', { name: 'Ficha IA' })

    expect(link.getAttribute('href')).toBeNull()
    expect(link.tabIndex).toBe(0)
    expect(link.getAttribute('aria-disabled')).toBe('true')
    expect(link.className).toContain('ficha')
  })

  it('the Market Context access seam maps signIn, loading, hidden and allowed', () => {
    const child = <button type="button">Mercado</button>
    const auth = { ...base, user: member }
    const { rerender } = render(wrap(auth, <RegisteredOnly access={{ status: 'signIn', preview: false }}>{child}</RegisteredOnly>))
    expect(screen.getByRole('button', { name: 'Mercado' }).getAttribute('aria-disabled')).toBe('true')

    rerender(wrap(auth, <RegisteredOnly access={{ status: 'loading', preview: false }}>{child}</RegisteredOnly>))
    expect(screen.getByRole('button', { name: 'Mercado' }).getAttribute('aria-busy')).toBe('true')

    rerender(wrap(auth, <RegisteredOnly access={{ status: 'hidden', preview: false }}>{child}</RegisteredOnly>))
    expect(screen.queryByRole('button', { name: 'Mercado' })).toBeNull()

    rerender(wrap(auth, <RegisteredOnly access={{ status: 'allowed', preview: false }}>{child}</RegisteredOnly>))
    expect(screen.getByRole('button', { name: 'Mercado' }).hasAttribute('aria-disabled')).toBe(false)
  })

  it('blocks editing of an input, a textarea, a select and a checkbox while keeping them focusable', () => {
    const onChange = vi.fn()
    render(wrap(base,
      <div>
        <RegisteredOnly><input aria-label="texto" defaultValue="" onChange={onChange} /></RegisteredOnly>
        <RegisteredOnly><textarea aria-label="area" defaultValue="" onChange={onChange} /></RegisteredOnly>
        <RegisteredOnly><select aria-label="plazo" defaultValue="1" onChange={onChange}><option value="1">1</option><option value="2">2</option></select></RegisteredOnly>
        <RegisteredOnly><input type="checkbox" aria-label="marca" onChange={onChange} /></RegisteredOnly>
      </div>,
    ))
    const text = screen.getByLabelText('texto') as HTMLInputElement
    const area = screen.getByLabelText('area') as HTMLTextAreaElement
    const select = screen.getByLabelText('plazo') as HTMLSelectElement
    const box = screen.getByLabelText('marca') as HTMLInputElement

    for (const element of [text, area, select, box]) {
      expect(element.getAttribute('aria-disabled')).toBe('true')
      expect(element.disabled).toBe(false)
      expect(element.tabIndex).toBe(0)
    }
    expect(text.readOnly).toBe(true)
    expect(area.readOnly).toBe(true)
    // Typing and arrow keys are cancelled.
    expect(fireEvent.keyDown(text, { key: 'a' })).toBe(false)
    expect(fireEvent.keyDown(select, { key: 'ArrowDown' })).toBe(false)
    expect(fireEvent.keyDown(area, { key: 'b' })).toBe(false)
    expect(fireEvent.keyDown(text, { key: 'Tab' })).toBe(true)
    fireEvent.paste(text)
    fireEvent.input(text, { target: { value: 'x' } })
    // A click on the checkbox is cancelled, so it does not toggle, and a mousedown on the select does not open it.
    fireEvent.click(box)
    expect(box.checked).toBe(false)
    expect(fireEvent.mouseDown(select)).toBe(false)
    expect(onChange).not.toHaveBeenCalled()
    expect(select.value).toBe('1')
  })

  it('closes the popover when Tab leaves it and focus ends outside', () => {
    vi.useFakeTimers()
    try {
      render(wrap(base, <div><RegisteredOnly><button type="button">Seguir</button></RegisteredOnly></div>))
      fireEvent.click(screen.getByRole('button', { name: 'Seguir' }))
      const link = screen.getByRole('link', { name: 'Crear cuenta' })
      link.focus()
      fireEvent.keyDown(link, { key: 'Tab' })
      link.blur()
      act(() => { vi.runAllTimers() })
      expect(screen.queryByRole('status')).toBeNull()
    } finally {
      vi.useRealTimers()
    }
  })

  it('flips to enabled on login and back on logout without remounting the surrounding page', () => {
    let setUser: (user: AuthUser | null) => void = () => {}
    const mounts = vi.fn()
    function Page() {
      useState(() => { mounts(); return true })
      return <RegisteredOnly><button type="button">Seguir</button></RegisteredOnly>
    }
    function Shell() {
      const [user, set] = useState<AuthUser | null>(null)
      setUser = set
      return <AuthContext.Provider value={{ ...base, user }}><Page /></AuthContext.Provider>
    }
    render(wrap(null, <Shell />))
    expect(screen.getByRole('button', { name: 'Seguir' }).getAttribute('aria-disabled')).toBe('true')

    act(() => setUser(member))
    expect(screen.getByRole('button', { name: 'Seguir' }).hasAttribute('aria-disabled')).toBe(false)

    act(() => setUser(null))
    expect(screen.getByRole('button', { name: 'Seguir' }).getAttribute('aria-disabled')).toBe('true')
    expect(mounts).toHaveBeenCalledTimes(1)
  })
})

describe('RegisteredOnlyPanel', () => {
  function panel() {
    return (
      <RegisteredOnlyPanel>
        {({ disabled }) => (
          <form>
            <input aria-label="Texto" disabled={disabled} />
            <button type="submit">Enviar</button>
          </form>
        )}
      </RegisteredOnlyPanel>
    )
  }

  it('a Visitor gets a disabled fieldset, a visible reason row and the sign-in link outside the fieldset', () => {
    const { container } = render(wrap(base, panel()))

    expect(container.querySelector('fieldset')?.disabled).toBe(true)
    expect((screen.getByRole('textbox', { name: 'Texto' }) as HTMLInputElement).disabled).toBe(true)
    expect(screen.getByText('Disponible al iniciar sesión')).toBeTruthy()
    expect(container.querySelector('[aria-disabled="true"]')).toBeTruthy()
    expect(screen.getAllByRole('link')[0].getAttribute('href')).toContain('/login?next=')
    expect(container.querySelector('fieldset')?.querySelector('a')).toBeNull()
  })

  it('keeps the same box in every state: the reason slot is reserved when loading, guest and enabled', () => {
    const states: [string, AuthContextValue][] = [
      ['guest', base],
      ['loading', { ...base, status: 'loading' }],
      ['enabled', { ...base, user: member }],
    ]
    const shapes = states.map(([, auth]) => {
      const { container, unmount } = render(wrap(auth, panel()))
      const outer = container.firstElementChild as HTMLElement
      const shape = [...outer.children].map((child) => `${child.tagName}.${child.className.split(' ').filter((c) => c.startsWith('min-h') || c === 'flex' || c === 'gap-2').join('.')}`)
      const slot = screen.getByTestId('registered-only-reason')
      expect(slot.className).toContain('min-h-5')
      unmount()
      return shape.join('|')
    })

    expect(new Set(shapes).size).toBe(1)
  })

  it('aria-disabled is on the dimmed fieldset only, not on the wrapper that holds the sign-in links', () => {
    const { container } = render(wrap(base, panel()))
    const row = screen.getByTestId('registered-only-reason')

    expect(container.querySelector('fieldset')?.getAttribute('aria-disabled')).toBe('true')
    expect(container.firstElementChild?.hasAttribute('aria-disabled')).toBe(false)
    expect(row.closest('[aria-disabled="true"]')).toBeNull()
  })

  it('loading keeps the shell with an empty reason row and no link', () => {
    render(wrap({ ...base, status: 'loading' }, panel()))

    expect(screen.getByTestId('registered-only-reason').textContent).toBe('')
    expect(screen.queryByRole('link')).toBeNull()
    expect((screen.getByRole('textbox', { name: 'Texto' }) as HTMLInputElement).disabled).toBe(true)
  })

  it('enabling keeps the same field element, drops the reason row, and logout disables it again', () => {
    let setUser: (user: AuthUser | null) => void = () => {}
    function Shell() {
      const [user, set] = useState<AuthUser | null>(null)
      setUser = set
      return <AuthContext.Provider value={{ ...base, user }}>{panel()}</AuthContext.Provider>
    }
    render(wrap(null, <Shell />))
    const field = screen.getByRole('textbox', { name: 'Texto' }) as HTMLInputElement
    expect(field.disabled).toBe(true)

    act(() => setUser(member))

    expect(screen.getByRole('textbox', { name: 'Texto' })).toBe(field)
    expect(field.disabled).toBe(false)
    expect(screen.getByTestId('registered-only-reason').textContent).toBe('')

    act(() => setUser(null))
    expect(field.disabled).toBe(true)
  })

  it('a pending account sees the confirm-email reason with the Account link', () => {
    render(wrap({ ...base, user: { ...member, email_verification_pending: true } }, panel()))

    expect(screen.getByText('Confirma tu email para usarlo')).toBeTruthy()
    expect(screen.getByRole('link', { name: 'Ir a mi cuenta' })).toBeTruthy()
  })

  it('access hidden renders nothing', () => {
    const { container } = render(wrap(base, <RegisteredOnlyPanel access={{ status: 'hidden', preview: false }}>{() => <p>x</p>}</RegisteredOnlyPanel>))

    expect(container.textContent).toBe('')
  })
})
