import { Navigate, Route, Routes } from 'react-router-dom'
import { ProtectedRoute } from './components/ProtectedRoute'
import { AppLayout } from './components/AppLayout'
import { ChangePasswordPage } from './pages/ChangePasswordPage'
import { DashboardPage } from './pages/DashboardPage'
import { LoginPage } from './pages/LoginPage'
import { ClientsPage } from './pages/ClientsPage'
import { CatalogsPage } from './pages/CatalogsPage'
import { MikrotikRoutersPage } from './pages/MikrotikRoutersPage'
import { ServicesPage } from './pages/ServicesPage'
import { useAuth } from './auth/useAuth'

function App() {
  const { user } = useAuth()

  return (
    <Routes>
      <Route
        path="/login"
        element={user ? <Navigate to="/" replace /> : <LoginPage />}
      />
      <Route element={<ProtectedRoute />}>
        <Route element={<AppLayout />}>
          <Route path="/" element={<DashboardPage />} />
          <Route path="/clientes" element={<ClientsPage />} />
          <Route path="/catalogos" element={<CatalogsPage />} />
          <Route path="/servicios" element={<ServicesPage />} />
          <Route path="/mikrotik" element={<MikrotikRoutersPage />} />
        </Route>
        <Route path="/cambiar-contrasena" element={<ChangePasswordPage />} />
      </Route>
      <Route path="*" element={<Navigate to={user ? '/' : '/login'} replace />} />
    </Routes>
  )
}

export default App
