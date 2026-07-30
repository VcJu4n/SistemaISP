import { CheckCircle2, Pencil, Plus, Router, Search, ShieldCheck, TestTube2, X, XCircle } from 'lucide-react'
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
    </section>
  )
}

function MikrotikRouterModal({ router, onClose, onSaved }: { router: MikrotikRouter | null; onClose: () => void; onSaved: (text: string) => Promise<void> }) {
  const [form, setForm] = useState({
    name: router?.name ?? 'MikroTik principal',
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
            <input required maxLength={100} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
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
