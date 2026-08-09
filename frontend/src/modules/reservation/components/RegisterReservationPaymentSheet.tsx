import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { Textarea } from '@/components/ui/textarea'
import type { IAmenity } from '@/modules/amenity/schema'
import { EARMARK_TYPE, PAYMENT_METHOD } from '@/modules/payment/schema'
import { PaymentResource } from '@/modules/payment/services/PaymentResource'
import {
  registerReservationPaymentSchema,
  type IAmenityReservation,
  type RegisterReservationPaymentValues,
} from '@/modules/reservation/schema'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { CatalogType, useLazyCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { todayLocalDateString } from '@/shared/utils/dateHelper'
import {
  formatMinorUnits,
  minorUnitsToPesos,
  moneyToMinorUnits,
  pesosToMinorUnits,
} from '@/shared/utils/money'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Paperclip } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'

export interface RegisterReservationPaymentSheetProps {
  isOpen: boolean
  onClose: () => void
  reservation: IAmenityReservation
  amenity: Pick<IAmenity, 'bank_account_id' | 'bank_account'>
  onRegistered: () => void
}

function buildDefaultValues(outstandingMinor: number): RegisterReservationPaymentValues {
  return {
    paid_at: todayLocalDateString(),
    total_amount: outstandingMinor > 0 ? minorUnitsToPesos(outstandingMinor) : 0,
    payment_method_id: PAYMENT_METHOD.CASH,
    reference: '',
    physical_receipt_number: '',
    notes: '',
  }
}

/**
 * Panel "Registrar pago" para una reservación (S13a): reutiliza `PaymentResource.register`
 * (payment/register, admin) etiquetando el pago con `earmark_type: 'amenity_reservation'` +
 * `earmark_id: <reservation.id>`. La cuenta destino NO la elige el admin — es fija, la
 * configurada en la amenidad (`amenity.bank_account_id`) — y llega como PROP desde
 * `AmenityReservationDetailPage` (que ya la trae en el mismo fetch del detalle vía
 * `amenity.bank_account` en `DETAIL_INCLUDE`), evitando un fetch redundante por cada
 * apertura del panel. Se muestra solo lectura. A diferencia de `RegisterPaymentSheet`
 * (FIFO/aplicación manual contra TODOS los cargos pendientes de la unidad), acá el backend
 * crea y liquida automáticamente un único cargo standalone por el monto del earmark — no
 * hay selección de aplicación.
 */
export function RegisterReservationPaymentSheet({
  isOpen,
  onClose,
  reservation,
  amenity,
  onRegistered,
}: RegisterReservationPaymentSheetProps) {
  if (!isOpen) return null
  return (
    <Sheet open onOpenChange={(open) => !open && onClose()}>
      <SheetContent side='right' className='flex w-full flex-col gap-0 p-0 sm:max-w-[440px]'>
        <RegisterReservationPaymentSheetBody
          onClose={onClose}
          reservation={reservation}
          amenity={amenity}
          onRegistered={onRegistered}
        />
      </SheetContent>
    </Sheet>
  )
}

