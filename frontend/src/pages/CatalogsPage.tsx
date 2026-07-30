import { Gauge, MapPin, Pencil, Plus, Search, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react'
import { api, getApiError } from '../lib/api'

type Zone = { id: number; name: string; description: string | null; active: boolean; clients_count: number }
type Plan = { id: number; name: string; download_mbps: number; upload_mbps: number; monthly_price: string | null; description: string | null; active: boolean; zones: Zone[] }
type Meta = { current_page: number; last_page: number; total: number }

export function CatalogsPage() {
  const [tab, setTab] = useState<'zones' | 'plans'>('zones')

  return <section className="page-content catalogs-page">
    <div className="page-heading"><div><span className="eyebrow">Configuración comercial</span><h1>Planes y zonas</h1><p>Define dónde opera el ISP y qué velocidades ofrece.</p></div></div>
    <div className="catalog-tabs"><button className={tab === 'zones' ? 'active' : ''} onClick={() => setTab('zones')}><MapPin size={18} /> Zonas</button><button className={tab === 'plans' ? 'active' : ''} onClick={() => setTab('plans')}><Gauge size={18} /> Planes</button></div>
    {tab === 'zones' ? <ZonesPanel /> : <PlansPanel />}
  </section>
}

function ZonesPanel() {
  const [zones, setZones] = useState<Zone[]>([])
  const [search, setSearch] = useState('')
  const [active, setActive] = useState('')
  const [editing, setEditing] = useState<Zone | 'new' | null>(null)
  const [message, setMessage] = useState('')

  const load = useCallback(async () => {
    const response = await api.get<{ data: Zone[] }>('/zones', { params: { all: 1 } })
    setZones(response.data.data)
  }, [])
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  const filtered = useMemo(() => zones.filter((zone) => {
    const matchesSearch = zone.name.toLowerCase().includes(search.toLowerCase())
    const matchesStatus = active === '' || zone.active === (active === '1')
    return matchesSearch && matchesStatus
  }), [active, search, zones])

  return <>
    <div className="catalog-toolbar"><label className="search-box"><Search size={18} /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Buscar zona" /></label><select value={active} onChange={(e) => setActive(e.target.value)}><option value="">Todos los estados</option><option value="1">Activas</option><option value="0">Inactivas</option></select><button className="button button-primary button-fit" onClick={() => setEditing('new')}><Plus size={18} /> Nueva zona</button></div>
    {message && <div className="alert alert-success">{message}</div>}
    <div className="catalog-grid">{filtered.length ? filtered.map((zone) => <article className="catalog-card" key={zone.id}><header><div className="catalog-icon"><MapPin /></div><span className={`status-badge ${zone.active ? 'active' : 'suspended'}`}>{zone.active ? 'Activa' : 'Inactiva'}</span></header><h2>{zone.name}</h2><p>{zone.description || 'Sin descripción'}</p><footer><span>{zone.clients_count} cliente{zone.clients_count === 1 ? '' : 's'}</span><button onClick={() => setEditing(zone)}><Pencil size={16} /> Editar</button></footer></article>) : <div className="table-message full-span"><MapPin /><strong>No se encontraron zonas</strong></div>}</div>
    {editing && <ZoneModal zone={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={async (text) => { setEditing(null); setMessage(text); await load() }} />}
  </>
}

function ZoneModal({ zone, onClose, onSaved }: { zone: Zone | null; onClose: () => void; onSaved: (text: string) => Promise<void> }) {
  const [name, setName] = useState(zone?.name ?? '')
  const [description, setDescription] = useState(zone?.description ?? '')
  const [active, setActive] = useState(zone?.active ?? true)
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const submit = async (event: FormEvent) => {
    event.preventDefault(); setSaving(true); setError('')
    try {
      if (zone) await api.put(`/zones/${zone.id}`, { name, description: description || null, active })
      else await api.post('/zones', { name, description: description || null })
      await onSaved(zone ? 'Zona actualizada correctamente.' : 'Zona registrada correctamente.')
    } catch (requestError) { setError(getApiError(requestError)) } finally { setSaving(false) }
  }
  return <div className="modal-backdrop"><section className="modal-card"><header><div><span className="eyebrow">{zone ? `Zona #${zone.id}` : 'Nueva zona'}</span><h2>{zone ? 'Editar zona' : 'Registrar zona'}</h2></div><button className="modal-close" onClick={onClose}><X /></button></header>{error && <div className="alert alert-error">{error}</div>}<form onSubmit={submit}><label className="field"><span>Nombre *</span><input required maxLength={100} value={name} onChange={(e) => setName(e.target.value)} /></label><label className="field"><span>Descripción</span><textarea maxLength={500} value={description} onChange={(e) => setDescription(e.target.value)} /></label>{zone && <label className="toggle-field"><input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} /><span>Zona activa</span></label>}<footer className="form-actions"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={saving}>{saving ? 'Guardando…' : zone ? 'Actualizar' : 'Guardar zona'}</button></footer></form></section></div>
}

function PlansPanel() {
  const [plans, setPlans] = useState<Plan[]>([])
  const [zones, setZones] = useState<Zone[]>([])
  const [meta, setMeta] = useState<Meta>({ current_page: 1, last_page: 1, total: 0 })
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [active, setActive] = useState('')
  const [zoneId, setZoneId] = useState('')
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<Plan | 'new' | null>(null)
  const [message, setMessage] = useState('')

  useEffect(() => { const timer = setTimeout(() => { setDebounced(search); setPage(1) }, 300); return () => clearTimeout(timer) }, [search])
  const load = useCallback(async () => {
    const [planResponse, zoneResponse] = await Promise.all([
      api.get<{ data: Plan[]; meta: Meta }>('/plans', { params: { search: debounced || undefined, active: active || undefined, zone_id: zoneId || undefined, page } }),
      api.get<{ data: Zone[] }>('/zones', { params: { all: 1 } }),
    ])
    setPlans(planResponse.data.data); setMeta(planResponse.data.meta); setZones(zoneResponse.data.data)
  }, [active, debounced, page, zoneId])
  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load()
  }, [load])

  return <>
    <div className="catalog-toolbar"><label className="search-box"><Search size={18} /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Buscar plan" /></label><select value={active} onChange={(e) => { setActive(e.target.value); setPage(1) }}><option value="">Todos los estados</option><option value="1">Activos</option><option value="0">Inactivos</option></select><select value={zoneId} onChange={(e) => { setZoneId(e.target.value); setPage(1) }}><option value="">Todas las zonas</option>{zones.map((zone) => <option key={zone.id} value={zone.id}>{zone.name}</option>)}</select><button className="button button-primary button-fit" onClick={() => setEditing('new')}><Plus size={18} /> Nuevo plan</button></div>
    {message && <div className="alert alert-success">{message}</div>}
    <div className="catalog-grid plan-grid">{plans.length ? plans.map((plan) => <article className="catalog-card plan-card" key={plan.id}><header><div className="catalog-icon"><Gauge /></div><span className={`status-badge ${plan.active ? 'active' : 'suspended'}`}>{plan.active ? 'Activo' : 'Inactivo'}</span></header><h2>{plan.name}</h2><div className="speed"><strong>{plan.download_mbps}</strong><span>Mbps descarga</span><strong>{plan.upload_mbps}</strong><span>Mbps subida</span></div><p>{plan.monthly_price ? `Bs ${plan.monthly_price} / mes` : 'Sin precio definido'}</p><div className="zone-tags">{plan.zones.map((zone) => <span key={zone.id}>{zone.name}</span>)}</div><footer><span>{plan.zones.length} zona{plan.zones.length === 1 ? '' : 's'}</span><button onClick={() => setEditing(plan)}><Pencil size={16} /> Editar</button></footer></article>) : <div className="table-message full-span"><Gauge /><strong>No se encontraron planes</strong></div>}</div>
    <footer className="pagination catalog-pagination"><span>{meta.total} planes · Página {meta.current_page} de {meta.last_page}</span><div><button disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Anterior</button><button disabled={page >= meta.last_page} onClick={() => setPage((value) => value + 1)}>Siguiente</button></div></footer>
    {editing && <PlanModal plan={editing === 'new' ? null : editing} zones={zones} onClose={() => setEditing(null)} onSaved={async (text) => { setEditing(null); setMessage(text); await load() }} />}
  </>
}

