import { Archive, CalendarDays, Clock, Eye, Pencil, Plus, Search, Users, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { api, getApiError } from '../lib/api'

type ClientStatus = 'active' | 'suspended'
type ServiceStatus = 'active' | 'suspended'
type PaymentMethod = 'cash' | 'transfer' | 'other'

type Zone = { id: number; name: string; active: boolean }
type Plan = { id: number; name: string; monthly_price: string | null; download_mbps?: number; upload_mbps?: number; active?: boolean }
type ServiceHistory = { id: number; event_type: string; description: string; metadata: Record<string, unknown> | null; occurred_at: string; user?: { id: number; name: string } | null }
type InternetService = { id: number; client_id: number; plan_id: number; status: ServiceStatus; next_due_date: string | null; suspended_at: string | null; suspension_reason: string | null; plan: Plan; histories?: ServiceHistory[] }
type Client = {
  id: number
  full_name: string
  document: string
  phone: string
  email: string | null
  address: string | null
  zone_id: number
  zone: Zone
  installation_date: string | null
  status: ClientStatus
  internet_service?: InternetService | null
  internet_service_exists?: boolean
}
type Payment = { id: number; amount: string; paid_at: string; billing_period: string; payment_method: PaymentMethod; observation: string | null; user?: { id: number; name: string } | null }
type ClientForm = { full_name: string; document: string; phone: string; email: string; address: string; zone_id: string; installation_date: string }
type ListResponse = { data: Client[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }
type PaymentResponse = { data: Payment[]; meta: { current_page: number; last_page: number; per_page: number; total: number } }

const emptyForm: ClientForm = { full_name: '', document: '', phone: '', email: '', address: '', zone_id: '', installation_date: '' }
const methodLabels: Record<PaymentMethod, string> = { cash: 'Efectivo', transfer: 'Transferencia', other: 'Otro' }

function toForm(client: Client): ClientForm {
  return {
    full_name: client.full_name,
    document: client.document,
    phone: client.phone,
    email: client.email ?? '',
    address: client.address ?? '',
    zone_id: String(client.zone_id),
    installation_date: client.installation_date ?? '',
  }
}

function formatDate(value: string | null | undefined) {
  if (!value) return 'Sin fecha'
  return new Date(`${value}T00:00:00`).toLocaleDateString('es-BO')
}

function formatDateTime(value: string) {
  return new Date(value).toLocaleString('es-BO')
}

function formatMoney(value: string | number | null | undefined) {
  if (value === null || value === undefined || value === '') return 'Bs 0.00'
  return `Bs ${Number(value).toFixed(2)}`
}

export function ClientsPage() {
  const [clients, setClients] = useState<Client[]>([])
  const [meta, setMeta] = useState<ListResponse['meta']>({ current_page: 1, last_page: 1, per_page: 10, total: 0 })
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [status, setStatus] = useState('')
  const [zone, setZone] = useState('')
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState('')
  const [formClient, setFormClient] = useState<Client | 'new' | null>(null)
  const [detailClient, setDetailClient] = useState<Client | null>(null)
  const [archiveClient, setArchiveClient] = useState<Client | null>(null)
  const [zones, setZones] = useState<Zone[]>([])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebouncedSearch(search)
      setPage(1)
    }, 300)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    const loadZones = async () => {
      const response = await api.get<{ data: Zone[] }>('/zones', { params: { all: 1 } })
      setZones(response.data.data)
    }
    void loadZones()
  }, [])

  const loadClients = useCallback(async () => {
    setLoading(true)
    try {
      const response = await api.get<ListResponse>('/clients', {
        params: { search: debouncedSearch || undefined, status: status || undefined, zone_id: zone || undefined, page },
      })
      setClients(response.data.data)
      setMeta(response.data.meta)
    } finally {
      setLoading(false)
    }
  }, [debouncedSearch, page, status, zone])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void loadClients()
  }, [loadClients])

  const activeZones = useMemo(() => zones.filter((item) => item.active), [zones])

  const openDetail = async (client: Client) => {
    const response = await api.get<{ data: Client }>(`/clients/${client.id}`)
    setDetailClient(response.data.data)
  }

  const archived = async () => {
    if (!archiveClient) return
    await api.delete(`/clients/${archiveClient.id}`)
    setArchiveClient(null)
    setMessage('Cliente archivado correctamente.')
    await loadClients()
  }

  return (
    <section className="page-content clients-page">
      <div className="page-heading">
        <div><span className="eyebrow">Administracion</span><h1>Clientes</h1><p>{meta.total} cliente{meta.total === 1 ? '' : 's'} registrado{meta.total === 1 ? '' : 's'}</p></div>
        <button className="button button-primary button-fit" onClick={() => setFormClient('new')}><Plus size={18} /> Registrar cliente</button>
      </div>

      {message && <div className="alert alert-success dismissible">{message}<button onClick={() => setMessage('')}><X size={16} /></button></div>}

      <div className="client-filters">
        <label className="search-box"><Search size={18} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar por nombre, cedula, telefono o correo" /></label>
        <select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }}><option value="">Todos los estados</option><option value="active">Activos</option><option value="suspended">Suspendidos</option></select>
        <select value={zone} onChange={(event) => { setZone(event.target.value); setPage(1) }}><option value="">Todas las zonas</option>{zones.map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select>
      </div>

      <div className="table-card">
        {loading ? <div className="table-message">Cargando clientes...</div> : clients.length === 0 ? (
          <div className="table-message"><Users size={30} /><strong>No se encontraron clientes con ese criterio</strong></div>
        ) : (
          <div className="table-scroll"><table><thead><tr><th>Cliente</th><th>Cedula/RUC</th><th>Telefono</th><th>Zona</th><th>Vencimiento</th><th>Estado</th><th aria-label="Acciones" /></tr></thead><tbody>
            {clients.map((client) => <tr key={client.id}>
              <td><button className="client-name" onClick={() => void openDetail(client)}>{client.full_name}<small>ID #{client.id}</small></button></td>
              <td>{client.document}</td><td>{client.phone}</td><td>{client.zone.name}</td>
              <td><DueDateBadge value={client.internet_service?.next_due_date ?? null} /></td>
              <td><span className={`status-badge ${client.status}`}>{client.status === 'active' ? 'Activo' : 'Suspendido'}</span></td>
              <td><div className="row-actions"><button title="Ver detalle" onClick={() => void openDetail(client)}><Eye size={17} /></button><button title="Editar" onClick={() => setFormClient(client)}><Pencil size={17} /></button><button disabled={client.internet_service_exists} className="danger" title={client.internet_service_exists ? 'No se puede archivar: tiene un servicio asignado' : 'Archivar'} onClick={() => setArchiveClient(client)}><Archive size={17} /></button></div></td>
            </tr>)}
          </tbody></table></div>
        )}
        <footer className="pagination"><span>Pagina {meta.current_page} de {meta.last_page}</span><div><button disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Anterior</button><button disabled={page >= meta.last_page} onClick={() => setPage((value) => value + 1)}>Siguiente</button></div></footer>
      </div>

      {formClient && <ClientFormModal zones={activeZones} client={formClient === 'new' ? null : formClient} onClose={() => setFormClient(null)} onSaved={async (text) => { setFormClient(null); setMessage(text); await loadClients() }} />}
      {detailClient && <ClientDetailModal client={detailClient} onClose={() => setDetailClient(null)} onEdit={(client) => { setFormClient(client); setDetailClient(null) }} onChanged={async () => { await loadClients() }} />}
      {archiveClient && <ConfirmArchiveModal client={archiveClient} onClose={() => setArchiveClient(null)} onConfirm={() => void archived()} />}
    </section>
  )
}

