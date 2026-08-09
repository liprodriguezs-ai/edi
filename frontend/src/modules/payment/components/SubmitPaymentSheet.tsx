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
import {
  PAYMENT_METHOD,
  submitPaymentSchema,
  type SubmitPaymentValues,
} from '@/modules/payment/schema'
import { PaymentResource } from '@/modules/payment/services/PaymentResource'
import { UnitDebtSummary } from '@/modules/payment/components/UnitDebtSummary'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { todayLocalDateString } from '@/shared/utils/dateHelper'
import { formatMinorUnits, moneyToMinorUnits, pesosToMinorUnits } from '@/shared/utils/money'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Paperclip } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { Controller, useForm } from 'react-hook-form'

interface SubmitPaymentSheetProps {
  isOpen: boolean
  onClose: () => void
  /** Unidad propia del residente (de su estado de cuenta). */
  unitId: string
  unitLabel?: string
}

/**
 * Hint en vivo "después de este pago…" en voz del residente ("tu unidad").
 * Se monta dentro del bloque de resumen (renglón propio), no encima del input.
 */
function renderAfterHint(
  outstandingMinor: number,
  totalMinor: number,
  creditMinor: number
): ReactNode {
  if (totalMinor <= 0 || outstandingMinor <= 0) return null
  // The unit's existing credit balance is consumed FIRST when this payment is applied
  // (FIFO credit-first), so count it toward what the payment clears — otherwise the
  // hint overstates the remaining debt for a unit that already has credit.
  const appliedPool = totalMinor + creditMinor
  if (appliedPool < outstandingMinor) {
    return (
      <>
        Después de este pago, tu unidad debe{' '}
        <b className='font-semibold text-[#C0533C]'>
          {formatMinorUnits(outstandingMinor - appliedPool)}
        </b>
        .
      </>
    )
  }
  const surplus = appliedPool - outstandingMinor
  return (
    <>
      Cubre toda tu deuda
      {surplus > 0 && (
        <>
          {' · saldo a favor '}
          <b className='font-semibold text-[#3F6E8E]'>{formatMinorUnits(surplus)}</b>
        </>
      )}
      .
    </>
  )
}

/**
 * Auto-captura de pago del residente (§8.5.2): registra un pago para SU unidad
 * que queda "pendiente de validación" hasta que el tesorero lo confirme. El
 * comprobante es obligatorio salvo en efectivo. POST /payment/submit.
 */
