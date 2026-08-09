import { resolveBillingError } from '@/modules/tenant/billing/billingErrors'
import type {
  IChangePaymentMethodRequest,
  IEntitlementRequest,
  IPreferentialSwapRequest,
  IRegisterPaymentRequest,
} from '@/modules/tenant/billing/schema'
import { TenantBillingResource } from '@/modules/tenant/services/TenantBillingResource'
import { toast } from '@/shared/hooks/useToast'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

/**
 * Estado de servidor (React Query) de la facturación de un tenant.
 *
 * - `billing`: lee el resumen agregado (GET /tenant/{id}/billing). Se habilita
 *   sólo cuando hay `tenantId` (el modal monta el panel únicamente al abrir).
 * - Mutaciones: cambiar método, registrar pago, cortesía, revocar y aplicar swap.
 *   Todas invalidan la query del resumen al terminar para refrescar la UI.
 *
 * Los errores de negocio se traducen con `resolveBillingError` y se exponen a la
 * UI vía el `error` de cada mutación (mensaje inline) — NO se dispara el
 * PermissionsModal global (los resources usan `skipPermissionsModal`).
 *
 * TODA mutación debe dejar ver su error al operador: cada sección pinta el `error`
 * de su mutación inline (el toast solo lo refuerza; dentro del modal puede pasar
 * desapercibido). Si agregas una mutación acá, agrega también su mensaje inline.
 */
export function useTenantBilling(tenantId: string | null) {
  const queryClient = useQueryClient()
  const queryKey = ['tenant-billing', tenantId] as const

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey })
  }

  const billing = useQuery({
    queryKey,
    queryFn: () => TenantBillingResource.getBilling(tenantId as string),
    enabled: Boolean(tenantId),
  })

  const changePaymentMethod = useMutation({
    mutationFn: (payload: IChangePaymentMethodRequest) =>
      TenantBillingResource.changePaymentMethod(tenantId as string, payload),
    onSuccess: () => {
      toast.success('Método de pago actualizado')
      invalidate()
    },
  })

  const registerPayment = useMutation({
    mutationFn: (payload: IRegisterPaymentRequest) =>
      TenantBillingResource.registerPayment(tenantId as string, payload),
    onSuccess: () => {
      toast.success('Pago registrado', 'Se extendió el acceso y quedó asentado en el historial')
      invalidate()
    },
  })

  const grantFree = useMutation({
    mutationFn: (payload: IEntitlementRequest) =>
      TenantBillingResource.grantFree(tenantId as string, payload),
    onSuccess: () => {
      toast.success(
        'Plan activado sin costo',
        'El organización tendrá acceso sin costo de forma permanente'
      )
      invalidate()
    },
    onError: (error: unknown) => {
      toast.error('No se pudo activar', resolveBillingError(error))
    },
  })

  const revoke = useMutation({
    mutationFn: (payload: IEntitlementRequest) =>
      TenantBillingResource.revoke(tenantId as string, payload),
    onSuccess: () => {
      toast.success('Acceso revocado')
      invalidate()
    },
    onError: (error: unknown) => {
      toast.error('No se pudo revocar', resolveBillingError(error))
    },
  })

  const applyPreferentialSwap = useMutation({
    mutationFn: (payload: IPreferentialSwapRequest) =>
      TenantBillingResource.applyPreferentialSwap(tenantId as string, payload),
    onSuccess: () => {
      toast.success('Cambio aplicado', 'El organización pasó al plan preferencial')
      invalidate()
    },
  })

  const startTrial = useMutation({
    mutationFn: (payload: IEntitlementRequest) =>
      TenantBillingResource.startTrial(tenantId as string, payload),
    onSuccess: () => {
      toast.success('Prueba activada', 'El organización tiene acceso de prueba por tiempo limitado')
      invalidate()
    },
    onError: (error: unknown) => {
      toast.error('No se pudo activar la prueba', resolveBillingError(error))
    },
  })

  return {
    billing,
    changePaymentMethod,
    registerPayment,
    grantFree,
    revoke,
    applyPreferentialSwap,
    startTrial,
  }
}
