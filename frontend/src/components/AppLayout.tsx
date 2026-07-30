import { KeyRound, Layers3, LogOut, RadioTower, Users, Wifi } from 'lucide-react'
import { NavLink, Outlet } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'

export function AppLayout() {
  const { user, logout } = useAuth()

  return (
    <main className="app-shell">
      <header className="topbar">
        <NavLink className="logo" to="/"><Wifi size={22} /> SistemaISP</NavLink>
        <nav className="main-nav" aria-label="Navegación principal">
          <NavLink to="/clientes"><Users size={17} /> Clientes</NavLink>
          <NavLink to="/catalogos"><Layers3 size={17} /> Planes y zonas</NavLink>
          <NavLink to="/servicios"><RadioTower size={17} /> Servicios</NavLink>
          <NavLink to="/cambiar-contrasena"><KeyRound size={17} /> Contraseña</NavLink>
        </nav>
        <div className="topbar-user">
          <span>{user?.name}</span>
          <button className="icon-button" onClick={() => void logout()} title="Cerrar sesión"><LogOut size={19} /></button>
        </div>
      </header>
      <Outlet />
    </main>
  )
}
