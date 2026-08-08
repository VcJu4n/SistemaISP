import { Ban, CheckCircle2, Download, Link2, Pencil, Plus, Radar, Router, Search, ShieldCheck, TestTube2, UserPlus, X, XCircle } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { api, getApiError } from '../lib/api'

type ConnectionStatus = 'pending' | 'connected' | 'disconnected'

type MikrotikRouter = {
  id: number
  name: string
  ip_address: string
  api_port: number
  username: string
  use_ssl: boolean
  active: boolean
  connection_status: ConnectionStatus
  last_successful_connection_at: string | null
  last_error: string | null
  services_count?: number
  pending_operations_count?: number
}

type Meta = { current_page: number; last_page: number; total: number }
type SourceType = 'pppoe' | 'simple_queue' | 'dhcp_mac' | 'hotspot'
type CandidateStatus = 'unlinked' | 'linked' | 'ignored'
type MikrotikCandidate = { id: number; source_type: SourceType; access_type: 'antenna' | 'fiber' | null; identifier: string; display_name: string | null; ip_address: string | null; mac_address: string | null; profile: string | null; rate_limit: string | null; status: CandidateStatus; last_seen_at: string; client?: { id: number; full_name: string } | null; internet_service?: { id: number; plan?: { id: number; name: string } | null } | null }
type ClientOption = { id: number; full_name: string; document: string; zone_id: number; internet_service_exists?: boolean; zone: { id: number; name: string } }
type ZoneOption = { id: number; name: string; active: boolean }
type PlanOption = { id: number; name: string; active: boolean; zones: ZoneOption[] }

const statusLabels: Record<ConnectionStatus, string> = {
  pending: 'Pendiente',
  connected: 'Conectado',
  disconnected: 'Desconectado',
}

