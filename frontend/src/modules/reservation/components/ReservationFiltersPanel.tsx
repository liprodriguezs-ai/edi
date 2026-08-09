import { useAmenityFilterOptions } from '@/modules/reservation/hooks/useAmenityFilterOptions'
import type { ReservationFilters } from '@/modules/reservation/reservationFilters'
import { reservationStatusTone } from '@/modules/reservation/schema'
import type { PillTone } from '@/shared/components/atoms/StatusPill'
import {
  MultiSelectFilter,
  type MultiSelectOption,
} from '@/shared/components/filters/MultiSelectFilter'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { useMemo } from 'react'

/** Tono → color hex del punto, en sincronía con StatusPill (DOT). */
const STATUS_TONE_COLOR: Record<PillTone, string> = {
  g: '#3F9A5E',
  y: '#C0862B',
  r: '#C0533C',
  b: '#3F6E8E',
}

interface ReservationFiltersPanelProps {
  filters: ReservationFilters
  onChange: (next: ReservationFilters) => void
}

/**
 * Panel de filtros de la lista de Reservaciones: Estado (catálogo central
 * `reservation_status`, lazy) y Amenidad (`useAmenityFilterOptions` — lazy,
 * compartido con `AmenityReservationCalendarPage`).
 */
export function ReservationFiltersPanel({ filters, onChange }: ReservationFiltersPanelProps) {
  const statusCatalog = useLazyCatalogs(
    useMemo(() => ({ [CatalogType.RESERVATION_STATUS]: { _sort: 'order' } }), [])
  )

  const amenities = useAmenityFilterOptions()

  const statusOptions = useMemo<MultiSelectOption[]>(
    () =>
      (statusCatalog.catalogs[CatalogType.RESERVATION_STATUS] ?? []).map((s) => ({
        value: s.id,
        label: s.name,
        color: statusToneColor(s.id),
      })),
    [statusCatalog.catalogs]
  )

  return (
    <div className='flex flex-wrap gap-2'>
      <MultiSelectFilter
        label='Estado'
        options={statusOptions}
        value={filters.statusIds}
        onChange={(statusIds) => onChange({ ...filters, statusIds })}
        placeholder='Todos los estados'
        compact
        onFirstOpen={statusCatalog.enable}
        isLoading={statusCatalog.isLoading}
      />
      <MultiSelectFilter
        label='Amenidad'
        options={amenities.options}
        value={filters.amenityIds}
        onChange={(amenityIds) => onChange({ ...filters, amenityIds })}
        placeholder='Todas las amenidades'
        compact
        onFirstOpen={amenities.enable}
        isLoading={amenities.isLoading}
      />
    </div>
  )
}

function statusToneColor(statusId: string): string {
  return STATUS_TONE_COLOR[reservationStatusTone(statusId)]
}
