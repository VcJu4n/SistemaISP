import { AlertTriangle, CalendarDays, CreditCard, Router, Users, Wifi } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
import { api } from '../lib/api'

type BillingSummary = {
  active_services: number
  overdue_services: number
  due_today_services: number
  due_next_5_days_services: number
  payments_this_month: number
  collected_this_month: string
}

type MikrotikRouter = { id: number; name: string; connection_status: 'pending' | 'connected' | 'disconnected'; active: boolean }

function formatMoney(value: string | number) {
  return `Bs ${Number(value).toFixed(2)}`
}

export function DashboardPage() {
  const { user } = useAuth()
  const [summary, setSummary] = useState<BillingSummary | null>(null)
  const [routers, setRouters] = useState<MikrotikRouter[]>([])
  const [routerId, setRouterId] = useState('')

  useEffect(() => {
    const load = async () => {
      const response = await api.get<{ data: MikrotikRouter[] }>('/mikrotik-routers', { params: { all: 1, active: 1 } })
      setRouters(response.data.data)
      if (response.data.data.length) setRouterId(String(response.data.data[0].id))
    }
    void load()
  }, [])

  useEffect(() => {
    if (!routerId) return
    const load = async () => {
      const response = await api.get<{ data: BillingSummary }>('/dashboard/billing-summary', { params: { mikrotik_router_id: routerId } })
      setSummary(response.data.data)
    }
    void load()
  }, [routerId])

  const selectedRouter = routers.find((router) => String(router.id) === routerId)

  return (
    <section className="page-content">
      <div className="page-heading">
        <div><span className="eyebrow">Panel principal</span><h1>Bienvenido, {user?.name}</h1></div>
        <Link className="button button-primary button-fit" to="/pagos"><CreditCard size={18} /> Registrar pago</Link>
      </div>

      <div className="client-filters">
        <label className="field"><span>MikroTik de trabajo</span><select value={routerId} onChange={(event) => setRouterId(event.target.value)}><option value="">Selecciona un MikroTik</option>{routers.map((router) => <option value={router.id} key={router.id}>{router.name}</option>)}</select></label>
        {selectedRouter && <span className={`status-badge ${selectedRouter.connection_status === 'connected' ? 'active' : 'failed'}`}>{selectedRouter.connection_status === 'connected' ? 'Conectado' : selectedRouter.connection_status === 'pending' ? 'Pendiente' : 'Desconectado'}</span>}
      </div>

      <div className="stats-grid">
        <article className="stat-card"><Users /><div><strong>{summary?.active_services ?? '-'}</strong><span>Clientes activos en {selectedRouter?.name ?? 'el MikroTik seleccionado'}</span></div></article>
        <article className="stat-card"><Router /><div><strong>{selectedRouter?.name ?? '-'}</strong><span>MikroTik actual</span></div></article>
        <article className="stat-card"><Wifi /><div><strong>{summary?.active_services ?? '-'}</strong><span>Servicios activos</span></div></article>
      </div>

      <div className="stats-grid billing-grid">
        <article className="stat-card"><AlertTriangle /><div><strong>{summary?.overdue_services ?? '-'}</strong><span>Servicios vencidos</span></div></article>
        <article className="stat-card"><CalendarDays /><div><strong>{summary?.due_next_5_days_services ?? '-'}</strong><span>Vencen en 5 dias</span></div></article>
        <article className="stat-card"><CreditCard /><div><strong>{summary ? formatMoney(summary.collected_this_month) : '-'}</strong><span>Cobrado este mes</span></div></article>
      </div>
    </section>
  )
}