function DueDateBadge({ value }: { value: string | null }) {
  if (!value) return <span className="muted-cell">Sin servicio</span>
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const due = new Date(`${value}T00:00:00`)
  const days = Math.round((due.getTime() - today.getTime()) / 86400000)
  const className = days < 0 ? 'failed' : days <= 5 ? 'pending' : 'active'
  const label = days < 0 ? `${Math.abs(days)} dia${Math.abs(days) === 1 ? '' : 's'} vencido` : days === 0 ? 'Vence hoy' : `${days} dia${days === 1 ? '' : 's'}`
  return <div className="due-cell"><span className={`status-badge ${className}`}>{label}</span><small>{formatDate(value)}</small></div>
}

function ClientFormModal({ client, zones, onClose, onSaved }: { client: Client | null; zones: Zone[]; onClose: () => void; onSaved: (message: string) => Promise<void> }) {
  const [form, setForm] = useState<ClientForm>(client ? toForm(client) : emptyForm)
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const set = (field: keyof ClientForm, value: string) => setForm((current) => ({ ...current, [field]: value }))

  const submit = async (event: FormEvent) => {
    event.preventDefault(); setSaving(true); setError('')
    try {
      const payload = { ...form, zone_id: Number(form.zone_id), email: form.email || null, address: form.address || null, installation_date: form.installation_date || null }
      if (client) await api.put(`/clients/${client.id}`, payload)
      else await api.post('/clients', payload)
      await onSaved(client ? 'Cliente actualizado correctamente.' : 'Cliente registrado correctamente.')
    } catch (requestError) { setError(getApiError(requestError)) } finally { setSaving(false) }
  }

  return <div className="modal-backdrop" role="presentation"><section className="modal-card modal-wide" role="dialog" aria-modal="true" aria-labelledby="client-form-title">
    <header><div><span className="eyebrow">{client ? `Cliente #${client.id}` : 'Nuevo registro'}</span><h2 id="client-form-title">{client ? 'Editar cliente' : 'Registrar cliente'}</h2></div><button className="modal-close" onClick={onClose}><X /></button></header>
    {error && <div className="alert alert-error">{error}</div>}
    <form className="client-form" onSubmit={submit}>
      <label className="field"><span>Nombre completo *</span><input required maxLength={150} value={form.full_name} onChange={(e) => set('full_name', e.target.value)} /></label>
      <label className="field"><span>Cedula/RUC *</span><input required maxLength={30} value={form.document} onChange={(e) => set('document', e.target.value)} /></label>
      <label className="field"><span>Telefono *</span><input required maxLength={30} value={form.phone} onChange={(e) => set('phone', e.target.value)} /></label>
      <label className="field"><span>Correo electronico</span><input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} /></label>
      <label className="field full"><span>Direccion</span><textarea maxLength={500} value={form.address} onChange={(e) => set('address', e.target.value)} /></label>
      <label className="field"><span>Zona de cobertura *</span><select required value={form.zone_id} onChange={(e) => set('zone_id', e.target.value)}><option value="">Selecciona una zona</option>{zones.map((zone) => <option value={zone.id} key={zone.id}>{zone.name}</option>)}</select></label>
      <label className="field"><span>Fecha de instalacion</span><input type="date" value={form.installation_date} onChange={(e) => set('installation_date', e.target.value)} /></label>
      <footer className="form-actions full"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={saving}>{saving ? 'Guardando...' : client ? 'Actualizar' : 'Guardar cliente'}</button></footer>
    </form>
  </section></div>
}

