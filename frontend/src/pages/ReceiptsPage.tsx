import { CreditCard, Eye, FileText, Mail, Printer, ReceiptText, RefreshCw, Save, Search, Send, Settings, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { api, getApiError } from '../lib/api'

type Zone = { id: number; name: string }
type Client = { id: number; full_name: string; document: string; phone: string; email: string | null; zone: Zone }
type Plan = { id: number; name: string; monthly_price: string | null; download_mbps: number; upload_mbps: number }
type Service = {
  id: number
  status: 'active' | 'suspended'
  billing_day: number
  cutoff_day: number
  grace_days: number
  billing_amount: string | null
  billing_enabled: boolean
  client: Client
  plan: Plan
}
type EmailDelivery = { id: number; recipient_email: string; status: 'pending' | 'sent' | 'failed'; error_message: string | null; sent_at: string | null; created_at: string }
type Receipt = {
  id: number
  receipt_number: string
  billing_charge_id?: number | null
  internet_service_id: number
  client_name: string
  client_document: string | null
  client_phone: string | null
  client_email: string | null
  plan_name: string | null
  payment_date: string
  cutoff_date: string | null
  billing_period: string | null
  concept: string
  observations: string | null
  currency: string
  subtotal: string
  iva_rate: string
  retention_rate: string
  iva: string
  retention: string
  total_received: string
  amount_words: string
  created_at: string
  latest_email_delivery?: EmailDelivery | null
}
type BillingCharge = {
  id: number
  internet_service_id: number
  service_id: number
  client_id: number
  client_name: string
  client_document: string | null
  client_phone: string | null
  client_email: string | null
  zone_name: string | null
  plan_name: string | null
  status: 'paid' | 'partial' | 'grace' | 'overdue' | 'pending' | 'cancelled'
  charge_status: string
  label: string
  period_label: string
  due_date: string
  cutoff_date: string
  grace_deadline: string
  concept: string
  currency: string
  original_amount: number
  paid_amount: number
  balance: number
  receipts?: Receipt[]
}
type Meta = { current_page: number; last_page: number; total: number }
type ReceiptSummary = { count: number; total_received: number }
type BillingSummary = {
  paid: number
  partial: number
  grace: number
  overdue: number
  pending: number
  cancelled: number
  paid_total: number
  partial_total: number
  grace_total: number
  overdue_total: number
  pending_total: number
  cancelled_total: number
  total_charged: number
  total_paid: number
  total_balance: number
}
type ReceiptForm = {
  billing_charge_id: string
  payment_date: string
  observations: string
  send_email: boolean
  email_to: string
}

const emptyMeta: Meta = { current_page: 1, last_page: 1, total: 0 }
const emptyReceiptSummary: ReceiptSummary = { count: 0, total_received: 0 }
const emptySummary: BillingSummary = { paid: 0, partial: 0, grace: 0, overdue: 0, pending: 0, cancelled: 0, paid_total: 0, partial_total: 0, grace_total: 0, overdue_total: 0, pending_total: 0, cancelled_total: 0, total_charged: 0, total_paid: 0, total_balance: 0 }
const todayIso = () => new Date().toISOString().slice(0, 10)
const moneyFormatter = new Intl.NumberFormat('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export function PaymentsPage() {
  const now = new Date()
  const [services, setServices] = useState<Service[]>([])
  const [charges, setCharges] = useState<BillingCharge[]>([])
  const [summary, setSummary] = useState<BillingSummary>(emptySummary)
  const [chargeSearch, setChargeSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<BillingCharge['status'] | ''>('')
  const [year, setYear] = useState(String(now.getFullYear()))
  const [month, setMonth] = useState(String(now.getMonth() + 1))
  const [form, setForm] = useState<ReceiptForm>({ billing_charge_id: '', payment_date: todayIso(), observations: '', send_email: false, email_to: '' })
  const [selectedReceipt, setSelectedReceipt] = useState<Receipt | null>(null)
  const [billingModal, setBillingModal] = useState<Service | null>(null)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [generating, setGenerating] = useState(false)
  const [loading, setLoading] = useState(true)

  const selectedCharge = charges.find((charge) => charge.id === Number(form.billing_charge_id)) ?? null
  const selectedService = selectedCharge ? services.find((service) => service.id === selectedCharge.internet_service_id) ?? null : null

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [servicesResponse, chargesResponse] = await Promise.all([
        api.get<{ data: Service[] }>('/billing/services'),
        api.get<{ data: BillingCharge[]; summary: BillingSummary }>('/billing/charges', { params: { year, month, per_page: 1000 } }),
      ])
      setServices(servicesResponse.data.data)
      setCharges(chargesResponse.data.data)
      setSummary({ ...emptySummary, ...chargesResponse.data.summary })
    } finally {
      setLoading(false)
    }
  }, [month, year])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const payableCharges = useMemo(() => charges.filter((charge) => charge.balance > 0 && charge.status !== 'cancelled' && charge.status !== 'paid'), [charges])
  const filteredCharges = useMemo(() => {
    const term = normalize(chargeSearch)
    return charges.filter((charge) => {
      const matchesStatus = !statusFilter || charge.status === statusFilter
      const matchesSearch = !term || normalize(`${charge.client_name} ${charge.client_document ?? ''} ${charge.client_phone ?? ''} ${charge.plan_name ?? ''} ${charge.period_label}`).includes(term)
      return matchesStatus && matchesSearch
    })
  }, [chargeSearch, charges, statusFilter])
  const filteredPayableCharges = useMemo(() => {
    const term = normalize(chargeSearch)
    if (!term) return payableCharges
    return payableCharges.filter((charge) => normalize(`${charge.client_name} ${charge.client_document ?? ''} ${charge.client_phone ?? ''} ${charge.plan_name ?? ''} ${charge.period_label}`).includes(term))
  }, [chargeSearch, payableCharges])
  const preview = receiptPreview(form, selectedCharge)

  const updateReceiptForm = (changes: Partial<ReceiptForm> | ((current: ReceiptForm) => ReceiptForm)) => {
    setSelectedReceipt(null)
    setForm((current) => typeof changes === 'function' ? changes(current) : { ...current, ...changes })
  }

  const selectCharge = (chargeId: string) => {
    const charge = charges.find((item) => item.id === Number(chargeId))
    setSelectedReceipt(null)
    if (!charge) {
      updateReceiptForm((current) => ({ ...current, billing_charge_id: chargeId }))
      return
    }

    updateReceiptForm((current) => ({
      ...current,
      billing_charge_id: chargeId,
      observations: current.observations,
      send_email: Boolean(charge.client_email),
      email_to: charge.client_email ?? '',
    }))
  }

  const generateCharges = async () => {
    setGenerating(true)
    setError('')
    setMessage('')
    try {
      const response = await api.post<{ message: string }>('/billing/charges/generate', { year: Number(year), month: Number(month) })
      setMessage(response.data.message)
      await load()
    } catch (requestError) {
      setError(getApiError(requestError))
    } finally {
      setGenerating(false)
    }
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (!selectedCharge) return
    setSaving(true)
    setError('')
    setMessage('')
    try {
      const response = await api.post<{ message: string; data: Receipt; email_delivery?: EmailDelivery | null }>('/payments', {
        billing_charge_id: selectedCharge.id,
        payment_date: form.payment_date,
        payment_amount: selectedCharge.balance,
        observations: form.observations || null,
        currency: selectedCharge.currency || 'Bolivianos',
        iva_rate: 0,
        retention_rate: 0,
        send_email: form.send_email,
        email_to: form.email_to || null,
      })
      setSelectedReceipt(response.data.data)
      setForm({ billing_charge_id: '', payment_date: todayIso(), observations: '', send_email: false, email_to: '' })
      const delivery = response.data.email_delivery ?? response.data.data.latest_email_delivery
      setMessage(delivery ? receiptEmailMessage(response.data.data, delivery) : `Recibo Nro. ${response.data.data.receipt_number} registrado correctamente.`)
      await load()
      printReceipt(response.data.data)
    } catch (requestError) {
      setError(getApiError(requestError))
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="page-content payments-page">
      <div className="page-heading">
        <div><span className="eyebrow">Cobranza</span><h1>Pagos</h1><p>{payableCharges.length} cobro{payableCharges.length === 1 ? '' : 's'} con saldo en el periodo</p></div>
        <div className="heading-actions">
          <button className="button button-secondary button-fit" disabled={generating} onClick={generateCharges}><RefreshCw size={17} /> {generating ? 'Generando...' : 'Generar cobros'}</button>
          <button className="button button-secondary button-fit" disabled={!selectedReceipt} onClick={() => selectedReceipt && printReceipt(selectedReceipt)}><Printer size={17} /> Imprimir ultimo</button>
        </div>
      </div>

      {message && <div className="alert alert-success dismissible">{message}<button onClick={() => setMessage('')}><X size={16} /></button></div>}
      {error && <div className="alert alert-error">{error}</div>}

      <div className="receipts-workspace">
        <section className="receipt-panel">
          <header><ReceiptText /><div><span className="eyebrow">Nuevo recibo</span><h2>Registrar pago de cobro</h2></div></header>
          <form onSubmit={submit}>
            <label className="field"><span>Buscar cobro</span><div className="search-box"><Search size={18} /><input value={chargeSearch} onChange={(event) => setChargeSearch(event.target.value)} placeholder="Nombre, carnet, telefono, plan o periodo" /></div></label>
            <label className="field"><span>Cobro pendiente *</span><select required value={form.billing_charge_id} onChange={(event) => selectCharge(event.target.value)}><option value="">Selecciona un cobro generado</option>{filteredPayableCharges.map((charge) => <option value={charge.id} key={charge.id}>{charge.client_name} - {charge.period_label} - saldo {moneyFormatter.format(charge.balance)} Bs</option>)}</select></label>
            {selectedCharge && <div className="receipt-client-summary charge-summary"><div><span>Cliente</span><strong>{selectedCharge.client_name}</strong></div><div><span>Plan</span><strong>{selectedCharge.plan_name ?? '-'}</strong></div><div><span>Saldo</span><strong>{moneyFormatter.format(selectedCharge.balance)} Bs</strong></div>{selectedService && <button type="button" title="Configurar cobranza" onClick={() => setBillingModal(selectedService)}><Settings size={17} /></button>}</div>}
            {selectedCharge && <div className="charge-details"><div><span>Periodo</span><strong>{selectedCharge.period_label}</strong></div><div><span>Pago</span><strong>{formatDate(selectedCharge.due_date)}</strong></div><div><span>Corte</span><strong>{formatDate(selectedCharge.cutoff_date)}</strong></div><div><span>Perdonazo</span><strong>{formatDate(selectedCharge.grace_deadline)}</strong></div><div><span>Pagado</span><strong>{moneyFormatter.format(selectedCharge.paid_amount)} Bs</strong></div><div><span>Estado</span><strong>{selectedCharge.label}</strong></div></div>}
            {!selectedCharge && charges.length === 0 && <div className="alert alert-error">No hay cobros generados para este periodo. Genera los cobros del mes antes de registrar pagos.</div>}
            <div className="client-form">
              <label className="field"><span>Fecha de pago *</span><input required type="date" value={form.payment_date} onChange={(event) => updateReceiptForm({ payment_date: event.target.value })} /></label>
              <label className="field"><span>Monto a pagar</span><input readOnly value={selectedCharge ? `${moneyFormatter.format(selectedCharge.balance)} Bs` : '0,00 Bs'} /></label>
              <label className="toggle-field full"><input type="checkbox" checked={form.send_email} onChange={(event) => updateReceiptForm({ send_email: event.target.checked })} /><span>Enviar recibo por correo al guardar</span></label>
              {form.send_email && <label className="field full"><span>Correo destino *</span><input required type="email" value={form.email_to} onChange={(event) => updateReceiptForm({ email_to: event.target.value })} placeholder="cliente@correo.com" /></label>}
              <label className="field full"><span>Observaciones</span><textarea maxLength={1000} value={form.observations} onChange={(event) => updateReceiptForm({ observations: event.target.value })} /></label>
            </div>
            <footer className="form-actions"><button className="button button-primary button-fit" disabled={saving || !selectedCharge || selectedCharge.balance <= 0}><Save size={16} /> {saving ? 'Guardando...' : 'Guardar e imprimir'}</button></footer>
          </form>
        </section>

        <ReceiptPreview receipt={selectedReceipt} preview={preview} />
      </div>

      <section className="billing-status-section">
        <div className="billing-toolbar">
          <div><span className="eyebrow">Cobros del periodo</span><h2>Estado mensual</h2></div>
          <div className="billing-period-controls">
            <select value={month} onChange={(event) => { setMonth(event.target.value); resetForm(setForm) }}>{monthOptions().map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select>
            <select value={year} onChange={(event) => { setYear(event.target.value); resetForm(setForm) }}>{yearOptions().map((option) => <option key={option} value={option}>{option}</option>)}</select>
          </div>
        </div>
        <div className="status-pills">
          <button className={statusFilter === 'paid' ? 'active paid' : 'paid'} onClick={() => setStatusFilter(statusFilter === 'paid' ? '' : 'paid')}>Pagados {summary.paid}<span>{moneyFormatter.format(summary.paid_total)} Bs</span></button>
          <button className={statusFilter === 'partial' ? 'active partial' : 'partial'} onClick={() => setStatusFilter(statusFilter === 'partial' ? '' : 'partial')}>Parciales {summary.partial}<span>Saldo {moneyFormatter.format(summary.partial_total)} Bs</span></button>
          <button className={statusFilter === 'grace' ? 'active grace' : 'grace'} onClick={() => setStatusFilter(statusFilter === 'grace' ? '' : 'grace')}>Perdonazo {summary.grace}<span>{moneyFormatter.format(summary.grace_total)} Bs</span></button>
          <button className={statusFilter === 'overdue' ? 'active overdue' : 'overdue'} onClick={() => setStatusFilter(statusFilter === 'overdue' ? '' : 'overdue')}>Vencidos {summary.overdue}<span>{moneyFormatter.format(summary.overdue_total)} Bs</span></button>
          <button className={statusFilter === 'pending' ? 'active pending' : 'pending'} onClick={() => setStatusFilter(statusFilter === 'pending' ? '' : 'pending')}>Pendientes {summary.pending}<span>{moneyFormatter.format(summary.pending_total)} Bs</span></button>
        </div>
        <div className="billing-totals"><div><span>Cargado</span><strong>{moneyFormatter.format(summary.total_charged)} Bs</strong></div><div><span>Cobrado</span><strong>{moneyFormatter.format(summary.total_paid)} Bs</strong></div><div><span>Saldo</span><strong>{moneyFormatter.format(summary.total_balance)} Bs</strong></div></div>
        <div className="table-card">
          {loading ? <div className="table-message">Cargando cobros...</div> : charges.length === 0 ? <div className="table-message"><CreditCard /><strong>Sin cobros generados</strong><span>Genera los cobros del periodo para registrar pagos desde saldos reales.</span></div> : (
            <div className="table-scroll">
              <table>
                <thead><tr><th>Cliente</th><th>Plan</th><th>Periodo</th><th>Pago</th><th>Corte</th><th>Saldo</th><th>Estado</th><th aria-label="Acciones" /></tr></thead>
                <tbody>{filteredCharges.map((charge) => <tr key={charge.id}><td><strong>{charge.client_name}</strong><small>{charge.client_document ?? '-'} - {charge.client_phone ?? '-'}</small></td><td>{charge.plan_name}</td><td>{charge.period_label}</td><td>{formatDate(charge.due_date)}</td><td>{formatDate(charge.cutoff_date)}</td><td><strong>{moneyFormatter.format(charge.balance)} Bs</strong><small>De {moneyFormatter.format(charge.original_amount)} Bs</small></td><td><span className={`status-badge ${charge.status}`}>{charge.label}</span></td><td><div className="row-actions"><button title="Registrar pago" disabled={charge.balance <= 0 || charge.status === 'cancelled'} onClick={() => selectCharge(String(charge.id))}><CreditCard size={17} /></button></div></td></tr>)}</tbody>
              </table>
            </div>
          )}
        </div>
      </section>

      {billingModal && <BillingConfigModal service={billingModal} onClose={() => setBillingModal(null)} onSaved={async () => { setBillingModal(null); await load() }} />}
    </section>
  )
}

export function ReceiptsPage() {
  const now = new Date()
  const [receipts, setReceipts] = useState<Receipt[]>([])
  const [meta, setMeta] = useState<Meta>(emptyMeta)
  const [summary, setSummary] = useState<ReceiptSummary>(emptyReceiptSummary)
  const [search, setSearch] = useState('')
  const [year, setYear] = useState(String(now.getFullYear()))
  const [month, setMonth] = useState(String(now.getMonth() + 1))
  const [page, setPage] = useState(1)
  const [selectedReceipt, setSelectedReceipt] = useState<Receipt | null>(null)
  const [emailModal, setEmailModal] = useState<Receipt | null>(null)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await api.get<{ data: Receipt[]; summary: ReceiptSummary; meta: Meta }>('/receipts', { params: { search: search || undefined, year: year || undefined, month: month || undefined, page } })
      setReceipts(response.data.data)
      setSummary({ ...emptyReceiptSummary, ...response.data.summary })
      setMeta(response.data.meta)
    } catch (requestError) {
      setError(getApiError(requestError))
    } finally {
      setLoading(false)
    }
  }, [month, page, search, setError, setLoading, setMeta, setReceipts, setSummary, year])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  return (
    <section className="page-content receipts-page">
      <div className="page-heading">
        <div><span className="eyebrow">Comprobantes</span><h1>Recibos</h1><p>{meta.total} recibo{meta.total === 1 ? '' : 's'} emitido{meta.total === 1 ? '' : 's'}</p></div>
        <button className="button button-secondary button-fit" disabled={!selectedReceipt} onClick={() => selectedReceipt && printReceipt(selectedReceipt)}><Printer size={17} /> Imprimir seleccionado</button>
      </div>

      {message && <div className="alert alert-success dismissible">{message}<button onClick={() => setMessage('')}><X size={16} /></button></div>}
      {error && <div className="alert alert-error">{error}</div>}

      <section className="receipt-history-section">
        <div className="billing-toolbar">
          <div><span className="eyebrow">Historial</span><h2>Recibos emitidos</h2></div>
          <div className="receipt-filters">
            <label className="search-box receipt-history-search"><Search size={18} /><input value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} placeholder="Buscar por cliente, numero o concepto" /></label>
            <div className="billing-period-controls">
              <select value={month} onChange={(event) => { setMonth(event.target.value); setPage(1) }}>{monthOptions().map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select>
              <select value={year} onChange={(event) => { setYear(event.target.value); setPage(1) }}>{yearOptions().map((option) => <option key={option} value={option}>{option}</option>)}</select>
            </div>
          </div>
        </div>
        <div className="table-card">
          <div className="receipt-history-summary"><div><span>Emitidos</span><strong>{summary.count}</strong></div><div><span>Total cobrado</span><strong>{moneyFormatter.format(summary.total_received)} Bs</strong></div><div><span>Periodo</span><strong>{monthOptions().find((option) => option.value === month)?.label} {year}</strong></div></div>
          {loading ? <div className="table-message">Cargando recibos...</div> : receipts.length === 0 ? <div className="table-message"><FileText /><strong>Sin recibos en este periodo</strong></div> : (
            <div className="table-scroll">
              <table>
                <thead><tr><th>Nro.</th><th>Fecha pago</th><th>Periodo</th><th>Cliente</th><th>Concepto</th><th>Total</th><th>Correo</th><th aria-label="Acciones" /></tr></thead>
                <tbody>{receipts.map((receipt) => <tr key={receipt.id}><td>{receipt.receipt_number}</td><td>{formatDate(receipt.payment_date)}</td><td>{receipt.billing_period ?? '-'}</td><td>{receipt.client_name}</td><td>{receipt.concept}</td><td>{moneyFormatter.format(Number(receipt.total_received))} Bs</td><td><EmailDeliveryBadge delivery={receipt.latest_email_delivery} /></td><td><div className="row-actions"><button title="Ver recibo" onClick={() => setSelectedReceipt(receipt)}><Eye size={17} /></button><button title="Imprimir" onClick={() => printReceipt(receipt)}><Printer size={17} /></button><button title="Enviar por correo" onClick={() => setEmailModal(receipt)}><Mail size={17} /></button></div></td></tr>)}</tbody>
              </table>
            </div>
          )}
          <footer className="pagination"><span>Pagina {meta.current_page} de {meta.last_page}</span><div><button disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Anterior</button><button disabled={page >= meta.last_page} onClick={() => setPage((value) => value + 1)}>Siguiente</button></div></footer>
        </div>
      </section>

      {selectedReceipt && <section className="receipt-selected-preview"><ReceiptPreview receipt={selectedReceipt} preview={selectedReceipt} /></section>}
      {emailModal && <EmailReceiptModal receipt={emailModal} onClose={() => setEmailModal(null)} onSent={async (updated, delivery) => { setEmailModal(null); setSelectedReceipt(updated); setMessage(receiptEmailMessage(updated, delivery)); await load() }} />}
    </section>
  )
}

