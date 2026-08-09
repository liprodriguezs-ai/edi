import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  reportPaymentFormSchema,
  type ReportPaymentFormValues,
} from '@/modules/communityProject/schema'
import { EARMARK_TYPE, PAYMENT_METHOD } from '@/modules/payment/schema'
import { PaymentResource } from '@/modules/payment/services/PaymentResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { formatMinorUnits, pesosToMinorUnits } from '@/shared/utils/money'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { Paperclip } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'

const MAX_RECEIPT_BYTES = 10 * 1024 * 1024

interface ReportPaymentModalProps {
  isOpen: boolean
  onClose: () => void
  /** Aportación a la que se ancla el pago reportado (earmark_id). */
  contributionId: string
  /** Unidad del residente (unit_id del pago). */
  unitId: string
  unitLabel: string
  /** Título del proyecto — se usa en las notas del pago. */
  projectTitle: string
  /** Monto esperado de la unidad, en minor units — solo referencia visual. */
  expectedMinor: number
  onReported: () => void
}

/**
 * Reporte de pago de una aportación por parte de un residente (flujo con revisión):
 * captura el pago con comprobante y lo envía por `POST /payment/submit` con
 * `earmark_type: 'project_contribution'`. Queda PENDIENTE de validación: la mesa
 * directiva lo confirma y recién ahí se genera el cargo del proyecto y se aplica.
 * Sin cuenta destino (el backend usa la cuenta primaria del clúster). El
 * comprobante es obligatorio salvo en efectivo.
 */
export function ReportPaymentModal({
  isOpen,
  onClose,
  contributionId,
  unitId,
  unitLabel,
  projectTitle,
  expectedMinor,
  onReported,
}: ReportPaymentModalProps) {
  const fileRef = useRef<HTMLInputElement>(null)
  const [receipt, setReceipt] = useState<File | null>(null)

  const defaultValues = useMemo<ReportPaymentFormValues>(
    () => ({
      amount: undefined as unknown as number,
      payment_method_id: PAYMENT_METHOD.CASH,
      paid_at: new Date().toISOString().slice(0, 10),
      reference: '',
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
  } = useForm<ReportPaymentFormValues>({
    resolver: zodResolver(reportPaymentFormSchema),
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

  const isCash = watch('payment_method_id') === PAYMENT_METHOD.CASH

  const mutation = useMutation({
    mutationFn: (values: ReportPaymentFormValues) =>
      PaymentResource.submit({
        unit_id: unitId,
        paid_at: values.paid_at,
        total_amount: pesosToMinorUnits(values.amount),
        payment_method_id: values.payment_method_id,
        reference: values.reference || undefined,
        notes: `Aportación al proyecto "${projectTitle}"`,
        receipt,
        earmark_type: EARMARK_TYPE.PROJECT_CONTRIBUTION,
        earmark_id: contributionId,
      }),
    onSuccess: () => {
      toast.success(
        'Pago reportado. Se aplicará a tu aportación cuando la mesa directiva lo valide.'
      )
      onReported()
      onClose()
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError ? formatApiErrorMessage(error) : 'No se pudo reportar el pago'
      )
    },
  })

  const applyReceipt = (file: File | null) => {
    if (file && file.size > MAX_RECEIPT_BYTES) {
      toast.error('El comprobante no debe superar los 10 MB')
      return
    }
    setReceipt(file)
  }

  const submit = handleSubmit((values) => {
    if (!isCash && !receipt) {
      toast.error('El comprobante es obligatorio para pagos que no son en efectivo')
      return
    }
    mutation.mutate(values)
  })

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent
        className='max-h-[92vh] max-w-md overflow-hidden p-0 flex flex-col'
        aria-describedby={undefined}
      >
        <DialogHeader className='shrink-0 px-6 pt-6'>
          <DialogTitle>Reportar pago · {unitLabel}</DialogTitle>
          <p className='text-[13px] text-[var(--ink-3)]'>
            Tu pago quedará pendiente hasta que la mesa directiva lo valide.
            {expectedMinor > 0 ? ` Esperado: ${formatMinorUnits(expectedMinor)}.` : ''}
          </p>
        </DialogHeader>

        <form className='min-h-0 flex-1 overflow-y-auto px-6 py-2' onSubmit={submit}>
          <FieldGroup>
            <RequiredFieldsLegend />
            <div className='grid grid-cols-12 gap-4'>
              <Field className='col-span-7'>
                <FieldLabel htmlFor='report-amount' required>
                  Monto
                </FieldLabel>
                <div className='relative'>
                  <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                    $
                  </span>
                  <Input
                    id='report-amount'
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
              <Field className='col-span-5'>
                <FieldLabel htmlFor='report-date' required>
                  Fecha
                </FieldLabel>
                <Input
                  id='report-date'
                  type='date'
                  {...register('paid_at')}
                  disabled={mutation.isPending}
                />
                <FieldError>{errors.paid_at?.message}</FieldError>
              </Field>
            </div>

            <Field>
              <FieldLabel id='report-method-label' required>
                Método de pago
              </FieldLabel>
              <Controller
                name='payment_method_id'
                control={control}
                render={({ field }) => (
                  <RadioGroup
                    aria-labelledby='report-method-label'
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
              <FieldLabel htmlFor='report-ref'>Referencia</FieldLabel>
              <Input
                id='report-ref'
                placeholder='Núm. de operación (opcional)'
                {...register('reference')}
                disabled={mutation.isPending}
              />
            </Field>

            <Field>
              <FieldLabel htmlFor='report-receipt' required={!isCash}>
                Comprobante
              </FieldLabel>
              <input
                ref={fileRef}
                id='report-receipt'
                type='file'
                accept='application/pdf,image/jpeg,image/png,image/webp'
                className='hidden'
                onChange={(e) => applyReceipt(e.target.files?.[0] ?? null)}
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
              <FieldDescription>
                {isCash
                  ? 'Opcional para efectivo · máximo 10 MB.'
                  : 'Obligatorio para transferencia, depósito y cheque · máximo 10 MB.'}
              </FieldDescription>
            </Field>
          </FieldGroup>
        </form>

        <DialogFooter className='shrink-0 px-6 pb-6'>
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
            onClick={() => void submit()}
            disabled={mutation.isPending}
            className='min-w-[100px]'
          >
            {mutation.isPending ? 'Enviando…' : 'Reportar pago'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
