import { Eye, FileText, Printer, ReceiptText, Save, Search, Settings, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { api, getApiError } from '../lib/api'

type Zone = { id: number; name: string }
type Client = { id: number; full_name: string; document: string; phone: string; zone: Zone }
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
type Receipt = {
  id: number
  receipt_number: string
  internet_service_id: number
  client_name: string
  client_document: string | null
  client_phone: string | null
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
}
type Meta = { current_page: number; last_page: number; total: number }
type BillingStatus = {
  service_id: number
  client_name: string
  client_document: string
  client_phone: string
  plan_name: string | null
  status: 'paid' | 'grace' | 'overdue' | 'pending'
  label: string
  due_date: string
  cutoff_date: string
  grace_deadline: string
  amount_due: number
  paid_amount: number
  receipt: Receipt | null
}
type BillingSummary = {
  paid: number
  grace: number
  overdue: number
  pending: number
  paid_total: number
  grace_total: number
  overdue_total: number
  pending_total: number
}
type ReceiptForm = {
  internet_service_id: string
  payment_date: string
  cutoff_date: string
  billing_period: string
  concept: string
  observations: string
  subtotal: string
}

const emptyMeta: Meta = { current_page: 1, last_page: 1, total: 0 }
const todayIso = () => new Date().toISOString().slice(0, 10)
const moneyFormatter = new Intl.NumberFormat('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

export function ReceiptsPage() {
  const now = new Date()
  const [services, setServices] = useState<Service[]>([])
  const [receipts, setReceipts] = useState<Receipt[]>([])
  const [statuses, setStatuses] = useState<BillingStatus[]>([])
  const [summary, setSummary] = useState<BillingSummary>({ paid: 0, grace: 0, overdue: 0, pending: 0, paid_total: 0, grace_total: 0, overdue_total: 0, pending_total: 0 })
  const [meta, setMeta] = useState<Meta>(emptyMeta)
  const [search, setSearch] = useState('')
  const [historySearch, setHistorySearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<BillingStatus['status'] | ''>('')
  const [year, setYear] = useState(String(now.getFullYear()))
  const [month, setMonth] = useState(String(now.getMonth() + 1))
  const [page, setPage] = useState(1)
  const [form, setForm] = useState<ReceiptForm>({ internet_service_id: '', payment_date: todayIso(), cutoff_date: '', billing_period: '', concept: 'Servicio de Internet', observations: '', subtotal: '0' })
  const [selectedReceipt, setSelectedReceipt] = useState<Receipt | null>(null)
  const [billingModal, setBillingModal] = useState<Service | null>(null)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(true)

  const selectedService = services.find((service) => service.id === Number(form.internet_service_id)) ?? null

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [servicesResponse, receiptsResponse, statusResponse] = await Promise.all([
        api.get<{ data: Service[] }>('/billing/services'),
        api.get<{ data: Receipt[]; meta: Meta }>('/receipts', { params: { search: historySearch || undefined, year: year || undefined, month: month || undefined, page } }),
        api.get<{ data: BillingStatus[]; summary: BillingSummary }>('/billing/status', { params: { year, month } }),
      ])
      setServices(servicesResponse.data.data)
      setReceipts(receiptsResponse.data.data)
      setMeta(receiptsResponse.data.meta)
      setStatuses(statusResponse.data.data)
      setSummary(statusResponse.data.summary)
    } finally {
      setLoading(false)
    }
  }, [historySearch, month, page, year])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const filteredServices = useMemo(() => {
    const term = normalize(search)
    if (!term) return services
    return services.filter((service) => normalize(`${service.client.full_name} ${service.client.document} ${service.client.phone} ${service.plan.name}`).includes(term))
  }, [search, services])

  const filteredStatuses = statusFilter ? statuses.filter((row) => row.status === statusFilter) : statuses
  const preview = receiptPreview(form, selectedService)

  const updateReceiptForm = (changes: Partial<ReceiptForm> | ((current: ReceiptForm) => ReceiptForm)) => {
    setSelectedReceipt(null)
    setForm((current) => typeof changes === 'function' ? changes(current) : { ...current, ...changes })
  }

  const selectService = (serviceId: string) => {
    const service = services.find((item) => item.id === Number(serviceId))
    setSelectedReceipt(null)
    if (!service) {
      updateReceiptForm((current) => ({ ...current, internet_service_id: serviceId }))
      return
    }

    const paymentDate = form.payment_date || todayIso()
    const schedule = billingSchedule(service, paymentDate)
    updateReceiptForm((current) => ({
      ...current,
      internet_service_id: serviceId,
      cutoff_date: schedule.cutoffDate,
      billing_period: schedule.period,
      concept: 'Servicio de Internet',
      observations: service.plan.name,
      subtotal: String(serviceAmount(service)),
    }))
  }

  const changePaymentDate = (paymentDate: string) => {
    if (!selectedService) {
      updateReceiptForm({ payment_date: paymentDate })
      return
    }

    const schedule = billingSchedule(selectedService, paymentDate)
    updateReceiptForm({
      payment_date: paymentDate,
      cutoff_date: schedule.cutoffDate,
      billing_period: schedule.period,
    })
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    setError('')
    setMessage('')
    try {
      const response = await api.post<{ message: string; data: Receipt }>('/receipts', {
        internet_service_id: Number(form.internet_service_id),
        payment_date: form.payment_date,
        cutoff_date: form.cutoff_date || null,
        billing_period: form.billing_period || null,
        concept: form.concept,
        observations: form.observations || null,
        currency: 'Bolivianos',
        subtotal: Number(form.subtotal || 0),
        iva_rate: 0,
        retention_rate: 0,
      })
      setSelectedReceipt(response.data.data)
      setMessage(`Recibo Nro. ${response.data.data.receipt_number} registrado correctamente.`)
      await load()
      printReceipt(response.data.data)
    } catch (requestError) {
      setError(getApiError(requestError))
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="page-content receipts-page">
      <div className="page-heading">
        <div><span className="eyebrow">Cobranza</span><h1>Recibos de pago</h1><p>{meta.total} recibo{meta.total === 1 ? '' : 's'} registrado{meta.total === 1 ? '' : 's'}</p></div>
        <button className="button button-secondary button-fit" disabled={!selectedReceipt} onClick={() => selectedReceipt && printReceipt(selectedReceipt)}><Printer size={17} /> Imprimir ultimo</button>
      </div>

      {message && <div className="alert alert-success dismissible">{message}<button onClick={() => setMessage('')}><X size={16} /></button></div>}
      {error && <div className="alert alert-error">{error}</div>}

      <div className="receipts-workspace">
        <section className="receipt-panel">
          <header><ReceiptText /><div><span className="eyebrow">Nuevo recibo</span><h2>Registrar pago mensual</h2></div></header>
          <form onSubmit={submit}>
            <label className="field"><span>Buscar cliente</span><div className="search-box"><Search size={18} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Nombre, carnet, telefono o plan" /></div></label>
            <label className="field"><span>Cliente con servicio *</span><select required value={form.internet_service_id} onChange={(event) => selectService(event.target.value)}><option value="">Selecciona un servicio</option>{filteredServices.map((service) => <option value={service.id} key={service.id}>{service.client.full_name} - {service.plan.name}{service.billing_enabled ? '' : ' - cobranza desactivada'}</option>)}</select></label>
            {selectedService && <div className="receipt-client-summary"><div><span>Carnet</span><strong>{selectedService.client.document}</strong></div><div><span>Telefono</span><strong>{selectedService.client.phone}</strong></div><div><span>Zona</span><strong>{selectedService.client.zone.name}</strong></div><button type="button" title="Configurar cobranza" onClick={() => setBillingModal(selectedService)}><Settings size={17} /></button></div>}
            {selectedService && !selectedService.billing_enabled && <div className="alert alert-error">La cobranza de este servicio esta desactivada. Abre configuracion para activarla antes de emitir recibos.</div>}
            <div className="client-form">
              <label className="field"><span>Fecha de pago *</span><input required type="date" value={form.payment_date} onChange={(event) => changePaymentDate(event.target.value)} /></label>
              <label className="field"><span>Fecha de corte</span><input type="date" value={form.cutoff_date} onChange={(event) => updateReceiptForm({ cutoff_date: event.target.value })} /></label>
              <label className="field full"><span>Periodo</span><input value={form.billing_period} onChange={(event) => updateReceiptForm({ billing_period: event.target.value })} placeholder="Agosto 2026" /></label>
              <label className="field"><span>Concepto *</span><input required maxLength={255} value={form.concept} onChange={(event) => updateReceiptForm({ concept: event.target.value })} /></label>
              <label className="field"><span>Monto Bs *</span><input required type="number" min="0" step="0.01" value={form.subtotal} onChange={(event) => updateReceiptForm({ subtotal: event.target.value })} /></label>
              <label className="field full"><span>Observaciones</span><textarea maxLength={1000} value={form.observations} onChange={(event) => updateReceiptForm({ observations: event.target.value })} /></label>
            </div>
            <footer className="form-actions"><button className="button button-primary button-fit" disabled={saving || !selectedService || !selectedService.billing_enabled}><Save size={16} /> {saving ? 'Guardando...' : 'Guardar e imprimir'}</button></footer>
          </form>
        </section>

        <ReceiptPreview receipt={selectedReceipt} preview={preview} />
      </div>

      <section className="billing-status-section">
        <div className="billing-toolbar">
          <div><span className="eyebrow">Estado mensual</span><h2>Consulta de clientes</h2></div>
          <div className="billing-period-controls">
            <select value={month} onChange={(event) => { setMonth(event.target.value); setPage(1) }}>{monthOptions().map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select>
            <select value={year} onChange={(event) => { setYear(event.target.value); setPage(1) }}>{yearOptions().map((option) => <option key={option} value={option}>{option}</option>)}</select>
          </div>
        </div>
        <div className="status-pills">
          <button className={statusFilter === 'paid' ? 'active paid' : 'paid'} onClick={() => setStatusFilter(statusFilter === 'paid' ? '' : 'paid')}>Pagados {summary.paid}<span>{moneyFormatter.format(summary.paid_total)} Bs</span></button>
          <button className={statusFilter === 'grace' ? 'active grace' : 'grace'} onClick={() => setStatusFilter(statusFilter === 'grace' ? '' : 'grace')}>Perdonazo {summary.grace}<span>{moneyFormatter.format(summary.grace_total)} Bs</span></button>
          <button className={statusFilter === 'overdue' ? 'active overdue' : 'overdue'} onClick={() => setStatusFilter(statusFilter === 'overdue' ? '' : 'overdue')}>Vencidos {summary.overdue}<span>{moneyFormatter.format(summary.overdue_total)} Bs</span></button>
          <button className={statusFilter === 'pending' ? 'active pending' : 'pending'} onClick={() => setStatusFilter(statusFilter === 'pending' ? '' : 'pending')}>Pendientes {summary.pending}<span>{moneyFormatter.format(summary.pending_total)} Bs</span></button>
        </div>
        <div className="table-card">
          {loading ? <div className="table-message">Cargando cobranza...</div> : (
            <div className="table-scroll">
              <table>
                <thead><tr><th>Cliente</th><th>Plan</th><th>Pago</th><th>Corte</th><th>Perdonazo</th><th>Monto</th><th>Estado</th></tr></thead>
                <tbody>{filteredStatuses.map((row) => <tr key={row.service_id}><td><strong>{row.client_name}</strong><small>{row.client_document} - {row.client_phone}</small></td><td>{row.plan_name}</td><td>{formatDate(row.due_date)}</td><td>{formatDate(row.cutoff_date)}</td><td>{formatDate(row.grace_deadline)}</td><td>{moneyFormatter.format(row.status === 'paid' ? row.paid_amount : row.amount_due)} Bs</td><td><span className={`status-badge ${row.status}`}>{row.label}</span></td></tr>)}</tbody>
              </table>
            </div>
          )}
        </div>
      </section>

      <section className="receipt-history-section">
        <div className="billing-toolbar">
          <div><span className="eyebrow">Historial</span><h2>Recibos emitidos</h2></div>
          <label className="search-box receipt-history-search"><Search size={18} /><input value={historySearch} onChange={(event) => { setHistorySearch(event.target.value); setPage(1) }} placeholder="Buscar por cliente, numero o concepto" /></label>
        </div>
        <div className="table-card">
          {loading ? <div className="table-message">Cargando recibos...</div> : receipts.length === 0 ? <div className="table-message"><FileText /><strong>Sin recibos en este periodo</strong></div> : (
            <div className="table-scroll">
              <table>
                <thead><tr><th>Nro.</th><th>Fecha</th><th>Cliente</th><th>Concepto</th><th>Total</th><th aria-label="Acciones" /></tr></thead>
                <tbody>{receipts.map((receipt) => <tr key={receipt.id}><td>{receipt.receipt_number}</td><td>{formatDate(receipt.payment_date)}</td><td>{receipt.client_name}</td><td>{receipt.concept}</td><td>{moneyFormatter.format(Number(receipt.total_received))} Bs</td><td><div className="row-actions"><button title="Ver recibo" onClick={() => setSelectedReceipt(receipt)}><Eye size={17} /></button><button title="Imprimir" onClick={() => printReceipt(receipt)}><Printer size={17} /></button></div></td></tr>)}</tbody>
              </table>
            </div>
          )}
          <footer className="pagination"><span>Pagina {meta.current_page} de {meta.last_page}</span><div><button disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Anterior</button><button disabled={page >= meta.last_page} onClick={() => setPage((value) => value + 1)}>Siguiente</button></div></footer>
        </div>
      </section>

      {billingModal && <BillingConfigModal service={billingModal} onClose={() => setBillingModal(null)} onSaved={async () => { setBillingModal(null); await load() }} />}
    </section>
  )
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

function receiptPreview(form: ReceiptForm, service: Service | null): Receipt {
  const amount = Number(form.subtotal || 0)
  return {
    id: 0,
    receipt_number: 'Pendiente',
    internet_service_id: Number(form.internet_service_id || 0),
    client_name: service?.client.full_name ?? '',
    client_document: service?.client.document ?? null,
    client_phone: service?.client.phone ?? null,
    plan_name: service?.plan.name ?? null,
    payment_date: form.payment_date,
    cutoff_date: form.cutoff_date || null,
    billing_period: form.billing_period || null,
    concept: form.concept,
    observations: form.observations || null,
    currency: 'Bolivianos',
    subtotal: amount.toFixed(2),
    iva_rate: '0.00',
    retention_rate: '0.00',
    iva: '0.00',
    retention: '0.00',
    total_received: amount.toFixed(2),
    amount_words: '',
    created_at: '',
  }
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
  return '@page{size:A4;margin:8mm}*{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}body{margin:0;background:#fff;color:#111827;font-family:Arial,Helvetica,sans-serif;font-size:11px}.receipt{width:160mm;max-width:160mm;background:#fff;border:2px solid #3b4652;padding:6mm 8mm;margin:0 auto;page-break-inside:avoid}.receipt-title{background:#08b558;color:#fff;text-align:center;font-size:20px;font-weight:800;padding:5px 10px}.receipt-company{display:grid;grid-template-columns:1fr 210px;gap:16px;border:2px solid #3b4652;border-top:0;padding:8px 12px}.company-info img{width:170px;display:block;margin-bottom:6px}.company-info p{margin:3px 0}.receipt-meta{display:grid;gap:5px;align-content:center;border-left:1px solid #aab2bd;padding-left:12px;font-weight:800}.receipt-meta div{display:grid;grid-template-columns:118px 1fr;gap:8px;min-height:18px}.receipt-meta dt,.receipt-meta dd{margin:0}.receipt-lines{display:grid;gap:6px;margin-top:8px}.line-row{display:grid;grid-template-columns:105px 1fr;min-height:25px}.line-row strong{display:grid;align-items:center;border:1px solid #9aa8b6;border-right:0;background:#edf2f7;padding:5px 7px;text-align:center}.line-row span{display:grid;align-items:center;border:1px solid #9aa8b6;padding:5px 7px}.amount-row{grid-template-columns:105px 1fr 110px}.amount-row span{border-right:0}.amount-row b{display:grid;align-items:center;border:1px solid #d7c15b;background:#ffe77a;padding:5px 7px;text-align:right;font-size:15px}table{width:100%;margin-top:10px;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #3b4652;padding:5px 6px;text-align:center;font-size:10px}th{background:#edf2f7;font-weight:800}th:nth-child(1),td:nth-child(1){width:34%}th:nth-child(2),td:nth-child(2){width:42%}th:nth-child(3),td:nth-child(3){width:24%}td:first-child{text-align:left}td:last-child{text-align:right;white-space:nowrap}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:65px;margin-top:50px}.signatures div{position:relative;border-top:2px solid #333;padding-top:8px}.signatures span{position:absolute;left:0;top:-23px;display:block}.signatures p{margin:0;font-style:italic;line-height:1.2}'
}

function serviceAmount(service: Service) {
  return Number(service.billing_amount ?? service.plan.monthly_price ?? 0)
}

function billingSchedule(service: Service, paymentDate: string) {
  const payment = parseIso(paymentDate || todayIso())
  const cutoffDate = dateInMonth(payment.getFullYear(), payment.getMonth() + 1, service.cutoff_day || service.billing_day || 1)
  const dueDate = dateInMonth(payment.getFullYear(), payment.getMonth() + 1, service.billing_day || 1)

  return {
    cutoffDate,
    period: `${monthLabel(dueDate)} | Pago: ${formatDate(dueDate)} | Corte: ${formatDate(cutoffDate)}`,
  }
}

function normalize(text: string) {
  return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
}

function parseIso(value: string) {
  return new Date(`${value}T00:00:00`)
}

function dateInMonth(year: number, month: number, day: number) {
  const lastDay = new Date(year, month, 0).getDate()
  return isoFromDate(new Date(year, month - 1, Math.min(day, lastDay)))
}

function isoFromDate(date: Date) {
  return date.toISOString().slice(0, 10)
}

function formatDate(value?: string | null) {
  if (!value) return '-'
  const [year, month, day] = value.split('-')
  return `${day}/${month}/${year}`
}

function monthLabel(value: string) {
  const date = parseIso(value)
  return new Intl.DateTimeFormat('es-BO', { month: 'long', year: 'numeric' }).format(date)
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