function EmailDeliveryBadge({ delivery }: { delivery?: EmailDelivery | null }) {
  if (!delivery) return <span className="status-badge unsent">Sin envio</span>
  if (delivery.status === 'sent') return <div className="email-status"><span className="status-badge sent">Enviado</span><small>{delivery.recipient_email}</small></div>
  if (delivery.status === 'failed') return <div className="email-status"><span className="status-badge failed">Fallido</span><small>{delivery.error_message ?? delivery.recipient_email}</small></div>
  return <span className="status-badge pending">Pendiente</span>
}

function EmailReceiptModal({ receipt, onClose, onSent }: { receipt: Receipt; onClose: () => void; onSent: (receipt: Receipt, delivery: EmailDelivery) => Promise<void> }) {
  const [email, setEmail] = useState(receipt.client_email || receipt.latest_email_delivery?.recipient_email || '')
  const [error, setError] = useState('')
  const [sending, setSending] = useState(false)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSending(true)
    setError('')
    try {
      const response = await api.post<{ data: Receipt; email_delivery: EmailDelivery }>(`/receipts/${receipt.id}/email`, { email_to: email || null })
      await onSent(response.data.data, response.data.email_delivery)
    } catch (requestError) {
      setError(getApiError(requestError))
    } finally {
      setSending(false)
    }
  }

  return <div className="modal-backdrop"><section className="modal-card"><header><div><span className="eyebrow">Correo</span><h2>Enviar recibo Nro. {receipt.receipt_number}</h2></div><button className="modal-close" onClick={onClose}><X /></button></header>{error && <div className="alert alert-error">{error}</div>}<form onSubmit={submit}><label className="field"><span>Correo destino *</span><input required type="email" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="cliente@correo.com" /></label>{receipt.latest_email_delivery && <div className="email-last-delivery"><EmailDeliveryBadge delivery={receipt.latest_email_delivery} /></div>}<footer className="form-actions"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={sending}><Send size={16} /> {sending ? 'Enviando...' : 'Enviar correo'}</button></footer></form></section></div>
}

