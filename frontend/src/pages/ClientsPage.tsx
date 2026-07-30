import { Archive, Eye, Pencil, Plus, Search, Users, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { api, getApiError } from '../lib/api'

type ClientStatus = 'active' | 'suspended'

type Client = {
  id: number
  full_name: string
  document: string
  phone: string
  email: string | null
  address: string | null
  zone_id: number
  zone: { id: number; name: string; active: boolean }
  installation_date: string | null
  status: ClientStatus
  internet_service_exists?: boolean
}

type ClientForm = {
  full_name: string
  document: string
  phone: string
  email: string
  address: string
  zone_id: string
  installation_date: string
}

type ListResponse = {
  data: Client[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

const emptyForm: ClientForm = {
  full_name: '', document: '', phone: '', email: '', address: '',
  zone_id: '', installation_date: '',
}

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
  const [zones, setZones] = useState<Array<{ id: number; name: string; active: boolean }>>([])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setDebouncedSearch(search)
      setPage(1)
    }, 300)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    const loadZones = async () => {
      const response = await api.get<{ data: Array<{ id: number; name: string; active: boolean }> }>('/zones', { params: { all: 1 } })
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
    // La consulta sincroniza el listado con los filtros y la página actuales.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void loadClients()
  }, [loadClients])

  const activeZones = useMemo(() => zones.filter((item) => item.active), [zones])

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
        <div><span className="eyebrow">Administración</span><h1>Clientes</h1><p>{meta.total} cliente{meta.total === 1 ? '' : 's'} registrado{meta.total === 1 ? '' : 's'}</p></div>
        <button className="button button-primary button-fit" onClick={() => setFormClient('new')}><Plus size={18} /> Registrar cliente</button>
      </div>

      {message && <div className="alert alert-success dismissible">{message}<button onClick={() => setMessage('')}><X size={16} /></button></div>}

      <div className="client-filters">
        <label className="search-box"><Search size={18} /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar por nombre, cédula, teléfono o correo" /></label>
        <select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1) }}><option value="">Todos los estados</option><option value="active">Activos</option><option value="suspended">Suspendidos</option></select>
        <select value={zone} onChange={(event) => { setZone(event.target.value); setPage(1) }}><option value="">Todas las zonas</option>{zones.map((item) => <option value={item.id} key={item.id}>{item.name}</option>)}</select>
      </div>

      <div className="table-card">
        {loading ? <div className="table-message">Cargando clientes…</div> : clients.length === 0 ? (
          <div className="table-message"><Users size={30} /><strong>No se encontraron clientes con ese criterio</strong></div>
        ) : (
          <div className="table-scroll"><table><thead><tr><th>Cliente</th><th>Cédula/RUC</th><th>Teléfono</th><th>Zona</th><th>Estado</th><th aria-label="Acciones" /></tr></thead><tbody>
            {clients.map((client) => <tr key={client.id}>
              <td><button className="client-name" onClick={() => setDetailClient(client)}>{client.full_name}<small>ID #{client.id}</small></button></td>
              <td>{client.document}</td><td>{client.phone}</td><td>{client.zone.name}</td>
              <td><span className={`status-badge ${client.status}`}>{client.status === 'active' ? 'Activo' : 'Suspendido'}</span></td>
              <td><div className="row-actions"><button title="Ver detalle" onClick={() => setDetailClient(client)}><Eye size={17} /></button><button title="Editar" onClick={() => setFormClient(client)}><Pencil size={17} /></button><button disabled={client.internet_service_exists} className="danger" title={client.internet_service_exists ? 'No se puede archivar: tiene un servicio asignado' : 'Archivar'} onClick={() => setArchiveClient(client)}><Archive size={17} /></button></div></td>
            </tr>)}
          </tbody></table></div>
        )}
        <footer className="pagination"><span>Página {meta.current_page} de {meta.last_page}</span><div><button disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Anterior</button><button disabled={page >= meta.last_page} onClick={() => setPage((value) => value + 1)}>Siguiente</button></div></footer>
      </div>

      {formClient && <ClientFormModal zones={activeZones} client={formClient === 'new' ? null : formClient} onClose={() => setFormClient(null)} onSaved={async (text) => { setFormClient(null); setMessage(text); await loadClients() }} />}
      {detailClient && <ClientDetailModal client={detailClient} onClose={() => setDetailClient(null)} onEdit={() => { setFormClient(detailClient); setDetailClient(null) }} />}
      {archiveClient && <ConfirmArchiveModal client={archiveClient} onClose={() => setArchiveClient(null)} onConfirm={() => void archived()} />}
    </section>
  )
}

function ClientFormModal({ client, zones, onClose, onSaved }: { client: Client | null; zones: Array<{ id: number; name: string }>; onClose: () => void; onSaved: (message: string) => Promise<void> }) {
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
      <label className="field"><span>Cédula/RUC *</span><input required maxLength={30} value={form.document} onChange={(e) => set('document', e.target.value)} /></label>
      <label className="field"><span>Teléfono *</span><input required maxLength={30} value={form.phone} onChange={(e) => set('phone', e.target.value)} /></label>
      <label className="field"><span>Correo electrónico</span><input type="email" value={form.email} onChange={(e) => set('email', e.target.value)} /></label>
      <label className="field full"><span>Dirección</span><textarea maxLength={500} value={form.address} onChange={(e) => set('address', e.target.value)} /></label>
      <label className="field"><span>Zona de cobertura *</span><select required value={form.zone_id} onChange={(e) => set('zone_id', e.target.value)}><option value="">Selecciona una zona</option>{zones.map((zone) => <option value={zone.id} key={zone.id}>{zone.name}</option>)}</select></label>
      <label className="field"><span>Fecha de instalación</span><input type="date" value={form.installation_date} onChange={(e) => set('installation_date', e.target.value)} /></label>
      <footer className="form-actions full"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={saving}>{saving ? 'Guardando…' : client ? 'Actualizar' : 'Guardar cliente'}</button></footer>
    </form>
  </section></div>
}

function ClientDetailModal({ client, onClose, onEdit }: { client: Client; onClose: () => void; onEdit: () => void }) {
  const fields = [['Cédula/RUC', client.document], ['Teléfono', client.phone], ['Correo', client.email || 'No registrado'], ['Dirección', client.address || 'No registrada'], ['Zona', client.zone.name], ['Instalación', client.installation_date || 'Sin fecha']]
  return <div className="modal-backdrop"><section className="modal-card" role="dialog" aria-modal="true"><header><div><span className="eyebrow">Cliente #{client.id}</span><h2>{client.full_name}</h2></div><button className="modal-close" onClick={onClose}><X /></button></header><span className={`status-badge ${client.status}`}>{client.status === 'active' ? 'Activo' : 'Suspendido'}</span><dl className="detail-list">{fields.map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value}</dd></div>)}</dl><footer className="form-actions"><button className="button button-secondary" onClick={onClose}>Cerrar</button><button className="button button-primary button-fit" onClick={onEdit}><Pencil size={17} /> Editar</button></footer></section></div>
}

function ConfirmArchiveModal({ client, onClose, onConfirm }: { client: Client; onClose: () => void; onConfirm: () => void }) {
  return <div className="modal-backdrop"><section className="modal-card confirm-card" role="alertdialog" aria-modal="true"><div className="danger-icon"><Archive /></div><h2>¿Archivar a {client.full_name}?</h2><p>El cliente desaparecerá del listado principal, pero sus datos podrán recuperarse desde la base de datos.</p><footer className="form-actions"><button className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-danger" onClick={onConfirm}>Sí, archivar</button></footer></section></div>
}