export function MikrotikRoutersPage() {
  const [routers, setRouters] = useState<MikrotikRouter[]>([])
  const [meta, setMeta] = useState<Meta>({ current_page: 1, last_page: 1, total: 0 })
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [active, setActive] = useState('')
  const [connectionStatus, setConnectionStatus] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<MikrotikRouter | 'new' | null>(null)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [testingId, setTestingId] = useState<number | null>(null)
  const [detectingId, setDetectingId] = useState<number | null>(null)
  const [importRouter, setImportRouter] = useState<MikrotikRouter | null>(null)

  useEffect(() => {
    const timer = setTimeout(() => { setDebounced(search); setPage(1) }, 300)
    return () => clearTimeout(timer)
  }, [search])

  const load = useCallback(async () => {
    setLoading(true)
    setError('')

    try {
      const response = await api.get<{ data: MikrotikRouter[]; meta: Meta }>('/mikrotik-routers', {
        params: {
          search: debounced || undefined,
          active: active || undefined,
          connection_status: connectionStatus || undefined,
          page,
        },
      })

      setRouters(response.data.data)
      setMeta(response.data.meta)
    } catch (requestError) {
      setError(getApiError(requestError))
    } finally {
      setLoading(false)
    }
  }, [active, connectionStatus, debounced, page])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const counters = useMemo(() => ({
    connected: routers.filter((router) => router.connection_status === 'connected').length,
    disconnected: routers.filter((router) => router.connection_status === 'disconnected').length,
    pending: routers.filter((router) => router.connection_status === 'pending').length,
  }), [routers])

  const testConnection = async (router: MikrotikRouter) => {
    setTestingId(router.id)
    setMessage('')
    setError('')

    try {
      const response = await api.post<{ message: string }>('/mikrotik-routers/' + router.id + '/test-connection')
      setMessage(response.data.message)
    } catch (requestError) {
      setError(getApiError(requestError))
    } finally {
      setTestingId(null)
      await load()
    }
  }

  const detectControlMethod = async (router: MikrotikRouter) => {
    setDetectingId(router.id)
    setMessage('')
    setError('')

    try {
      const response = await api.post<{ data: { primary_method: string; counts: Record<string, number> } }>('/mikrotik-routers/' + router.id + '/detect-control-method')
      const counts = response.data.data.counts
      setMessage(`Metodo principal: ${sourceLabel(response.data.data.primary_method)}. PPPoE ${counts.pppoe ?? 0}, Queue ${counts.simple_queue ?? 0}, DHCP ${counts.dhcp_mac ?? 0}, Hotspot ${counts.hotspot ?? 0}.`)
    } catch (requestError) {
      setError(getApiError(requestError))
    } finally {
      setDetectingId(null)
      await load()
    }
  }

  const saved = async (text: string) => {
    setEditing(null)
    setMessage(text)
    setError('')
    await load()
  }

  return (
    <section className="page-content routers-page">
      <div className="page-heading">
        <div>
          <span className="eyebrow">Integracion MikroTik</span>
          <h1>Routers principales</h1>
          <p>{meta.total} router{meta.total === 1 ? '' : 's'} registrado{meta.total === 1 ? '' : 's'}</p>
        </div>
        <button className="button button-primary button-fit" onClick={() => setEditing('new')}>
          <Plus size={18} /> Registrar MikroTik
        </button>
      </div>

      <div className="router-health">
        <article><CheckCircle2 /><div><strong>{counters.connected}</strong><span>Conectados</span></div></article>
        <article><XCircle /><div><strong>{counters.disconnected}</strong><span>Desconectados</span></div></article>
        <article><ShieldCheck /><div><strong>{counters.pending}</strong><span>Pendientes</span></div></article>
      </div>

      {message && <div className="alert alert-success dismissible">{message}<button onClick={() => setMessage('')}><X size={16} /></button></div>}
      {error && <div className="alert alert-error dismissible">{error}<button onClick={() => setError('')}><X size={16} /></button></div>}

      <div className="router-filters">
        <label className="search-box">
          <Search size={18} />
          <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar por nombre o IP" />
        </label>
        <select value={active} onChange={(event) => { setActive(event.target.value); setPage(1) }}>
          <option value="">Todos los estados</option>
          <option value="1">Activos</option>
          <option value="0">Inactivos</option>
        </select>
        <select value={connectionStatus} onChange={(event) => { setConnectionStatus(event.target.value); setPage(1) }}>
          <option value="">Todas las conexiones</option>
          <option value="pending">Pendientes</option>
          <option value="connected">Conectados</option>
          <option value="disconnected">Desconectados</option>
        </select>
      </div>

      <div className="table-card">
        {loading ? <div className="table-message">Cargando routers...</div> : routers.length === 0 ? (
          <div className="table-message"><Router /><strong>No se encontraron routers</strong></div>
        ) : (
          <div className="table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Router</th>
                  <th>Endpoint API</th>
                  <th>Usuario</th>
                  <th>Conexion</th>
                  <th>Servicios</th>
                  <th>Ultimo acceso</th>
                  <th aria-label="Acciones" />
                </tr>
              </thead>
              <tbody>
                {routers.map((router) => (
                  <tr key={router.id}>
                    <td>
                      <div className="router-name">
                        <strong>{router.name}</strong>
                        <span>{router.active ? 'Activo' : 'Inactivo'}</span>
                      </div>
                    </td>
                    <td>
                      <div className="router-endpoint">
                        <strong>{router.ip_address}:{router.api_port}</strong>
                        <span>{router.use_ssl ? 'API SSL' : 'API normal'}</span>
                      </div>
                    </td>
                    <td>{router.username}</td>
                    <td>
                      <div className="connection-cell">
                        <span className={'status-badge ' + router.connection_status}>{statusLabels[router.connection_status]}</span>
                        {router.last_error && <small title={router.last_error}>{router.last_error}</small>}
                      </div>
                    </td>
                    <td>
                      <div className="router-counts">
                        <strong>{router.services_count ?? 0}</strong>
                        <span>{router.pending_operations_count ?? 0} pendientes/fallidas</span>
                      </div>
                    </td>
                    <td>{formatDate(router.last_successful_connection_at)}</td>
                    <td>
                      <div className="row-actions">
                        <button title="Editar" onClick={() => setEditing(router)}><Pencil size={17} /></button>
                        <button title="Probar conexion" disabled={testingId === router.id} onClick={() => void testConnection(router)}>
                          <TestTube2 size={17} />
                        </button>
                        <button title="Detectar metodo" disabled={detectingId === router.id} onClick={() => void detectControlMethod(router)}>
                          <Radar size={17} />
                        </button>
                        <button title="Importar clientes" onClick={() => setImportRouter(router)}>
                          <Download size={17} />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <footer className="pagination">
          <span>Pagina {meta.current_page} de {meta.last_page}</span>
          <div>
            <button disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Anterior</button>
            <button disabled={page >= meta.last_page} onClick={() => setPage((value) => value + 1)}>Siguiente</button>
          </div>
        </footer>
      </div>

      {editing && (
        <MikrotikRouterModal
          router={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={saved}
        />
      )}
      {importRouter && <MikrotikImportModal router={importRouter} onClose={() => setImportRouter(null)} />}
    </section>
  )
}

function MikrotikImportModal({ router, onClose }: { router: MikrotikRouter; onClose: () => void }) {
  const [candidates, setCandidates] = useState<MikrotikCandidate[]>([])
  const [clients, setClients] = useState<ClientOption[]>([])
  const [zones, setZones] = useState<ZoneOption[]>([])
  const [plans, setPlans] = useState<PlanOption[]>([])
  const [status, setStatus] = useState('')
  const [source, setSource] = useState('')
  const [accessType, setAccessType] = useState('')
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [syncing, setSyncing] = useState(false)
  const [linking, setLinking] = useState<MikrotikCandidate | null>(null)
  const [creating, setCreating] = useState<MikrotikCandidate | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [candidateResponse, clientResponse, zoneResponse, planResponse] = await Promise.all([
        api.get<{ data: MikrotikCandidate[] }>('/mikrotik-routers/' + router.id + '/import-candidates', { params: { all: 1, status: status || undefined, source_type: source || undefined, access_type: accessType || undefined } }),
        api.get<{ data: ClientOption[] }>('/clients', { params: { all: 1, status: 'active' } }),
        api.get<{ data: ZoneOption[] }>('/zones', { params: { all: 1 } }),
        api.get<{ data: PlanOption[] }>('/plans', { params: { all: 1, active: 1 } }),
      ])
      setCandidates(candidateResponse.data.data)
      setClients(clientResponse.data.data)
      setZones(zoneResponse.data.data)
      setPlans(planResponse.data.data)
    } finally {
      setLoading(false)
    }
  }, [accessType, router.id, source, status])

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const sync = async () => {
    setSyncing(true)
    setError('')
    setMessage('')
    try {
      const response = await api.post<{ data: { synced: number } }>('/mikrotik-routers/' + router.id + '/import-candidates/sync')
      setMessage(`${response.data.data.synced} registros leidos desde MikroTik.`)
      await load()
    } catch (requestError) {
      setError(getApiError(requestError))
    } finally {
      setSyncing(false)
    }
  }

  const ignore = async (candidate: MikrotikCandidate) => {
    setError('')
    setMessage('')
    try {
      await api.post('/mikrotik-import-candidates/' + candidate.id + '/ignore')
      setMessage('Registro ignorado correctamente.')
      await load()
    } catch (requestError) {
      setError(getApiError(requestError))
    }
  }

  return <div className="modal-backdrop"><section className="modal-card modal-wide import-modal"><header><div><span className="eyebrow">Importacion</span><h2>Importar desde {router.name}</h2><p>Se muestran clientes clasificados como antena o fibra. Los equipos comunes quedan en "No clasificados".</p></div><button className="modal-close" onClick={onClose}><X /></button></header>{message && <div className="alert alert-success">{message}</div>}{error && <div className="alert alert-error">{error}</div>}<div className="import-toolbar"><select value={accessType} onChange={(event) => setAccessType(event.target.value)}><option value="">Antena y fibra</option><option value="antenna">Solo antena</option><option value="fiber">Solo fibra</option><option value="unclassified">No clasificados</option></select><select value={status} onChange={(event) => setStatus(event.target.value)}><option value="">Todos</option><option value="unlinked">Sin relacionar</option><option value="linked">Vinculados</option><option value="ignored">Ignorados</option></select><select value={source} onChange={(event) => setSource(event.target.value)}><option value="">Todos los origenes</option><option value="pppoe">PPPoE</option><option value="simple_queue">Simple Queue</option><option value="dhcp_mac">DHCP/MAC</option><option value="hotspot">Hotspot</option></select><button className="button button-primary button-fit" disabled={syncing} onClick={() => void sync()}><Download size={17} /> {syncing ? 'Leyendo...' : 'Leer MikroTik'}</button></div>{linking && <LinkCandidateModal candidate={linking} clients={clients} plans={plans} onClose={() => setLinking(null)} onSaved={async () => { setLinking(null); setMessage('Registro vinculado correctamente.'); await load() }} onError={setError} />}{creating && <CreateCandidateClientModal candidate={creating} zones={zones} plans={plans} onClose={() => setCreating(null)} onSaved={async () => { setCreating(null); setMessage('Cliente importado correctamente.'); await load() }} onError={setError} />}{loading ? <div className="table-message">Cargando registros...</div> : <div className="import-list">{candidates.length ? candidates.map((candidate) => <article key={candidate.id}><div><strong>{candidate.display_name || candidate.identifier}</strong><span>{sourceLabel(candidate.source_type)} - {candidate.identifier}</span><small>{candidate.ip_address || candidate.mac_address || candidate.profile || 'Sin dato tecnico adicional'}</small>{candidate.client && <small>Vinculado a {candidate.client.full_name}</small>}</div><div><span className={`status-badge ${candidate.access_type ? 'active' : 'pending'}`}>{candidate.access_type === 'antenna' ? 'Antena' : candidate.access_type === 'fiber' ? 'Fibra' : 'No clasificado'}</span><span className={`status-badge ${candidate.status}`}>{candidateStatusLabel(candidate.status)}</span></div><div className="row-actions"><button title="Vincular" disabled={candidate.status === 'ignored' || !candidate.access_type} onClick={() => { setCreating(null); setLinking(candidate) }}><Link2 size={17} /></button><button title="Crear cliente" disabled={candidate.status === 'ignored' || !candidate.access_type} onClick={() => { setLinking(null); setCreating(candidate) }}><UserPlus size={17} /></button><button className="danger" title="Ignorar" onClick={() => void ignore(candidate)}><Ban size={17} /></button></div></article>) : <div className="table-message"><Download /><strong>No hay registros con este filtro</strong></div>}</div>}</section></div>
}

function LinkCandidateModal({ candidate, clients, plans, onClose, onSaved, onError }: { candidate: MikrotikCandidate; clients: ClientOption[]; plans: PlanOption[]; onClose: () => void; onSaved: () => Promise<void>; onError: (message: string) => void }) {
  const [clientId, setClientId] = useState('')
  const [planId, setPlanId] = useState('')
  const [saving, setSaving] = useState(false)
  const client = clients.find((item) => item.id === Number(clientId))
  const needsPlan = serviceImportable(candidate) && client?.internet_service_exists === false
  const availablePlans = needsPlan && client ? plans.filter((plan) => plan.zones.some((zone) => zone.id === client.zone_id)) : []

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    try {
      await api.post('/mikrotik-import-candidates/' + candidate.id + '/link', { client_id: Number(clientId), plan_id: planId ? Number(planId) : undefined })
      await onSaved()
    } catch (requestError) {
      onError(getApiError(requestError))
    } finally {
      setSaving(false)
    }
  }

  return <div className="nested-panel"><h3>Vincular registro</h3><form onSubmit={submit}><label className="field"><span>Cliente existente *</span><select required value={clientId} onChange={(event) => { setClientId(event.target.value); setPlanId('') }}><option value="">Selecciona cliente</option>{clients.map((client) => <option key={client.id} value={client.id}>{client.full_name} - {client.document}</option>)}</select></label>{serviceImportable(candidate) && client?.internet_service_exists && <div className="alert alert-success">Se usara el servicio existente del cliente.</div>}{needsPlan && <label className="field"><span>Plan para crear servicio *</span><select required value={planId} onChange={(event) => setPlanId(event.target.value)}><option value="">Selecciona plan</option>{availablePlans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name}</option>)}</select></label>}<footer className="form-actions"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={saving}>{saving ? 'Vinculando...' : 'Vincular'}</button></footer></form></div>
}

