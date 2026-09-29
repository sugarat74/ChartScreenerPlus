import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, Navigate, useNavigate } from 'react-router'
import { useAuth } from '../auth/useAuth.ts'
import AuthField from '../components/AuthField.tsx'
import { ApiError } from '../lib/api.ts'
import type { ValidationErrors } from '../lib/api.ts'
import { DEFAULT_ROUTE, REGISTER_ROUTE } from '../nav.ts'

export default function LoginPage() {
  const { user, login } = useAuth()
  const navigate = useNavigate()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [errors, setErrors] = useState<ValidationErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  if (user) {
    return <Navigate to={DEFAULT_ROUTE} replace />
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setSubmitting(true)
    setErrors({})
    setFormError(null)

    try {
      await login(email, password)
      navigate(DEFAULT_ROUTE, { replace: true })
    } catch (error) {
      if (error instanceof ApiError) {
        setErrors(error.errors)
        if (Object.keys(error.errors).length === 0) {
          setFormError(error.message)
        }
      } else {
        setFormError('No se pudo contactar al servidor. Inténtalo de nuevo.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <section className="mx-auto flex w-full max-w-md flex-col gap-5">
      <div>
        <span className="w-fit rounded-[4px] border border-outline bg-surface-container px-2 py-0.5 font-mono text-[10px] font-bold uppercase tracking-wider text-on-surface shadow-[1px_1px_0px_#1a1a1a]">
          Acceso
        </span>
        <h2 className="mt-3 font-headline text-3xl font-black tracking-tight uppercase text-on-surface">
          Iniciar sesión
        </h2>
        <p className="mt-2 text-sm text-on-surface-variant">
          Entra para guardar tus Screeners y Watchlists.
        </p>
      </div>

      <form
        onSubmit={handleSubmit}
        noValidate
        className="flex flex-col gap-4 rounded-md border-2 border-outline bg-surface-bright p-6 shadow-[2px_2px_0px_#1a1a1a]"
      >
        {formError ? (
          <p
            role="alert"
            className="rounded-[4px] border-2 border-secondary bg-secondary-container px-3 py-2 font-mono text-xs text-on-secondary-container"
          >
            {formError}
          </p>
        ) : null}

        <AuthField
          id="email"
          label="Email"
          type="email"
          autoComplete="email"
          value={email}
          onChange={setEmail}
          error={errors.email?.[0]}
        />
        <AuthField
          id="password"
          label="Contraseña"
          type="password"
          autoComplete="current-password"
          value={password}
          onChange={setPassword}
          error={errors.password?.[0]}
        />

        <button
          type="submit"
          disabled={submitting}
          className="rounded-md border-2 border-outline bg-primary-container px-4 py-2.5 font-headline text-sm font-bold uppercase tracking-wider text-on-primary-container shadow-[2px_2px_0px_#1a1a1a] transition-transform hover:-translate-y-px disabled:cursor-not-allowed disabled:opacity-70"
        >
          {submitting ? 'Entrando…' : 'Entrar'}
        </button>
      </form>

      <p className="text-sm text-on-surface-variant">
        ¿No tienes cuenta?{' '}
        <Link to={REGISTER_ROUTE} className="font-bold text-on-surface underline">
          Crear cuenta
        </Link>
      </p>
    </section>
  )
}