function ReceiptPreview({ receipt, preview }: { receipt: Receipt | null; preview: Receipt }) {
  const display = receipt ?? preview
  const amountWords = receipt ? receipt.amount_words : 'Se completara al guardar el recibo.'

  return (
    <article className="receipt-preview receipt-print-surface">
      <header className="receipt-title">RECIBO DE PAGO</header>
      <section className="receipt-company">
        <div className="company-info"><img src="/fibracom-logo.svg" alt="SERVI-TEC" /><p>servi-tec</p><p>Referencia atencion al cliente: 63918303</p></div>
        <dl className="receipt-meta">
          <div><dt>Nro. RECIBO</dt><dd>{receipt?.receipt_number ?? 'Pendiente'}</dd></div>
          <div><dt>FECHA DE EMISION</dt><dd>{formatDate(display.payment_date)}</dd></div>
          <div><dt>CORTE</dt><dd>{formatDate(display.cutoff_date)}</dd></div>
        </dl>
      </section>
      <section className="receipt-lines">
        <div className="line-row"><strong>RECIBI DE:</strong><span>{display.client_name}</span></div>
        <div className="line-row amount-row"><strong>LA SUMA DE:</strong><span>{amountWords}</span><b>{moneyFormatter.format(Number(display.total_received || 0))} Bs</b></div>
      </section>
      <table className="receipt-detail-table">
        <thead><tr><th>CONCEPTO</th><th>PERIODO A CANCELAR SERVICIO</th><th>MONTO</th></tr></thead>
        <tbody><tr><td>{display.concept || 'Servicio de Internet'}</td><td>{display.billing_period || display.observations || '-'}</td><td>{moneyFormatter.format(Number(display.total_received || 0))} Bs</td></tr></tbody>
      </table>
      <footer className="signatures"><div><span>x.</span><p>Firma de Recibido</p></div><div><span>x.</span><p>Firma de Entregado</p></div></footer>
    </article>
  )
}

