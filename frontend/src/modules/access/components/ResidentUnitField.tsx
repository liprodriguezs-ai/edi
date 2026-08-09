import { Input } from '@/components/ui/input'
import type { IUnit } from '@/modules/unit/schema'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { useQuery } from '@tanstack/react-query'
import { useEffect } from 'react'

interface ResidentUnitFieldProps {
  value?: string
  onChange: (_unitId: string) => void
  disabled?: boolean
  invalid?: boolean
  id?: string
}

const unitLabel = (unit: IUnit): string => unit.full_identifier ?? unit.number ?? ''

/**
 * Selector de unidad para un RESIDENTE: ofrece únicamente sus propias unidades
 * (GET /unit/my), no el condominio entero.
 *
 * El backend ya rechaza una unidad ajena (regla CallerBelongsToUnit), así que un
 * buscador global solo le ofrecía al residente opciones que no puede usar. Con
 * una sola unidad no hay nada que elegir: se pinta y queda seleccionada (mismo
 * criterio que ReserveAmenityWizard, que directamente se salta el paso).
 */
export function ResidentUnitField({
  value,
  onChange,
  disabled,
  invalid,
  id,
}: ResidentUnitFieldProps) {
  // Comparte queryKey con ResidentHome / StatementPage / ReserveAmenityWizard, así
  // que normalmente pega en caché. El staleTime evita que montar este observador
  // dispare un refetch de fondo de una lista que ya está en memoria (regla de infra).
  const { data: ownUnits = [], isLoading } = useQuery({
    queryKey: ['unit', 'my'],
    queryFn: () => UnitResource.my(),
    staleTime: 5 * 60 * 1000,
  })

  const onlyUnit = ownUnits.length === 1 ? ownUnits[0] : undefined

  useEffect(() => {
    if (!onlyUnit || value === onlyUnit.id) return
    onChange(onlyUnit.id)
  }, [onlyUnit, value, onChange])

  if (isLoading) {
    return (
      <p className='flex h-9 items-center text-[13px] text-[var(--ink-3)]'>
        Cargando tus unidades…
      </p>
    )
  }

  if (ownUnits.length === 0) {
    return (
      <p className='text-[13px] text-[var(--ink-3)]'>
        No tienes ninguna unidad asignada. Pide al administrador del condominio que te asocie a la
        tuya.
      </p>
    )
  }

  if (onlyUnit) {
    return (
      <Input
        id={id}
        value={unitLabel(onlyUnit)}
        readOnly
        aria-readonly
        className='bg-[var(--surface-2)] font-medium text-foreground'
      />
    )
  }

  return (
    <SearchSelect
      id={id}
      value={value}
      onChange={(v) => onChange((v as string | null) ?? '')}
      options={ownUnits}
      getOptionLabel={unitLabel}
      isDisabled={disabled}
      invalid={invalid}
      placeholder='Seleccionar unidad…'
    />
  )
}
