import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { UserCombobox } from '@/modules/unit/components/UserCombobox'
import { unitUserFormSchema, type IUnitUser, type UnitUserFormValues } from '@/modules/unit/schema'
import { UnitUserResource } from '@/modules/unit/services/UnitUserResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useMemo } from 'react'
import { Controller, useForm } from 'react-hook-form'

interface AssociatePersonModalProps {
  unitId: string
  unitLabel: string
  /** Asociación existente para edición; null = nueva */
  entity: IUnitUser | null
  isOpen: boolean
  onClose: () => void
}

/**
 * Asociar persona a unidad (§6.3.4): persona existente + rol + titular de pago
 * + responsable legal + vigencia. El backend hace cumplir las invariantes
 * (un solo titular de pago activo, inquilino requiere propietario, etc.).
 */
export function AssociatePersonModal({
  unitId,
  unitLabel,
  entity,
  isOpen,
  onClose,
}: AssociatePersonModalProps) {
  const queryClient = useQueryClient()
  const isEdit = Boolean(entity)

  const defaultValues = useMemo<UnitUserFormValues>(
    () => ({
      user_id: entity?.user_id ?? '',
      unit_relationship_id: entity?.unit_relationship_id ?? '',
      is_billing_owner: entity?.is_billing_owner ?? false,
      is_legal_representative: entity?.is_legal_representative ?? false,
      start_date: entity?.start_date?.slice(0, 10) ?? new Date().toISOString().slice(0, 10),
      end_date: entity?.end_date?.slice(0, 10) ?? '',
      notes: entity?.notes ?? '',
    }),
    [entity]
  )

  const {
    register,
    handleSubmit,
    reset,
    control,
    setValue,
    watch,
    formState: { errors },
  } = useForm<UnitUserFormValues>({
    resolver: zodResolver(unitUserFormSchema),
    defaultValues,
  })

  useEffect(() => {
    if (isOpen) reset(defaultValues)
  }, [isOpen, reset, defaultValues])

  const { catalogs } = useCatalogs(
    useMemo(
      () => ({
        [CatalogType.UNIT_RELATIONSHIP]: { _sort: 'order' },
      }),
      []
    )
  )

  const mutation = useMutation({
    mutationFn: (values: UnitUserFormValues) => {
      const payload = {
        ...values,
        unit_id: unitId,
        end_date: values.end_date || undefined,
        notes: values.notes || undefined,
      }
      return entity ? UnitUserResource.update(entity.id, payload) : UnitUserResource.create(payload)
    },
    onSuccess: () => {
      toast.success(isEdit ? 'Asociación actualizada' : 'Persona asociada a la unidad')
      void queryClient.invalidateQueries({ queryKey: ['unit'] })
      onClose()
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError
          ? formatApiErrorMessage(error)
          : 'No se pudo guardar la asociación'
      )
    },
  })

  const selectedUserId = watch('user_id')

  return (
    <Sheet open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <SheetContent
        side='right'
        className='flex flex-col gap-0 p-0 w-full sm:max-w-xl'
        aria-describedby={undefined}
      >
        <SheetHeader className='shrink-0 px-6 pt-6 pb-2'>
          <SheetTitle>
            {isEdit ? `Editar asociación · ${unitLabel}` : `Asociar persona a ${unitLabel}`}
          </SheetTitle>
        </SheetHeader>

        <form
          className='min-h-0 flex-1 overflow-y-auto px-6 py-2'
          onSubmit={handleSubmit((values) => mutation.mutate(values))}
        >
          <FieldGroup>
            <RequiredFieldsLegend />
            <Field>
              <FieldLabel htmlFor='assoc-user' required>
                Persona
              </FieldLabel>
              <UserCombobox
                id='assoc-user'
                value={selectedUserId}
                initialLabel={entity?.user?.name}
                disabled={mutation.isPending || isEdit}
                invalid={!!errors.user_id}
                onSelect={(user) => setValue('user_id', user.id, { shouldValidate: true })}
                onClear={() => setValue('user_id', '')}
              />
              {isEdit && (
                <FieldDescription>
                  La persona no se puede cambiar; cierra esta asociación y crea una nueva.
                </FieldDescription>
              )}
              <FieldError>{errors.user_id?.message}</FieldError>
            </Field>

            <div className='grid grid-cols-12 gap-4'>
              <Field className='col-span-12 md:col-span-6'>
                <FieldLabel htmlFor='assoc-rel' required>
                  Rol
                </FieldLabel>
                <Controller
                  name='unit_relationship_id'
                  control={control}
                  render={({ field }) => (
                    <SearchSelect
                      id='assoc-rel'
                      value={field.value}
                      onChange={(v) => field.onChange((v as string | null) ?? '')}
                      options={catalogs[CatalogType.UNIT_RELATIONSHIP] ?? []}
                      isDisabled={mutation.isPending}
                      invalid={!!errors.unit_relationship_id}
                      placeholder='Seleccionar rol…'
                    />
                  )}
                />
                <FieldError>{errors.unit_relationship_id?.message}</FieldError>
              </Field>

              <Field className='col-span-6 md:col-span-3'>
                <FieldLabel htmlFor='assoc-start' required>
                  Inicio
                </FieldLabel>
                <Input
                  id='assoc-start'
                  type='date'
                  {...register('start_date')}
                  disabled={mutation.isPending}
                />
                <FieldError>{errors.start_date?.message}</FieldError>
              </Field>

              <Field className='col-span-6 md:col-span-3'>
                <FieldLabel htmlFor='assoc-end'>Fin</FieldLabel>
                <Input
                  id='assoc-end'
                  type='date'
                  {...register('end_date')}
                  disabled={mutation.isPending}
                />
                <FieldDescription>Vacío = indefinida</FieldDescription>
              </Field>
            </div>

            <div className='flex flex-col gap-2.5'>
              <Controller
                name='is_billing_owner'
                control={control}
                render={({ field }) => (
                  <label className='flex cursor-pointer items-center gap-2 text-sm'>
                    <Checkbox
                      checked={field.value}
                      onCheckedChange={(c) => field.onChange(c === true)}
                      disabled={mutation.isPending}
                    />
                    Es titular de pago (recibe el estado de cuenta)
                  </label>
                )}
              />
              <Controller
                name='is_legal_representative'
                control={control}
                render={({ field }) => (
                  <label className='flex cursor-pointer items-center gap-2 text-sm'>
                    <Checkbox
                      checked={field.value}
                      onCheckedChange={(c) => field.onChange(c === true)}
                      disabled={mutation.isPending}
                    />
                    Es responsable legal
                  </label>
                )}
              />
            </div>

            <Field>
              <FieldLabel htmlFor='assoc-notes'>Notas</FieldLabel>
              <Textarea
                id='assoc-notes'
                rows={2}
                className='resize-none'
                {...register('notes')}
                disabled={mutation.isPending}
              />
            </Field>
          </FieldGroup>
        </form>

        <SheetFooter className='shrink-0 px-6 pb-6 pt-4 border-t border-border flex-row justify-end gap-2'>
          <Button
            type='button'
            variant='outline'
            onClick={onClose}
            disabled={mutation.isPending}
            className='min-w-[80px]'
          >
            Cancelar
          </Button>
          <Button
            type='button'
            onClick={handleSubmit((values) => mutation.mutate(values))}
            disabled={mutation.isPending}
            className='min-w-[150px]'
          >
            {mutation.isPending ? 'Guardando…' : 'Guardar asociación'}
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}
