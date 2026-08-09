// Registra el polyfill de Temporal como GLOBAL (no solo como import nombrado): el bundle
// de `@schedule-x/calendar` referencia el identificador `Temporal` a secas en tiempo de
// ejecución (`Temporal.Instant`, `instanceof Temporal.ZonedDateTime`, etc.), sin importarlo
// él mismo — espera que el consumidor lo exponga como global (igual que un futuro `Temporal`
// nativo del navegador). Este side-effect import también trae la declaración ambient global
// de tipos, así el resto del archivo puede usar `Temporal.*` sin un import explícito.
import 'temporal-polyfill/global'

import type { CalendarType } from '@schedule-x/calendar'
import type { IAmenityOccupancyEntry, IAmenityReservation } from '@/modules/reservation/schema'

export interface ReservationCalendarEvent {
  id: string
  start: Temporal.ZonedDateTime | Temporal.PlainDate
  end: Temporal.ZonedDateTime | Temporal.PlainDate
  title: string
  calendarId: string
}

interface ColorDefinition {
  main: string
  container: string
  onContainer: string
}

/**
 * Paleta fija de colores (10 cupos). Las amenidades NO se conocen de antemano al crear
 * el `CalendarApp` (schedule-x congela `config.calendars` en el montaje — ver
 * `useCalendarApp` en `@schedule-x/react`, que solo llama `createCalendar()` una vez
 * dentro de un `useEffect([])`), así que en vez de una entrada por amenidad real,
 * cada amenidad se asigna de forma determinística a uno de estos 10 "cupos de color"
 * vía `amenityCalendarId()`. Con las amenidades típicas de un fraccionamiento (alberca,
 * salón, gym, cancha…) la colisión de dos amenidades en el mismo cupo es un caso límite
 * aceptable (comparten color, pero cada evento sigue siendo clicable/legible).
 */
const AMENITY_COLOR_PALETTE: readonly ColorDefinition[] = [
  { main: '#3F6E8E', container: '#DCEAF5', onContainer: '#1B3A4E' }, // azul
  { main: '#3F9A5E', container: '#DDF3E4', onContainer: '#1C4A2E' }, // verde
  { main: '#C0862B', container: '#FBEBD3', onContainer: '#5C3E10' }, // ámbar
  { main: '#8C5EC0', container: '#EEE1F8', onContainer: '#3E2359' }, // morado
  { main: '#C0533C', container: '#FBE1DA', onContainer: '#5C2418' }, // terracota
  { main: '#2B8C8C', container: '#D7F0F0', onContainer: '#124040' }, // teal
  { main: '#B0559C', container: '#F7E1F1', onContainer: '#4E2244' }, // rosa
  { main: '#6E7E3F', container: '#E9F0D7', onContainer: '#333E17' }, // oliva
  { main: '#5C6BC0', container: '#E3E6F8', onContainer: '#28306E' }, // índigo
  { main: '#A67C52', container: '#F3E6D8', onContainer: '#4A3319' }, // café
] as const

/** `config.calendars` de schedule-x: un cupo de color fijo por índice de la paleta. */
export const AMENITY_CALENDARS: Record<string, CalendarType> = Object.fromEntries(
  AMENITY_COLOR_PALETTE.map((colors, index) => [
    `amenity-color-${index}`,
    { colorName: `amenity-color-${index}`, lightColors: colors } satisfies CalendarType,
  ])
)

/** Hash determinístico (FNV-1a simplificado) de un id de amenidad a un cupo de la paleta. */
function hashToBucket(value: string, bucketCount: number): number {
  let hash = 0
  for (let i = 0; i < value.length; i += 1) {
    hash = (hash * 31 + value.charCodeAt(i)) >>> 0
  }
  return hash % bucketCount
}

/** `calendarId` del evento — mismo id de amenidad siempre resuelve al mismo cupo de color. */
export function amenityCalendarId(amenityId: string): string {
  return `amenity-color-${hashToBucket(amenityId, AMENITY_COLOR_PALETTE.length)}`
}

