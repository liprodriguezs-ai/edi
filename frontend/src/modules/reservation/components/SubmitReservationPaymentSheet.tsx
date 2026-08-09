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
import type { IAmenity } from '@/modules/amenity/schema'
import {
  costInputsFromReservation,
  reserveDepositHint,
  suggestedPaymentMinor,
} from '@/modules/reservation/reserveWizard'
import {
  EARMARK_TYPE,
  PAYMENT_METHOD,
  submitPaymentSchema,
  type SubmitPaymentValues,
} from '@/modules/payment/schema'
import { PaymentResource } from '@/modules/payment/services/PaymentResource'
import type { IAmenityReservation } from '@/modules/reservation/schema'
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

export interface SubmitReservationPaymentSheetProps {
  isOpen: boolean
  onClose: () => void
  reservation: IAmenityReservation
  amenity: Pick<IAmenity, 'bank_account_id' | 'bank_account'>
  onSubmitted: () => void
}

function buildDefaultValues(amountMinor: number): SubmitPaymentValues {
  return {
    paid_at: todayLocalDateString(),
    total_amount: amountMinor > 0 ? minorUnitsToPesos(amountMinor) : 0,
    payment_method_id: PAYMENT_METHOD.TRANSFER,
    reference: '',
    notes: '',
  }
}

/**
 * Pago earmark del residente para SU reservación (S15): reutiliza
 * `PaymentResource.submit` (`payment/submit`) etiquetado con
 * `earmark_type: 'amenity_reservation'` + `earmark_id: <reservation.id>` y la
 * cuenta destino FIJA de la amenidad (`amenity.bank_account_id` — el
 * residente NO la elige, a diferencia de un pago libre). Queda
 * `pending_validation` hasta que el tesorero lo confirme (mismo flujo que
 * `SubmitPaymentSheet`). El monto por defecto es el depósito mínimo si la
 * amenidad lo requiere — el residente puede pagar más, hasta el saldo
 * pendiente, para liquidar de una vez.
 */
export function SubmitReservationPaymentSheet({
  isOpen,
  onClose,
  reservation,
  amenity,
  onSubmitted,
}: SubmitReservationPaymentSheetProps) {
  if (!isOpen) return null
  return (
    <Sheet open onOpenChange={(open) => !open && onClose()}>
      <SheetContent side='right' className='flex w-full flex-col gap-0 p-0 sm:max-w-[420px]'>
        <SubmitReservationPaymentSheetBody
          onClose={onClose}
          reservation={reservation}
          amenity={amenity}
          onSubmitted={onSubmitted}
        />
      </SheetContent>
    </Sheet>
  )
}

