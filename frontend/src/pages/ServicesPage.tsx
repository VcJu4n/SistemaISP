import { ArrowRightLeft, Eye, PauseCircle, PlayCircle, Plus, RadioTower, Router, Search, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { api, getApiError } from '../lib/api'

type Zone = { id: number; name: string }
type Client = { id: number; full_name: string; document: string; phone: string; zone_id: number; zone: Zone }
type Plan = { id: number; name: string; download_mbps: number; upload_mbps: number; monthly_price: string | null; active: boolean; zones: Zone[] }
type History = { id: number; event_type: string; description: string; metadata: Record<string, unknown> | null; occurred_at: string }
type MikrotikRouter = { id: number; name: string; connection_status: 'pending' | 'connected' | 'disconnected'; active: boolean }
type ControlMethod = 'manual' | 'pppoe' | 'simple_queue'
type Service = { id: number; client_id: number; plan_id: number; status: 'active' | 'suspended'; installation_date: string | null; notes: string | null; suspended_at: string | null; suspension_reason: string | null; suspension_notes: string | null; mikrotik_router_id: number | null; mikrotik_control_method: ControlMethod; pppoe_username: string | null; pppoe_profile: string | null; simple_queue_name: string | null; service_ip_address: string | null; service_mac_address: string | null; client_antenna_ip: string | null; client_antenna_mac: string | null; client_antenna_brand_model: string | null; client_antenna_device_name: string | null; technical_notes: string | null; client: Client; plan: Plan; mikrotik_router?: MikrotikRouter | null; histories?: History[] }
type Meta = { current_page: number; last_page: number; total: number }
type Action = { type: 'suspend' | 'reactivate' | 'plan' | 'technical'; service: Service }
type TechnicalForm = { mikrotik_router_id: string; mikrotik_control_method: ControlMethod; pppoe_username: string; pppoe_profile: string; simple_queue_name: string; service_ip_address: string; service_mac_address: string; client_antenna_ip: string; client_antenna_mac: string; client_antenna_brand_model: string; client_antenna_device_name: string; technical_notes: string }

export function ServicesPage() {
  const [services, setServices] = useState<Service[]>([])
  const [zones, setZones] = useState<Zone[]>([])
  const [plans, setPlans] = useState<Plan[]>([])
  const [meta, setMeta] = useState<Meta>({ current_page: 1, last_page: 1, total: 0 })
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [status, setStatus] = useState('')
  const [zoneId, setZoneId] = useState('')
  const [planId, setPlanId] = useState('')
  const [page, setPage] = useState(1)
  const [modal, setModal] = useState<'new' | null>(null)
  const [detail, setDetail] = useState<Service | null>(null)
  const [action, setAction] = useState<Action | null>(null)
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    const timer = setTimeout(() => { setDebounced(search); setPage(1) }, 300)
    return () => clearTimeout(timer)
  }, [search])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [serviceResponse, zoneResponse, planResponse] = await Promise.all([
        api.get<{ data: Service[]; meta: Meta }>('/services', { params: { search: debounced || undefined, status: status || undefined, zone_id: zoneId || undefined, plan_id: planId || undefined, page } }),
        api.get<{ data: Zone[] }>('/zones', { params: { all: 1, active: 1 } }),
        api.get<{ data: Plan[] }>('/plans', { params: { all: 1 } }),
      ])
      setServices(serviceResponse.data.data)
      setMeta(serviceResponse.data.meta)
      setZones(zoneResponse.data.data)
      setPlans(planResponse.data.data)
    } finally {
      setLoading(false)
    }
  }, [debounced, page, planId, status, zoneId])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const saved = async (text: string) => {
    setModal(null)
    setAction(null)
    setDetail(null)
    setMessage(text)
    await load()
  }

  const openDetail = async (service: Service) => {
    const response = await api.get<{ data: Service }>(`/services/${service.id}`)
    setDetail(response.data.data)
  }

  return (
    <section className="page-content services-page">
      <div className="page-heading">
        <div><span className="eyebrow">Conexiones</span><h1>Servicios</h1><p>{meta.total} servicio{meta.total === 1 ? '' : 's'} registrado{meta.total === 1 ? '' : 's'}</p></div>
        <button className="button button-primary button-fit" onClick={() => setModal('new')}><Plus size={18} /> Asignar servicio</button>
      </div>

      {message && <div className="alert alert-success dismissible">{message}<button onClick={() => setMessage('')}><X size={16} /></button></div>}

      <div className="service-filters">
        <label className="search-box"><Search size={18} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar cliente, cedula o telefono" /></label>
        <select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }}><option value="">Todos los estados</option><option value="active">Activos</option><option value="suspended">Suspendidos</option></select>
        <select value={zoneId} onChange={(event) => { setZoneId(event.target.value); setPage(1) }}><option value="">Todas las zonas</option>{zones.map((zone) => <option value={zone.id} key={zone.id}>{zone.name}</option>)}</select>
        <select value={planId} onChange={(event) => { setPlanId(event.target.value); setPage(1) }}><option value="">Todos los planes</option>{plans.map((plan) => <option value={plan.id} key={plan.id}>{plan.name}</option>)}</select>
      </div>

      <div className="table-card">
        {loading ? <div className="table-message">Cargando servicios...</div> : services.length === 0 ? (
          <div className="table-message"><RadioTower /><strong>No se encontraron servicios</strong></div>
        ) : (
          <div className="table-scroll">
            <table>
              <thead><tr><th>Cliente</th><th>Plan</th><th>Velocidad</th><th>Zona</th><th>MikroTik</th><th>Estado</th><th aria-label="Acciones" /></tr></thead>
              <tbody>{services.map((service) => <tr key={service.id}>
                <td><button className="client-name" onClick={() => void openDetail(service)}>{service.client.full_name}<small>Servicio #{service.id}</small></button></td>
                <td>{service.plan.name}</td>
                <td>{service.plan.download_mbps}/{service.plan.upload_mbps} Mbps</td>
                <td>{service.client.zone.name}</td>
                <td><TechnicalMethodBadge service={service} /></td>
                <td><span className={`status-badge ${service.status}`}>{service.status === 'active' ? 'Activo' : 'Suspendido'}</span></td>
                <td><div className="row-actions"><button title="Detalle" onClick={() => void openDetail(service)}><Eye size={17} /></button><button title="Configurar MikroTik" onClick={() => setAction({ type: 'technical', service })}><Router size={17} /></button><button title="Cambiar plan" onClick={() => setAction({ type: 'plan', service })}><ArrowRightLeft size={17} /></button>{service.status === 'active' ? <button className="danger" title="Suspender" onClick={() => setAction({ type: 'suspend', service })}><PauseCircle size={17} /></button> : <button title="Reactivar" onClick={() => setAction({ type: 'reactivate', service })}><PlayCircle size={17} /></button>}</div></td>
              </tr>)}</tbody>
            </table>
          </div>
        )}
        <footer className="pagination"><span>Pagina {meta.current_page} de {meta.last_page}</span><div><button disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Anterior</button><button disabled={page >= meta.last_page} onClick={() => setPage((value) => value + 1)}>Siguiente</button></div></footer>
      </div>

      {modal === 'new' && <NewServiceModal onClose={() => setModal(null)} onSaved={saved} />}
      {detail && <ServiceDetailModal service={detail} onClose={() => setDetail(null)} onAction={(type) => { setDetail(null); setAction({ type, service: detail }) }} />}
      {action?.type === 'suspend' && <SuspendModal service={action.service} onClose={() => setAction(null)} onSaved={saved} />}
      {action?.type === 'reactivate' && <ReactivateModal service={action.service} onClose={() => setAction(null)} onSaved={saved} />}
      {action?.type === 'plan' && <ChangePlanModal service={action.service} plans={plans} onClose={() => setAction(null)} onSaved={saved} />}
      {action?.type === 'technical' && <TechnicalConfigModal service={action.service} onClose={() => setAction(null)} onSaved={saved} />}
    </section>
  )
}

