import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import {
  chargeConceptFormSchema,
  type ChargeConceptFormValues,
  type IChargeConcept,
} from '@/modules/chargeConcept/schema'
import { ChargeConceptResource } from '@/modules/chargeConcept/services/ChargeConceptResource'
import { useChargeConceptStore } from '@/modules/chargeConcept/store/chargeConceptStore'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo } from 'react'
import { Controller, useForm } from 'react-hook-form'

interface CatalogItem {
  id: string
  name?: string
}

const EMPTY_FORM_VALUES: ChargeConceptFormValues = {
  code: '',
  name: '',
  description: '',
  charge_concept_category_id: '',
  is_active: true,
}

function buildDefaultValues(entity: IChargeConcept | null): ChargeConceptFormValues {
  if (!entity) return { ...EMPTY_FORM_VALUES }

  return {
    code: entity.code,
    name: entity.name,
    description: entity.description ?? '',
    charge_concept_category_id: entity.charge_concept_category_id ?? '',
    is_active: entity.is_active ?? true,
  }
}

export interface ChargeConceptFormModalProps {
  isOpen: boolean
  mode: CrudMode
  entity: IChargeConcept | null
  onClose: () => void
}

interface ChargeConceptFormBodyProps {
  mode: CrudMode
  entity: IChargeConcept | null
  onClose: () => void
}

/**
 * Cuerpo del form (alta + edición). Vive en un componente HIJO que SOLO se monta
 * cuando el sheet está abierto, así `useCatalogs` (POST /selects) NO dispara al
 * montar la lista — solo cuando el usuario abre el modal.
 */
