import { AxiosError } from 'axios'
import { AlertTriangle, Bell, CreditCard, Eye, MessageCircle, Settings, X } from 'lucide-react'
import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { api, getApiError } from '../lib/api'

type PaymentMethod = 'cash' | 'transfer' | 'other'
type NoticeKey = 'payment_due_5' | 'payment_due_2' | 'payment_due_today' | 'suspended'
type NoticeCard = { service_id: number; client_id: number; client_name: string; phone: string; zone: string | null; plan_name: string; amount: string; next_due_date: string | null; days_remaining: number | null; status: 'active' | 'suspended'; suspension_reason: string | null }
type NoticeColumn = { key: NoticeKey; title: string; template_key: NoticeKey; cards: NoticeCard[] }
type NoticeTemplate = { id: number; key: NoticeKey; name: string; body: string; active: boolean }
type NoticeSummary = { date_from: string; date_to: string; notifications_count: number; by_type: Array<{ type: NoticeKey; notifications_count: number }> }
type ServiceHistory = { id: number; description: string; occurred_at: string; user?: { id: number; name: string } | null }
type NotificationLog = { id: number; type: NoticeKey; channel: string; phone: string; message: string; sent_at: string; template?: { id: number; key: string; name: string } | null; user?: { id: number; name: string } | null }
type ClientDetail = { id: number; full_name: string; document: string; phone: string; email: string | null; address: string | null; zone: { id: number; name: string }; internet_service?: { id: number; status: 'active' | 'suspended'; next_due_date: string | null; plan: { id: number; name: string; monthly_price: string | null }; histories?: ServiceHistory[] } | null }
type DuplicateResponse = { message?: string }

const typeLabels: Record<NoticeKey, string> = {
  payment_due_5: 'Vence en 5 dias',
  payment_due_2: 'Vence en 2 dias',
  payment_due_today: 'Vence hoy',
  suspended: 'Suspendidos',
}

function formatMoney(value: string | number | null | undefined) {
  if (value === null || value === undefined || value === '') return 'Bs 0.00'
  return `Bs ${Number(value).toFixed(2)}`
}

function formatDate(value: string | null | undefined) {
  if (!value) return 'Sin fecha'
  return new Date(`${value}T00:00:00`).toLocaleDateString('es-BO')
}

function formatDateTime(value: string) {
  return new Date(value).toLocaleString('es-BO')
}

