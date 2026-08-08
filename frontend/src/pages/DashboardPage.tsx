import { AlertTriangle, CalendarCheck2, CalendarClock, CreditCard, ReceiptText, Router, Settings2, Users } from 'lucide-react'
import { useEffect, useState, type ReactNode } from 'react'
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
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

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
      setLoading(true)
      setError('')
      try {
        const response = await api.get<{ data: BillingSummary }>('/dashboard/billing-summary', { params: { mikrotik_router_id: routerId } })
        setSummary(response.data.data)
      } catch {
        setError('No se pudo cargar el resumen de este MikroTik.')
      } finally {
        setLoading(false)
      }
    }
    void load()
  }, [routerId])

  const selectedRouter = routers.find((router) => String(router.id) === routerId)

  return (
    <section className="page-content dashboard-page">
      <div className="page-heading dashboard-heading">
        <div><span className="eyebrow">Panel principal</span><h1>Bienvenido, {user?.name}</h1><p>Resumen operativo de clientes, vencimientos y cobros.</p></div>
        <Link className="button button-primary button-fit" to="/pagos"><CreditCard size={18} /> Registrar pago</Link>
      </div>

      {routers.length ? <>
        <section className="router-context-card">
          <div className="router-context-icon"><Router /></div>
          <div className="router-context-copy"><span className="eyebrow">MikroTik de trabajo</span><strong>{selectedRouter?.name ?? 'Selecciona un router'}</strong><small>Las cifras corresponden únicamente a este equipo.</small></div>
          <label className="router-selector"><span>Seleccionar router</span><select value={routerId} onChange={(event) => setRouterId(event.target.value)}>{routers.map((router) => <option value={router.id} key={router.id}>{router.name}</option>)}</select></label>
          {selectedRouter && <span className={`router-status ${selectedRouter.connection_status}`}><i />{selectedRouter.connection_status === 'connected' ? 'Conectado' : selectedRouter.connection_status === 'pending' ? 'Pendiente' : 'Desconectado'}</span>}
          <Link className="router-settings" to="/mikrotik" title="Administrar MikroTik"><Settings2 size={18} /></Link>
        </section>

        {error && <div className="alert alert-error">{error}</div>}
        <div className={`dashboard-metrics ${loading ? 'is-loading' : ''}`}>
          <MetricCard icon={<Users />} value={summary?.active_services ?? '-'} label="Clientes activos" detail={selectedRouter?.name} tone="green" />
          <MetricCard icon={<AlertTriangle />} value={summary?.overdue_services ?? '-'} label="Servicios vencidos" detail="Requieren seguimiento" tone="red" />
          <MetricCard icon={<CalendarCheck2 />} value={summary?.due_today_services ?? '-'} label="Vencen hoy" detail="Cobros del día" tone="amber" />
          <MetricCard icon={<CalendarClock />} value={summary?.due_next_5_days_services ?? '-'} label="Próximos vencimientos" detail="Dentro de 5 días" tone="blue" />
          <MetricCard icon={<CreditCard />} value={summary ? formatMoney(summary.collected_this_month) : '-'} label="Cobrado este mes" detail="Total confirmado" tone="green" />
          <MetricCard icon={<ReceiptText />} value={summary?.payments_this_month ?? '-'} label="Pagos registrados" detail="Durante este mes" tone="blue" />
        </div>
      </> : <div className="empty-state dashboard-empty"><Router size={34} /><h2>No hay MikroTik activos</h2><p>Registra o activa un router para consultar su resumen operativo.</p><Link className="button button-primary button-fit" to="/mikrotik">Configurar MikroTik</Link></div>}
    </section>
  )
}

function MetricCard({ icon, value, label, detail, tone }: { icon: ReactNode; value: string | number; label: string; detail?: string; tone: 'green' | 'red' | 'amber' | 'blue' }) {
  return <article className={`metric-card metric-${tone}`}><div className="metric-icon">{icon}</div><div className="metric-copy"><span>{label}</span><strong>{value}</strong><small>{detail}</small></div></article>
}
