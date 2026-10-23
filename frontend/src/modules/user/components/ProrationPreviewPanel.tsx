import { Button } from '@/components/ui/button'
import { formatPrice } from '@/modules/checkoutSubscription/components/formatPrice'
import type { BillingInterval, ISubscriptionChangePreview } from '@/modules/checkoutSubscription/schema'
import { intervalSuffix } from '@/modules/user/components/subscriptionHelpers'
import { InlineLoading } from '@/shared/components/molecules/InlineLoading'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import { AlertCircle, ArrowRight } from 'lucide-react'

interface ProrationPreviewPanelProps {
  preview: ISubscriptionChangePreview | null
  isLoading: boolean
  error: string | null
  /** Intervalo del NUEVO estado, para rotular el total recurrente ("/mes" | "/año"). */
  interval: BillingInterval
  /** Reintenta el cálculo de la vista previa. */
  onRetry: () => void
}

/**
 * Muestra el resultado de una vista previa de prorrateo de forma CLARA: cuánto se
 * cobra (o acredita) ahora y cuál será el nuevo total recurrente, con su fecha
 * efectiva. Nunca muestra $0 silencioso si el cálculo falla: presenta el error y un
 * botón de reintento.
 */
export function ProrationPreviewPanel({
  preview,
  isLoading,
  error,
  interval,
  onRetry,
}: ProrationPreviewPanelProps) {
  if (isLoading) {
    return <InlineLoading name="proration-preview" />
  }

  if (error) {
    return (
      <div className="space-y-3 border border-destructive/30 bg-destructive/5 p-4">
        <div className="flex items-start gap-3 text-destructive">
          <AlertCircle className="mt-0.5 h-5 w-5 shrink-0" />
          <p className="text-sm font-medium">{error}</p>
        </div>
        <Button size="sm" variant="outline" onClick={onRetry}>
          Reintentar
        </Button>
      </div>
    )
  }

  if (!preview) return null

  const currency = preview.currency
  const isCredit = preview.proration_amount < 0
  const hasImmediateAmount = preview.proration_amount !== 0
  const immediateAmount = formatPrice(Math.abs(preview.proration_amount), currency)
  const newTotal = formatPrice(preview.new_recurring_total, currency)

  return (
    <div className="space-y-4 border bg-muted/40 p-4">
      <div>
        <p className="text-xs font-medium text-muted-foreground">
          {isCredit ? 'Crédito a tu favor (prorrateo)' : 'Cargo inmediato (prorrateo)'}
        </p>
        {hasImmediateAmount ? (
          <p className={`text-2xl font-bold tabular-nums ${isCredit ? 'text-primary' : ''}`}>
            {isCredit ? `+${immediateAmount}` : immediateAmount}
          </p>
        ) : (
          <p className="text-sm font-medium">No hay cargo inmediato por prorrateo.</p>
        )}
        <p className="mt-1 text-sm text-muted-foreground">
          {isCredit
            ? `Se te acreditará ${immediateAmount} a favor por el tiempo no usado.`
            : hasImmediateAmount
              ? `Se te cobrará ${immediateAmount} ahora por el tiempo restante del ciclo.`
              : 'El cambio no genera un cobro proporcional en este momento.'}
        </p>
      </div>

      <div className="flex items-center gap-2 border-t pt-4">
        <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground" />
        <p className="text-sm">
          Tu nuevo total será{' '}
          <span className="font-semibold">
            {newTotal}
            {intervalSuffix(interval)}
          </span>
          .
        </p>
      </div>

      <p className="text-xs text-muted-foreground">
        Efectivo a partir del {displayFormatDate(preview.effective_at, 'mediumDate')}.
      </p>
    </div>
  )
}