function TechnicalMethodBadge({ service }: { service: Service }) {
  if (service.mikrotik_control_method === 'pppoe') return <div className="technical-badge"><span className="status-badge connected">PPPoE</span><small>{service.pppoe_username}</small></div>
  if (service.mikrotik_control_method === 'simple_queue') return <div className="technical-badge"><span className="status-badge pending">Simple Queue</span><small>{service.service_ip_address}</small></div>
  return <div className="technical-badge"><span className="status-badge suspended">Manual</span><small>Sin sincronizacion</small></div>
}

function initialTechnicalForm(service?: Service | null): TechnicalForm {
  return {
    mikrotik_router_id: service?.mikrotik_router_id ? String(service.mikrotik_router_id) : '',
    mikrotik_control_method: service?.mikrotik_control_method ?? 'manual',
    pppoe_username: service?.pppoe_username ?? '',
    pppoe_profile: service?.pppoe_profile ?? '',
    simple_queue_name: service?.simple_queue_name ?? '',
    service_ip_address: service?.service_ip_address ?? '',
    service_mac_address: service?.service_mac_address ?? '',
    client_antenna_ip: service?.client_antenna_ip ?? '',
    client_antenna_mac: service?.client_antenna_mac ?? '',
    client_antenna_brand_model: service?.client_antenna_brand_model ?? '',
    client_antenna_device_name: service?.client_antenna_device_name ?? '',
    technical_notes: service?.technical_notes ?? '',
  }
}

