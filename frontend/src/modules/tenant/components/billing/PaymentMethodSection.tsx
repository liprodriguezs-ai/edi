import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { resolveBillingError } from '@/modules/tenant/billing/billingErrors'
import type {
  BillingPaymentMethod,
  CancelMode,
  IChangePaymentMethodRequest,
} from '@/modules/tenant/billing/schema'
import { CancelSubscriptionDialog } from '@/modules/tenant/components/billing/CancelSubscriptionDialog'
import type { UseMutationResult } from '@tanstack/react-query'
import { AlertCircle, Banknote, CreditCard, Landmark } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Panel } from '@/shared/components/molecules/Panel'

interface MethodOption {
  value: BillingPaymentMethod
  label: string
  icon: ReactNode
  description: string
}

const METHOD_OPTIONS: MethodOption[] = [
  {
    value: 'card',
    label: 'Tarjeta (Stripe)',
    icon: <CreditCard className="h-4 w-4" aria-hidden />,
    description: 'Cobro automático recurrente con Stripe. La organización paga en línea sin tu intervención.',
  },
  {
    value: 'transfer',
    label: 'Transferencia',
    icon: <Landmark className="h-4 w-4" aria-hidden />,
    description: 'Pago manual. Tú registras cada transferencia recibida para extender el acceso.',
  },
  {
    value: 'cash',
    label: 'Efectivo',
    icon: <Banknote className="h-4 w-4" aria-hidden />,
    description: 'Pago manual. Tú registras cada cobro en efectivo para extender el acceso.',
  },
]

interface PaymentMethodSectionProps {
  currentMethod: BillingPaymentMethod
  changePaymentMethod: UseMutationResult<void, unknown, IChangePaymentMethodRequest>
}

/**
 * Elige cómo cobra la organización. El cambio de TARJETA a un método manual cancela la
 * suscripción de Stripe, por eso ese caso pasa por un diálogo que pide elegir
 * cuándo cancelar. Los demás cambios se aplican directo.
 */
export function PaymentMethodSection({ currentMethod, changePaymentMethod }: PaymentMethodSectionProps) {
  const [cancelDialogOpen, setCancelDialogOpen] = useState(false)
  const [targetMethod, setTargetMethod] = useState<BillingPaymentMethod>('transfer')

  const handleSelect = (value: string) => {
    const next = value as BillingPaymentMethod
    if (next === currentMethod) {
      return
    }
    changePaymentMethod.reset()

    const leavingCard = currentMethod === 'card' && next !== 'card'
    if (leavingCard) {
      setTargetMethod(next)
      setCancelDialogOpen(true)
      return
    }
    changePaymentMethod.mutate({ payment_method: next })
  }

  const handleConfirmCancel = (cancelMode: CancelMode) => {
    changePaymentMethod.mutate(
      { payment_method: targetMethod, cancel_mode: cancelMode },
      { onSuccess: () => setCancelDialogOpen(false) },
    )
  }

  const inlineError =
    changePaymentMethod.isError && !cancelDialogOpen
      ? resolveBillingError(changePaymentMethod.error)
      : undefined

  return (
    <Panel
      title="Método de pago"
      description="Define cómo cobra la organización su suscripción. Con tarjeta el cobro es automático; con transferencia o efectivo los pagos son manuales y los registras tú."
    >
      <RadioGroup
        value={currentMethod}
        onValueChange={handleSelect}
        className="gap-3 sm:grid-cols-3"
        aria-label="Método de pago de la organización"
      >
        {METHOD_OPTIONS.map((option) => {
          const inputId = `billing-method-${option.value}`
          return (
            <label
              key={option.value}
              htmlFor={inputId}
              className="flex cursor-pointer flex-col gap-2 border border-border p-4 transition-colors hover:border-primary has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60"
            >
              <span className="flex items-center gap-2">
                <RadioGroupItem id={inputId} value={option.value} disabled={changePaymentMethod.isPending} />
                <span className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                  {option.icon}
                  {option.label}
                </span>
              </span>
              <span className="text-xs leading-snug text-muted-foreground">{option.description}</span>
            </label>
          )
        })}
      </RadioGroup>

      {inlineError && (
        <p
          role="alert"
          className="mt-4 flex items-start gap-2 bg-destructive/5 p-3 text-sm text-destructive"
        >
          <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
          <span>{inlineError}</span>
        </p>
      )}

      <CancelSubscriptionDialog
        isOpen={cancelDialogOpen}
        targetMethod={targetMethod}
        isLoading={changePaymentMethod.isPending}
        errorMessage={
          changePaymentMethod.isError ? resolveBillingError(changePaymentMethod.error) : undefined
        }
        onConfirm={handleConfirmCancel}
        onCancel={() => setCancelDialogOpen(false)}
      />
    </Panel>
  )
}