function BillingConfigModal({ service, onClose, onSaved }: { service: Service; onClose: () => void; onSaved: () => Promise<void> }) {
  const [form, setForm] = useState({ billing_day: String(service.billing_day || 1), cutoff_day: String(service.cutoff_day || 1), grace_days: String(service.grace_days ?? 3), billing_amount: service.billing_amount ?? '', billing_enabled: service.billing_enabled })
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      await api.put(`/billing/services/${service.id}`, { billing_day: Number(form.billing_day), cutoff_day: Number(form.cutoff_day), grace_days: Number(form.grace_days), billing_amount: form.billing_amount ? Number(form.billing_amount) : null, billing_enabled: form.billing_enabled })
      await onSaved()
    } catch (requestError) {
      setError(getApiError(requestError))
    } finally {
      setSaving(false)
    }
  }

  return <div className="modal-backdrop"><section className="modal-card"><header><div><span className="eyebrow">Cobranza</span><h2>{service.client.full_name}</h2></div><button className="modal-close" onClick={onClose}><X /></button></header>{error && <div className="alert alert-error">{error}</div>}<form onSubmit={submit}><label className="field"><span>Dia de pago *</span><input required type="number" min="1" max="31" value={form.billing_day} onChange={(event) => setForm({ ...form, billing_day: event.target.value })} /></label><label className="field"><span>Dia de corte *</span><input required type="number" min="1" max="31" value={form.cutoff_day} onChange={(event) => setForm({ ...form, cutoff_day: event.target.value })} /></label><label className="field"><span>Perdonazo dias *</span><input required type="number" min="0" max="15" value={form.grace_days} onChange={(event) => setForm({ ...form, grace_days: event.target.value })} /></label><label className="field"><span>Monto mensual Bs</span><input type="number" min="0" step="0.01" placeholder={service.plan.monthly_price ?? '0'} value={form.billing_amount} onChange={(event) => setForm({ ...form, billing_amount: event.target.value })} /></label><label className="toggle-field"><input type="checkbox" checked={form.billing_enabled} onChange={(event) => setForm({ ...form, billing_enabled: event.target.checked })} /><span>Cobranza activa</span></label><footer className="form-actions"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={saving}>{saving ? 'Guardando...' : 'Guardar'}</button></footer></form></section></div>
}

