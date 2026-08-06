import { divIcon, type LeafletEvent } from 'leaflet'
import { Circle, MapContainer, Marker, TileLayer, useMap, useMapEvents } from 'react-leaflet'
import { LocateFixed, MapPin, X } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import 'leaflet/dist/leaflet.css'

type Coordinates = { latitude: number; longitude: number }

type Props = {
  initialPosition: Coordinates | null
  onClose: () => void
  onConfirm: (coordinates: Coordinates) => void
}

const defaultPosition: Coordinates = { latitude: -17.7833, longitude: -63.1821 }
const markerIcon = divIcon({
  className: 'location-marker',
  html: '<span aria-hidden="true"></span>',
  iconSize: [28, 38],
  iconAnchor: [14, 38],
})

function MapClick({ onSelect }: { onSelect: (coordinates: Coordinates) => void }) {
  useMapEvents({
    click(event) {
      onSelect({ latitude: event.latlng.lat, longitude: event.latlng.lng })
    },
  })
  return null
}

function CenterMap({ position }: { position: Coordinates }) {
  const map = useMap()
  useEffect(() => {
    map.setView([position.latitude, position.longitude], Math.max(map.getZoom(), 17))
  }, [map, position])
  return null
}

export function LocationPickerModal({ initialPosition, onClose, onConfirm }: Props) {
  const [position, setPosition] = useState<Coordinates>(initialPosition ?? defaultPosition)
  const [accuracy, setAccuracy] = useState<number | null>(null)
  const [locating, setLocating] = useState(false)
  const [error, setError] = useState('')
  const markerHandlers = useMemo(() => ({
    dragend(event: LeafletEvent) {
      const point = event.target.getLatLng()
      setPosition({ latitude: point.lat, longitude: point.lng })
      setAccuracy(null)
    },
  }), [])

  const locate = () => {
    if (!navigator.geolocation) {
      setError('Este navegador no permite obtener la ubicacion actual.')
      return
    }
    setLocating(true)
    setError('')
    navigator.geolocation.getCurrentPosition(
      ({ coords }) => {
        setPosition({ latitude: coords.latitude, longitude: coords.longitude })
        setAccuracy(coords.accuracy)
        setLocating(false)
      },
      () => {
        setError('No se pudo obtener la ubicacion. Revisa el permiso y activa el GPS.')
        setLocating(false)
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    )
  }

  return <div className="modal-backdrop location-picker-backdrop"><section className="modal-card location-picker-modal" role="dialog" aria-modal="true" aria-labelledby="location-picker-title">
    <header><div><span className="eyebrow">Ubicacion de instalacion</span><h2 id="location-picker-title">Marca la casa del cliente</h2><p>Haz clic en el mapa o arrastra el marcador hasta el punto exacto.</p></div><button type="button" className="modal-close" onClick={onClose}><X /></button></header>
    {error && <div className="alert alert-error">{error}</div>}
    <div className="location-map">
      <MapContainer center={[position.latitude, position.longitude]} zoom={initialPosition ? 17 : 13} scrollWheelZoom>
        <TileLayer attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>' url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
        <MapClick onSelect={(coordinates) => { setPosition(coordinates); setAccuracy(null) }} />
        <CenterMap position={position} />
        {accuracy !== null && <Circle center={[position.latitude, position.longitude]} radius={accuracy} pathOptions={{ color: '#1f8a67', fillOpacity: 0.12 }} />}
        <Marker draggable position={[position.latitude, position.longitude]} icon={markerIcon} eventHandlers={markerHandlers} />
      </MapContainer>
    </div>
    <div className="location-picker-info"><div><MapPin size={18} /><span><strong>{position.latitude.toFixed(7)}, {position.longitude.toFixed(7)}</strong>{accuracy !== null && <small>Precision estimada: ±{Math.round(accuracy)} metros</small>}</span></div><button type="button" className="button button-secondary button-fit" onClick={locate} disabled={locating}><LocateFixed size={17} /> {locating ? 'Buscando...' : 'Usar mi ubicacion'}</button></div>
    <footer className="form-actions"><button type="button" className="button button-secondary" onClick={onClose}>Cancelar</button><button type="button" className="button button-primary button-fit" onClick={() => onConfirm(position)}><MapPin size={17} /> Confirmar ubicacion</button></footer>
  </section></div>
}