function PlanModal({ plan, zones, onClose, onSaved }: { plan: Plan | null; zones: Zone[]; onClose: () => void; onSaved: (text: string) => Promise<void> }) {
  const [form, setForm] = useState({ name: plan?.name ?? '', download_mbps: String(plan?.download_mbps ?? ''), upload_mbps: String(plan?.upload_mbps ?? ''), monthly_price: plan?.monthly_price ?? '', description: plan?.description ?? '', active: plan?.active ?? true, zone_ids: plan?.zones.map((zone) => zone.id) ?? [] })
  const [error, setError] = useState(''); const [saving, setSaving] = useState(false)
  const toggleZone = (id: number) => setForm((current) => ({ ...current, zone_ids: current.zone_ids.includes(id) ? current.zone_ids.filter((zoneId) => zoneId !== id) : [...current.zone_ids, id] }))
  const submit = async (event: FormEvent) => {
    event.preventDefault(); setSaving(true); setError('')
    const payload = { ...form, download_mbps: Number(form.download_mbps), upload_mbps: Number(form.upload_mbps), monthly_price: form.monthly_price ? Number(form.monthly_price) : null }
    try {
      if (plan) await api.put(`/plans/${plan.id}`, payload); else await api.post('/plans', payload)
      await onSaved(plan ? 'Plan actualizado correctamente.' : 'Plan registrado correctamente.')
    } catch (requestError) { setError(getApiError(requestError)) } finally { setSaving(false) }
  }
  return <div className="modal-backdrop"><section className="modal-card modal-wide"><header><div><span className="eyebrow">{plan ? `Plan #${plan.id}` : 'Nuevo plan'}</span><h2>{plan ? 'Editar plan' : 'Registrar plan'}</h2></div><button className="modal-close" onClick={onClose}><X /></button></header>{error && <div className="alert alert-error">{error}</div>}<form className="client-form" onSubmit={submit}><label className="field full"><span>Nombre *</span><input required maxLength={100} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></label><label className="field"><span>Descarga (Mbps) *</span><input required type="number" min="1" value={form.download_mbps} onChange={(e) => setForm({ ...form, download_mbps: e.target.value })} /></label><label className="field"><span>Subida (Mbps) *</span><input required type="number" min="1" value={form.upload_mbps} onChange={(e) => setForm({ ...form, upload_mbps: e.target.value })} /></label><label className="field"><span>Precio mensual (Bs)</span><input type="number" min="0.01" step="0.01" value={form.monthly_price} onChange={(e) => setForm({ ...form, monthly_price: e.target.value })} /></label>{plan && <label className="toggle-field plan-toggle"><input type="checkbox" checked={form.active} onChange={(e) => setForm({ ...form, active: e.target.checked })} /><span>Plan activo</span></label>}<label className="field full"><span>Descripción</span><textarea maxLength={500} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></label><fieldset className="zone-picker full"><legend>Zonas disponibles *</legend>{zones.map((zone) => <label key={zone.id} className={!zone.active ? 'inactive' : ''}><input type="checkbox" checked={form.zone_ids.includes(zone.id)} onChange={() => toggleZone(zone.id)} />{zone.name}{!zone.active && ' (inactiva)'}</label>)}</fieldset><footer className="form-actions full"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button className="button button-primary button-fit" disabled={saving || form.zone_ids.length === 0}>{saving ? 'Guardando…' : plan ? 'Actualizar' : 'Guardar plan'}</button></footer></form></section></div>
}
