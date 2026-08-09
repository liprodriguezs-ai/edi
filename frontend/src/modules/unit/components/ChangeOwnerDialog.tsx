import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { UserCombobox } from '@/modules/unit/components/UserCombobox'
import { changeOwnerSchema, type ChangeOwnerValues, type IUnitUser } from '@/modules/unit/schema'
import { UnitResource } from '@/modules/unit/services/UnitResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { toast } from '@/shared/hooks/useToast'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { TriangleAlert } from 'lucide-react'
import { useEffect } from 'react'
import { useForm } from 'react-hook-form'

interface ChangeOwnerDialogProps {
  unitId: string
  unitLabel: string
  currentOwner: IUnitUser | null
  isOpen: boolean
  onClose: () => void
}

/**
 * Cambio de propietario (§6.3.5) — acción crítica con doble confirmación:
 * cierra la asociación del propietario actual y crea la nueva como titular de pago.
 */
export function ChangeOwnerDialog({
  unitId,
  unitLabel,
  currentOwner,
  isOpen,
  onClose,
}: ChangeOwnerDialogProps) {
  const queryClient = useQueryClient()

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    watch,
    formState: { errors },
  } = useForm<ChangeOwnerValues>({
    resolver: zodResolver(changeOwnerSchema),
    defaultValues: {
      user_id: '',
      change_date: new Date().toISOString().slice(0, 10),
      notes: '',
      confirmation: '',
    },
  })

  useEffect(() => {
    if (isOpen) {
      reset({
        user_id: '',
        change_date: new Date().toISOString().slice(0, 10),
        notes: '',
        confirmation: '',
      })
    }
  }, [isOpen, reset])

  const mutation = useMutation({
    mutationFn: (values: ChangeOwnerValues) =>
      UnitResource.changeOwner(unitId, {
        user_id: values.user_id,
        change_date: values.change_date || undefined,
        notes: values.notes || undefined,
      }),
    onSuccess: () => {
      toast.success('Propietario actualizado correctamente')
      void queryClient.invalidateQueries({ queryKey: ['unit'] })
      onClose()
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError
          ? formatApiErrorMessage(error)
          : 'No se pudo cambiar el propietario'
      )
    },
  })

  const selectedUserId = watch('user_id')

  return (
    <Sheet open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <SheetContent
        side='right'
        className='flex flex-col gap-0 p-0 w-full sm:max-w-lg'
        aria-describedby={undefined}
      >
        <SheetHeader className='shrink-0 px-6 pt-6 pb-2'>
          <SheetTitle className='flex items-center gap-2'>
            <TriangleAlert className='size-5 text-[#C0533C]' aria-hidden />
            Cambio de propietario · {unitLabel}
          </SheetTitle>
        </SheetHeader>

        <form
          className='flex-1 overflow-y-auto px-6 py-2'
          onSubmit={handleSubmit((values) => mutation.mutate(values))}
        >
          <FieldGroup>
            <RequiredFieldsLegend />
            {currentOwner && (
              <div className='rounded-[10px] border border-border bg-[var(--surface-2)] px-4 py-3 text-[13px]'>
                <span className='block text-[11px] font-semibold uppercase tracking-[0.08em] text-[var(--ink-3)]'>
                  Propietario actual
                </span>
                <b className='font-semibold'>{currentOwner.user?.name ?? '—'}</b>
                {currentOwner.start_date && (
                  <span className='text-[var(--ink-3)]'>
                    {' '}
                    · desde {displayFormatDate(currentOwner.start_date, 'mediumDate')}
                  </span>
                )}
              </div>
            )}

            <Field>
              <FieldLabel htmlFor='owner-user' required>
                Nuevo propietario
              </FieldLabel>
              <UserCombobox
                id='owner-user'
                value={selectedUserId}
                disabled={mutation.isPending}
                invalid={!!errors.user_id}
                onSelect={(user) => setValue('user_id', user.id, { shouldValidate: true })}
                onClear={() => setValue('user_id', '')}
              />
              <FieldError>{errors.user_id?.message}</FieldError>
            </Field>

            <Field className='max-w-[200px]'>
              <FieldLabel htmlFor='owner-date'>Fecha de cambio</FieldLabel>
              <Input
                id='owner-date'
                type='date'
                {...register('change_date')}
                disabled={mutation.isPending}
              />
            </Field>

            <div className='rounded-[10px] border border-[#E8D5CF] bg-[#FBF3F1] px-4 py-3 text-[12.5px] leading-relaxed text-[#8A4838]'>
              Esto cerrará la asociación del propietario actual, creará la nueva como
              <b className='font-semibold'> titular de pago</b>, conservará el histórico y mantendrá
              los cargos pendientes en la unidad.
            </div>

            <Field>
              <FieldLabel htmlFor='owner-notes'>Notas</FieldLabel>
              <Textarea
                id='owner-notes'
                rows={2}
                className='resize-none'
                {...register('notes')}
                disabled={mutation.isPending}
              />
            </Field>

            <Field>
              <FieldLabel htmlFor='owner-confirm' required>
                Escribe «CAMBIAR» para confirmar
              </FieldLabel>
              <Input
                id='owner-confirm'
                autoComplete='off'
                placeholder='CAMBIAR'
                {...register('confirmation')}
                disabled={mutation.isPending}
              />
              <FieldDescription>
                Acción crítica — queda registrada en la auditoría.
              </FieldDescription>
              <FieldError>{errors.confirmation?.message}</FieldError>
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
            variant='destructive'
            onClick={handleSubmit((values) => mutation.mutate(values))}
            disabled={mutation.isPending}
            className='min-w-[150px]'
          >
            {mutation.isPending ? 'Cambiando…' : 'Confirmar cambio'}
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}
