import { Router, Users, Wifi } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'

export function DashboardPage() {
  const { user } = useAuth()

  return (
    <section className="page-content">
      <div className="page-heading">
        <div><span className="eyebrow">Panel principal</span><h1>Bienvenido, {user?.name}</h1></div>
        <Link className="button button-primary button-fit" to="/clientes"><Users size={18} /> Gestionar clientes</Link>
      </div>

      <div className="stats-grid">
        <article className="stat-card"><Users /><div><strong>Clientes</strong><span>Módulo disponible</span></div></article>
        <article className="stat-card"><Router /><div><strong>Routers</strong><span>Próximo módulo</span></div></article>
        <article className="stat-card"><Wifi /><div><strong>Servicios</strong><span>Módulo disponible</span></div></article>
      </div>

      <section className="empty-state">
        <Users size={34} />
        <h2>Gestión de clientes disponible</h2>
        <p>Ya puedes registrar, buscar, editar y archivar clientes.</p>
      </section>
    </section>
  )
}
