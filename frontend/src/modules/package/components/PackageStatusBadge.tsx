import type { PillTone } from '@/shared/components/atoms/StatusPill'
import { StatusPill } from '@/shared/components/atoms/StatusPill'

/** Mapa de key del catálogo central package_status → etiqueta en español. */
const KEY_LABEL: Record<string, string> = {
  received: 'Recibido',
  ready: 'Listo',
  picked_up: 'Retirado',
  returned: 'Devuelto',
  incident: 'Incidente',
}

/**
 * Fallback de tone cuando no se dispone del color hex del API.
 * PillTone: g (verde), y (amarillo), r (rojo), b (azul)
 * received → azul (recibido pero pendiente de revisión)
 * ready    → azul (en portería, esperando retiro)
 * picked_up → verde (entregado)
 * returned  → amarillo (devuelto al remitente)
 * incident  → rojo (incidencia)
 */
const KEY_TONE: Record<string, PillTone> = {
  received: 'b',
  ready: 'b',
  picked_up: 'g',
  returned: 'y',
  incident: 'r',
}

interface PackageStatusBadgeProps {
  /** Clave del catálogo central (ej. "ready", "picked_up"). */
  statusKey?: string | null
  /** Color hex devuelto por el API (ej. "#3b82f6") — tiene precedencia sobre el tone. */
  statusColor?: string | null
  /** Nombre legible devuelto por el API — tiene precedencia sobre el label mapeado. */
  statusName?: string | null
}

/**
 * Badge de estado de paquete.
 *
 * Prioridad:
 * 1. Color hex del API (statusColor) → badge con fondo/texto derivado del hex.
 * 2. statusKey mapeado a un StatusPill tone.
 * 3. Fallback: pill azul con etiqueta genérica.
 */
export function PackageStatusBadge({ statusKey, statusColor, statusName }: PackageStatusBadgeProps) {
  const label = statusName ?? (statusKey ? (KEY_LABEL[statusKey] ?? statusKey) : '—')

  if (statusColor) {
    return (
      <span
        className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium"
        style={{ backgroundColor: `${statusColor}20`, color: statusColor }}
      >
        <i
          aria-hidden
          className="h-1.5 w-1.5 flex-none rounded-full"
          style={{ backgroundColor: statusColor }}
        />
        {label}
      </span>
    )
  }

  const tone: PillTone = statusKey ? (KEY_TONE[statusKey] ?? 'b') : 'b'

  return <StatusPill tone={tone}>{label}</StatusPill>
}