export function NoticesPage() {
  const [columns, setColumns] = useState<NoticeColumn[]>([])
  const [templates, setTemplates] = useState<NoticeTemplate[]>([])
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [templatesOpen, setTemplatesOpen] = useState(false)
  const [paymentCard, setPaymentCard] = useState<NoticeCard | null>(null)
  const [detailClientId, setDetailClientId] = useState<number | null>(null)
  const [summary, setSummary] = useState<NoticeSummary | null>(null)
  const [summaryFrom, setSummaryFrom] = useState('')
  const [summaryTo, setSummaryTo] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const [kanbanResponse, templateResponse, summaryResponse] = await Promise.all([
        api.get<{ data: { columns: NoticeColumn[] } }>('/notices/kanban'),
        api.get<{ data: NoticeTemplate[] }>('/notification-templates'),
        api.get<{ data: NoticeSummary }>('/notices/summary', { params: { date_from: summaryFrom || undefined, date_to: summaryTo || undefined } }),
      ])
      setColumns(kanbanResponse.data.data.columns)
      setTemplates(templateResponse.data.data)
      setSummary(summaryResponse.data.data)
    } catch (requestError) {
      setError(getApiError(requestError))
    } finally {
      setLoading(false)
    }
  }, [summaryFrom, summaryTo])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const sendWhatsapp = async (card: NoticeCard, templateKey: NoticeKey) => {
    const popup = window.open('', '_blank')
    try {
      const response = await api.post<{ data: { wa_url: string } }>(`/notices/${card.service_id}/whatsapp`, { template_key: templateKey })
      if (popup) popup.location.href = response.data.data.wa_url
      else window.open(response.data.data.wa_url, '_blank')
      setMessage(`Aviso registrado para ${card.client_name}.`)
      await load()
    } catch (requestError) {
      popup?.close()
      setError(getApiError(requestError))
    }
  }

  const totalCards = columns.reduce((sum, column) => sum + column.cards.length, 0)

  return <section className="page-content notices-page">
    <div className="page-heading">
      <div><span className="eyebrow">Cobranza</span><h1>Avisos</h1><p>{totalCards} cliente{totalCards === 1 ? '' : 's'} requiere{totalCards === 1 ? '' : 'n'} gestion</p></div>
      <button className="button button-secondary button-fit" onClick={() => setTemplatesOpen(true)}><Settings size={18} /> Plantillas</button>
    </div>

    {message && <div className="alert alert-success dismissible">{message}<button onClick={() => setMessage('')}><X size={16} /></button></div>}
    {error && <div className="alert alert-error dismissible">{error}<button onClick={() => setError('')}><X size={16} /></button></div>}

    <section className="notice-summary">
      <div><span>Periodo</span><strong>{formatDate(summary?.date_from)} - {formatDate(summary?.date_to)}</strong></div>
      <div><span>Avisos registrados</span><strong>{summary?.notifications_count ?? 0}</strong></div>
      <div className="notice-summary-types">{(['payment_due_5', 'payment_due_2', 'payment_due_today', 'suspended'] as NoticeKey[]).map((key) => <span key={key}>{typeLabels[key]}: <b>{summary?.by_type.find((item) => item.type === key)?.notifications_count ?? 0}</b></span>)}</div>
      <div className="notice-summary-filters"><input type="date" value={summaryFrom} onChange={(event) => setSummaryFrom(event.target.value)} title="Desde" /><input type="date" value={summaryTo} onChange={(event) => setSummaryTo(event.target.value)} title="Hasta" /></div>
    </section>

    {loading ? <div className="table-card"><div className="table-message">Cargando avisos...</div></div> : <div className="notice-kanban">
      {columns.map((column) => <section className="notice-column" key={column.key}>
        <header><div><h2>{column.title}</h2><span>{column.cards.length} cliente{column.cards.length === 1 ? '' : 's'}</span></div></header>
        <div className="notice-card-list">{column.cards.length ? column.cards.map((card) => <NoticeCardItem key={card.service_id} card={card} templateKey={column.template_key} onWhatsapp={() => void sendWhatsapp(card, column.template_key)} onPayment={() => setPaymentCard(card)} onDetail={() => setDetailClientId(card.client_id)} />) : <div className="notice-empty"><Bell size={22} /><strong>Sin avisos</strong></div>}</div>
      </section>)}
    </div>}

    {templatesOpen && <TemplatesModal templates={templates} onClose={() => setTemplatesOpen(false)} onSaved={async () => { setMessage('Plantilla actualizada correctamente.'); await load() }} />}
    {paymentCard && <NoticePaymentModal card={paymentCard} onClose={() => setPaymentCard(null)} onSaved={async () => { setPaymentCard(null); setMessage('Pago registrado correctamente.'); await load() }} />}
    {detailClientId && <NoticeClientDetailModal clientId={detailClientId} onClose={() => setDetailClientId(null)} />}
  </section>
}