function technicalPayload(form: TechnicalForm) {
  return {
    mikrotik_router_id: form.mikrotik_control_method === 'manual' ? null : Number(form.mikrotik_router_id),
    mikrotik_control_method: form.mikrotik_control_method,
    pppoe_username: form.pppoe_username || null,
    pppoe_profile: form.pppoe_profile || null,
    simple_queue_name: form.simple_queue_name || null,
    service_ip_address: form.service_ip_address || null,
    service_mac_address: form.service_mac_address || null,
    client_antenna_ip: form.client_antenna_ip || null,
    client_antenna_mac: form.client_antenna_mac || null,
    client_antenna_brand_model: form.client_antenna_brand_model || null,
    client_antenna_device_name: form.client_antenna_device_name || null,
    technical_notes: form.technical_notes || null,
  }
}

function NewServiceModal({ onClose, onSaved }: { onClose: () => void; onSaved: (text: string) => Promise<void> }) {
  const [clients, setClients] = useState<Client[]>([])
  const [plans, setPlans] = useState<Plan[]>([])
  const [routers, setRouters] = useState<MikrotikRouter[]>([])
  const [clientId, setClientId] = useState('')
  const [planId, setPlanId] = useState('')
  const [date, setDate] = useState('')
  const [notes, setNotes] = useState('')
  const [technical, setTechnical] = useState<TechnicalForm>(initialTechnicalForm())
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    const load = async () => {
      const [clientResponse, routerResponse] = await Promise.all([
        api.get<{ data: Client[] }>('/clients', { params: { all: 1, without_service: 1, status: 'active' } }),
        api.get<{ data: MikrotikRouter[] }>('/mikrotik-routers', { params: { all: 1, active: 1 } }),
      ])
      setClients(clientResponse.data.data)
      setRouters(routerResponse.data.data)
    }
    void load()
  }, [])

  const client = clients.find((item) => item.id === Number(clientId))
  const selectedPlan = plans.find((plan) => plan.id === Number(planId)) ?? null

  useEffect(() => {
    if (!client) return
    const load = async () => {
      const response = await api.get<{ data: Plan[] }>('/plans', { params: { all: 1, active: 1, zone_id: client.zone_id } })
      setPlans(response.data.data)
    }
    void load()
  }, [client])

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      await api.post('/services', { client_id: Number(clientId), plan_id: Number(planId), installation_date: date || null, notes: notes || null, ...technicalPayload(technical) })
      await onSaved('Servicio asignado correctamente.')
    } catch (requestError) {
      setError(getApiError(requestError))
    } finally {
      setSaving(false)
    }
  }

  return <div className="modal-backdrop"><section className="modal-card modal-wide"><header><div><span className="eyebrow">Nuevo servicio</span><h2>Asignar plan a cliente</h2></div><button className="modal-close" onClick={onClose}><X /></button></header>{error && <div className="alert alert-error">{error}</div>}<form className="client-form" onSubmit={submit}><label className="field"><span>Cliente *</span><select required value={clientId} onChange={(event) => { setClientId(event.target.value); setPlanId(''); setPlans([]) }}><option value="">Selecciona un cliente</option>{clients.map((item) => <option value={item.id} key={item.id}>{item.full_name} - {item.zone.name}</option>)}</select></label><label className="field"><span>Plan disponible *</span><select required disabled={!client} value={planId} onChange={(event) => setPlanId(event.target.value)}><option value="">Selecciona un plan</option>{plans.map((plan) => <option value={plan.id} key={plan.id}>{plan.name} - {plan.download_mbps}/{plan.upload_mbps} Mbps</option>)}</select></label><label className="field"><span>Fecha de instalacion</span><input type="date" value={date} onChange={(event) => setDate(event.target.value)} /></label><label className="field"><span>Observaciones</span><textarea maxLength={1000} value={notes} onChange={(event) => setNotes(event.target.value)} /></label><TechnicalConfigFields form={technical} setForm={setTechnical} routers={routers} plan={selectedPlan} /><footer className="form-actions full"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={saving}>{saving ? 'Guardando...' : 'Asignar servicio'}</button></footer></form></section></div>
}

