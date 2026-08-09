import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { VehicleResource } from '@/modules/vehicle/services/VehicleResource'
import { vehicleFormSchema, type VehicleFormValues } from '@/modules/vehicle/schema'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { toast } from '@/shared/hooks/useToast'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useEffect } from 'react'
import { useForm } from 'react-hook-form'

interface VehicleFormModalStandaloneProps {
  unitId: string
  isOpen: boolean
  onClose: () => void
  onSaved: () => void
}

/** Alta rápida de vehículo desde el detalle de unidad (unit_id fijo). */
export function VehicleFormModalStandalone({
  unitId,
  isOpen,
  onClose,
  onSaved,
}: VehicleFormModalStandaloneProps) {
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<VehicleFormValues>({
    resolver: zodResolver(vehicleFormSchema),
    defaultValues: { unit_id: unitId, plate: '', make: '', model: '', color: '', year: null },
  })

  useEffect(() => {
    if (isOpen) reset({ unit_id: unitId, plate: '', make: '', model: '', color: '', year: null })
  }, [isOpen, unitId, reset])

  const mutation = useMutation({
    mutationFn: (values: VehicleFormValues) => VehicleResource.create(values),
    onSuccess: () => {
      toast.success('Vehículo registrado')
      onSaved()
      onClose()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError
          ? formatApiErrorMessage(error)
          : 'No se pudo registrar el vehículo'
      ),
  })

  return (
    <Sheet open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <SheetContent
        side='right'
        className='flex flex-col gap-0 p-0 w-full sm:max-w-md'
        aria-describedby={undefined}
      >
        <SheetHeader className='shrink-0 px-6 pt-6 pb-2'>
          <SheetTitle>Registrar vehículo</SheetTitle>
        </SheetHeader>
        <form
          className='flex-1 overflow-y-auto px-6 py-2'
          onSubmit={handleSubmit((values) => mutation.mutate(values))}
        >
          <FieldGroup>
            <RequiredFieldsLegend />
            <div className='grid grid-cols-12 gap-4'>
              <Field className='col-span-6'>
                <FieldLabel htmlFor='veh-plate' required>
                  Placa
                </FieldLabel>
                <Input
                  id='veh-plate'
                  placeholder='ABC-123-4'
                  {...register('plate')}
                  disabled={mutation.isPending}
                />
                <FieldError>{errors.plate?.message}</FieldError>
              </Field>
              <Field className='col-span-6'>
                <FieldLabel htmlFor='veh-year'>Año</FieldLabel>
                <Input
                  id='veh-year'
                  type='number'
                  placeholder='2020'
                  {...register('year', {
                    setValueAs: (v) => (v === '' || v == null ? null : Number(v)),
                  })}
                  disabled={mutation.isPending}
                />
                <FieldError>{errors.year?.message}</FieldError>
              </Field>
              <Field className='col-span-6'>
                <FieldLabel htmlFor='veh-make'>Marca</FieldLabel>
                <Input
                  id='veh-make'
                  placeholder='Honda'
                  {...register('make')}
                  disabled={mutation.isPending}
                />
              </Field>
              <Field className='col-span-6'>
                <FieldLabel htmlFor='veh-model'>Modelo</FieldLabel>
                <Input
                  id='veh-model'
                  placeholder='Civic'
                  {...register('model')}
                  disabled={mutation.isPending}
                />
              </Field>
              <Field className='col-span-6'>
                <FieldLabel htmlFor='veh-color'>Color</FieldLabel>
                <Input
                  id='veh-color'
                  placeholder='Blanco'
                  {...register('color')}
                  disabled={mutation.isPending}
                />
              </Field>
            </div>
          </FieldGroup>
        </form>
        <SheetFooter className='shrink-0 px-6 pb-6 pt-4 border-t border-border flex-row justify-end gap-2'>
          <Button type='button' variant='outline' onClick={onClose} disabled={mutation.isPending}>
            Cancelar
          </Button>
          <Button
            type='button'
            onClick={handleSubmit((values) => mutation.mutate(values))}
            disabled={mutation.isPending}
            className='min-w-[110px]'
          >
            {mutation.isPending ? 'Guardando…' : 'Registrar'}
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}
