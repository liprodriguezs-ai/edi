import { Button } from '@/components/ui/button'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { DatePicker } from '@/shared/components/DatePicker'
import { RequiredFieldsLegend } from '@/shared/components/RequiredFieldsLegend'
import { resolveBillingError } from '@/modules/tenant/billing/billingErrors'
import { BILLING_CURRENCY, billingIntervalLabel } from '@/modules/tenant/billing/billingFormat'
import {
  manualPaymentFormSchema,
  type IBillingManualPayment,
  type IRegisterPaymentRequest,
  type ManualPaymentFormValues,
} from '@/modules/tenant/billing/schema'
import { formatPrice } from '@/modules/checkoutSubscription/components/formatPrice'
import type { UseMutationResult } from '@tanstack/react-query'
import { zodResolver } from '@hookform/resolvers/zod'
import { format } from 'date-fns'
import { AlertCircle, CheckCircle2, Gift } from 'lucide-react'
import { useCallback, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'

interface ManualPaymentSectionProps {
  /** Pago manual sugerido: líneas a cobrar (hoy siempre una: el plan) + total. */
  manualPayment: IBillingManualPayment
  /** true si el organización tiene el plan por cortesía (super_admin_free): no se cobra. */
  hasCourtesyAccess: boolean
  /** Nombre del plan a cobrar, para el resumen. */
  planName: string
  registerPayment: UseMutationResult<void, unknown, IRegisterPaymentRequest>
}

/**
 * Registra UN pago recibido fuera de Stripe (transferencia o efectivo) que cubre el
 * plan del organización. Con un solo plan base el desglose colapsa a UNA línea: plan,
 * intervalo y total (derivados de `manualPayment.lines`, que el backend sigue
 * mandando por línea). El admin sólo elige el método, la fecha y notas opcionales.
 *
 * Esta sección NO se monta cuando el método de pago es tarjeta (Stripe) — la
 * decisión la toma el padre (TenantBillingPanel).
 */
export function ManualPaymentSection({
  manualPayment,
  hasCourtesyAccess,
  planName,
  registerPayment,
}: ManualPaymentSectionProps) {
  const [justRegistered, setJustRegistered] = useState(false)

  const buildDefaults = useCallback(
    (): ManualPaymentFormValues => ({
      method: 'transfer',
      paid_at: format(new Date(), 'yyyy-MM-dd'),
      notes: '',
    }),
    []
  )

  const {
    register,
    handleSubmit,
    control,
    reset,
    formState: { errors },
  } = useForm<ManualPaymentFormValues>({
    resolver: zodResolver(manualPaymentFormSchema),
    defaultValues: buildDefaults(),
  })

  const isSaving = registerPayment.isPending
  const hasPayableLines = manualPayment.lines.length > 0
  // Intervalo del cobro: con una sola línea (el plan) es el de esa línea.
  const billedInterval = manualPayment.lines[0]?.interval ?? null

  const onSubmit = handleSubmit((values) => {
    setJustRegistered(false)
    registerPayment.reset()
    const payload: IRegisterPaymentRequest = {
      method: values.method,
      paid_at: values.paid_at,
      notes: values.notes?.trim() ? values.notes.trim() : undefined,
      // Las líneas vienen del resumen del backend sin modificar (importes en centavos).
      lines: manualPayment.lines.map((line) => ({
        module_key: line.module_key,
        plan_key: line.plan_key,
        amount: line.amount ?? 0,
        interval: line.interval ?? undefined,
      })),
    }
    registerPayment.mutate(payload, {
      onSuccess: () => {
        setJustRegistered(true)
        reset(buildDefaults())
      },
    })
  })

  return (
    <section
      aria-labelledby='billing-manual-heading'
      className='rounded-xl border border-border bg-card p-5 sm:p-6'
    >
      <h3 id='billing-manual-heading' className='text-base font-semibold text-foreground'>
        Registrar un pago manual
      </h3>
      <p className='mt-1 text-sm text-muted-foreground'>
        Un solo pago (transferencia o efectivo) que cubre el plan del organización. Esto extiende el
        acceso un ciclo y queda guardado en el historial.
      </p>

      {/* Estado vacío: no hay nada que cobrar de forma manual */}
      {!hasPayableLines ? (
        <p className='mt-4 rounded-lg border border-dashed border-border p-6 text-center text-sm text-muted-foreground'>
          {hasCourtesyAccess
            ? 'Este organización tiene el plan por cortesía: no hay nada que cobrar.'
            : 'Este organización no tiene un plan de pago por cobrar (es de cortesía o se cobra por Stripe).'}
        </p>
      ) : (
        <form onSubmit={onSubmit} className='mt-4 space-y-5'>
          <RequiredFieldsLegend />

          {/* Resumen read-only: UNA línea (el plan del organización) + total */}
          <div className='space-y-3'>
            <p className='text-sm font-medium text-foreground'>Concepto a cobrar</p>

            <div className='flex items-center justify-between rounded-lg border border-border px-4 py-3'>
              <div className='min-w-0'>
                <p className='text-sm font-medium text-foreground'>Plan {planName}</p>
                <p className='text-xs text-muted-foreground'>
                  Cobro por {billingIntervalLabel(billedInterval)}
                </p>
              </div>
              <span className='ml-4 shrink-0 text-lg font-semibold tabular-nums text-foreground'>
                {formatPrice(manualPayment.total, BILLING_CURRENCY)}
              </span>
            </div>

            {hasCourtesyAccess && (
              <p className='flex items-center gap-2 rounded-lg border border-dashed border-border p-3 text-xs text-muted-foreground'>
                <Gift className='h-3.5 w-3.5 shrink-0' aria-hidden />
                El organización tiene además un acceso de cortesía vigente, que no se cobra.
              </p>
            )}
          </div>

          {/* Campos del formulario */}
          <div className='grid grid-cols-12 gap-4'>
            <Field className='col-span-12 md:col-span-6'>
              <FieldLabel htmlFor='manual-method' required>
                Método del pago
              </FieldLabel>
              <Controller
                name='method'
                control={control}
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange} disabled={isSaving}>
                    <SelectTrigger id='manual-method' className='w-full'>
                      <SelectValue placeholder='Elige el método' />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value='transfer'>Transferencia</SelectItem>
                      <SelectItem value='cash'>Efectivo</SelectItem>
                    </SelectContent>
                  </Select>
                )}
              />
              <FieldError>{errors.method?.message}</FieldError>
            </Field>

            <Field className='col-span-12 md:col-span-6'>
              <FieldLabel htmlFor='manual-paid-at' required>
                Fecha del pago
              </FieldLabel>
              <Controller
                name='paid_at'
                control={control}
                render={({ field }) => (
                  <DatePicker
                    id='manual-paid-at'
                    value={field.value}
                    onChange={field.onChange}
                    clearable={false}
                    disabled={isSaving}
                    placeholder='Elige la fecha'
                  />
                )}
              />
              <FieldError>{errors.paid_at?.message}</FieldError>
            </Field>

            <Field className='col-span-12'>
              <FieldLabel htmlFor='manual-notes'>Notas (opcional)</FieldLabel>
              <Textarea
                id='manual-notes'
                rows={2}
                className='resize-none'
                placeholder='Referencia, banco, observaciones…'
                disabled={isSaving}
                {...register('notes')}
              />
              <FieldDescription>Se guarda junto con el pago en el historial.</FieldDescription>
            </Field>
          </div>

          {registerPayment.isError && (
            <p
              role='alert'
              className='flex items-start gap-2 rounded-lg bg-destructive/5 p-3 text-sm text-destructive'
            >
              <AlertCircle className='mt-0.5 h-4 w-4 shrink-0' aria-hidden />
              <span>{resolveBillingError(registerPayment.error)}</span>
            </p>
          )}

          {justRegistered && !registerPayment.isError && (
            <p
              role='status'
              className='flex items-start gap-2 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400'
            >
              <CheckCircle2 className='mt-0.5 h-4 w-4 shrink-0' aria-hidden />
              <span>Pago registrado. Revisa el nuevo vencimiento más abajo.</span>
            </p>
          )}

          <div className='flex justify-end'>
            <Button type='submit' disabled={isSaving} className='min-w-[140px]'>
              {isSaving ? 'Registrando…' : 'Registrar pago'}
            </Button>
          </div>
        </form>
      )}
    </section>
  )
}