function NoticeCardItem({ card, templateKey, onWhatsapp, onPayment, onDetail }: { card: NoticeCard; templateKey: NoticeKey; onWhatsapp: () => void; onPayment: () => void; onDetail: () => void }) {
  const daysLabel = card.status === 'suspended' ? 'Suspendido' : card.days_remaining === 0 ? 'Vence hoy' : `${card.days_remaining} dia${card.days_remaining === 1 ? '' : 's'}`

  return <article className="notice-card">
    <div className="notice-card-main">
      <strong>{card.client_name}</strong>
      <span>{card.plan_name} - {card.zone ?? 'Sin zona'}</span>
      <small>{formatDate(card.next_due_date)} - {daysLabel}</small>
    </div>
    <div className="notice-card-amount"><span>Monto</span><strong>{formatMoney(card.amount)}</strong></div>
    <footer>
      <button title="WhatsApp" className="whatsapp" onClick={onWhatsapp}><MessageCircle size={17} /></button>
      <button title="Ver cliente" onClick={onDetail}><Eye size={17} /></button>
      <button title="Registrar pago" onClick={onPayment}><CreditCard size={17} /></button>
    </footer>
    <span className={`status-badge ${templateKey === 'suspended' ? 'suspended' : card.days_remaining === 0 ? 'pending' : 'active'}`}>{typeLabels[templateKey]}</span>
  </article>
}