function TechnicalConfigFields({ form, setForm, routers, plan }: { form: TechnicalForm; setForm: (form: TechnicalForm) => void; routers: MikrotikRouter[]; plan?: Plan | null }) {
  const managed = form.mikrotik_control_method !== 'manual'

  return <>
    <div className="technical-section full">
      <span className="eyebrow">Configuracion tecnica</span>
      <div className="method-options">
        <label><input type="radio" checked={form.mikrotik_control_method === 'manual'} onChange={() => setForm({ ...form, mikrotik_control_method: 'manual', mikrotik_router_id: '', pppoe_username: '', pppoe_profile: '', simple_queue_name: '', service_ip_address: '' })} /> Manual</label>
        <label><input type="radio" checked={form.mikrotik_control_method === 'pppoe'} onChange={() => setForm({ ...form, mikrotik_control_method: 'pppoe', simple_queue_name: '', service_ip_address: '' })} /> PPPoE</label>
        <label><input type="radio" checked={form.mikrotik_control_method === 'simple_queue'} onChange={() => setForm({ ...form, mikrotik_control_method: 'simple_queue', pppoe_username: '', pppoe_profile: '' })} /> Simple Queue</label>
      </div>
    </div>
    {managed && <label className="field full"><span>Router MikroTik *</span><select required value={form.mikrotik_router_id} onChange={(event) => setForm({ ...form, mikrotik_router_id: event.target.value })}><option value="">Selecciona un router activo</option>{routers.map((router) => <option key={router.id} value={router.id}>{router.name} - {router.connection_status}</option>)}</select></label>}
    {form.mikrotik_control_method === 'pppoe' && <><label className="field"><span>Usuario PPPoE *</span><input required maxLength={100} placeholder="juan.perez" value={form.pppoe_username} onChange={(event) => setForm({ ...form, pppoe_username: event.target.value })} /></label><label className="field"><span>Perfil PPPoE *</span><input required maxLength={100} placeholder="plan-30m" value={form.pppoe_profile} onChange={(event) => setForm({ ...form, pppoe_profile: event.target.value })} /></label></>}
    {form.mikrotik_control_method === 'simple_queue' && <><label className="field"><span>IP del cliente *</span><input required placeholder="192.168.10.20" value={form.service_ip_address} onChange={(event) => setForm({ ...form, service_ip_address: event.target.value })} /></label><label className="field"><span>Nombre de cola *</span><input required maxLength={100} placeholder="cliente-juan" value={form.simple_queue_name} onChange={(event) => setForm({ ...form, simple_queue_name: event.target.value })} /></label><div className="technical-speed full"><span>Velocidad MikroTik</span><strong>{plan ? `${plan.download_mbps}M/${plan.upload_mbps}M` : 'Selecciona un plan'}</strong></div></>}
    <label className="field"><span>MAC del servicio</span><input placeholder="AA:BB:CC:DD:EE:01" value={form.service_mac_address} onChange={(event) => setForm({ ...form, service_mac_address: event.target.value })} /></label>
    <label className="field"><span>IP de antena</span><input placeholder="192.168.20.10" value={form.client_antenna_ip} onChange={(event) => setForm({ ...form, client_antenna_ip: event.target.value })} /></label>
    <label className="field"><span>MAC de antena</span><input placeholder="AA:BB:CC:DD:EE:02" value={form.client_antenna_mac} onChange={(event) => setForm({ ...form, client_antenna_mac: event.target.value })} /></label>
    <label className="field"><span>Marca/modelo antena</span><input maxLength={255} placeholder="Ubiquiti LiteBeam" value={form.client_antenna_brand_model} onChange={(event) => setForm({ ...form, client_antenna_brand_model: event.target.value })} /></label>
    <label className="field"><span>Nombre dispositivo</span><input maxLength={255} placeholder="antena-juan" value={form.client_antenna_device_name} onChange={(event) => setForm({ ...form, client_antenna_device_name: event.target.value })} /></label>
    <label className="field full"><span>Comentario tecnico</span><textarea maxLength={1000} value={form.technical_notes} onChange={(event) => setForm({ ...form, technical_notes: event.target.value })} /></label>
  </>
}

