import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { CheckoutSubscriptionResource } from '@/modules/checkoutSubscription/services/CheckoutSubscriptionResource'
import type { BillingInterval } from '@/modules/checkoutSubscription/schema'
import { ProrationPreviewPanel } from '@/modules/user/components/ProrationPreviewPanel'
import { useSubscriptionChange } from '@/modules/user/hooks/useSubscriptionChange'
import { Modal } from '@/shared/components/Modal'
import { Loader2 } from 'lucide-react'
import { useCallback, useState } from 'react'

interface ChangeIntervalModalProps {
  isOpen: boolean
  currentInterval: BillingInterval
  onClose: () => void
  onApplied: () => void | Promise<void>
}

const INTERVAL_OPTIONS: { value: BillingInterval; label: string }[] = [
  { value: 'month', label: 'Mensual' },
  { value: 'year', label: 'Anual' },
]

/**
 * Cambiar el intervalo de cobro (mes ↔ año). El cambio cascada a TODAS las líneas
 * (base + complementos) en un solo swap prorrateado. Si algún paquete no tiene
 * precio en el intervalo destino, el backend responde 422 `interval_unavailable` y
 * se muestra ese mensaje.
 */
export function ChangeIntervalModal({
  isOpen,
  currentInterval,
  onClose,
  onApplied,
}: ChangeIntervalModalProps) {
  const [target, setTarget] = useState<BillingInterval | ''>('')

  const { preview, isPreviewing, previewError, runPreview, apply, isApplying } =
    useSubscriptionChange({ onApplied })

  const handleSelect = useCallback(
    (value: string) => {
      // Fix #9: guard en tiempo de ejecución antes del narrowing para evitar el cast ciego.
      if (value !== 'month' && value !== 'year') return
      const interval: BillingInterval = value
      setTarget(interval)
      void runPreview({ change_type: 'change_interval', interval })
    },
    [runPreview],
  )

  const handleConfirm = useCallback(async () => {
    if (!target || !preview) return
    const applied = await apply(() =>
      CheckoutSubscriptionResource.changeInterval(target, preview.proration_date),
    )
    if (applied) onClose()
  }, [apply, onClose, preview, target])

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Cambiar intervalo de cobro"
      description="Cambia entre cobro mensual y anual. Verás el prorrateo antes de confirmar."
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={isApplying} className="min-w-[80px]">
            Cancelar
          </Button>
          <Button
            onClick={() => void handleConfirm()}
            disabled={!target || !preview || isPreviewing || isApplying}
            className="min-w-[110px]"
          >
            {isApplying ? (
              <>
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                Guardando...
              </>
            ) : (
              'Confirmar'
            )}
          </Button>
        </>
      }
    >
      <div className="space-y-5 py-2">
        <div className="space-y-3">
          <Label id="interval-group-label">Intervalo de cobro</Label>
          <RadioGroup
            aria-labelledby="interval-group-label"
            value={target || undefined}
            onValueChange={handleSelect}
            className="gap-3"
          >
            {INTERVAL_OPTIONS.map((option) => {
              const isCurrent = option.value === currentInterval
              return (
                <div key={option.value} className="flex items-center gap-3 rounded-lg border p-3">
                  <RadioGroupItem
                    id={`interval-${option.value}`}
                    value={option.value}
                    disabled={isCurrent || isApplying}
                  />
                  <Label
                    htmlFor={`interval-${option.value}`}
                    className="flex-1 cursor-pointer font-normal"
                  >
                    {option.label}
                    {isCurrent && (
                      <span className="ml-2 text-xs text-muted-foreground">(actual)</span>
                    )}
                  </Label>
                </div>
              )
            })}
          </RadioGroup>
        </div>

        {target && (
          <ProrationPreviewPanel
            preview={preview}
            isLoading={isPreviewing}
            error={previewError}
            interval={target}
            onRetry={() => void runPreview({ change_type: 'change_interval', interval: target })}
          />
        )}
      </div>
    </Modal>
  )
}