function TemplatesModal({ templates, onClose, onSaved }: { templates: NoticeTemplate[]; onClose: () => void; onSaved: () => Promise<void> }) {
  const [editing, setEditing] = useState<NoticeTemplate | null>(templates[0] ?? null)
  const [name, setName] = useState(editing?.name ?? '')
  const [body, setBody] = useState(editing?.body ?? '')
  const [active, setActive] = useState(editing?.active ?? true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  const select = (template: NoticeTemplate) => {
    setEditing(template)
    setName(template.name)
    setBody(template.body)
    setActive(template.active)
    setError('')
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (!editing) return
    setSaving(true)
    setError('')
    try {
      await api.put(`/notification-templates/${editing.id}`, { name, body, active })
      await onSaved()
    } catch (requestError) {
      setError(getApiError(requestError))
    } finally {
      setSaving(false)
    }
  }

  return <div className="modal-backdrop"><section className="modal-card modal-wide"><header><div><span className="eyebrow">Mensajes</span><h2>Plantillas de avisos</h2></div><button className="modal-close" onClick={onClose}><X /></button></header>{error && <div className="alert alert-error">{error}</div>}<div className="template-editor"><aside>{templates.map((template) => <button key={template.id} className={editing?.id === template.id ? 'active' : ''} onClick={() => select(template)}><span>{template.name}</span><small>{template.active ? 'Activa' : 'Inactiva'}</small></button>)}</aside><form onSubmit={submit}><label className="field"><span>Nombre *</span><input required maxLength={100} value={name} onChange={(event) => setName(event.target.value)} /></label><label className="field"><span>Mensaje *</span><textarea required maxLength={1000} value={body} onChange={(event) => setBody(event.target.value)} /></label><div className="variable-tags"><span>{'{nombre}'}</span><span>{'{monto}'}</span><span>{'{fecha}'}</span><span>{'{dias}'}</span><span>{'{instrucciones_pago}'}</span></div><label className="toggle-field"><input type="checkbox" checked={active} onChange={(event) => setActive(event.target.checked)} /><span>Plantilla activa</span></label><footer className="form-actions"><button type="button" className="button button-secondary" onClick={onClose}>Cerrar</button><button className="button button-primary button-fit" disabled={saving}>{saving ? 'Guardando...' : 'Guardar plantilla'}</button></footer></form></div></section></div>
}

function NoticePaymentModal({ card, onClose, onSaved }: { card: NoticeCard; onClose: () => void; onSaved: () => Promise<void> }) {
  const [amount, setAmount] = useState(card.amount)
  const [paidAt, setPaidAt] = useState(new Date().toISOString().slice(0, 10))
  const [method, setMethod] = useState<PaymentMethod>('cash')
  const [observation, setObservation] = useState('')
  const [reactivate, setReactivate] = useState(card.status === 'suspended' && card.suspension_reason === 'debt')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [duplicateWarning, setDuplicateWarning] = useState('')

  const canReactivate = card.status === 'suspended' && card.suspension_reason === 'debt'

  const submit = async (event?: FormEvent, confirmDuplicate = false) => {
    event?.preventDefault()
    setSaving(true)
    setError('')
    try {
      await api.post('/payments', {
        client_id: card.client_id,
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

  return <div className="modal-backdrop"><section className="modal-card"><header><div><span className="eyebrow">Pago</span><h2>{card.client_name}</h2></div><button className="modal-close" onClick={onClose}><X /></button></header>{error && <div className="alert alert-error">{error}</div>}{duplicateWarning && <div className="alert alert-warning duplicate-warning"><AlertTriangle size={18} /><span>{duplicateWarning}</span><button className="button button-secondary button-fit" disabled={saving} onClick={() => void submit(undefined, true)}>Registrar de todos modos</button></div>}<form onSubmit={(event) => void submit(event)}><label className="field"><span>Monto *</span><input required type="number" min="0.01" step="0.01" value={amount} onChange={(event) => setAmount(event.target.value)} /></label><label className="field"><span>Fecha de pago *</span><input required type="date" value={paidAt} onChange={(event) => setPaidAt(event.target.value)} /></label><label className="field"><span>Metodo *</span><select required value={method} onChange={(event) => setMethod(event.target.value as PaymentMethod)}><option value="cash">Efectivo</option><option value="transfer">Transferencia</option><option value="other">Otro</option></select></label>{canReactivate && <label className="toggle-field"><input type="checkbox" checked={reactivate} onChange={(event) => setReactivate(event.target.checked)} /><span>Reactivar servicio suspendido por mora</span></label>}<label className="field"><span>Observacion</span><textarea maxLength={1000} value={observation} onChange={(event) => setObservation(event.target.value)} /></label><footer className="form-actions"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={saving}>{saving ? 'Guardando...' : 'Guardar pago'}</button></footer></form></section></div>
}

function NoticeClientDetailModal({ clientId, onClose }: { clientId: number; onClose: () => void }) {
  const [client, setClient] = useState<ClientDetail | null>(null)
  const [logs, setLogs] = useState<NotificationLog[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const load = async () => {
      setLoading(true)
      try {
        const [clientResponse, logsResponse] = await Promise.all([
          api.get<{ data: ClientDetail }>(`/clients/${clientId}`),
          api.get<{ data: NotificationLog[] }>(`/clients/${clientId}/notification-logs`),
        ])
        setClient(clientResponse.data.data)
        setLogs(logsResponse.data.data)
      } finally {
        setLoading(false)
      }
    }
    void load()
  }, [clientId])

  return <div className="modal-backdrop"><section className="modal-card modal-wide"><header><div><span className="eyebrow">Cliente</span><h2>{client?.full_name ?? 'Detalle'}</h2></div><button className="modal-close" onClick={onClose}><X /></button></header>{loading || !client ? <div className="table-message compact">Cargando cliente...</div> : <><div className="service-summary"><div><span>Telefono</span><strong>{client.phone}</strong></div><div><span>Zona</span><strong>{client.zone.name}</strong></div><div><span>Plan</span><strong>{client.internet_service?.plan.name ?? 'Sin servicio'}</strong></div><div><span>Vencimiento</span><strong>{formatDate(client.internet_service?.next_due_date)}</strong></div></div><section className="sync-list"><h3>Notificaciones</h3>{logs.length ? logs.map((log) => <article key={log.id}><div><strong>{log.template?.name ?? typeLabels[log.type]}</strong><span>{formatDateTime(log.sent_at)} - {log.user?.name ?? 'Sistema'}</span><small>{log.message}</small></div><span className="status-badge active">WhatsApp</span></article>) : <p>Sin notificaciones registradas.</p>}</section><section className="sync-list"><h3>Historial</h3>{client.internet_service?.histories?.length ? client.internet_service.histories.map((history) => <article key={history.id}><div><strong>{history.description}</strong><span>{formatDateTime(history.occurred_at)} - {history.user?.name ?? 'Sistema'}</span></div></article>) : <p>Sin eventos registrados.</p>}</section></>}</section></div>
}
