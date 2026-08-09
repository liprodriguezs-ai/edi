import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { formatPrice } from '@/modules/checkoutSubscription/components/formatPrice'
import { resolveBillingError } from '@/modules/tenant/billing/billingErrors'
import type {
  IPreferentialSwapPreview,
  IPreferentialSwapRequest,
} from '@/modules/tenant/billing/schema'
import { TenantBillingResource } from '@/modules/tenant/services/TenantBillingResource'
import { displayFormatDate } from '@/shared/utils/dateHelper'
import type { UseMutationResult } from '@tanstack/react-query'
import { AlertCircle, Loader2 } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'

interface PreferentialSwapDialogProps {
  isOpen: boolean
  tenantId: string
  /** Clave del plan destino (preferencial/oculto). */
  toPlanKey: string
  /** Nombre del plan destino para mostrarlo en el título del diálogo. */
  toPlanName: string
  applyPreferentialSwap: UseMutationResult<void, unknown, IPreferentialSwapRequest>
  onClose: () => void
}

/**
 * Confirma el cambio de plan a una variante PREFERENCIAL. Primero pide al
 * backend un preview del prorrateo:
 *  - Tarjeta: muestra el monto a cobrar ahora y el desglose de líneas de Stripe.
 *  - Manual: avisa que el nuevo precio aplica el próximo ciclo.
 * Sólo al confirmar se aplica el cambio.
 *
 * El swap es a nivel de PLAN (no de módulo individual): el backend resuelve los
 * módulos contratados del tenant y valida que el plan destino sea preferencial.
 */
export function PreferentialSwapDialog({
  isOpen,
  tenantId,
  toPlanKey,
  toPlanName,
  applyPreferentialSwap,
  onClose,
}: PreferentialSwapDialogProps) {
  const [preview, setPreview] = useState<IPreferentialSwapPreview | null>(null)
  const [isLoading, setIsLoading] = useState(false)
  const [previewError, setPreviewError] = useState<string | null>(null)

  const loadPreview = useCallback(async () => {
    setIsLoading(true)
    setPreviewError(null)
    try {
      const result = await TenantBillingResource.previewPreferentialSwap(tenantId, {
        to_plan_key: toPlanKey,
      })
      setPreview(result)
    } catch (error) {
      setPreviewError(resolveBillingError(error, 'No se pudo calcular el cambio. Inténtalo de nuevo.'))
    } finally {
      setIsLoading(false)
    }
  }, [tenantId, toPlanKey])

  useEffect(() => {
    if (isOpen) {
      applyPreferentialSwap.reset()
      void loadPreview()
    } else {
      setPreview(null)
      setPreviewError(null)
    }
    // applyPreferentialSwap.reset es estable; se omite a propósito para no re-disparar el preview.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, loadPreview])

  const handleApply = () => {
    const isManual = preview?.manual === true
    applyPreferentialSwap.mutate(
      {
        to_plan_key: toPlanKey,
        proration_date: isManual ? undefined : (preview?.proration_date ?? undefined),
      },
      { onSuccess: onClose },
    )
  }

  if (!isOpen) {
    return null
  }

  const currency = preview?.currency ?? 'MXN'
  const isManual = preview?.manual === true

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>Cambiar a {toPlanName}</DialogTitle>
          <DialogDescription>
            Revisa cómo queda el cobro antes de aplicar el cambio al plan preferencial.
          </DialogDescription>
        </DialogHeader>

        {isLoading && (
          <div className="flex items-center gap-2 py-6 text-sm text-muted-foreground" aria-busy="true">
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
            Calculando el cambio…
          </div>
        )}

        {!isLoading && previewError && (
          <div role="alert" className="space-y-3 py-2">
            <p className="flex items-start gap-2 rounded-lg bg-destructive/5 p-3 text-sm text-destructive">
              <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
              <span>{previewError}</span>
            </p>
            <Button type="button" variant="outline" size="sm" onClick={() => void loadPreview()}>
              Reintentar
            </Button>
          </div>
        )}

        {!isLoading && !previewError && preview && isManual && (
          <div className="space-y-2 rounded-lg border border-border bg-muted/30 p-4 text-sm">
            <p className="text-foreground">{preview.message ?? 'El nuevo precio aplica en el próximo ciclo.'}</p>
            {typeof preview.new_price === 'number' && (
              <p className="text-muted-foreground">
                Nuevo precio:{' '}
                <span className="font-medium text-foreground">{formatPrice(preview.new_price, currency)}</span>
              </p>
            )}
            <p className="text-xs text-muted-foreground">
              Como el condominio paga de forma manual, no se cobra nada ahora: el ajuste se refleja en
              el siguiente pago.
            </p>
          </div>
        )}

        {!isLoading && !previewError && preview && !isManual && (
          <div className="space-y-3 text-sm">
            <ul className="space-y-2">
              {(preview.lines ?? []).map((line, index) => (
                <li key={index} className="flex items-baseline justify-between gap-3">
                  <span className="text-muted-foreground">{line.description ?? 'Ajuste'}</span>
                  <span className="font-medium tabular-nums text-foreground">
                    {typeof line.amount === 'number' ? formatPrice(line.amount, currency) : '—'}
                  </span>
                </li>
              ))}
            </ul>
            <div className="flex items-baseline justify-between gap-3 border-t border-border pt-3">
              <span className="font-medium text-foreground">A cobrar ahora</span>
              <span className="text-lg font-semibold tabular-nums text-foreground">
                {formatPrice(preview.amount_due ?? 0, currency)}
              </span>
            </div>
            {typeof preview.proration_date === 'number' && (
              <p className="text-xs text-muted-foreground">
                Prorrateo calculado al{' '}
                {displayFormatDate(new Date(preview.proration_date * 1000).toISOString(), 'mediumDate')}.
              </p>
            )}
          </div>
        )}

        {applyPreferentialSwap.isError && (
          <p
            role="alert"
            className="flex items-start gap-2 rounded-lg bg-destructive/5 p-3 text-sm text-destructive"
          >
            <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            <span>{resolveBillingError(applyPreferentialSwap.error)}</span>
          </p>
        )}

        <DialogFooter>
          <Button type="button" variant="outline" onClick={onClose} disabled={applyPreferentialSwap.isPending}>
            Cancelar
          </Button>
          <Button
            type="button"
            onClick={handleApply}
            disabled={isLoading || Boolean(previewError) || !preview || applyPreferentialSwap.isPending}
          >
            {applyPreferentialSwap.isPending ? 'Aplicando…' : 'Aplicar cambio'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
