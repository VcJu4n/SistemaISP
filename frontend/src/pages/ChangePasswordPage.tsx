import { zodResolver } from '@hookform/resolvers/zod'
import { ArrowLeft, KeyRound } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate } from 'react-router-dom'
import { z } from 'zod'
import { useAuth } from '../auth/useAuth'
import { api, getApiError } from '../lib/api'

const schema = z.object({
  current_password: z.string().min(1, 'Ingresa tu contraseña actual.'),
  password: z.string().min(8, 'Debe tener al menos 8 caracteres.').regex(/[A-Z]/, 'Debe incluir una letra mayúscula.').regex(/\d/, 'Debe incluir un número.'),
  password_confirmation: z.string(),
}).refine((data) => data.password === data.password_confirmation, {
  message: 'Las contraseñas no coinciden.',
  path: ['password_confirmation'],
}).refine((data) => data.password !== data.current_password, {
  message: 'La nueva contraseña debe ser diferente.',
  path: ['password'],
})

type PasswordForm = z.infer<typeof schema>

export function ChangePasswordPage() {
  const { clearSession } = useAuth()
  const navigate = useNavigate()
  const [serverError, setServerError] = useState('')
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<PasswordForm>({ resolver: zodResolver(schema) })

  const submit = async (data: PasswordForm) => {
    setServerError('')
    try {
      await api.put('/auth/password', data)
      clearSession()
      navigate('/login', {
        replace: true,
        state: { message: 'Contraseña actualizada. Inicia sesión nuevamente.' },
      })
    } catch (error) {
      setServerError(getApiError(error))
    }
  }

  return (
    <main className="form-page">
      <section className="form-card">
        <Link className="back-link" to="/"><ArrowLeft size={17} /> Volver al panel</Link>
        <div className="form-icon"><KeyRound /></div>
        <h1>Cambiar contraseña</h1>
        <p>Al guardar, se cerrarán todas las sesiones abiertas.</p>

        {serverError && <div className="alert alert-error">{serverError}</div>}

        <form onSubmit={handleSubmit(submit)} noValidate>
          <label className="field"><span>Contraseña actual</span><input type="password" autoComplete="current-password" {...register('current_password')} />{errors.current_password && <small>{errors.current_password.message}</small>}</label>
          <label className="field"><span>Nueva contraseña</span><input type="password" autoComplete="new-password" {...register('password')} />{errors.password && <small>{errors.password.message}</small>}</label>
          <label className="field"><span>Confirmar contraseña</span><input type="password" autoComplete="new-password" {...register('password_confirmation')} />{errors.password_confirmation && <small>{errors.password_confirmation.message}</small>}</label>
          <button className="button button-primary" disabled={isSubmitting}>{isSubmitting ? 'Guardando…' : 'Actualizar contraseña'}</button>
        </form>
      </section>
    </main>
  )
}