function ServiceDetailModal({ service, onClose, onAction }: { service: Service; onClose: () => void; onAction: (type: Action['type']) => void }) {
  return <div className="modal-backdrop"><section className="modal-card modal-wide"><header><div><span className="eyebrow">Servicio #{service.id}</span><h2>{service.client.full_name}</h2></div><button className="modal-close" onClick={onClose}><X /></button></header><div className="service-summary"><div><span>Plan actual</span><strong>{service.plan.name}</strong></div><div><span>Velocidad</span><strong>{service.plan.download_mbps}/{service.plan.upload_mbps} Mbps</strong></div><div><span>Zona</span><strong>{service.client.zone.name}</strong></div><div><span>Estado</span><span className={`status-badge ${service.status}`}>{service.status === 'active' ? 'Activo' : 'Suspendido'}</span></div></div><TechnicalSummary service={service} /><h3>Historial tecnico</h3><div className="timeline">{service.histories?.length ? service.histories.map((history) => <article key={history.id}><i /><div><strong>{history.description}</strong><time>{new Date(history.occurred_at).toLocaleString('es-BO')}</time></div></article>) : <p>Sin eventos registrados.</p>}</div><footer className="form-actions"><button className="button button-secondary" onClick={onClose}>Cerrar</button><button className="button button-secondary" onClick={() => onAction('technical')}><Router size={16} /> MikroTik</button><button className="button button-secondary" onClick={() => onAction('plan')}><ArrowRightLeft size={16} /> Cambiar plan</button>{service.status === 'active' ? <button className="button button-danger" onClick={() => onAction('suspend')}>Suspender</button> : <button className="button button-primary button-fit" onClick={() => onAction('reactivate')}>Reactivar</button>}</footer></section></div>
}

function TechnicalSummary({ service }: { service: Service }) {
  return <section className="technical-summary"><h3>Configuracion MikroTik</h3><div><span>Metodo</span><strong>{service.mikrotik_control_method === 'pppoe' ? 'PPPoE' : service.mikrotik_control_method === 'simple_queue' ? 'Simple Queue' : 'Manual'}</strong></div><div><span>Router</span><strong>{service.mikrotik_router?.name ?? 'Sin router'}</strong></div>{service.mikrotik_control_method === 'pppoe' && <><div><span>Usuario PPPoE</span><strong>{service.pppoe_username}</strong></div><div><span>Perfil</span><strong>{service.pppoe_profile}</strong></div></>}{service.mikrotik_control_method === 'simple_queue' && <><div><span>IP cliente</span><strong>{service.service_ip_address}</strong></div><div><span>Cola</span><strong>{service.simple_queue_name}</strong></div><div><span>Velocidad</span><strong>{service.plan.download_mbps}M/{service.plan.upload_mbps}M</strong></div></>}<div><span>MAC servicio</span><strong>{service.service_mac_address ?? 'Sin MAC'}</strong></div><div><span>Antena</span><strong>{service.client_antenna_device_name || service.client_antenna_ip || 'Sin datos'}</strong></div>{service.technical_notes && <div className="full"><span>Comentario</span><strong>{service.technical_notes}</strong></div>}</section>
}

function TechnicalConfigModal({ service, onClose, onSaved }: { service: Service; onClose: () => void; onSaved: (text: string) => Promise<void> }) {
  const [routers, setRouters] = useState<MikrotikRouter[]>([])
  const [form, setForm] = useState<TechnicalForm>(initialTechnicalForm(service))
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    const load = async () => {
      const response = await api.get<{ data: MikrotikRouter[] }>('/mikrotik-routers', { params: { all: 1, active: 1 } })
      setRouters(response.data.data)
    }
    void load()
  }, [])

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    setError('')
    try {
      await api.put(`/services/${service.id}/technical-config`, technicalPayload(form))
      await onSaved('Configuracion tecnica actualizada correctamente.')
    } catch (requestError) {
      setError(getApiError(requestError))
    } finally {
      setSaving(false)
    }
  }

  return <div className="modal-backdrop"><section className="modal-card modal-wide"><header><div><span className="eyebrow">HU-026</span><h2>Configurar MikroTik de {service.client.full_name}</h2></div><button className="modal-close" onClick={onClose}><X /></button></header>{error && <div className="alert alert-error">{error}</div>}<form className="client-form" onSubmit={submit}><TechnicalConfigFields form={form} setForm={setForm} routers={routers} plan={service.plan} /><footer className="form-actions full"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={saving}>{saving ? 'Guardando...' : 'Guardar configuracion'}</button></footer></form></section></div>
}