function ChargeConceptFormBody({ mode, entity, onClose }: ChargeConceptFormBodyProps) {
  const isSaving = useChargeConceptStore((s) => s.isSaving)

  const crud = useBaseCrud<IChargeConcept, Record<string, unknown>>({
    store: useChargeConceptStore,
    resource: {
      save: ChargeConceptResource.create,
      update: ChargeConceptResource.update,
    },
    mode,
    entityId: entity?.id,
    initialData: entity,
  })

  const defaultValues = useMemo(() => buildDefaultValues(entity), [entity])

  const {
    register,
    handleSubmit,
    reset,
    control,
    formState: { errors },
  } = useForm<ChargeConceptFormValues>({
    resolver: zodResolver(chargeConceptFormSchema),
    defaultValues,
  })

  useEffect(() => {
    reset(defaultValues)
  }, [reset, defaultValues])

  const { catalogs } = useCatalogs(
    useMemo(() => ({ [CatalogType.CHARGE_CONCEPT_CATEGORY]: { _sort: 'order' } }), [])
  )

  const doSubmit = handleSubmit(async (values) => {
    const payload: Record<string, unknown> = {
      code: values.code,
      name: values.name,
      description: values.description || null,
      charge_concept_category_id: values.charge_concept_category_id,
      is_active: values.is_active,
    }
    const result = await crud.submit(payload)
    if (result) onClose()
  })

  return (
    <>
      <SheetHeader className='shrink-0 px-6 pt-6 pb-2'>
        <SheetTitle>
          {mode === CrudMode.EDIT ? 'Editar concepto' : 'Nuevo concepto de cobro'}
        </SheetTitle>
      </SheetHeader>

      <form
        className='min-h-0 flex-1 overflow-y-auto px-6 py-2'
        onSubmit={(e) => {
          e.preventDefault()
          void doSubmit(e)
        }}
      >
        <FieldGroup>
          <RequiredFieldsLegend />
          <div className='grid grid-cols-12 gap-4'>
            <Field className='col-span-12 md:col-span-5'>
              <FieldLabel htmlFor='concept-code' required>
                Clave única
              </FieldLabel>
              <Input
                id='concept-code'
                placeholder='MANT_MENSUAL'
                className='font-mono uppercase'
                {...register('code', { setValueAs: (v: string) => v?.toUpperCase().trim() })}
                disabled={isSaving || mode === CrudMode.EDIT}
              />
              <FieldDescription>Solo mayúsculas, números y guión bajo</FieldDescription>
              <FieldError>{errors.code?.message}</FieldError>
            </Field>
            <Field className='col-span-12 md:col-span-7'>
              <FieldLabel htmlFor='concept-name' required>
                Nombre visible
              </FieldLabel>
              <Input
                id='concept-name'
                placeholder='Mantenimiento mensual'
                {...register('name')}
                disabled={isSaving}
              />
              <FieldError>{errors.name?.message}</FieldError>
            </Field>

            <Field className='col-span-12'>
              <FieldLabel htmlFor='concept-desc'>Descripción</FieldLabel>
              <Textarea
                id='concept-desc'
                rows={2}
                className='resize-none'
                {...register('description')}
                disabled={isSaving}
              />
              <FieldError>{errors.description?.message}</FieldError>
            </Field>

            <Field className='col-span-12 md:col-span-6'>
              <FieldLabel htmlFor='concept-category' required>
                Categoría
              </FieldLabel>
              <Controller
                name='charge_concept_category_id'
                control={control}
                render={({ field }) => (
                  <SearchSelect<CatalogItem>
                    id='concept-category'
                    value={field.value}
                    onChange={(v) => field.onChange((v as string | null) ?? '')}
                    options={catalogs[CatalogType.CHARGE_CONCEPT_CATEGORY] ?? []}
                    isDisabled={isSaving}
                    invalid={!!errors.charge_concept_category_id}
                    placeholder='Seleccionar…'
                    getOptionLabel={(o) => o.name ?? ''}
                  />
                )}
              />
              <FieldError>{errors.charge_concept_category_id?.message}</FieldError>
            </Field>

            <Field className='col-span-12 md:col-span-6'>
              <FieldLabel htmlFor='concept-active'>Activo</FieldLabel>
              <Controller
                name='is_active'
                control={control}
                render={({ field }) => (
                  <Switch
                    id='concept-active'
                    checked={field.value}
                    onCheckedChange={field.onChange}
                    disabled={isSaving}
                  />
                )}
              />
              <FieldDescription>Se puede usar para crear programaciones y cobros</FieldDescription>
            </Field>
          </div>
        </FieldGroup>
      </form>

      <SheetFooter className='shrink-0 px-6 pb-6 pt-4 border-t border-border flex-row justify-end gap-2'>
        <Button type='button' variant='outline' onClick={onClose} disabled={isSaving}>
          Cancelar
        </Button>
        <Button
          type='button'
          onClick={() => void doSubmit()}
          disabled={isSaving}
          className='min-w-[140px]'
        >
          {isSaving ? 'Guardando…' : 'Guardar concepto'}
        </Button>
      </SheetFooter>
    </>
  )
}

/**
 * Form de concepto de cobro en SHEET (alta + edición), adelgazado a la IDENTIDAD
 * del cobrable: code (UI "Clave"), nombre, descripción, categoría, activo. El monto, la
 * periodicidad y la vigencia viven en las políticas del concepto (chargePolicy).
 *
 * Gating de carga: cuando el sheet está cerrado el componente retorna null, por lo
 * que el cuerpo (y su `useCatalogs`) ni se monta. Montar la lista NO dispara
 * /selects; solo se carga al abrir el modal.
 */
export function ChargeConceptFormModal({
  isOpen,
  mode,
  entity,
  onClose,
}: ChargeConceptFormModalProps) {
  if (!isOpen) return null

  return (
    <Sheet open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <SheetContent
        side='right'
        className='flex flex-col gap-0 p-0 w-full sm:max-w-xl'
        aria-describedby={undefined}
      >
        <ChargeConceptFormBody mode={mode} entity={entity} onClose={onClose} />
      </SheetContent>
    </Sheet>
  )
}
