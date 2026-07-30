import axios, { AxiosError } from 'axios'

export const TOKEN_KEY = 'sistemaisp_token'

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'http://127.0.0.1:8000/api',
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  return config
})

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (error.response?.status === 401) {
      localStorage.removeItem(TOKEN_KEY)
      window.dispatchEvent(new Event('auth:unauthorized'))
    }

    return Promise.reject(error)
  },
)

type ValidationResponse = {
  message?: string
  errors?: Record<string, string[]>
}

export function getApiError(error: unknown, field?: string): string {
  if (!(error instanceof AxiosError)) return 'Ocurrió un error inesperado.'

  const data = error.response?.data as ValidationResponse | undefined
  if (field && data?.errors?.[field]?.[0]) return data.errors[field][0]

  const firstError = data?.errors && Object.values(data.errors)[0]?.[0]
  return firstError ?? data?.message ?? 'No se pudo conectar con el servidor.'
}
