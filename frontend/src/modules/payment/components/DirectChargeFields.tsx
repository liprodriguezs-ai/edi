import { Field, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import type { RegisterPaymentValues } from '@/modules/payment/schema'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { useCallback, useEffect, useMemo } from 'react'
import type { FieldErrors, UseFormRegister, UseFormSetValue } from 'react-hook-form'

interface ConceptOption {
  id: string
  name: string
}

/** Opción sintética que representa "Otro" en el select de concepto — nunca viaja al backend. */
const OTHER_CONCEPT_OPTION: ConceptOption = { id: '__OTHER__', name: 'Otro (especificar motivo)' }

const CONCEPT_CATALOG_REQUEST = {
  [CatalogType.CHARGE_CONCEPT]: { _sort: 'name' },
} as const

interface DirectChargeFieldsProps {
  conceptMode: 'catalog' | 'other'
  chargeConceptId: string | undefined
  errors: FieldErrors<RegisterPaymentValues>
  register: UseFormRegister<RegisterPaymentValues>
  setValue: UseFormSetValue<RegisterPaymentValues>
  isPending: boolean
}

/**
 * Campos exclusivos del modo "Cobro directo" del RegisterPaymentSheet:
 * selector de concepto (catálogo u "Otro" con texto libre → concept_free_text).
 *
 * El catálogo de conceptos se carga LAZY (§2.12 del skill react-frontend-architecture): este
 * componente solo se monta al ENTRAR al modo directo, y `enable()` dispara el
 * fetch recién en ese momento — abrir el sheet en modo "Cargos pendientes"
 * jamás pide CHARGE_CONCEPT a `POST /selects`.
 */
export function DirectChargeFields({
  conceptMode,
  chargeConceptId,
  errors,
  register,
  setValue,
  isPending,
}: DirectChargeFieldsProps) {
  const { catalogs, enable } = useLazyCatalogs(CONCEPT_CATALOG_REQUEST)

  useEffect(() => {
    enable()
  }, [enable])

  const conceptOptions = useMemo<ConceptOption[]>(() => {
    const catalogItems = (catalogs[CatalogType.CHARGE_CONCEPT] ?? []).map((c) => ({
      id: c.id,
      name: c.name,
    }))
    return [...catalogItems, OTHER_CONCEPT_OPTION]
  }, [catalogs])

  const isOtherConcept = conceptMode === 'other'
  const conceptSelectValue = isOtherConcept ? OTHER_CONCEPT_OPTION.id : chargeConceptId || ''

  const handleConceptChange = useCallback(
    (value: string | string[] | null) => {
      const selected = Array.isArray(value) ? value[0] : value
      if (selected === OTHER_CONCEPT_OPTION.id) {
        setValue('concept_mode', 'other', { shouldValidate: true })
        setValue('charge_concept_id', '', { shouldValidate: true })
      } else {
        setValue('concept_mode', 'catalog', { shouldValidate: true })
        setValue('charge_concept_id', selected ?? '', { shouldValidate: true })
      }
    },
    [setValue]
  )

  return (
    <>
      <Field>
        <FieldLabel htmlFor='pay-direct-concept' required>
          Concepto
        </FieldLabel>
        <SearchSelect
          id='pay-direct-concept'
          value={conceptSelectValue}
          onChange={handleConceptChange}
          options={conceptOptions}
          isDisabled={isPending}
          invalid={!!errors.charge_concept_id}
          placeholder='Seleccionar concepto…'
        />
        <FieldError>{errors.charge_concept_id?.message}</FieldError>
      </Field>

      {isOtherConcept && (
        <Field>
          <FieldLabel htmlFor='pay-direct-concept-text' required>
            Motivo del cobro
          </FieldLabel>
          <Input
            id='pay-direct-concept-text'
            placeholder='Describe el motivo…'
            {...register('concept_free_text')}
            disabled={isPending}
          />
          <FieldError>{errors.concept_free_text?.message}</FieldError>
        </Field>
      )}
    </>
  )
}
