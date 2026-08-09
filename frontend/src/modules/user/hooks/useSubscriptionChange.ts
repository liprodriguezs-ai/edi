import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { CheckoutSubscriptionResource, type IMultiItemMutationResult } from '@/modules/checkoutSubscription/services/CheckoutSubscriptionResource'
import type {
  ISubscriptionChangePreview,
  ISubscriptionPreviewRequest,
} from '@/modules/checkoutSubscription/schema'
import { toast } from '@/shared/hooks/useToast'
import { useCallback, useState } from 'react'

interface UseSubscriptionChangeOptions {
  /** Se ejecuta tras aplicar el cambio con éxito (refrescar la pantalla). */
  onApplied: () => void | Promise<void>
}

/**
 * Encapsula el flujo PREVIEW → CONFIRMAR de UN cambio de la suscripción multi-item.
 * Cada modal de gestión (agregar/quitar complemento, unidades, intervalo) instancia
 * este hook: `runPreview` calcula el prorrateo real (una llamada deliberada al
 * backend, nunca por tecleo, para no disparar `previewInvoice` de Stripe de más) y
 * `apply` confirma usando el `proration_date` de la vista previa. Los errores 422
 * muestran el `message` del backend, no un texto genérico.
 */
export function useSubscriptionChange({ onApplied }: UseSubscriptionChangeOptions) {
  const [preview, setPreview] = useState<ISubscriptionChangePreview | null>(null)
  const [isPreviewing, setIsPreviewing] = useState(false)
  const [previewError, setPreviewError] = useState<string | null>(null)
  const [isApplying, setIsApplying] = useState(false)

  const runPreview = useCallback(async (request: ISubscriptionPreviewRequest) => {
    setIsPreviewing(true)
    setPreviewError(null)
    try {
      const data = await CheckoutSubscriptionResource.previewChange(request)
      setPreview(data)
    } catch (err) {
      setPreview(null)
      setPreviewError(
        err instanceof ApiError
          ? formatApiErrorMessage(err).title
          : 'No se pudo calcular la vista previa del cambio.',
      )
    } finally {
      setIsPreviewing(false)
    }
  }, [])

  const clearPreview = useCallback(() => {
    setPreview(null)
    setPreviewError(null)
    setIsPreviewing(false)
  }, [])

  /**
   * Ejecuta la mutación (recibe la llamada concreta al resource). Devuelve `true`
   * si se aplicó, `false` si falló — el modal usa eso para cerrarse o no.
   */
  const apply = useCallback(
    async (action: () => Promise<IMultiItemMutationResult>): Promise<boolean> => {
      setIsApplying(true)
      try {
        const { message } = await action()
        toast.success('Suscripción actualizada', message)
        await onApplied()
        return true
      } catch (err) {
        const msg =
          err instanceof ApiError
            ? formatApiErrorMessage(err).title
            : 'No se pudo aplicar el cambio.'
        toast.error('Error', msg)
        return false
      } finally {
        setIsApplying(false)
      }
    },
    [onApplied],
  )

  return { preview, isPreviewing, previewError, runPreview, clearPreview, apply, isApplying }
}
