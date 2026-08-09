import type { PillTone } from '@/shared/components/atoms/StatusPill'
import { StatusPill } from '@/shared/components/atoms/StatusPill'

/** Mapa de key del catálogo central complaint_status → etiqueta en español. */
const KEY_LABEL: Record<string, string> = {
  open: 'Registrada',
  in_review: 'En revisión',
  resolved: 'Resuelta',
  dismissed: 'Desestimada',
}

/**
 * Fallback de tone cuando no se dispone del color hex del API.
 * PillTone: g (verde), y (amarillo), r (rojo), b (azul)
 */
const KEY_TONE: Record<string, PillTone> = {
  open: 'b',
  in_review: 'y',
  resolved: 'g',
  dismissed: 'r',
}

interface ComplaintStatusBadgeProps {
  /** Clave del catálogo central (ej. "open", "in_review"). */
  statusKey?: string | null
  /** Color hex devuelto por el API (ej. "#3b82f6") — tiene precedencia sobre el tone. */
  statusColor?: string | null
  /** Nombre legible devuelto por el API — tiene precedencia sobre el label mapeado. */
  statusName?: string | null
}

/**
 * Badge de estado de incidencia.
 *
 * Prioridad:
 * 1. Color hex del API (statusColor) → badge con fondo/texto derivado del hex.
 * 2. statusKey mapeado a un StatusPill tone.
 * 3. Fallback: pill azul con etiqueta genérica.
 */
export function ComplaintStatusBadge({
  statusKey,
  statusColor,
  statusName,
}: ComplaintStatusBadgeProps) {
  const label = statusName ?? (statusKey ? (KEY_LABEL[statusKey] ?? statusKey) : '—')

  if (statusColor) {
    return (
      <span
        className='inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium'
        style={{ backgroundColor: `${statusColor}20`, color: statusColor }}
      >
        <i
          aria-hidden
          className='h-1.5 w-1.5 flex-none rounded-full'
          style={{ backgroundColor: statusColor }}
        />
        {label}
      </span>
    )
  }

  const tone: PillTone = statusKey ? (KEY_TONE[statusKey] ?? 'b') : 'b'

  return <StatusPill tone={tone}>{label}</StatusPill>
}
