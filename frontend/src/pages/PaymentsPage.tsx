import { AxiosError } from 'axios'
import { AlertTriangle, CreditCard, Plus, Search, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { api, getApiError } from '../lib/api'

type PaymentMethod = 'cash' | 'transfer' | 'other'
type Zone = { id: number; name: string; active: boolean }
type Plan = { id: number; name: string; monthly_price: string | null }
type InternetService = { id: number; status: 'active' | 'suspended'; next_due_date: string | null; suspension_reason: string | null; plan: Plan }
type Client = { id: number; full_name: string; document: string; phone: string; zone: Zone; internet_service?: InternetService | null; internet_service_exists?: boolean }
type Payment = { id: number; amount: string; paid_at: string; billing_period: string; payment_method: PaymentMethod; observation: string | null; duplicate_confirmed: boolean; client: Client; service: { id: number; plan: Plan }; user?: { id: number; name: string } | null }
type Meta = { current_page: number; last_page: number; per_page: number; total: number }
type ListResponse = { data: Payment[]; meta: Meta }
type DuplicateResponse = { message?: string; code?: string; data?: Payment }

const methodLabels: Record<PaymentMethod, string> = { cash: 'Efectivo', transfer: 'Transferencia', other: 'Otro' }

function formatMoney(value: string | number | null | undefined) {
  if (value === null || value === undefined || value === '') return 'Bs 0.00'
  return `Bs ${Number(value).toFixed(2)}`
}

function formatDate(value: string | null | undefined) {
  if (!value) return 'Sin fecha'
  return new Date(`${value}T00:00:00`).toLocaleDateString('es-BO')
}

export function PaymentsPage() {
  const [payments, setPayments] = useState<Payment[]>([])
  const [zones, setZones] = useState<Zone[]>([])
  const [clients, setClients] = useState<Client[]>([])
  const [meta, setMeta] = useState<Meta>({ current_page: 1, last_page: 1, per_page: 10, total: 0 })
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [clientId, setClientId] = useState('')
  const [zoneId, setZoneId] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [modal, setModal] = useState(false)
  const [message, setMessage] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => { setDebounced(search); setPage(1) }, 300)
    return () => clearTimeout(timer)
  }, [search])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [paymentResponse, zoneResponse, clientResponse] = await Promise.all([
        api.get<ListResponse>('/payments', { params: { search: debounced || undefined, client_id: clientId || undefined, zone_id: zoneId || undefined, date_from: dateFrom || undefined, date_to: dateTo || undefined, page } }),
        api.get<{ data: Zone[] }>('/zones', { params: { all: 1 } }),
        api.get<{ data: Client[] }>('/clients', { params: { all: 1 } }),
      ])
      setPayments(paymentResponse.data.data)
      setMeta(paymentResponse.data.meta)
      setZones(zoneResponse.data.data)
      setClients(clientResponse.data.data)
    } finally {
      setLoading(false)
    }
  }, [clientId, dateFrom, dateTo, debounced, page, zoneId])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  return <section className="page-content payments-page">
    <div className="page-heading">
      <div><span className="eyebrow">Cobranza</span><h1>Pagos</h1><p>{meta.total} pago{meta.total === 1 ? '' : 's'} registrado{meta.total === 1 ? '' : 's'}</p></div>
      <button className="button button-primary button-fit" onClick={() => setModal(true)}><Plus size={18} /> Registrar pago</button>
    </div>
    {message && <div className="alert alert-success dismissible">{message}<button onClick={() => setMessage('')}><X size={16} /></button></div>}

    <div className="payment-filters">
      <label className="search-box"><Search size={18} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar cliente, cedula o telefono" /></label>
      <select value={clientId} onChange={(event) => { setClientId(event.target.value); setPage(1) }}><option value="">Todos los clientes</option>{clients.map((client) => <option key={client.id} value={client.id}>{client.full_name}</option>)}</select>
      <select value={zoneId} onChange={(event) => { setZoneId(event.target.value); setPage(1) }}><option value="">Todas las zonas</option>{zones.map((zone) => <option key={zone.id} value={zone.id}>{zone.name}</option>)}</select>
      <input type="date" value={dateFrom} onChange={(event) => { setDateFrom(event.target.value); setPage(1) }} title="Desde" />
      <input type="date" value={dateTo} onChange={(event) => { setDateTo(event.target.value); setPage(1) }} title="Hasta" />
    </div>

    <div className="table-card">
      {loading ? <div className="table-message">Cargando pagos...</div> : payments.length === 0 ? <div className="table-message"><CreditCard /><strong>No se encontraron pagos</strong></div> : <div className="table-scroll"><table><thead><tr><th>Cliente</th><th>Plan</th><th>Fecha</th><th>Periodo</th><th>Monto</th><th>Metodo</th><th>Operador</th><th>Observacion</th></tr></thead><tbody>{payments.map((payment) => <tr key={payment.id}><td><div className="client-name">{payment.client.full_name}<small>{payment.client.zone.name}</small></div></td><td>{payment.service.plan.name}</td><td>{formatDate(payment.paid_at)}</td><td><span className={`status-badge ${payment.duplicate_confirmed ? 'pending' : 'active'}`}>{payment.billing_period}</span></td><td><strong>{formatMoney(payment.amount)}</strong></td><td>{methodLabels[payment.payment_method]}</td><td>{payment.user?.name ?? 'No registrado'}</td><td>{payment.observation ?? 'Sin observacion'}</td></tr>)}</tbody></table></div>}
      <footer className="pagination"><span>Pagina {meta.current_page} de {meta.last_page}</span><div><button disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Anterior</button><button disabled={page >= meta.last_page} onClick={() => setPage((value) => value + 1)}>Siguiente</button></div></footer>
    </div>

    {modal && <PaymentModal clients={clients} onClose={() => setModal(false)} onSaved={async () => { setModal(false); setMessage('Pago registrado correctamente.'); await load() }} />}
  </section>
}