function CreateCandidateClientModal({ candidate, zones, plans, onClose, onSaved, onError }: { candidate: MikrotikCandidate; zones: ZoneOption[]; plans: PlanOption[]; onClose: () => void; onSaved: () => Promise<void>; onError: (message: string) => void }) {
  const [form, setForm] = useState({ full_name: candidate.display_name || candidate.identifier, document: '', phone: '', address: '', zone_id: '', plan_id: '' })
  const [saving, setSaving] = useState(false)
  const availablePlans = serviceImportable(candidate) && form.zone_id ? plans.filter((plan) => plan.zones.some((zone) => zone.id === Number(form.zone_id))) : []

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    try {
      await api.post('/mikrotik-import-candidates/' + candidate.id + '/create-client', { ...form, email: null, address: form.address || null, zone_id: Number(form.zone_id), plan_id: form.plan_id ? Number(form.plan_id) : undefined })
      await onSaved()
    } catch (requestError) {
      onError(getApiError(requestError))
    } finally {
      setSaving(false)
    }
  }

  return <div className="nested-panel"><h3>Crear cliente desde MikroTik</h3><form className="client-form" onSubmit={submit}><label className="field"><span>Nombre *</span><input required maxLength={150} value={form.full_name} onChange={(event) => setForm({ ...form, full_name: event.target.value })} /></label><label className="field"><span>Documento *</span><input required maxLength={30} value={form.document} onChange={(event) => setForm({ ...form, document: event.target.value })} /></label><label className="field"><span>Telefono *</span><input required maxLength={30} value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} /></label><label className="field"><span>Zona *</span><select required value={form.zone_id} onChange={(event) => setForm({ ...form, zone_id: event.target.value, plan_id: '' })}><option value="">Selecciona zona</option>{zones.map((zone) => <option key={zone.id} value={zone.id}>{zone.name}</option>)}</select></label>{serviceImportable(candidate) && <label className="field full"><span>Plan para el servicio importado *</span><select required value={form.plan_id} onChange={(event) => setForm({ ...form, plan_id: event.target.value })}><option value="">Selecciona plan</option>{availablePlans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name}</option>)}</select></label>}<label className="field full"><span>Direccion</span><input maxLength={500} value={form.address} onChange={(event) => setForm({ ...form, address: event.target.value })} /></label><footer className="form-actions full"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={saving}>{saving ? 'Creando...' : 'Crear cliente'}</button></footer></form></div>
}