function SuspendModal({ service, onClose, onSaved }: { service: Service; onClose: () => void; onSaved: (text: string) => Promise<void> }) {
  const [reason, setReason] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const submit = async (event: FormEvent) => { event.preventDefault(); setSaving(true); setError(''); try { await api.post(`/services/${service.id}/suspend`, { reason, notes: notes || null }); await onSaved('Servicio suspendido correctamente.') } catch (requestError) { setError(getApiError(requestError)) } finally { setSaving(false) } }
  return <div className="modal-backdrop"><section className="modal-card"><header><div><span className="eyebrow">Suspension</span><h2>Suspender servicio de {service.client.full_name}</h2></div><button className="modal-close" onClick={onClose}><X /></button></header>{error && <div className="alert alert-error">{error}</div>}<form onSubmit={submit}><label className="field"><span>Motivo *</span><select required value={reason} onChange={(event) => setReason(event.target.value)}><option value="">Selecciona un motivo</option><option value="debt">Mora</option><option value="client_request">Solicitud del cliente</option><option value="technical">Motivo tecnico</option><option value="other">Otro</option></select></label><label className="field"><span>Detalle {reason === 'other' && '*'}</span><textarea required={reason === 'other'} maxLength={1000} value={notes} onChange={(event) => setNotes(event.target.value)} /></label><footer className="form-actions"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-danger" disabled={saving}>{saving ? 'Suspendiendo...' : 'Confirmar suspension'}</button></footer></form></section></div>
}

function ReactivateModal({ service, onClose, onSaved }: { service: Service; onClose: () => void; onSaved: (text: string) => Promise<void> }) {
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const confirm = async () => { setSaving(true); setError(''); try { await api.post(`/services/${service.id}/reactivate`); await onSaved('Servicio reactivado correctamente.') } catch (requestError) { setError(getApiError(requestError)); setSaving(false) } }
  return <div className="modal-backdrop"><section className="modal-card confirm-card"><div className="success-icon"><PlayCircle /></div><h2>Reactivar a {service.client.full_name}</h2><p>Confirma que la causa de la suspension fue resuelta.</p>{error && <div className="alert alert-error">{error}</div>}<footer className="form-actions"><button className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={saving} onClick={() => void confirm()}>{saving ? 'Reactivando...' : 'Reactivar'}</button></footer></section></div>
}

function ChangePlanModal({ service, plans, onClose, onSaved }: { service: Service; plans: Plan[]; onClose: () => void; onSaved: (text: string) => Promise<void> }) {
  const available = useMemo(() => plans.filter((plan) => plan.active && plan.id !== service.plan_id && plan.zones.some((zone) => zone.id === service.client.zone_id)), [plans, service])
  const [planId, setPlanId] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const submit = async (event: FormEvent) => { event.preventDefault(); setSaving(true); setError(''); try { await api.put(`/services/${service.id}/plan`, { plan_id: Number(planId) }); await onSaved('Plan cambiado correctamente.') } catch (requestError) { setError(getApiError(requestError)) } finally { setSaving(false) } }
  return <div className="modal-backdrop"><section className="modal-card"><header><div><span className="eyebrow">Cambio de plan</span><h2>{service.client.full_name}</h2></div><button className="modal-close" onClick={onClose}><X /></button></header><p className="current-plan">Plan actual: <strong>{service.plan.name}</strong></p>{error && <div className="alert alert-error">{error}</div>}<form onSubmit={submit}><label className="field"><span>Nuevo plan *</span><select required value={planId} onChange={(event) => setPlanId(event.target.value)}><option value="">Selecciona un plan</option>{available.map((plan) => <option value={plan.id} key={plan.id}>{plan.name} - {plan.download_mbps}/{plan.upload_mbps} Mbps</option>)}</select></label>{available.length === 0 && <div className="alert alert-error">No hay otros planes activos disponibles en esta zona.</div>}<footer className="form-actions"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={saving || available.length === 0}>{saving ? 'Cambiando...' : 'Cambiar plan'}</button></footer></form></section></div>
}
