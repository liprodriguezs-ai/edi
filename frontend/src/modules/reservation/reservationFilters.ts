import type { Filter } from '@/shared/interfaces/list.types'

/** Estado local del panel de filtros de la lista de reservaciones. */
export interface ReservationFilters {
  statusIds: string[]
  amenityIds: string[]
}

export const INITIAL_RESERVATION_FILTERS: ReservationFilters = {
  statusIds: [],
  amenityIds: [],
}

/**
 * Construye el arreglo de filtros Orion (AND semantics) a partir del estado
 * local del panel. `reservation_status_id`/`amenity_id` son FK directas
 * (filterableBy de AmenityReservationController).
 */
export function buildReservationFilters(filters: ReservationFilters): Filter[] {
  const result: Filter[] = []

  if (filters.statusIds.length > 0) {
    result.push({ field: 'reservation_status_id', operator: 'in', value: filters.statusIds })
  }

  if (filters.amenityIds.length > 0) {
    result.push({ field: 'amenity_id', operator: 'in', value: filters.amenityIds })
  }

  return result
}