export function SubmitPaymentSheet({
  isOpen,
  onClose,
  unitId,
  unitLabel,
}: SubmitPaymentSheetProps) {
  const queryClient = useQueryClient()
  const fileRef = useRef<HTMLInputElement>(null)
  const [receipt, setReceipt] = useState<File | null>(null)

  const defaultValues = useMemo<SubmitPaymentValues>(
    () => ({
      paid_at: todayLocalDateString(),
      total_amount: 0,
      payment_method_id: PAYMENT_METHOD.CASH,
      reference: '',
      notes: '',
    }),
    []
  )

  const {
    register,
    handleSubmit,
    reset,
    control,
    watch,
    formState: { errors },
  } = useForm<SubmitPaymentValues>({
    resolver: zodResolver(submitPaymentSchema),
    defaultValues,
  })

  useEffect(() => {
    if (isOpen) {
      reset(defaultValues)
      setReceipt(null)
    }
  }, [isOpen, reset, defaultValues])

  const { catalogs } = useCatalogs(
    useMemo(
      () => ({
        [CatalogType.PAYMENT_METHOD]: { _sort: 'order' },
      }),
      []
    )
  )

  // Estado de cuenta de la unidad del residente: reutiliza el mismo cache que el
  // Estado de cuenta (`['statement', unitId]`) para mostrar su deuda total.
  const { data: statement } = useQuery({
    queryKey: ['statement', unitId],
    queryFn: () => PaymentResource.statement(unitId),
    enabled: isOpen && Boolean(unitId),
  })

  const outstandingMinor = moneyToMinorUnits(statement?.totals.outstanding)
  // Cargos con saldo pendiente (no cancelados, esperado − descuento − pagado > 0):
  // misma definición que la vista previa FIFO del panel del administrador.
  const pendingCount = useMemo(
    () =>
      (statement?.charges ?? []).filter((c) => {
        if (c.is_cancelled) return false
        const expected = moneyToMinorUnits(c.expected_amount)
        const discount = moneyToMinorUnits(c.discount_amount ?? 0)
        const paid = moneyToMinorUnits(c.paid_amount)
        return expected - discount - paid > 0
      }).length,
    [statement]
  )

  const isCash = watch('payment_method_id') === PAYMENT_METHOD.CASH
  const totalMinor = pesosToMinorUnits(watch('total_amount') || 0)

  const mutation = useMutation({
    mutationFn: (values: SubmitPaymentValues) =>
      PaymentResource.submit({
        unit_id: unitId,
        paid_at: values.paid_at,
        total_amount: pesosToMinorUnits(values.total_amount),
        payment_method_id: values.payment_method_id,
        reference: values.reference || undefined,
        notes: values.notes || undefined,
        receipt,
      }),
    onSuccess: () => {
      toast.success('Pago enviado — quedará pendiente de validación por el tesorero')
      void queryClient.invalidateQueries({ queryKey: ['statement'] })
      void queryClient.invalidateQueries({ queryKey: ['payments'] })
      onClose()
    },
    onError: (error) =>
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo enviar el pago'
      ),
  })

  const submit = handleSubmit((values) => {
    if (!isCash && !receipt) {
      toast.error('El comprobante es obligatorio para pagos que no son en efectivo')
      return
    }
    mutation.mutate(values)
  })

  return (
    <Sheet open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <SheetContent side='right' className='flex w-full flex-col gap-0 p-0 sm:max-w-[420px]'>
        <SheetHeader className='border-b border-[var(--line-2)] px-5 py-4'>
          <SheetTitle>Reportar un pago{unitLabel ? ` · ${unitLabel}` : ''}</SheetTitle>
          <SheetDescription>
            Reporta un pago que hiciste para que el administrador lo valide.
          </SheetDescription>
        </SheetHeader>

        <form className='min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-4' onSubmit={submit}>
          <RequiredFieldsLegend />
          <FieldGroup>
            {statement && (
              <UnitDebtSummary
                outstandingMinor={outstandingMinor}
                creditMinor={moneyToMinorUnits(statement.credit_balance)}
                pendingCount={pendingCount}
                afterHint={renderAfterHint(
                  outstandingMinor,
                  totalMinor,
                  moneyToMinorUnits(statement.credit_balance)
                )}
              />
            )}

            <div className='grid grid-cols-12 gap-4'>
              <Field className='col-span-6 min-w-0'>
                <FieldLabel htmlFor='sp-date' required>
                  Fecha de pago
                </FieldLabel>
                <Input
                  id='sp-date'
                  type='date'
                  {...register('paid_at')}
                  disabled={mutation.isPending}
                />
                <FieldError>{errors.paid_at?.message}</FieldError>
              </Field>
              <Field className='col-span-6 min-w-0'>
                <FieldLabel htmlFor='sp-amount' required>
                  Monto
                </FieldLabel>
                <div className='relative'>
                  <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                    $
                  </span>
                  <Input
                    id='sp-amount'
                    type='number'
                    step='0.01'
                    min={0}
                    placeholder='0.00'
                    className='pl-7'
                    {...register('total_amount', {
                      setValueAs: (v) => (v === '' || v == null ? 0 : Number(v)),
                    })}
                    disabled={mutation.isPending}
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
                    disabled={mutation.isPending}
                    className='grid grid-cols-2 gap-2'
                  >
                    {catalogs[CatalogType.PAYMENT_METHOD]?.map((method) => (
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
              <FieldLabel htmlFor='sp-ref'>Referencia</FieldLabel>
              <Input
                id='sp-ref'
                placeholder='Núm. de operación'
                {...register('reference')}
                disabled={mutation.isPending}
              />
            </Field>

            <Field>
              <FieldLabel htmlFor='sp-receipt' required={!isCash}>
                Comprobante
              </FieldLabel>
              <input
                ref={fileRef}
                id='sp-receipt'
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
                disabled={mutation.isPending}
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
              <FieldLabel htmlFor='sp-notes'>Notas</FieldLabel>
              <Textarea
                id='sp-notes'
                rows={2}
                className='resize-none'
                {...register('notes')}
                disabled={mutation.isPending}
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
              disabled={mutation.isPending}
            >
              Cancelar
            </Button>
            <Button
              type='button'
              className='flex-1 rounded-full'
              onClick={() => void submit()}
              disabled={mutation.isPending}
            >
              {mutation.isPending ? 'Enviando…' : 'Enviar pago'}
            </Button>
          </div>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}