function receiptPreview(form: ReceiptForm, charge: BillingCharge | null): Receipt {
  const amount = charge?.balance ?? 0
  return {
    id: 0,
    receipt_number: 'Pendiente',
    billing_charge_id: charge?.id ?? null,
    internet_service_id: charge?.internet_service_id ?? 0,
    client_name: charge?.client_name ?? '',
    client_document: charge?.client_document ?? null,
    client_phone: charge?.client_phone ?? null,
    client_email: charge?.client_email ?? null,
    plan_name: charge?.plan_name ?? null,
    payment_date: form.payment_date,
    cutoff_date: charge?.cutoff_date ?? null,
    billing_period: charge?.period_label ?? null,
    concept: charge?.concept ?? 'Servicio de Internet',
    observations: form.observations || null,
    currency: charge?.currency ?? 'Bolivianos',
    subtotal: amount.toFixed(2),
    iva_rate: '0.00',
    retention_rate: '0.00',
    iva: '0.00',
    retention: '0.00',
    total_received: amount.toFixed(2),
    amount_words: '',
    created_at: '',
    latest_email_delivery: null,
  }
}

function resetForm(setForm: (value: ReceiptForm) => void) {
  setForm({ billing_charge_id: '', payment_date: todayIso(), observations: '', send_email: false, email_to: '' })
}

