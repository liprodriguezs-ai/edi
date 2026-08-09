import { FieldGroup, FieldLegend, FieldSet } from '@/components/ui/field'
import { AMENITY_TARGET_NAMES, type AmenityFormValues } from '@/modules/amenity/schema'
import {
  SingleTargetSelector,
  type SingleTargetCatalogs,
} from '@/shared/components/target/SingleTargetSelector'
import type { Control, FieldErrors, UseFormRegister } from 'react-hook-form'

export interface AmenityTargetFieldsProps {
  control: Control<AmenityFormValues>
  register: UseFormRegister<AmenityFormValues>
  errors: FieldErrors<AmenityFormValues>
  targetType: string
  targetUnitId: string | undefined
  targetUnitIds: string[]
  catalogs: SingleTargetCatalogs
  disabled: boolean
  singleUnitLabel: string
  onSingleUnitLabelChange: (_label: string) => void
  onUnitSelect: (_id: string) => void
  onUnitIdsChange: (_ids: string[]) => void
}

/**
 * FieldSet "Alcance" del `AmenityForm`, extraído para mantener el archivo bajo el límite de
 * líneas de ESLint (mismo motivo que `AmenityCostDepositFields`). Envuelve el selector
 * compartido `SingleTargetSelector` — misma pieza que usa Chat para el alcance de lectura de
 * un canal — sin `availableTypes` (el default del componente ya coincide con el set que
 * amenity-visibility-target pide) ni `previewFetcher` (no hay endpoint de vista previa en
 * esta entrega).
 */
export function AmenityTargetFields({
  control,
  register,
  errors,
  targetType,
  targetUnitId,
  targetUnitIds,
  catalogs,
  disabled,
  singleUnitLabel,
  onSingleUnitLabelChange,
  onUnitSelect,
  onUnitIdsChange,
}: AmenityTargetFieldsProps) {
  return (
    <FieldSet>
      <FieldLegend>Alcance</FieldLegend>
      <FieldGroup>
        <SingleTargetSelector
          control={control}
          register={register}
          errors={errors}
          names={AMENITY_TARGET_NAMES}
          sectionLabel='Alcance — ¿quién puede ver y reservar?'
          targetType={targetType}
          targetUnitId={targetUnitId}
          targetUnitIds={targetUnitIds}
          catalogs={catalogs}
          disabled={disabled}
          idPrefix='amenity'
          floorIsMulti
          singleUnitLabel={singleUnitLabel}
          onSingleUnitLabelChange={onSingleUnitLabelChange}
          onUnitSelect={onUnitSelect}
          onUnitIdsChange={onUnitIdsChange}
        />
      </FieldGroup>
    </FieldSet>
  )
}
