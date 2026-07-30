import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { api, TOKEN_KEY } from '../lib/api'
import {
  AuthContext,
  type LoginCredentials,
  type User,
} from './auth-context'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)

  const clearSession = useCallback(() => {
    localStorage.removeItem(TOKEN_KEY)
    setUser(null)
  }, [])

  useEffect(() => {
    const restoreSession = async () => {
      if (!localStorage.getItem(TOKEN_KEY)) {
        setLoading(false)
        return
      }

      try {
        const response = await api.get<{ data: User }>('/auth/me')
        setUser(response.data.data)
      } catch {
        clearSession()
      } finally {
        setLoading(false)
      }
    }

    void restoreSession()
  }, [clearSession])

  useEffect(() => {
    window.addEventListener('auth:unauthorized', clearSession)
    return () => window.removeEventListener('auth:unauthorized', clearSession)
  }, [clearSession])

  const login = async (credentials: LoginCredentials) => {
    const response = await api.post<{ data: { user: User; token: string } }>(
      '/auth/login',
      { ...credentials, device_name: 'sistemaisp-web' },
    )

    localStorage.setItem(TOKEN_KEY, response.data.data.token)
    setUser(response.data.data.user)
  }

  const logout = async () => {
    try {
      await api.post('/auth/logout')
    } finally {
      clearSession()
    }
  }

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, clearSession }}>
      {children}
    </AuthContext.Provider>
  )
}