function ClientDetailModal({ client, onClose, onEdit, onChanged }: { client: Client; onClose: () => void; onEdit: (client: Client) => void; onChanged: () => Promise<void> }) {
  const [current, setCurrent] = useState(client)
  const [tab, setTab] = useState<'info' | 'payments' | 'history'>('info')

  const refresh = async () => {
    const response = await api.get<{ data: Client }>(`/clients/${current.id}`)
    setCurrent(response.data.data)
    await onChanged()
  }

  return <div className="modal-backdrop"><section className="modal-card modal-wide" role="dialog" aria-modal="true"><header><div><span className="eyebrow">Cliente #{current.id}</span><h2>{current.full_name}</h2></div><button className="modal-close" onClick={onClose}><X /></button></header><div className="modal-tabs"><button className={tab === 'info' ? 'active' : ''} onClick={() => setTab('info')}>Info</button><button className={tab === 'payments' ? 'active' : ''} onClick={() => setTab('payments')}>Pagos</button><button className={tab === 'history' ? 'active' : ''} onClick={() => setTab('history')}>Historial</button></div>{tab === 'info' && <ClientInfoTab client={current} onEdit={() => onEdit(current)} onDueUpdated={() => void refresh()} onClose={onClose} />}{tab === 'payments' && <ClientPaymentsTab client={current} />}{tab === 'history' && <ClientHistoryTab client={current} />}</section></div>
}