function SubmitReservationPaymentSheetBody({
  onClose,
  reservation,
  amenity,
  onSubmitted,
}: Omit<SubmitReservationPaymentSheetProps, 'isOpen'>) {
  const queryClient = useQueryClient()
  const fileRef = useRef<HTMLInputElement>(null)
  const [receipt, setReceipt] = useState<File | null>(null)

  const { catalogs, enable } = useLazyCatalogs(
    useMemo(() => ({ [CatalogType.PAYMENT_METHOD]: { _sort: 'order' } }), [])
  )
  useEffect(() => {
    enable()
  }, [enable])

  const outstandingMinor = moneyToMinorUnits(reservation.amount_outstanding ?? 0)
  const costInputs = costInputsFromReservation(reservation)
  const hint = reserveDepositHint(costInputs)
  const isFirstPayment = hint != null && outstandingMinor === hint.totalMinor
  const suggestedMinor = suggestedPaymentMinor(costInputs, outstandingMinor)
  // Plain const, not useMemo: react-hook-form only reads `defaultValues` once
  // at mount, so a fresh object per render is harmless here.
  const defaultValues = buildDefaultValues(suggestedMinor)

  const {
    register,
    handleSubmit,
    control,
    formState: { errors },
  } = useForm<SubmitPaymentValues>({
    resolver: zodResolver(submitPaymentSchema),
    defaultValues,
  })

  const bankAccountId = amenity.bank_account_id ?? undefined
  const bankAccountName = amenity.bank_account?.name

  const mutation = useMutation({
    mutationFn: (values: SubmitPaymentValues) =>
      PaymentResource.submit({
        unit_id: reservation.unit_id,
        paid_at: values.paid_at,
        total_amount: pesosToMinorUnits(values.total_amount),
        payment_method_id: values.payment_method_id,
        reference: values.reference || undefined,
        notes: values.notes || undefined,
        receipt,
        earmark_type: EARMARK_TYPE.AMENITY_RESERVATION,
        earmark_id: reservation.id,
        bank_account_id: bankAccountId,
      }),
    onSuccess: () => {
      toast.success('Pago enviado — quedará pendiente de validación por el tesorero')
      void queryClient.invalidateQueries({ queryKey: ['amenity-reservation'] })
      void queryClient.invalidateQueries({ queryKey: ['reservation-payments'] })
      onSubmitted()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo enviar el pago'
      ),
  })

  const submit = handleSubmit((values) => {
    if (!bankAccountId) {
      toast.error('La amenidad no tiene una cuenta destino configurada')
      return
    }
    if (!receipt) {
      toast.error('El comprobante es obligatorio')
      return
    }
    if (pesosToMinorUnits(values.total_amount) > outstandingMinor) {
      toast.error(
        `El monto no puede superar el saldo pendiente (${formatMinorUnits(outstandingMinor)})`
      )
      return
    }
    // Client-side mirror of AmenityReservationEarmarkHandler::validateSubmission's
    // deposit-floor guard — only prevents a known-bad submit; the backend
    // remains the authority (422 on the same condition).
    const depositMinor = costInputs.depositAmountMinor
    if (
      isFirstPayment &&
      depositMinor != null &&
      depositMinor > 0 &&
      pesosToMinorUnits(values.total_amount) < depositMinor
    ) {
      toast.error(
        `El primer pago debe cubrir al menos el depósito de ${formatMinorUnits(depositMinor)}`
      )
      return
    }
    mutation.mutate(values)
  })

  // Cash is admin-register-only (Slice C): a resident self-submitting a
  // payment must not even be offered it.
  const paymentMethods = (catalogs[CatalogType.PAYMENT_METHOD] ?? []).filter(
    (method) => method.id !== PAYMENT_METHOD.CASH
  )
  const isPending = mutation.isPending

  return (
    <>
      <SheetHeader className='border-b border-[var(--line-2)] px-5 py-4'>
        <SheetTitle>Pagar reservación</SheetTitle>
        <SheetDescription>
          Saldo pendiente: <b>{formatMinorUnits(outstandingMinor)}</b>
          {hint?.isPartial && (
            <>
              {' '}
              · Depósito mínimo: <b>{formatMinorUnits(hint.minimumMinor)}</b>
            </>
          )}
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
              Cuenta configurada en la amenidad — no se puede cambiar.
            </FieldDescription>
          </Field>

          <div className='grid grid-cols-12 gap-4'>
            <Field className='col-span-6 min-w-0'>
              <FieldLabel htmlFor='srp-date' required>
                Fecha de pago
              </FieldLabel>
              <Input id='srp-date' type='date' {...register('paid_at')} disabled={isPending} />
              <FieldError>{errors.paid_at?.message}</FieldError>
            </Field>
            <Field className='col-span-6 min-w-0'>
              <FieldLabel htmlFor='srp-amount' required>
                Monto
              </FieldLabel>
              <div className='relative'>
                <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                  $
                </span>
                <Input
                  id='srp-amount'
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

          <Field>
            <FieldLabel htmlFor='srp-ref'>Referencia</FieldLabel>
            <Input
              id='srp-ref'
              placeholder='Núm. de operación'
              {...register('reference')}
              disabled={isPending}
            />
          </Field>

          <Field>
            <FieldLabel htmlFor='srp-receipt' required>
              Comprobante
            </FieldLabel>
            <input
              ref={fileRef}
              id='srp-receipt'
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
            <FieldDescription>
              El comprobante es obligatorio. Los pagos en efectivo los registra la administración
              directamente en la oficina.
            </FieldDescription>
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
            Pagar después
          </Button>
          <Button
            type='button'
            className='flex-1 rounded-full'
            onClick={() => void submit()}
            disabled={isPending || !bankAccountId}
          >
            {isPending ? 'Enviando…' : 'Enviar pago'}
          </Button>
        </div>
      </SheetFooter>
    </>
  )
}