/** Hex del color asignado a una amenidad — para pintar el swatch del filtro (misma paleta). */
export function amenityColorHex(amenityId: string): string {
  return AMENITY_COLOR_PALETTE[hashToBucket(amenityId, AMENITY_COLOR_PALETTE.length)].main
}

/** Extrae la parte `YYYY-MM-DD` de un string ISO (misma técnica que `dateHelper.formatDateForInput` — evita el day-shift por TZ al truncar a solo fecha). */
function extractDatePart(dateStr: string): string {
  const match = dateStr.match(/^(\d{4}-\d{2}-\d{2})/)
  return match ? match[1] : dateStr
}

/**
 * Convierte un instante UTC ISO (`start_at`/`end_at`, ya materializados por el backend)
 * al huso horario LOCAL del navegador — la misma convención que ya usa el resto de la
 * app (`parseISO` + formateo local de `date-fns`), sin ninguna conversión de TZ manual.
 */
function toBrowserZonedDateTime(isoInstant: string): Temporal.ZonedDateTime {
  return Temporal.Instant.from(isoInstant).toZonedDateTimeISO(Temporal.Now.timeZoneId())
}

/**
 * Mapea una reservación al evento de schedule-x. `by_slot` (tiene `start_at`/`end_at`,
 * ver migración `create_amenity_reservation_table` — null solo para `by_day`) produce un
 * evento con horario; `by_day` produce un evento de día completo anclado a
 * `reservation_date` (start === end, requerido por schedule-x para eventos de un solo día
 * completo — ver `_isSingleDayFullDay` en `@schedule-x/calendar`).
 */
export function mapReservationToCalendarEvent(
  reservation: IAmenityReservation
): ReservationCalendarEvent {
  const unitLabel = reservation.unit?.full_identifier ?? reservation.unit?.number
  const title = [reservation.amenity?.name ?? 'Amenidad', unitLabel].filter(Boolean).join(' — ')
  const calendarId = amenityCalendarId(reservation.amenity_id)

  if (reservation.start_at && reservation.end_at) {
    return {
      id: reservation.id,
      start: toBrowserZonedDateTime(reservation.start_at),
      end: toBrowserZonedDateTime(reservation.end_at),
      title,
      calendarId,
    }
  }

  const date = Temporal.PlainDate.from(extractDatePart(reservation.reservation_date))
  return {
    id: reservation.id,
    start: date,
    end: date,
    title,
    calendarId,
  }
}

/**
 * Mapea una entrada ANONIMIZADA del calendario de ocupación (comité de
 * vigilancia — S18, ver `AmenityOccupancyService`/`IAmenityOccupancyEntry`) a
 * un evento de schedule-x. Título = amenidad + "Ocupado" (+ "n/m" cuando la
 * amenidad opera por horarios con capacidad > 1) — NUNCA unidad, residente ni
 * id de reservación, porque esos campos no existen en `IAmenityOccupancyEntry`.
 * El id del evento se deriva de (amenidad, fecha, horario) — no hay un id de
 * reservación que anonimizar aquí.
 */
export function mapOccupancyEntryToCalendarEvent(
  entry: IAmenityOccupancyEntry
): ReservationCalendarEvent {
  const calendarId = amenityCalendarId(entry.amenity_id)
  const showCount = entry.slot_id !== null && entry.capacity > 1
  const title = showCount
    ? `${entry.amenity_name} — Ocupado (${entry.occupied}/${entry.capacity})`
    : `${entry.amenity_name} — Ocupado`
  const id = `${entry.amenity_id}-${entry.reservation_date}-${entry.slot_id ?? 'day'}`

  if (entry.start_at && entry.end_at) {
    return {
      id,
      start: toBrowserZonedDateTime(entry.start_at),
      end: toBrowserZonedDateTime(entry.end_at),
      title,
      calendarId,
    }
  }

  const date = Temporal.PlainDate.from(extractDatePart(entry.reservation_date))
  return { id, start: date, end: date, title, calendarId }
}