function ClientInfoTab({ client, onClose, onEdit, onDueUpdated }: { client: Client; onClose: () => void; onEdit: () => void; onDueUpdated: () => void }) {
  const fields = [['Cedula/RUC', client.document], ['Telefono', client.phone], ['Correo', client.email || 'No registrado'], ['Direccion', client.address || 'No registrada'], ['Zona', client.zone.name], ['Instalacion', formatDate(client.installation_date)]]
  return <>
    <div className="detail-header-row"><span className={`status-badge ${client.status}`}>{client.status === 'active' ? 'Activo' : 'Suspendido'}</span>{client.internet_service && <DueDateBadge value={client.internet_service.next_due_date} />}</div>
    <dl className="detail-list">{fields.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl>
    {client.internet_service ? <DueDateEditor service={client.internet_service} onUpdated={onDueUpdated} /> : <div className="alert alert-error">Este cliente aun no tiene servicio asignado.</div>}
    <footer className="form-actions"><button className="button button-secondary" onClick={onClose}>Cerrar</button><button className="button button-primary button-fit" onClick={onEdit}><Pencil size={17} /> Editar</button></footer>
  </>
}

function DueDateEditor({ service, onUpdated }: { service: InternetService; onUpdated: () => void }) {
  const [editing, setEditing] = useState(false)
  const [nextDueDate, setNextDueDate] = useState(service.next_due_date ?? '')
  const [reason, setReason] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      await api.put(`/services/${service.id}/due-date`, { next_due_date: nextDueDate, reason })
      setEditing(false)
      setReason('')
      onUpdated()
    } catch (requestError) {
      setError(getApiError(requestError))
    } finally {
      setSaving(false)
    }
  }

  return <section className="nested-panel due-editor"><header><div><h3>Vencimiento</h3><p>Actual: <strong>{formatDate(service.next_due_date)}</strong></p></div><button className="button button-secondary button-fit" onClick={() => { setNextDueDate(service.next_due_date ?? ''); setEditing((value) => !value) }}><CalendarDays size={16} /> Cambiar</button></header>{editing && <form className="client-form" onSubmit={submit}>{error && <div className="alert alert-error full">{error}</div>}<label className="field"><span>Nueva fecha *</span><input required type="date" value={nextDueDate} onChange={(event) => setNextDueDate(event.target.value)} /></label><label className="field full"><span>Motivo *</span><textarea required maxLength={1000} value={reason} onChange={(event) => setReason(event.target.value)} /></label><footer className="form-actions full"><button type="button" className="button button-secondary" onClick={() => setEditing(false)}>Cancelar</button><button className="button button-primary button-fit" disabled={saving}>{saving ? 'Guardando...' : 'Guardar vencimiento'}</button></footer></form>}</section>
}

function ClientPaymentsTab({ client }: { client: Client }) {
  const [payments, setPayments] = useState<Payment[]>([])
  const [meta, setMeta] = useState<PaymentResponse['meta']>({ current_page: 1, last_page: 1, per_page: 10, total: 0 })
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const response = await api.get<PaymentResponse>(`/clients/${client.id}/payments`, { params: { date_from: dateFrom || undefined, date_to: dateTo || undefined, page } })
      setPayments(response.data.data)
      setMeta(response.data.meta)
    } finally {
      setLoading(false)
    }
  }, [client.id, dateFrom, dateTo, page])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  return <section className="modal-section"><div className="inline-filters"><label className="field"><span>Desde</span><input type="date" value={dateFrom} onChange={(event) => { setDateFrom(event.target.value); setPage(1) }} /></label><label className="field"><span>Hasta</span><input type="date" value={dateTo} onChange={(event) => { setDateTo(event.target.value); setPage(1) }} /></label></div>{loading ? <div className="table-message compact">Cargando pagos...</div> : payments.length ? <div className="payment-list">{payments.map((payment) => <article key={payment.id}><div><strong>{formatMoney(payment.amount)}</strong><span>{formatDate(payment.paid_at)} - {methodLabels[payment.payment_method]}</span><small>Operador: {payment.user?.name ?? 'No registrado'}{payment.observation ? ` - ${payment.observation}` : ''}</small></div><span className="status-badge active">{payment.billing_period}</span></article>)}</div> : <div className="table-message compact"><Clock /><strong>Sin pagos registrados</strong></div>}<footer className="pagination compact-pagination"><span>{meta.total} pagos</span><div><button disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Anterior</button><button disabled={page >= meta.last_page} onClick={() => setPage((value) => value + 1)}>Siguiente</button></div></footer></section>
}

function ClientHistoryTab({ client }: { client: Client }) {
  const histories = client.internet_service?.histories ?? []
  return <section className="modal-section"><div className="timeline">{histories.length ? histories.map((history) => <article key={history.id}><i /><div><strong>{history.description}</strong><time>{formatDateTime(history.occurred_at)} - {history.user?.name ?? 'Sistema'}</time></div></article>) : <p>Sin eventos registrados.</p>}</div></section>
}

function ConfirmArchiveModal({ client, onClose, onConfirm }: { client: Client; onClose: () => void; onConfirm: () => void }) {
  return <div className="modal-backdrop"><section className="modal-card confirm-card" role="alertdialog" aria-modal="true"><div className="danger-icon"><Archive /></div><h2>Archivar a {client.full_name}</h2><p>El cliente desaparecera del listado principal, pero sus datos podran recuperarse desde la base de datos.</p><footer className="form-actions"><button className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-danger" onClick={onConfirm}>Archivar</button></footer></section></div>
}
