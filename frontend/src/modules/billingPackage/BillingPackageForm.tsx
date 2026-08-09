import {
  Field,
  FieldDescription,
  FieldError,
  FieldGroup,
  FieldLabel,
  FieldLegend,
  FieldSet,
} from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import {
  billingPackageFormSchema,
  type BillingPackageFormValues,
  type IBillingPackage,
} from '@/modules/billingPackage/schema'
import { BillingPackageResource } from '@/modules/billingPackage/services/BillingPackageResource'
import { useBillingPackageStore } from '@/modules/billingPackage/store/billingPackageStore'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import type { IFormProps } from '@/shared/interfaces/Entity'
import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo } from 'react'
import { type Resolver, Controller, useForm } from 'react-hook-form'

function buildDefaultValues(entity?: Partial<IBillingPackage> | null): BillingPackageFormValues {
  return {
    key: entity?.key ?? '',
    name: entity?.name ?? '',
    description: entity?.description ?? '',
    is_public: entity?.is_public ?? false,
    is_active: entity?.is_active ?? true,
    sort_order: entity?.sort_order ?? 0,
    suggested_unit_min: entity?.suggested_unit_min ?? null,
    suggested_unit_max: entity?.suggested_unit_max ?? null,
  }
}

export const BillingPackageForm = ({
  mode,
  entity,
  onSuccess,
  formId,
}: IFormProps<IBillingPackage>) => {
  const isEdit = mode === CrudMode.EDIT
  const defaultValues = useMemo(() => buildDefaultValues(entity), [entity])

  const crud = useBaseCrud<IBillingPackage, BillingPackageFormValues>({
    store: useBillingPackageStore,
    resource: {
      save: BillingPackageResource.create,
      update: BillingPackageResource.update,
      delete: BillingPackageResource.remove,
    },
    mode,
    entityId: entity?.id,
    initialData: entity,
  })

  const {
    register,
    handleSubmit,
    control,
    reset,
    formState: { errors },
  } = useForm<BillingPackageFormValues>({
    resolver: zodResolver(billingPackageFormSchema) as Resolver<BillingPackageFormValues>,
    defaultValues,
  })

  useEffect(() => {
    reset(defaultValues)
  }, [reset, defaultValues])

  const onFormSubmit = handleSubmit(async (values) => {
    const result = await crud.submit(values)
    if (result) {
      onSuccess?.(result)
    }
  })

  const isSaving = crud.store.isSaving

  return (
    <form id={formId} onSubmit={(e) => { e.preventDefault(); void onFormSubmit(e) }}>
      <FieldGroup>
        <RequiredFieldsLegend />

        <FieldSet>
          <FieldLegend>Información del plan</FieldLegend>
          <FieldDescription>Datos de identificación y configuración base</FieldDescription>
          <FieldGroup>
            <div className="grid grid-cols-12 gap-4">
              <Field className="col-span-12 md:col-span-6">
                <FieldLabel htmlFor="bp-key" required>Clave</FieldLabel>
                <Input
                  id="bp-key"
                  placeholder="ej. basico, pro_anual"
                  {...register('key')}
                  disabled={isSaving || isEdit}
                />
                {isEdit && (
                  <FieldDescription>La clave no se puede modificar una vez creada.</FieldDescription>
                )}
                <FieldError>{errors.key?.message}</FieldError>
              </Field>

              <Field className="col-span-12 md:col-span-6">
                <FieldLabel htmlFor="bp-sort-order">Orden</FieldLabel>
                <Input
                  id="bp-sort-order"
                  type="number"
                  min={0}
                  placeholder="0"
                  {...register('sort_order')}
                  disabled={isSaving}
                />
                <FieldDescription>Posición en la lista (menor = primero)</FieldDescription>
              </Field>

              <Field className="col-span-12">
                <FieldLabel htmlFor="bp-name" required>Nombre</FieldLabel>
                <Input
                  id="bp-name"
                  placeholder="Ej. Plan Básico"
                  {...register('name')}
                  disabled={isSaving}
                />
                <FieldError>{errors.name?.message}</FieldError>
              </Field>

              <Field className="col-span-12">
                <FieldLabel htmlFor="bp-description">Descripción</FieldLabel>
                <Input
                  id="bp-description"
                  placeholder="Descripción breve del plan..."
                  {...register('description')}
                  disabled={isSaving}
                />
              </Field>
            </div>
          </FieldGroup>
        </FieldSet>

        <FieldSet>
          <FieldLegend>Visibilidad</FieldLegend>
          <FieldGroup>
            <div className="grid grid-cols-12 gap-4">
              <Field className="col-span-12 sm:col-span-6">
                <FieldLabel htmlFor="bp-is-public">Plan público</FieldLabel>
                <Controller
                  name="is_public"
                  control={control}
                  render={({ field }) => (
                    <Switch
                      id="bp-is-public"
                      checked={field.value ?? false}
                      onCheckedChange={field.onChange}
                      disabled={isSaving}
                    />
                  )}
                />
                <FieldDescription>Visible para clientes en el cotizador</FieldDescription>
              </Field>

              <Field className="col-span-12 sm:col-span-6">
                <FieldLabel htmlFor="bp-is-active">Activo</FieldLabel>
                <Controller
                  name="is_active"
                  control={control}
                  render={({ field }) => (
                    <Switch
                      id="bp-is-active"
                      checked={field.value ?? true}
                      onCheckedChange={field.onChange}
                      disabled={isSaving}
                    />
                  )}
                />
                <FieldDescription>Disponible para nuevas suscripciones</FieldDescription>
              </Field>
            </div>
          </FieldGroup>
        </FieldSet>

        <FieldSet>
          <FieldLegend>Rango de unidades sugerido</FieldLegend>
          <FieldDescription>Orientativo para el cotizador (no es un límite técnico)</FieldDescription>
          <FieldGroup>
            <div className="grid grid-cols-12 gap-4">
              <Field className="col-span-12 sm:col-span-6">
                <FieldLabel htmlFor="bp-min">Mínimo de unidades</FieldLabel>
                <Input
                  id="bp-min"
                  type="number"
                  min={0}
                  placeholder="Sin mínimo"
                  {...register('suggested_unit_min')}
                  disabled={isSaving}
                />
                <FieldError>{errors.suggested_unit_min?.message}</FieldError>
              </Field>

              <Field className="col-span-12 sm:col-span-6">
                <FieldLabel htmlFor="bp-max">Máximo de unidades</FieldLabel>
                <Input
                  id="bp-max"
                  type="number"
                  min={0}
                  placeholder="Sin máximo"
                  {...register('suggested_unit_max')}
                  disabled={isSaving}
                />
                <FieldError>{errors.suggested_unit_max?.message}</FieldError>
              </Field>
            </div>
          </FieldGroup>
        </FieldSet>
      </FieldGroup>
    </form>
  )
}
