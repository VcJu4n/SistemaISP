import { ArrowRightLeft, Eye, PauseCircle, PlayCircle, Plus, RadioTower, Search, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { api, getApiError } from '../lib/api'

type Zone = { id: number; name: string }
type Client = { id: number; full_name: string; document: string; phone: string; zone_id: number; zone: Zone }
type Plan = { id: number; name: string; download_mbps: number; upload_mbps: number; monthly_price: string | null; active: boolean; zones: Zone[] }
type History = { id: number; event_type: string; description: string; metadata: Record<string, unknown> | null; occurred_at: string }
type Service = { id: number; client_id: number; plan_id: number; status: 'active' | 'suspended'; installation_date: string | null; notes: string | null; suspended_at: string | null; suspension_reason: string | null; suspension_notes: string | null; client: Client; plan: Plan; histories?: History[] }
type Meta = { current_page: number; last_page: number; total: number }

export function ServicesPage() {
  const [services, setServices] = useState<Service[]>([])
  const [zones, setZones] = useState<Zone[]>([])
  const [plans, setPlans] = useState<Plan[]>([])
  const [meta, setMeta] = useState<Meta>({ current_page: 1, last_page: 1, total: 0 })
  const [search, setSearch] = useState(''); const [debounced, setDebounced] = useState('')
  const [status, setStatus] = useState(''); const [zoneId, setZoneId] = useState(''); const [planId, setPlanId] = useState(''); const [page, setPage] = useState(1)
  const [modal, setModal] = useState<'new' | null>(null)
  const [detail, setDetail] = useState<Service | null>(null)
  const [action, setAction] = useState<{ type: 'suspend' | 'reactivate' | 'plan'; service: Service } | null>(null)
  const [message, setMessage] = useState(''); const [loading, setLoading] = useState(true)

  useEffect(() => { const timer = setTimeout(() => { setDebounced(search); setPage(1) }, 300); return () => clearTimeout(timer) }, [search])
  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [serviceResponse, zoneResponse, planResponse] = await Promise.all([
        api.get<{ data: Service[]; meta: Meta }>('/services', { params: { search: debounced || undefined, status: status || undefined, zone_id: zoneId || undefined, plan_id: planId || undefined, page } }),
        api.get<{ data: Zone[] }>('/zones', { params: { all: 1, active: 1 } }),
        api.get<{ data: Plan[] }>('/plans', { params: { all: 1 } }),
      ])
      setServices(serviceResponse.data.data); setMeta(serviceResponse.data.meta); setZones(zoneResponse.data.data); setPlans(planResponse.data.data)
    } finally { setLoading(false) }
  }, [debounced, page, planId, status, zoneId])
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const saved = async (text: string) => { setModal(null); setAction(null); setDetail(null); setMessage(text); await load() }
  const openDetail = async (service: Service) => {
    const response = await api.get<{ data: Service }>(`/services/${service.id}`)
    setDetail(response.data.data)
  }

  return <section className="page-content services-page">
    <div className="page-heading"><div><span className="eyebrow">Conexiones</span><h1>Servicios</h1><p>{meta.total} servicio{meta.total === 1 ? '' : 's'} registrado{meta.total === 1 ? '' : 's'}</p></div><button className="button button-primary button-fit" onClick={() => setModal('new')}><Plus size={18} /> Asignar servicio</button></div>
    {message && <div className="alert alert-success dismissible">{message}<button onClick={() => setMessage('')}><X size={16} /></button></div>}
    <div className="service-filters"><label className="search-box"><Search size={18} /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Buscar cliente, cédula o teléfono" /></label><select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}><option value="">Todos los estados</option><option value="active">Activos</option><option value="suspended">Suspendidos</option></select><select value={zoneId} onChange={(e) => { setZoneId(e.target.value); setPage(1) }}><option value="">Todas las zonas</option>{zones.map((zone) => <option value={zone.id} key={zone.id}>{zone.name}</option>)}</select><select value={planId} onChange={(e) => { setPlanId(e.target.value); setPage(1) }}><option value="">Todos los planes</option>{plans.map((plan) => <option value={plan.id} key={plan.id}>{plan.name}</option>)}</select></div>
    <div className="table-card">{loading ? <div className="table-message">Cargando servicios…</div> : services.length === 0 ? <div className="table-message"><RadioTower /><strong>No se encontraron servicios</strong></div> : <div className="table-scroll"><table><thead><tr><th>Cliente</th><th>Plan</th><th>Velocidad</th><th>Zona</th><th>Estado</th><th aria-label="Acciones" /></tr></thead><tbody>{services.map((service) => <tr key={service.id}><td><button className="client-name" onClick={() => void openDetail(service)}>{service.client.full_name}<small>Servicio #{service.id}</small></button></td><td>{service.plan.name}</td><td>{service.plan.download_mbps}/{service.plan.upload_mbps} Mbps</td><td>{service.client.zone.name}</td><td><span className={`status-badge ${service.status}`}>{service.status === 'active' ? 'Activo' : 'Suspendido'}</span></td><td><div className="row-actions"><button title="Detalle" onClick={() => void openDetail(service)}><Eye size={17} /></button><button title="Cambiar plan" onClick={() => setAction({ type: 'plan', service })}><ArrowRightLeft size={17} /></button>{service.status === 'active' ? <button className="danger" title="Suspender" onClick={() => setAction({ type: 'suspend', service })}><PauseCircle size={17} /></button> : <button title="Reactivar" onClick={() => setAction({ type: 'reactivate', service })}><PlayCircle size={17} /></button>}</div></td></tr>)}</tbody></table></div>}<footer className="pagination"><span>Página {meta.current_page} de {meta.last_page}</span><div><button disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Anterior</button><button disabled={page >= meta.last_page} onClick={() => setPage((value) => value + 1)}>Siguiente</button></div></footer></div>
    {modal === 'new' && <NewServiceModal onClose={() => setModal(null)} onSaved={saved} />}
    {detail && <ServiceDetailModal service={detail} onClose={() => setDetail(null)} onAction={(type) => { setDetail(null); setAction({ type, service: detail }) }} />}
    {action?.type === 'suspend' && <SuspendModal service={action.service} onClose={() => setAction(null)} onSaved={saved} />}
    {action?.type === 'reactivate' && <ReactivateModal service={action.service} onClose={() => setAction(null)} onSaved={saved} />}
    {action?.type === 'plan' && <ChangePlanModal service={action.service} plans={plans} onClose={() => setAction(null)} onSaved={saved} />}
  </section>
}

function NewServiceModal({ onClose, onSaved }: { onClose: () => void; onSaved: (text: string) => Promise<void> }) {
  const [clients, setClients] = useState<Client[]>([]); const [plans, setPlans] = useState<Plan[]>([])
  const [clientId, setClientId] = useState(''); const [planId, setPlanId] = useState(''); const [date, setDate] = useState(''); const [notes, setNotes] = useState('')
  const [error, setError] = useState(''); const [saving, setSaving] = useState(false)
  useEffect(() => { const load = async () => { const response = await api.get<{ data: Client[] }>('/clients', { params: { all: 1, without_service: 1, status: 'active' } }); setClients(response.data.data) }; void load() }, [])
  const client = clients.find((item) => item.id === Number(clientId))
  useEffect(() => { if (!client) return; const load = async () => { const response = await api.get<{ data: Plan[] }>('/plans', { params: { all: 1, active: 1, zone_id: client.zone_id } }); setPlans(response.data.data) }; void load() }, [client])
  const submit = async (event: FormEvent) => { event.preventDefault(); setSaving(true); setError(''); try { await api.post('/services', { client_id: Number(clientId), plan_id: Number(planId), installation_date: date || null, notes: notes || null }); await onSaved('Servicio asignado correctamente.') } catch (requestError) { setError(getApiError(requestError)) } finally { setSaving(false) } }
  return <div className="modal-backdrop"><section className="modal-card"><header><div><span className="eyebrow">Nuevo servicio</span><h2>Asignar plan a cliente</h2></div><button className="modal-close" onClick={onClose}><X /></button></header>{error && <div className="alert alert-error">{error}</div>}<form onSubmit={submit}><label className="field"><span>Cliente *</span><select required value={clientId} onChange={(e) => { setClientId(e.target.value); setPlanId(''); setPlans([]) }}><option value="">Selecciona un cliente</option>{clients.map((item) => <option value={item.id} key={item.id}>{item.full_name} · {item.zone.name}</option>)}</select></label><label className="field"><span>Plan disponible *</span><select required disabled={!client} value={planId} onChange={(e) => setPlanId(e.target.value)}><option value="">Selecciona un plan</option>{plans.map((plan) => <option value={plan.id} key={plan.id}>{plan.name} · {plan.download_mbps}/{plan.upload_mbps} Mbps</option>)}</select></label><label className="field"><span>Fecha de instalación</span><input type="date" value={date} onChange={(e) => setDate(e.target.value)} /></label><label className="field"><span>Observaciones</span><textarea maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} /></label><footer className="form-actions"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={saving}>{saving ? 'Guardando…' : 'Asignar servicio'}</button></footer></form></section></div>
}

function ServiceDetailModal({ service, onClose, onAction }: { service: Service; onClose: () => void; onAction: (type: 'suspend' | 'reactivate' | 'plan') => void }) {
  return <div className="modal-backdrop"><section className="modal-card modal-wide"><header><div><span className="eyebrow">Servicio #{service.id}</span><h2>{service.client.full_name}</h2></div><button className="modal-close" onClick={onClose}><X /></button></header><div className="service-summary"><div><span>Plan actual</span><strong>{service.plan.name}</strong></div><div><span>Velocidad</span><strong>{service.plan.download_mbps}/{service.plan.upload_mbps} Mbps</strong></div><div><span>Zona</span><strong>{service.client.zone.name}</strong></div><div><span>Estado</span><span className={`status-badge ${service.status}`}>{service.status === 'active' ? 'Activo' : 'Suspendido'}</span></div></div><h3>Historial técnico</h3><div className="timeline">{service.histories?.length ? service.histories.map((history) => <article key={history.id}><i /><div><strong>{history.description}</strong><time>{new Date(history.occurred_at).toLocaleString('es-BO')}</time></div></article>) : <p>Sin eventos registrados.</p>}</div><footer className="form-actions"><button className="button button-secondary" onClick={onClose}>Cerrar</button><button className="button button-secondary" onClick={() => onAction('plan')}><ArrowRightLeft size={16} /> Cambiar plan</button>{service.status === 'active' ? <button className="button button-danger" onClick={() => onAction('suspend')}>Suspender</button> : <button className="button button-primary button-fit" onClick={() => onAction('reactivate')}>Reactivar</button>}</footer></section></div>
}

function SuspendModal({ service, onClose, onSaved }: { service: Service; onClose: () => void; onSaved: (text: string) => Promise<void> }) {
  const [reason, setReason] = useState(''); const [notes, setNotes] = useState(''); const [error, setError] = useState(''); const [saving, setSaving] = useState(false)
  const submit = async (event: FormEvent) => { event.preventDefault(); setSaving(true); setError(''); try { await api.post(`/services/${service.id}/suspend`, { reason, notes: notes || null }); await onSaved('Servicio suspendido correctamente.') } catch (requestError) { setError(getApiError(requestError)) } finally { setSaving(false) } }
  return <div className="modal-backdrop"><section className="modal-card"><header><div><span className="eyebrow">Suspensión</span><h2>Suspender servicio de {service.client.full_name}</h2></div><button className="modal-close" onClick={onClose}><X /></button></header>{error && <div className="alert alert-error">{error}</div>}<form onSubmit={submit}><label className="field"><span>Motivo *</span><select required value={reason} onChange={(e) => setReason(e.target.value)}><option value="">Selecciona un motivo</option><option value="debt">Mora</option><option value="client_request">Solicitud del cliente</option><option value="technical">Motivo técnico</option><option value="other">Otro</option></select></label><label className="field"><span>Detalle {reason === 'other' && '*'}</span><textarea required={reason === 'other'} maxLength={1000} value={notes} onChange={(e) => setNotes(e.target.value)} /></label><footer className="form-actions"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-danger" disabled={saving}>{saving ? 'Suspendiendo…' : 'Confirmar suspensión'}</button></footer></form></section></div>
}

function ReactivateModal({ service, onClose, onSaved }: { service: Service; onClose: () => void; onSaved: (text: string) => Promise<void> }) {
  const [saving, setSaving] = useState(false); const [error, setError] = useState('')
  const confirm = async () => { setSaving(true); setError(''); try { await api.post(`/services/${service.id}/reactivate`); await onSaved('Servicio reactivado correctamente.') } catch (requestError) { setError(getApiError(requestError)); setSaving(false) } }
  return <div className="modal-backdrop"><section className="modal-card confirm-card"><div className="success-icon"><PlayCircle /></div><h2>¿Reactivar a {service.client.full_name}?</h2><p>Confirma que la causa de la suspensión fue resuelta.</p>{error && <div className="alert alert-error">{error}</div>}<footer className="form-actions"><button className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={saving} onClick={() => void confirm()}>{saving ? 'Reactivando…' : 'Sí, reactivar'}</button></footer></section></div>
}

function ChangePlanModal({ service, plans, onClose, onSaved }: { service: Service; plans: Plan[]; onClose: () => void; onSaved: (text: string) => Promise<void> }) {
  const available = useMemo(() => plans.filter((plan) => plan.active && plan.id !== service.plan_id && plan.zones.some((zone) => zone.id === service.client.zone_id)), [plans, service])
  const [planId, setPlanId] = useState(''); const [error, setError] = useState(''); const [saving, setSaving] = useState(false)
  const submit = async (event: FormEvent) => { event.preventDefault(); setSaving(true); setError(''); try { await api.put(`/services/${service.id}/plan`, { plan_id: Number(planId) }); await onSaved('Plan cambiado correctamente.') } catch (requestError) { setError(getApiError(requestError)) } finally { setSaving(false) } }
  return <div className="modal-backdrop"><section className="modal-card"><header><div><span className="eyebrow">Cambio de plan</span><h2>{service.client.full_name}</h2></div><button className="modal-close" onClick={onClose}><X /></button></header><p className="current-plan">Plan actual: <strong>{service.plan.name}</strong></p>{error && <div className="alert alert-error">{error}</div>}<form onSubmit={submit}><label className="field"><span>Nuevo plan *</span><select required value={planId} onChange={(e) => setPlanId(e.target.value)}><option value="">Selecciona un plan</option>{available.map((plan) => <option value={plan.id} key={plan.id}>{plan.name} · {plan.download_mbps}/{plan.upload_mbps} Mbps</option>)}</select></label>{available.length === 0 && <div className="alert alert-error">No hay otros planes activos disponibles en esta zona.</div>}<footer className="form-actions"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={saving || available.length === 0}>{saving ? 'Cambiando…' : 'Cambiar plan'}</button></footer></form></section></div>
}
