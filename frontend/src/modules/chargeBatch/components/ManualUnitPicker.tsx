import { Field, FieldError, FieldLabel } from '@/components/ui/field'
import type { IUnit } from '@/modules/unit/schema'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { useQuery } from '@tanstack/react-query'
import { useMemo, useState } from 'react'

interface ManualUnitPickerProps {
  selected: string[]
  onChange: (_ids: string[]) => void
  error?: string
  disabled?: boolean
}

const unitLabel = (unit: IUnit) => unit.full_identifier ?? unit.number ?? unit.id

/**
 * Selección manual de unidades (§8.4.3) — multiselect con búsqueda remota (typeahead).
 * Se apoya en el `SearchSelect` compartido: el menú NO se cierra al elegir, marca con ✓ las
 * ya seleccionadas, navega por teclado y acumula chips abajo. Permite elegir VARIAS de corrido.
 */
export function ManualUnitPicker({ selected, onChange, error, disabled }: ManualUnitPickerProps) {
  // Cache de unidades vistas en la búsqueda remota. Se llena en el callback async de
  // `loadUnits` (NO en un effect), para que el chip de una unidad recién elegida muestre su
  // nombre (el select habla en ids hacia afuera).
  const [searchCache, setSearchCache] = useState<Record<string, IUnit>>({})

  // Ids seleccionados cuyo nombre aún no tenemos cacheado (caso EDICIÓN: llegan pre-cargados
  // del backend). Se resuelven en UN request; sólo dispara cuando falta alguno → cero costo al crear.
  const missingIds = selected.filter((id) => !searchCache[id])
  const missingKey = [...missingIds].sort().join(',')

  const { data: resolved } = useQuery({
    queryKey: ['manual-unit-labels', missingKey],
    queryFn: () =>
      UnitResource.search({
        filters: [{ field: 'id', operator: 'in', value: missingIds }],
        limit: missingIds.length,
      }),
    enabled: missingIds.length > 0,
    staleTime: 5 * 60 * 1000,
  })

  // Nombre de cada unidad seleccionada, resuelto en RENDER (sin effect): mezcla lo que la
  // búsqueda cacheó con lo que devolvió la query de ids faltantes. Sin esto el chip caería
  // al UUID crudo.
  const selectedOptions = useMemo(() => {
    const byId = new Map<string, IUnit>()
    for (const unit of resolved?.data ?? []) byId.set(unit.id, unit)
    for (const id of Object.keys(searchCache)) byId.set(id, searchCache[id])
    return selected.map((id) => byId.get(id)).filter((unit): unit is IUnit => Boolean(unit))
  }, [selected, resolved, searchCache])

  // Búsqueda remota (typeahead). Cachea cada resultado en un callback async (no effect).
  const loadUnits = async (term: string): Promise<IUnit[]> => {
    const { data } = await UnitResource.search({
      search: { value: term },
      limit: 20,
      sort: [{ field: 'number', direction: 'asc' }],
    })
    setSearchCache((prev) => {
      const next = { ...prev }
      for (const unit of data) next[unit.id] = unit
      return next
    })
    return data
  }

  return (
    <Field>
      <FieldLabel htmlFor='batch-manual-units' required>
        Unidades seleccionadas ({selected.length})
      </FieldLabel>
      <SearchSelect<IUnit>
        id='batch-manual-units'
        multiple
        value={selected}
        onChange={(ids) => onChange((ids as string[] | null) ?? [])}
        loadOptions={loadUnits}
        selectedOptions={selectedOptions}
        getOptionLabel={unitLabel}
        placeholder='Buscar y agregar unidades…'
        noOptionsMessage='Escribe para buscar unidades'
        isDisabled={disabled}
      />
      <FieldError>{error}</FieldError>
    </Field>
  )
}
