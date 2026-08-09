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
import { contributeFormSchema, type ContributeFormValues } from '@/modules/communityProject/schema'
import { EARMARK_TYPE, PAYMENT_METHOD } from '@/modules/payment/schema'
import { PaymentResource } from '@/modules/payment/services/PaymentResource'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { SearchSelect } from '@/shared/components/SearchSelect'
import { CatalogType, useCatalogs } from '@/shared/hooks/useCatalogs'
import { toast } from '@/shared/hooks/useToast'
import { formatMinorUnits, pesosToMinorUnits } from '@/shared/utils/money'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { Paperclip } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'

const MAX_RECEIPT_BYTES = 10 * 1024 * 1024

interface ContributeModalProps {
  isOpen: boolean
  onClose: () => void
  contributionId: string
  unitId: string
  unitLabel: string
  /** Monto esperado de la unidad, en minor units — solo referencia visual. */
  expectedMinor: number
  onContributed: () => void
}

/**
 * Aporte directo de la mesa directiva a una aportación voluntaria (§11.8.1). Viaja
 * por el mismo seam de pagos con destino etiquetado (earmark): registra un pago
 * CONFIRMADO (`POST /payment/register`) con `earmark_type: 'project_contribution'`
 * y el id de la aportación, y el backend crea el cargo del proyecto + la aplicación
 * anclada por el monto completo. El comprobante es obligatorio salvo en efectivo.
 * El monto se captura en pesos y se envía en minor units.
 */
export function ContributeModal({
  isOpen,
  onClose,
  contributionId,
  unitId,
  unitLabel,
  expectedMinor,
  onContributed,
}: ContributeModalProps) {
  const defaultValues = useMemo<ContributeFormValues>(
    () => ({
      // Empieza vacío (no "0") para que un submit sin monto muestre "requerido"
      // en vez del confuso "debe ser mayor a 0".
      amount: undefined as unknown as number,
      payment_method_id: PAYMENT_METHOD.CASH,
      bank_account_id: '',
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
  } = useForm<ContributeFormValues>({
    resolver: zodResolver(contributeFormSchema),
    defaultValues,
  })

  const fileRef = useRef<HTMLInputElement>(null)
  const [receipt, setReceipt] = useState<File | null>(null)
  const isCash = watch('payment_method_id') === PAYMENT_METHOD.CASH

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
        [CatalogType.BANK_ACCOUNT]: { _sort: 'name' },
      }),
      []
    )
  )

  const mutation = useMutation({
    mutationFn: (values: ContributeFormValues) =>
      PaymentResource.register({
        unit_id: unitId,
        paid_at: values.paid_at,
        total_amount: pesosToMinorUnits(values.amount),
        payment_method_id: values.payment_method_id,
        bank_account_id: values.bank_account_id,
        reference: values.reference || undefined,
        receipt,
        earmark_type: EARMARK_TYPE.PROJECT_CONTRIBUTION,
        earmark_id: contributionId,
      }),
    onSuccess: () => {
      toast.success('Aportación registrada')
      onContributed()
      onClose()
    },
    onError: (error) => {
      toast.error(
        error instanceof ApiError
          ? formatApiErrorMessage(error)
          : 'No se pudo registrar la aportación'
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
          <DialogTitle>Aportar a {unitLabel}</DialogTitle>
          {expectedMinor > 0 && (
            <p className='text-[13px] text-[var(--ink-3)]'>
              Esperado: {formatMinorUnits(expectedMinor)}
            </p>
          )}
        </DialogHeader>

        <form className='min-h-0 flex-1 overflow-y-auto px-6 py-2' onSubmit={submit}>
          <FieldGroup>
            <RequiredFieldsLegend />
            <div className='grid grid-cols-12 gap-4'>
              <Field className='col-span-7'>
                <FieldLabel htmlFor='contrib-amount' required>
                  Monto
                </FieldLabel>
                <div className='relative'>
                  <span className='pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-[var(--ink-3)]'>
                    $
                  </span>
                  <Input
                    id='contrib-amount'
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
                <FieldLabel htmlFor='contrib-date' required>
                  Fecha
                </FieldLabel>
                <Input
                  id='contrib-date'
                  type='date'
                  {...register('paid_at')}
                  disabled={mutation.isPending}
                />
                <FieldError>{errors.paid_at?.message}</FieldError>
              </Field>
            </div>

            <Field>
              <FieldLabel id='contrib-method-label' required>
                Método de pago
              </FieldLabel>
              <Controller
                name='payment_method_id'
                control={control}
                render={({ field }) => (
                  <RadioGroup
                    aria-labelledby='contrib-method-label'
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
              <FieldLabel htmlFor='contrib-account' required>
                Cuenta destino
              </FieldLabel>
              <Controller
                name='bank_account_id'
                control={control}
                render={({ field }) => (
                  <SearchSelect
                    id='contrib-account'
                    value={field.value}
                    onChange={(v) => field.onChange((v as string | null) ?? '')}
                    options={catalogs[CatalogType.BANK_ACCOUNT] ?? []}
                    isDisabled={mutation.isPending}
                    invalid={!!errors.bank_account_id}
                    placeholder='Seleccionar cuenta…'
                  />
                )}
              />
              <FieldError>{errors.bank_account_id?.message}</FieldError>
            </Field>

            <Field>
              <FieldLabel htmlFor='contrib-ref'>Referencia</FieldLabel>
              <Input
                id='contrib-ref'
                placeholder='Núm. de operación (opcional)'
                {...register('reference')}
                disabled={mutation.isPending}
              />
            </Field>

            <Field>
              <FieldLabel htmlFor='contrib-receipt' required={!isCash}>
                Comprobante
              </FieldLabel>
              <input
                ref={fileRef}
                id='contrib-receipt'
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
            {mutation.isPending ? 'Registrando…' : 'Aportar'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