function serviceImportable(candidate: MikrotikCandidate): boolean {
  return candidate.source_type === 'pppoe' || candidate.source_type === 'simple_queue' || candidate.source_type === 'dhcp_mac'
}

function sourceLabel(source: string): string {
  const labels: Record<string, string> = { pppoe: 'PPPoE', simple_queue: 'Simple Queue', dhcp_mac: 'DHCP/MAC', hotspot: 'Hotspot', manual: 'Manual' }
  return labels[source] ?? source
}

function candidateStatusLabel(status: CandidateStatus): string {
  const labels: Record<CandidateStatus, string> = { unlinked: 'Sin relacionar', linked: 'Vinculado', ignored: 'Ignorado' }
  return labels[status]
}

function MikrotikRouterModal({ router, onClose, onSaved }: { router: MikrotikRouter | null; onClose: () => void; onSaved: (text: string) => Promise<void> }) {
  const [form, setForm] = useState({
    name: router?.name ?? '',
    ip_address: router?.ip_address ?? '',
    api_port: String(router?.api_port ?? 8728),
    username: router?.username ?? '',
    password: '',
    use_ssl: router?.use_ssl ?? false,
    active: router?.active ?? true,
  })
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    setError('')

    const payload: Record<string, string | number | boolean> = {
      name: form.name,
      ip_address: form.ip_address,
      api_port: Number(form.api_port),
      username: form.username,
      use_ssl: form.use_ssl,
      active: form.active,
    }

    if (!router || form.password.trim() !== '') {
      payload.password = form.password
    }

    try {
      if (router) {
        await api.put('/mikrotik-routers/' + router.id, payload)
      } else {
        await api.post('/mikrotik-routers', payload)
      }

      await onSaved(router ? 'MikroTik actualizado correctamente.' : 'MikroTik registrado correctamente.')
    } catch (requestError) {
      setError(getApiError(requestError))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="modal-backdrop">
      <section className="modal-card modal-wide">
        <header>
          <div>
            <span className="eyebrow">{router ? 'Editar MikroTik' : 'Nuevo MikroTik'}</span>
            <h2>{router ? router.name : 'Registrar MikroTik principal'}</h2>
          </div>
          <button className="modal-close" onClick={onClose}><X /></button>
        </header>

        {error && <div className="alert alert-error">{error}</div>}

        <form className="client-form" onSubmit={submit}>
          <label className="field full">
            <span>Nombre *</span>
            <input required maxLength={100} placeholder="MikroTik nombre" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
          </label>
          <label className="field">
            <span>IP local *</span>
            <input required inputMode="decimal" placeholder="192.168.88.1" value={form.ip_address} onChange={(event) => setForm({ ...form, ip_address: event.target.value })} />
          </label>
          <label className="field">
            <span>Puerto API *</span>
            <input required type="number" min="1" max="65535" value={form.api_port} onChange={(event) => setForm({ ...form, api_port: event.target.value })} />
          </label>
          <label className="field">
            <span>Usuario *</span>
            <input required maxLength={100} value={form.username} onChange={(event) => setForm({ ...form, username: event.target.value })} />
          </label>
          <label className="field">
            <span>{router ? 'Nueva contrasena' : 'Contrasena *'}</span>
            <input required={!router} type="password" maxLength={255} autoComplete="new-password" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} />
          </label>
          <label className="toggle-field">
            <input type="checkbox" checked={form.use_ssl} onChange={(event) => setForm({ ...form, use_ssl: event.target.checked })} />
            <span>Conexion segura SSL</span>
          </label>
          <label className="toggle-field">
            <input type="checkbox" checked={form.active} onChange={(event) => setForm({ ...form, active: event.target.checked })} />
            <span>Router activo</span>
          </label>
          <footer className="form-actions full">
            <button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button>
            <button className="button button-primary button-fit" disabled={saving}>{saving ? 'Guardando...' : router ? 'Actualizar' : 'Guardar router'}</button>
          </footer>
        </form>
      </section>
    </div>
  )
}

function formatDate(value: string | null): string {
  if (!value) return 'Sin acceso correcto'

  return new Date(value).toLocaleString('es-BO')
}
