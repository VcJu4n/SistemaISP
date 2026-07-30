import { createContext } from 'react'

export type User = {
  id: number
  name: string
  email: string
}

export type LoginCredentials = {
  email: string
  password: string
}

export type AuthContextValue = {
  user: User | null
  loading: boolean
  login: (credentials: LoginCredentials) => Promise<void>
  logout: () => Promise<void>
  clearSession: () => void
}

export const AuthContext = createContext<AuthContextValue | undefined>(undefined)