function receiptEmailMessage(receipt: Receipt, delivery: EmailDelivery) {
  if (delivery.status === 'sent') return `Recibo Nro. ${receipt.receipt_number} enviado a ${delivery.recipient_email}.`
  return `Recibo Nro. ${receipt.receipt_number} guardado, pero no se pudo enviar el correo.`
}

function printReceipt(receipt: Receipt) {
  const printWindow = window.open('', '_blank', 'width=860,height=620')
  if (!printWindow) {
    window.print()
    return
  }
  printWindow.document.write(receiptPrintHtml(receipt))
  printWindow.document.close()
  printWindow.focus()
  setTimeout(() => printWindow.print(), 250)
}

function receiptPrintHtml(receipt: Receipt) {
  const total = `${moneyFormatter.format(Number(receipt.total_received || 0))} Bs`
  return `<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Recibo-${escapeMarkup(receipt.receipt_number)}-${escapeMarkup(receipt.client_name)}</title><style>${printStyles()}</style></head><body><article class="receipt"><header class="receipt-title">RECIBO DE PAGO</header><section class="receipt-company"><div class="company-info"><img src="${window.location.origin}/fibracom-logo.svg" alt="SERVI-TEC"><p>servi-tec</p><p>Referencia atencion al cliente: 63918303</p></div><dl class="receipt-meta"><div><dt>Nro. RECIBO</dt><dd>${escapeMarkup(receipt.receipt_number)}</dd></div><div><dt>FECHA DE EMISION</dt><dd>${formatDate(receipt.payment_date)}</dd></div><div><dt>CORTE</dt><dd>${formatDate(receipt.cutoff_date)}</dd></div></dl></section><section class="receipt-lines"><div class="line-row"><strong>RECIBI DE:</strong><span>${escapeMarkup(receipt.client_name)}</span></div><div class="line-row amount-row"><strong>LA SUMA DE:</strong><span>${escapeMarkup(receipt.amount_words)}</span><b>${total}</b></div></section><table class="receipt-detail-table"><thead><tr><th>CONCEPTO</th><th>PERIODO A CANCELAR SERVICIO</th><th>MONTO</th></tr></thead><tbody><tr><td>${escapeMarkup(receipt.concept)}</td><td>${escapeMarkup(receipt.billing_period || receipt.observations || '-')}</td><td>${total}</td></tr></tbody></table><footer class="signatures"><div><span>x.</span><p>Firma de Recibido</p></div><div><span>x.</span><p>Firma de Entregado</p></div></footer></article></body></html>`
}

