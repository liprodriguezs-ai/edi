import type {
  IAmenityAvailabilityDay,
  IAmenityAvailabilityResponse,
  IAmenityAvailabilitySlot,
} from '@/modules/amenity/schema'

/** Copy en español por razón de cierre estructural — espeja `AmenityAvailabilityService::structuralAvailability`. */
const REASON_LABEL: Record<string, string> = {
  out_of_service: 'Fuera de servicio',
  closed_weekday: 'La amenidad no opera este día',
  blackout: 'Bloqueado por el administrador',
}

/** Indexa los días de una respuesta de disponibilidad por fecha (`YYYY-MM-DD`) para lookup O(1). */
export function buildAvailabilityDayMap(
  response: IAmenityAvailabilityResponse | undefined
): Map<string, IAmenityAvailabilityDay> {
  const map = new Map<string, IAmenityAvailabilityDay>()
  response?.days.forEach((day) => map.set(day.date, day))
  return map
}

/**
 * Si una fecha puede seleccionarse en el calendario: no pasada, presente en el
 * rango consultado (el backend ya decide todo — el front nunca recalcula
 * weekday/blackout) y `available` en la respuesta.
 */
export function isDateSelectable(
  dayMap: Map<string, IAmenityAvailabilityDay>,
  dateKey: string,
  isPastDate: boolean
): boolean {
  if (isPastDate) return false
  const day = dayMap.get(dateKey)
  return Boolean(day?.available)
}

/** Copy en español para el motivo de un día cerrado (`day.reason`, o "sin cupo" cuando `open` pero sin lugares libres). */
export function availabilityReasonLabel(day: IAmenityAvailabilityDay | undefined): string {
  if (!day) return 'No disponible'
  if (!day.open) return day.reason ? (REASON_LABEL[day.reason] ?? 'No disponible') : 'No disponible'
  if (!day.available) return 'Sin cupo disponible'
  return 'Disponible'
}

/** Horarios (`amenity_slot`) de un día, ya resueltos con cupo (`free`/`taken`) — vacío si el día no abre. */
export function freeSlotsForDay(
  day: IAmenityAvailabilityDay | undefined
): IAmenityAvailabilitySlot[] {
  return day?.slots ?? []
}
