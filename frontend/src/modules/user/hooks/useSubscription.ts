import { CheckoutSubscriptionResource } from '@/modules/checkoutSubscription/services/CheckoutSubscriptionResource'
import type {
  ICurrentPlan,
  IModuleTrial,
  IMultiItemSubscription,
  IStripeSubscription,
} from '@/modules/checkoutSubscription/schema'
import { fetchSessionProfile } from '@/shared/auth/authService'
import { sessionStore } from '@/shared/auth/sessionStore'
import { ApiError, formatApiErrorMessage } from '@/shared/api/apiClient'
import { toast } from '@/shared/hooks/useToast'
import { useCallback, useEffect, useState } from 'react'

export interface SubscriptionDetails {
  /** Plan bajo el que se contrató, tomado del bloque multi_item (billing v2). */
  plan: { key: string; name: string } | null
  /** Plan vigente sea cual sea la fuente (Stripe / manual / cortesía / prueba). */
  currentPlan: ICurrentPlan | null
  subscription: IStripeSubscription | null
  multiItem: IMultiItemSubscription | null
  isMultiItem: boolean
  unitCount: number
  unitLimit: number | null
  /** Pruebas activas de módulos. Array vacío si no hay ninguna. */
  trials: IModuleTrial[]
}

export function useSubscription() {
  const [details, setDetails] = useState<SubscriptionDetails | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [hasLoadError, setHasLoadError] = useState(false)
  const [isCanceling, setIsCanceling] = useState(false)
  const [isResuming, setIsResuming] = useState(false)

  const refreshSession = useCallback(async () => {
    try {
      const profile = await fetchSessionProfile()
      sessionStore.getState().setProfile(profile)
    } catch {
      /* session refresh failed, non-critical */
    }
  }, [])

  const loadDetails = useCallback(async () => {
    setIsLoading(true)
    setHasLoadError(false)
    try {
      const data = await CheckoutSubscriptionResource.getMySubscription()
      setDetails(data)
    } catch {
      toast.error('Error', 'No se pudo cargar la información de tu suscripción.')
      setHasLoadError(true)
    } finally {
      setIsLoading(false)
    }
  }, [])

  /**
   * Recarga los datos SIN togglear `isLoading`, para no blanquear la pantalla con
   * el spinner global tras aplicar un cambio de gestión (agregar/quitar complemento,
   * unidades, intervalo). El cambio se reconcilia asíncronamente vía webhook de
   * Stripe, así que puede tardar en reflejarse: se reintenta al reabrir la pantalla.
   */
  const reloadSilently = useCallback(async () => {
    try {
      const data = await CheckoutSubscriptionResource.getMySubscription()
      setDetails(data)
    } catch {
      /* refresh silencioso fallido, no crítico */
    }
  }, [])

  // Sólo carga los detalles al montar; el cambio de plan v2 vive inline en el
  // gestor multi-item, así que la pantalla hace UN solo GET.
  useEffect(() => {
    void loadDetails()
  }, [loadDetails])

  const handleCancel = useCallback(async (): Promise<boolean> => {
    setIsCanceling(true)
    try {
      await CheckoutSubscriptionResource.cancelSubscription()
      toast.success(
        'Suscripción cancelada',
        'Tu plan se cancelará al final del ciclo de facturación. Puedes reactivarlo antes de esa fecha.'
      )
      await Promise.all([loadDetails(), refreshSession()])
      return true
    } catch (err) {
      const msg =
        err instanceof ApiError
          ? formatApiErrorMessage(err).title
          : 'No se pudo cancelar la suscripción.'
      toast.error('Error', msg)
      return false
    } finally {
      setIsCanceling(false)
    }
  }, [loadDetails, refreshSession])

  const handleResume = useCallback(async () => {
    setIsResuming(true)
    try {
      await CheckoutSubscriptionResource.resumeSubscription()
      toast.success('Suscripción reactivada', 'Tu plan seguirá activo y se renovará normalmente.')
      await Promise.all([loadDetails(), refreshSession()])
    } catch (err) {
      const msg =
        err instanceof ApiError
          ? formatApiErrorMessage(err).title
          : 'No se pudo reactivar la suscripción.'
      toast.error('Error', msg)
    } finally {
      setIsResuming(false)
    }
  }, [loadDetails, refreshSession])

  /**
   * Refresco tras una mutación de gestión multi-item: recarga los datos de la
   * suscripción (silencioso) y el perfil de sesión (sistemas/menú pueden cambiar al
   * agregar/quitar complementos), sin blanquear la pantalla.
   */
  const refresh = useCallback(async () => {
    await Promise.all([reloadSilently(), refreshSession()])
  }, [reloadSilently, refreshSession])

  const handlePortal = useCallback(async () => {
    try {
      const url = await CheckoutSubscriptionResource.getPortalUrl()
      if (!url) {
        toast.error('Error', 'No se pudo obtener el portal de facturación.')
        return
      }
      window.location.href = url
    } catch (err) {
      const msg =
        err instanceof ApiError
          ? formatApiErrorMessage(err).title
          : 'No se pudo abrir el portal de facturación.'
      toast.error('Error', msg)
    }
  }, [])

  return {
    details,
    isLoading,
    hasLoadError,
    isCanceling,
    isResuming,
    handleCancel,
    handleResume,
    handlePortal,
    reload: loadDetails,
    refresh,
  }
}
