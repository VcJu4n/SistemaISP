import { zodResolver } from '@hookform/resolvers/zod'
import { Eye, EyeOff, Router, Wifi } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useLocation } from 'react-router-dom'
import { z } from 'zod'
import { useAuth } from '../auth/useAuth'
import { getApiError } from '../lib/api'

const schema = z.object({
  email: z.email('Ingresa un correo válido.'),
  password: z.string().min(1, 'Ingresa tu contraseña.'),
})

type LoginForm = z.infer<typeof schema>

export function LoginPage() {
  const { login } = useAuth()
  const location = useLocation()
  const successMessage = (location.state as { message?: string } | null)?.message
  const [showPassword, setShowPassword] = useState(false)
  const [serverError, setServerError] = useState('')
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginForm>({ resolver: zodResolver(schema) })

  const submit = async (data: LoginForm) => {
    setServerError('')
    try {
      await login(data)
    } catch (error) {
      setServerError(getApiError(error, 'email'))
    }
  }

  return (
    <main className="auth-layout">
      <section className="auth-brand" aria-label="SistemaISP">
        <div className="brand-mark"><Wifi size={28} /></div>
        <div>
          <span className="eyebrow">Administración de red</span>
          <h1>SistemaISP</h1>
          <p>Control simple y centralizado para tu proveedor de Internet.</p>
        </div>
        <div className="brand-status"><Router size={20} /> Preparado para MikroTik</div>
      </section>

      <section className="auth-panel">
        <form className="auth-card" onSubmit={handleSubmit(submit)} noValidate>
          <header>
            <span className="eyebrow">Acceso seguro</span>
            <h2>Iniciar sesión</h2>
            <p>Ingresa con la cuenta del administrador.</p>
          </header>

          {serverError && <div className="alert alert-error">{serverError}</div>}
          {successMessage && <div className="alert alert-success">{successMessage}</div>}

          <label className="field">
            <span>Correo electrónico</span>
            <input type="email" autoComplete="username" {...register('email')} />
            {errors.email && <small>{errors.email.message}</small>}
          </label>

          <label className="field">
            <span>Contraseña</span>
            <div className="password-input">
              <input
                type={showPassword ? 'text' : 'password'}
                autoComplete="current-password"
                {...register('password')}
              />
              <button type="button" onClick={() => setShowPassword((value) => !value)} aria-label="Mostrar u ocultar contraseña">
                {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
              </button>
            </div>
            {errors.password && <small>{errors.password.message}</small>}
          </label>

          <button className="button button-primary" disabled={isSubmitting}>
            {isSubmitting ? 'Ingresando…' : 'Ingresar al sistema'}
          </button>
        </form>
      </section>
    </main>
  )
}
