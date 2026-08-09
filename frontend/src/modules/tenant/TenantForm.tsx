import { Button } from '@/components/ui/button'
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { TENANT_STATUS_CONFIG, tenantFormSchema, type ITenant, type TenantFormValues, type TenantStatus } from '@/modules/tenant/schema'
import { TenantResource } from '@/modules/tenant/services/TenantResource'
import { useTenantStore } from '@/modules/tenant/store/tenantStore'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { CrudMode } from '@/shared/enums/CrudMode'
import { useBaseCrud } from '@/shared/hooks/useBaseCrud'
import type { IFormProps } from '@/shared/interfaces/Entity'
import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useMemo } from 'react'
import { Controller, useForm } from 'react-hook-form'

/**
 * Edición de metadatos de un tenant existente: nombre + estado.
 *
 * La facturación (plan, método de pago, entitlements) se administra en el modal
 * de facturación del tenant, NO aquí. El alta de un tenant nuevo se hace en el
 * wizard (`TenantProvisionForm`) con la facturación integrada. Por eso este form
 * ya no expone los selects legacy de plan/tipo de pago (que además duplicaban
 * opciones) ni depende de `subscription_plan_id`.
 */
export const TenantForm = ({ mode, entity, onSuccess, onCancel, formId }: IFormProps<ITenant>) => {
  const defaultValues = useMemo<TenantFormValues>(
    () => ({
      name: entity?.name ?? '',
      status: entity?.status ?? 'activo',
    }),
    [entity],
  )

  const crud = useBaseCrud<ITenant, TenantFormValues>({
    store: useTenantStore,
    resource: {
      save: TenantResource.create,
      update: TenantResource.update,
      delete: TenantResource.remove,
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
  } = useForm<TenantFormValues>({
    resolver: zodResolver(tenantFormSchema),
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
    <form id={formId} onSubmit={onFormSubmit}>
      <FieldGroup>
        <RequiredFieldsLegend />
        <FieldSet>
          <FieldLegend>Información del Tenant</FieldLegend>
          <FieldDescription>Datos del espacio de trabajo</FieldDescription>
          <FieldGroup>
            <Field>
              <FieldLabel htmlFor="tenant-name" required>
                Nombre
              </FieldLabel>
              <Input
                id="tenant-name"
                placeholder="Ingresa el nombre del tenant"
                {...register('name')}
                disabled={isSaving}
              />
              <FieldDescription>Identificador único del espacio de trabajo</FieldDescription>
              <FieldError>{errors.name?.message}</FieldError>
            </Field>

            <div className="grid grid-cols-12 gap-4">
              <Field className="col-span-12 md:col-span-6">
                <FieldLabel required htmlFor="tenant-status">
                  Estado
                </FieldLabel>
                <Controller
                  name="status"
                  control={control}
                  render={({ field }) => (
                    <Select value={field.value || undefined} onValueChange={field.onChange} disabled={isSaving}>
                      <SelectTrigger id="tenant-status" className="w-full">
                        <SelectValue placeholder="Seleccionar estado..." />
                      </SelectTrigger>
                      <SelectContent>
                        {(Object.entries(TENANT_STATUS_CONFIG) as [TenantStatus, { label: string; className: string }][]).map(
                          ([value, config]) => (
                            <SelectItem key={value} value={value}>
                              {config.label}
                            </SelectItem>
                          ),
                        )}
                      </SelectContent>
                    </Select>
                  )}
                />
                <FieldError>{errors.status?.message}</FieldError>
              </Field>
            </div>
          </FieldGroup>
        </FieldSet>

        {!formId && (
          <Field orientation="horizontal" className="justify-end border-t border-gray-200 pt-6">
            {onCancel && (
              <Button type="button" variant="outline" onClick={onCancel} disabled={isSaving} className="min-w-[80px]">
                Cancelar
              </Button>
            )}
            <Button type="submit" disabled={isSaving} className="min-w-[100px]">
              {isSaving
                ? mode === CrudMode.EDIT
                  ? 'Actualizando...'
                  : 'Creando...'
                : mode === CrudMode.EDIT
                  ? 'Actualizar'
                  : 'Crear'}
            </Button>
          </Field>
        )}
      </FieldGroup>
    </form>
  )
}
