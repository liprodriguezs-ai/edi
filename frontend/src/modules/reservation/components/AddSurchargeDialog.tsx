import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { surchargeFormSchema, type SurchargeFormValues } from '@/modules/reservation/schema'
import { AmenityReservationResource } from '@/modules/reservation/services/AmenityReservationResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { pesosToMinorUnits } from '@/shared/utils/money'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useEffect, useMemo } from 'react'
import { Controller, useForm, type UseFormSetError } from 'react-hook-form'

export interface AddSurchargeDialogProps {
  isOpen: boolean
  onClose: () => void
  reservationId: string
  onCreated: () => void
}

function buildDefaultValues(): SurchargeFormValues {
  return {
    charge_concept_id: '',
    amount: undefined as unknown as number,
    description: '',
  }
}

/**
 * Vuelca los errores de validación (`{message, errors}` — 422 de FormRequest)
 * a campos del form. Un 422 de compuerta de negocio (`{message}` sin `errors`
 * — reservación no confirmada/completada) NO tiene nada que mapear aquí; solo
 * se muestra el toast general con `formatApiErrorMessage`.
 */
function applyServerFieldErrors(
  error: ApiError,
  setError: UseFormSetError<SurchargeFormValues>
): void {
  const errors = error.payload?.errors
  if (!errors) return

  const fieldKeys: Array<keyof SurchargeFormValues> = ['charge_concept_id', 'amount', 'description']
  for (const field of fieldKeys) {
    const messages = errors[field]
    if (messages?.length) {
      setError(field, { type: 'server', message: messages[0] })
    }
  }
}

/**
 * Diálogo "Agregar cargo adicional" (S9 — incidente/daño) sobre una
 * reservación CONFIRMADA o COMPLETADA. Backend responde DOS formas de 422:
 * (a) compuerta de negocio — `{message}` sin `errors` (reservación no
 * confirmada/completada) → solo toast; (b) validación de campos —
 * `{message, errors}` → además vuelca el error al campo correspondiente.
 * Shell delgado (§2.9): el catálogo de conceptos carga lazy recién al abrir.
 */
export function AddSurchargeDialog({
  isOpen,
  onClose,
  reservationId,
  onCreated,
}: AddSurchargeDialogProps) {
  if (!isOpen) return null
  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className='max-w-md p-0' aria-describedby={undefined}>
        <AddSurchargeDialogBody
          onClose={onClose}
          reservationId={reservationId}
          onCreated={onCreated}
        />
      </DialogContent>
    </Dialog>
  )
}

function AddSurchargeDialogBody({
  onClose,
  reservationId,
  onCreated,
}: Omit<AddSurchargeDialogProps, 'isOpen'>) {
  const { catalogs, enable } = useLazyCatalogs(
    useMemo(() => ({ [CatalogType.CHARGE_CONCEPT]: { _sort: 'name' } }), [])
  )
  useEffect(() => {
    enable()
  }, [enable])

  const defaultValues = useMemo(() => buildDefaultValues(), [])

  const {
    register,
    handleSubmit,
    control,
    setError,
    formState: { errors },
  } = useForm<SurchargeFormValues>({
    resolver: zodResolver(surchargeFormSchema),
    defaultValues,
  })

  const mutation = useMutation({
    mutationFn: (values: SurchargeFormValues) =>
      AmenityReservationResource.addSurcharge(reservationId, {
        charge_concept_id: values.charge_concept_id,
        amount: pesosToMinorUnits(values.amount),
        description: values.description || undefined,
      }),
    onSuccess: () => {
      toast.success('Cargo adicional aplicado')
      onCreated()
      onClose()
    },
    onError: (error) => {
      if (error instanceof ApiError) {
        applyServerFieldErrors(error, setError)
        toast.error(formatApiErrorMessage(error))
      } else {
        toast.error('No se pudo aplicar el cargo adicional')
      }
    },
  })

  const submit = handleSubmit((values) => mutation.mutate(values))
  const conceptOptions = catalogs[CatalogType.CHARGE_CONCEPT] ?? []

  return (
    <>
      <DialogHeader className='px-6 pt-6'>
        <DialogTitle>Agregar cargo adicional</DialogTitle>
      </DialogHeader>

      <form className='px-6 py-2' onSubmit={submit}>
        <FieldGroup>
          <RequiredFieldsLegend />
          <Field>
            <FieldLabel htmlFor='surcharge-concept' required>
              Concepto
            </FieldLabel>
            <Controller
              name='charge_concept_id'
              control={control}
              render={({ field }) => (
                <SearchSelect
                  id='surcharge-concept'
                  value={field.value}
                  onChange={(v) => field.onChange((v as string | null) ?? '')}
                  options={conceptOptions}
                  isDisabled={mutation.isPending}
                  invalid={!!errors.charge_concept_id}
                  placeholder='Seleccionar concepto…'
                />
              )}
            />
            <FieldError>{errors.charge_concept_id?.message}</FieldError>
          </Field>

          <Field>
            <FieldLabel htmlFor='surcharge-amount' required>
              Monto
            </FieldLabel>
            <div className='relative'>
              <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                $
              </span>
              <Input
                id='surcharge-amount'
                type='number'
                step='0.01'
                min={0}
                placeholder='0.00'
                className='pl-7'
                {...register('amount', {
                  setValueAs: (v) => (v === '' || v == null ? undefined : Number(v)),
                })}
                disabled={mutation.isPending}
              />
            </div>
            <FieldError>{errors.amount?.message}</FieldError>
          </Field>

          <Field>
            <FieldLabel htmlFor='surcharge-description'>Descripción</FieldLabel>
            <Textarea
              id='surcharge-description'
              rows={3}
              className='resize-none'
              placeholder='Detalle del incidente/daño (opcional)…'
              {...register('description')}
              disabled={mutation.isPending}
            />
            <FieldError>{errors.description?.message}</FieldError>
          </Field>
        </FieldGroup>
      </form>

      <DialogFooter className='px-6 pb-6'>
        <Button type='button' variant='outline' onClick={onClose} disabled={mutation.isPending}>
          Cancelar
        </Button>
        <Button type='button' onClick={() => void submit()} disabled={mutation.isPending}>
          {mutation.isPending ? 'Aplicando…' : 'Aplicar cargo'}
        </Button>
      </DialogFooter>
    </>
  )
}
