import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import type { BillingPaymentMethod, CancelMode } from '@/modules/tenant/billing/schema'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import { AlertCircle } from 'lucide-react'
import { useState } from 'react'

const METHOD_LABEL: Record<BillingPaymentMethod, string> = {
  card: 'Tarjeta (Stripe)',
  transfer: 'Transferencia',
  cash: 'Efectivo',
}

interface CancelSubscriptionDialogProps {
  isOpen: boolean
  /** Método al que se quiere cambiar (transferencia o efectivo). */
  targetMethod: BillingPaymentMethod
  isLoading: boolean
  errorMessage?: string
  onConfirm: (cancelMode: CancelMode) => void
  onCancel: () => void
}

/**
 * Confirma el paso de Tarjeta (Stripe) a un método manual. Como la organización tiene
 * una suscripción automática activa, hay que decidir CUÁNDO se cancela: al final
 * del ciclo ya pagado, o de inmediato. El texto explica la consecuencia de cada
 * opción para que el super-admin elija con criterio.
 */
export function CancelSubscriptionDialog({
  isOpen,
  targetMethod,
  isLoading,
  errorMessage,
  onConfirm,
  onCancel,
}: CancelSubscriptionDialogProps) {
  const [cancelMode, setCancelMode] = useState<CancelMode>('at_period_end')

  if (!isOpen) {
    return null
  }

  return (
    <ConfirmDialog
      isOpen={isOpen}
      title={`Cambiar a ${METHOD_LABEL[targetMethod].toLowerCase()}`}
      description='La organización paga hoy con tarjeta mediante una suscripción automática en Stripe. Al cambiar a un método manual, esa suscripción se cancela y, a partir de entonces, tú registras los pagos manualmente.'
      confirmLabel='Confirmar cambio'
      loadingLabel="Aplicando…"
      isLoading={isLoading}
      onConfirm={() => onConfirm(cancelMode)}
      onCancel={onCancel}
    >
      <fieldset className="space-y-3">
        <legend className="text-sm font-medium text-foreground">
          ¿Cuándo quieres cancelar la suscripción de tarjeta?
        </legend>
        <RadioGroup
          value={cancelMode}
          onValueChange={(value) => setCancelMode(value as CancelMode)}
          className="gap-3"
        >
          <label
            htmlFor="cancel-at-period-end"
            className="flex cursor-pointer items-start gap-3 border border-border p-3 transition-colors hover:border-primary has-[:checked]:border-primary has-[:checked]:bg-primary/5"
          >
            <RadioGroupItem id="cancel-at-period-end" value="at_period_end" className="mt-0.5" />
            <span className="space-y-0.5">
              <span className="block text-sm font-medium text-foreground">Al final del ciclo</span>
              <span className="block text-xs text-muted-foreground">
                La organización conserva el acceso hasta que termine el periodo que ya pagó. No se
                cobra de nuevo con tarjeta. Recomendado.
              </span>
            </span>
          </label>

          <label
            htmlFor="cancel-now"
            className="flex cursor-pointer items-start gap-3 border border-border p-3 transition-colors hover:border-primary has-[:checked]:border-primary has-[:checked]:bg-primary/5"
          >
            <RadioGroupItem id="cancel-now" value="now" className="mt-0.5" />
            <span className="space-y-0.5">
              <span className="block text-sm font-medium text-foreground">De inmediato</span>
              <span className="block text-xs text-muted-foreground">
                La suscripción de tarjeta se cancela ahora mismo. El acceso dependerá de los
                pagos manuales que registres a partir de este momento.
              </span>
            </span>
          </label>
        </RadioGroup>
      </fieldset>

      {errorMessage && (
        <p
          role="alert"
          className="flex items-start gap-2 bg-destructive/5 p-3 text-sm text-destructive"
        >
          <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
          <span>{errorMessage}</span>
        </p>
      )}
    </ConfirmDialog>
  )
}