function RegisterReservationPaymentSheetBody({
  onClose,
  reservation,
  amenity,
  onRegistered,
}: Omit<RegisterReservationPaymentSheetProps, 'isOpen'>) {
  const queryClient = useQueryClient()
  const fileRef = useRef<HTMLInputElement>(null)
  const [receipt, setReceipt] = useState<File | null>(null)

  const { catalogs, enable } = useLazyCatalogs(
    useMemo(() => ({ [CatalogType.PAYMENT_METHOD]: { _sort: 'order' } }), [])
  )
  useEffect(() => {
    enable()
  }, [enable])

  const bankAccountId = amenity.bank_account_id ?? undefined
  const bankAccountName = amenity.bank_account?.name

  const outstandingMinor = moneyToMinorUnits(reservation.amount_outstanding ?? 0)
  const defaultValues = useMemo(() => buildDefaultValues(outstandingMinor), [outstandingMinor])

  const {
    register,
    handleSubmit,
    control,
    watch,
    formState: { errors },
  } = useForm<RegisterReservationPaymentValues>({
    resolver: zodResolver(registerReservationPaymentSchema),
    defaultValues,
  })

  const isCash = watch('payment_method_id') === PAYMENT_METHOD.CASH

  const mutation = useMutation({
    mutationFn: ({
      values,
      bankAccountId: resolvedBankAccountId,
    }: {
      values: RegisterReservationPaymentValues
      bankAccountId: string
    }) => {
      const totalMinor = pesosToMinorUnits(values.total_amount)
      return PaymentResource.register({
        unit_id: reservation.unit_id,
        paid_at: values.paid_at,
        total_amount: totalMinor,
        payment_method_id: values.payment_method_id,
        bank_account_id: resolvedBankAccountId,
        reference: values.reference || undefined,
        physical_receipt_number: values.physical_receipt_number || undefined,
        notes: values.notes || undefined,
        receipt,
        earmark_type: EARMARK_TYPE.AMENITY_RESERVATION,
        earmark_id: reservation.id,
      })
    },
    onSuccess: (payment) => {
      toast.success(`Pago registrado · folio ${payment.receipt_number ?? ''}`)
      void queryClient.invalidateQueries({ queryKey: ['amenity-reservation'] })
      void queryClient.invalidateQueries({ queryKey: ['reservation-payments'] })
      onRegistered()
      onClose()
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo registrar el pago'
      )
    },
  })

  const submit = handleSubmit((values) => {
    if (!bankAccountId) {
      toast.error('La amenidad no tiene una cuenta destino configurada')
      return
    }
    if (!isCash && !receipt) {
      toast.error('El comprobante es obligatorio para métodos distintos a efectivo')
      return
    }
    if (pesosToMinorUnits(values.total_amount) > outstandingMinor) {
      toast.error(
        `El monto no puede superar el saldo pendiente (${formatMinorUnits(outstandingMinor)})`
      )
      return
    }
    mutation.mutate({ values, bankAccountId })
  })

  const paymentMethods = catalogs[CatalogType.PAYMENT_METHOD] ?? []
  const isPending = mutation.isPending

  return (
    <>
      <SheetHeader className='border-b border-[var(--line-2)] px-5 py-4'>
        <SheetTitle>Registrar pago de reservación</SheetTitle>
        <SheetDescription>
          Saldo pendiente: <b>{formatMinorUnits(outstandingMinor)}</b>
        </SheetDescription>
      </SheetHeader>

      <form className='min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4' onSubmit={submit}>
        <RequiredFieldsLegend />
        <FieldGroup>
          <Field>
            <FieldLabel>Cuenta destino</FieldLabel>
            <p className='rounded-[10px] bg-[var(--surface-2)] px-3 py-2.5 text-[13px] text-[var(--ink-2)]'>
              {bankAccountName ?? 'Sin cuenta configurada'}
            </p>
            <FieldDescription>
              Cuenta configurada en la amenidad — no se puede cambiar aquí.
            </FieldDescription>
          </Field>

          <div className='grid grid-cols-12 gap-4'>
            <Field className='col-span-6 min-w-0'>
              <FieldLabel htmlFor='rp-date' required>
                Fecha de pago
              </FieldLabel>
              <Input id='rp-date' type='date' {...register('paid_at')} disabled={isPending} />
              <FieldError>{errors.paid_at?.message}</FieldError>
            </Field>
            <Field className='col-span-6 min-w-0'>
              <FieldLabel htmlFor='rp-amount' required>
                Monto
              </FieldLabel>
              <div className='relative'>
                <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                  $
                </span>
                <Input
                  id='rp-amount'
                  type='number'
                  step='0.01'
                  min={0}
                  placeholder='0.00'
                  className='pl-7'
                  {...register('total_amount', {
                    setValueAs: (v) => (v === '' || v == null ? 0 : Number(v)),
                  })}
                  disabled={isPending}
                />
              </div>
              <FieldError>{errors.total_amount?.message}</FieldError>
            </Field>
          </div>

          <Field>
            <FieldLabel required>Método de pago</FieldLabel>
            <Controller
              name='payment_method_id'
              control={control}
              render={({ field }) => (
                <RadioGroup
                  aria-label='Método de pago'
                  value={field.value}
                  onValueChange={field.onChange}
                  disabled={isPending}
                  className='grid grid-cols-2 gap-2'
                >
                  {paymentMethods.map((method) => (
                    <label
                      key={method.id}
                      className='flex cursor-pointer items-center gap-2 rounded-[10px] border border-border px-3 py-2 text-[13px] has-[[data-state=checked]]:border-[var(--green)] has-[[data-state=checked]]:bg-[var(--green-bg)]'
                    >
                      <RadioGroupItem value={method.id} />
                      {method.name}
                    </label>
                  ))}
                </RadioGroup>
              )}
            />
            <FieldError>{errors.payment_method_id?.message}</FieldError>
          </Field>

          <div className='grid grid-cols-12 gap-4'>
            <Field className='col-span-6 min-w-0'>
              <FieldLabel htmlFor='rp-ref'>Referencia</FieldLabel>
              <Input
                id='rp-ref'
                placeholder='Núm. de operación'
                {...register('reference')}
                disabled={isPending}
              />
            </Field>
            <Field className='col-span-6 min-w-0'>
              <FieldLabel htmlFor='rp-folio'>Folio recibo físico</FieldLabel>
              <Input
                id='rp-folio'
                placeholder='0024'
                {...register('physical_receipt_number')}
                disabled={isPending}
              />
            </Field>
          </div>

          <Field>
            <FieldLabel htmlFor='rp-receipt' required={!isCash}>
              Comprobante
            </FieldLabel>
            <input
              ref={fileRef}
              id='rp-receipt'
              type='file'
              accept='image/*,.pdf'
              className='hidden'
              onChange={(e) => setReceipt(e.target.files?.[0] ?? null)}
            />
            <Button
              type='button'
              variant='outline'
              className='justify-start'
              onClick={() => fileRef.current?.click()}
              disabled={isPending}
            >
              <Paperclip className='size-4' />
              {receipt ? receipt.name : 'Subir foto / PDF'}
            </Button>
            {!isCash && (
              <FieldDescription>
                Obligatorio para transferencia, depósito y cheque.
              </FieldDescription>
            )}
          </Field>

          <Field>
            <FieldLabel htmlFor='rp-notes'>Notas</FieldLabel>
            <Textarea
              id='rp-notes'
              rows={2}
              className='resize-none'
              {...register('notes')}
              disabled={isPending}
            />
          </Field>
        </FieldGroup>
      </form>

      <SheetFooter className='border-t border-[var(--line-2)] px-5 py-4'>
        <div className='flex w-full gap-2'>
          <Button
            type='button'
            variant='outline'
            className='flex-1 rounded-full'
            onClick={onClose}
            disabled={isPending}
          >
            Cancelar
          </Button>
          <Button
            type='button'
            className='flex-1 rounded-full'
            onClick={() => void submit()}
            disabled={isPending || !bankAccountId}
          >
            {isPending ? 'Registrando…' : 'Registrar pago'}
          </Button>
        </div>
      </SheetFooter>
    </>
  )
}