function printStyles() {
  return '@page{size:A4;margin:8mm}*{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}body{margin:0;background:#fff;color:#111827;font-family:Arial,Helvetica,sans-serif;font-size:11px}.receipt{width:160mm;max-width:160mm;background:#fff;border:2px solid #3b4652;padding:6mm 8mm;margin:0 auto;page-break-inside:avoid}.receipt-title{background:#08b558;color:#fff;text-align:center;font-size:20px;font-weight:800;padding:5px 10px}.receipt-company{display:grid;grid-template-columns:1fr 210px;gap:16px;border:2px solid #3b4652;border-top:0;padding:8px 12px}.company-info img{width:170px;display:block;margin-bottom:6px}.company-info p{margin:3px 0}.receipt-meta{display:grid;gap:5px;align-content:center;border-left:1px solid #aab2bd;padding-left:12px;font-weight:800}.receipt-meta div{display:grid;grid-template-columns:118px 1fr;gap:8px;min-height:18px}.receipt-meta dt,.receipt-meta dd{margin:0}.receipt-lines{display:grid;gap:6px;margin-top:8px}.line-row{display:grid;grid-template-columns:105px 1fr;min-height:25px}.line-row strong{display:grid;align-items:center;border:1px solid #9aa8b6;border-right:0;background:#edf2f7;padding:5px 7px;text-align:center}.line-row span{display:grid;align-items:center;border:1px solid #9aa8b6;padding:5px 7px}.amount-row{grid-template-columns:105px 1fr 110px}.amount-row span{border-right:0}.amount-row b{display:grid;align-items:center;border:1px solid #d7c15b;background:#ffe77a;padding:5px 7px;text-align:right;font-size:15px}table{width:100%;margin-top:10px;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #3b4652;padding:5px 6px;text-align:center;font-size:10px}th{background:#edf2f7;font-weight:800}th:nth-child(1),td:nth-child(1){width:34%}th:nth-child(2),td:nth-child(2){width:42%}th:nth-child(3),td:nth-child(3){width:24%}td:first-child{text-align:left}td:last-child{text-align:right;white-space:nowrap}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:65px;margin-top:50px}.signatures div{position:relative;border-top:2px solid #333;padding-top:10px}.signatures span{position:absolute;left:0;top:-22px;display:block}.signatures p{margin:0;font-style:italic;line-height:1.2}'
}

function normalize(text: string) {
  return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
}

function formatDate(value?: string | null) {
  if (!value) return '-'
  const [year, month, day] = value.split('-')
  return `${day}/${month}/${year}`
}

function monthOptions() {
  return ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'].map((label, index) => ({ label, value: String(index + 1) }))
}

function yearOptions() {
  const year = new Date().getFullYear()
  return [year - 1, year, year + 1, year + 2]
}

function escapeMarkup(value: string | number | null | undefined) {
  return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
}
