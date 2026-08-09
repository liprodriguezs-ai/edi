import { AmenityResource } from '@/modules/amenity/services/AmenityResource'
import type { MultiSelectOption } from '@/shared/components/filters/MultiSelectFilter'
import { useQuery } from '@tanstack/react-query'
import { useCallback, useMemo, useState } from 'react'

/**
 * Opciones lazy del filtro/leyenda de Amenidad. `Amenity` NO está en el mapper de
 * `/selects` del backend (`AngularController::$mapperSelects` solo mapea
 * `ReservationMode`/`ReservationStatus`), así que sus opciones se
 * resuelven con una consulta propia contra `/amenity` — no dispara hasta llamar
 * `enable()` (mismo criterio infra-cost que `useLazyCatalogs`: nada de fetch al montar).
 * Compartido por `ReservationFiltersPanel` (lista) y `AmenityReservationCalendarPage`
 * (calendario) para no duplicar la consulta lazy.
 */
export function useAmenityFilterOptions() {
  const [enabled, setEnabled] = useState(false)
  const enable = useCallback(() => setEnabled(true), [])

  const { data, isLoading } = useQuery({
    queryKey: ['amenity', 'filter-options'],
    queryFn: () =>
      AmenityResource.search({ sort: [{ field: 'name', direction: 'asc' }], limit: 200 }),
    enabled,
    staleTime: 5 * 60 * 1000,
  })

  const options = useMemo<MultiSelectOption[]>(
    () => (data?.data ?? []).map((a) => ({ value: a.id, label: a.name })),
    [data]
  )

  return { options, isLoading, enable }
}