function PaymentModal({ clients, onClose, onSaved }: { clients: Client[]; onClose: () => void; onSaved: () => Promise<void> }) {
  const payableClients = useMemo(() => clients.filter((client) => client.internet_service), [clients])
  const [clientId, setClientId] = useState('')
  const [amount, setAmount] = useState('')
  const [paidAt, setPaidAt] = useState(new Date().toISOString().slice(0, 10))
  const [method, setMethod] = useState<PaymentMethod>('cash')
  const [observation, setObservation] = useState('')
  const [reactivate, setReactivate] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [duplicateWarning, setDuplicateWarning] = useState('')

  const selectedClient = payableClients.find((client) => client.id === Number(clientId)) ?? null
  const service = selectedClient?.internet_service ?? null
  const canReactivate = service?.status === 'suspended' && service.suspension_reason === 'debt'

  const submit = async (event?: FormEvent, confirmDuplicate = false) => {
    event?.preventDefault()
    setSaving(true)
    setError('')
    try {
      await api.post('/payments', {
        client_id: Number(clientId),
        amount: Number(amount),
        paid_at: paidAt,
        payment_method: method,
        observation: observation || null,
        reactivate_if_suspended: canReactivate ? reactivate : false,
        confirm_duplicate: confirmDuplicate,
      })
      await onSaved()
    } catch (requestError) {
      if (requestError instanceof AxiosError && requestError.response?.status === 409) {
        const data = requestError.response.data as DuplicateResponse
        setDuplicateWarning(data.message ?? 'Ya existe un pago registrado para este mes.')
      } else {
        setError(getApiError(requestError))
      }
    } finally {
      setSaving(false)
    }
  }

  return <div className="modal-backdrop"><section className="modal-card modal-wide"><header><div><span className="eyebrow">HU-027</span><h2>Registrar pago mensual</h2></div><button className="modal-close" onClick={onClose}><X /></button></header>{error && <div className="alert alert-error">{error}</div>}{duplicateWarning && <div className="alert alert-warning duplicate-warning"><AlertTriangle size={18} /><span>{duplicateWarning}</span><button className="button button-secondary button-fit" disabled={saving} onClick={() => void submit(undefined, true)}>Registrar de todos modos</button></div>}<form className="client-form" onSubmit={(event) => void submit(event)}><label className="field full"><span>Cliente *</span><select required value={clientId} onChange={(event) => { const nextClient = payableClients.find((client) => client.id === Number(event.target.value)); setClientId(event.target.value); setAmount(nextClient?.internet_service?.plan.monthly_price ?? ''); setDuplicateWarning('') }}><option value="">Selecciona un cliente con servicio</option>{payableClients.map((client) => <option value={client.id} key={client.id}>{client.full_name} - {client.zone.name}</option>)}</select></label>{selectedClient && <div className="payment-context full"><div><span>Plan activo</span><strong>{service?.plan.name}</strong></div><div><span>Precio del plan</span><strong>{service?.plan.monthly_price ? formatMoney(service.plan.monthly_price) : 'Sin precio'}</strong></div><div><span>Vencimiento actual</span><strong>{formatDate(service?.next_due_date)}</strong></div></div>}<label className="field"><span>Monto *</span><input required type="number" min="0.01" step="0.01" value={amount} onChange={(event) => setAmount(event.target.value)} /></label><label className="field"><span>Fecha de pago *</span><input required type="date" value={paidAt} onChange={(event) => setPaidAt(event.target.value)} /></label><label className="field"><span>Metodo *</span><select required value={method} onChange={(event) => setMethod(event.target.value as PaymentMethod)}><option value="cash">Efectivo</option><option value="transfer">Transferencia</option><option value="other">Otro</option></select></label>{canReactivate && <label className="toggle-field payment-reactivate"><input type="checkbox" checked={reactivate} onChange={(event) => setReactivate(event.target.checked)} /><span>Reactivar servicio suspendido por mora al guardar</span></label>}<label className="field full"><span>Observacion</span><textarea maxLength={1000} value={observation} onChange={(event) => setObservation(event.target.value)} /></label><footer className="form-actions full"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={saving || !clientId}>{saving ? 'Guardando...' : 'Guardar pago'}</button></footer></form></section></div>
}
