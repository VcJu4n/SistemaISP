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

function formatMoney(value: string | number) {
  return `Bs ${Number(value).toFixed(2)}`
}

export function DashboardPage() {
  const { user } = useAuth()
  const [summary, setSummary] = useState<BillingSummary | null>(null)

  useEffect(() => {
    const load = async () => {
      const response = await api.get<{ data: BillingSummary }>('/dashboard/billing-summary')
      setSummary(response.data.data)
    }
    void load()
  }, [])

  return (
    <section className="page-content">
      <div className="page-heading">
        <div><span className="eyebrow">Panel principal</span><h1>Bienvenido, {user?.name}</h1></div>
        <Link className="button button-primary button-fit" to="/pagos"><CreditCard size={18} /> Registrar pago</Link>
      </div>

      <div className="stats-grid">
        <article className="stat-card"><Users /><div><strong>Clientes</strong><span>Modulo disponible</span></div></article>
        <article className="stat-card"><Router /><div><strong>Routers</strong><span>Modulo disponible</span></div></article>
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
