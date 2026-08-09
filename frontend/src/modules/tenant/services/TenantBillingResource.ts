import { apiClient } from '@/shared/api/apiClient'
import {
  billingSummarySchema,
  preferentialSwapPreviewSchema,
  type IBillingSummary,
  type IChangePaymentMethodRequest,
  type IEntitlementRequest,
  type IPreferentialSwapPreview,
  type IPreferentialSwapRequest,
  type IRegisterPaymentRequest,
} from '@/modules/tenant/billing/schema'

/**
 * Cliente de los endpoints de facturación por tenant (super-admin) — billing v2.
 *
 * Todas las mutaciones usan `skipPermissionsModal` porque sus 403/422 son REGLAS
 * DE NEGOCIO (contact_sales, not_account_owner, owner ambiguo, etc.) y se manejan
 * inline, no con el PermissionsModal global.
 *
 * Las respuestas se validan con Zod para fallar temprano si cambia el contrato.
 * El backend envuelve las lecturas en `{ data: ... }` (envelope Orion).
 */
function unwrap(payload: unknown): unknown {
  if (payload && typeof payload === 'object' && 'data' in payload) {
    return (payload as { data: unknown }).data
  }
  return payload
}

export const TenantBillingResource = {
  getBilling: async (tenantId: string): Promise<IBillingSummary> => {
    const { data } = await apiClient.get<unknown>(`/tenant/${tenantId}/billing`)
    return billingSummarySchema.parse(unwrap(data))
  },

  changePaymentMethod: async (
    tenantId: string,
    payload: IChangePaymentMethodRequest,
  ): Promise<void> => {
    await apiClient.patch(`/tenant/${tenantId}/payment-method`, payload, {
      skipPermissionsModal: true,
    })
  },

  registerPayment: async (
    tenantId: string,
    payload: IRegisterPaymentRequest,
  ): Promise<void> => {
    await apiClient.post(`/tenant/${tenantId}/subscription-payment`, payload, {
      skipPermissionsModal: true,
    })
  },

  grantFree: async (tenantId: string, payload: IEntitlementRequest): Promise<void> => {
    await apiClient.post(`/tenant/${tenantId}/entitlement/grant-free`, payload, {
      skipPermissionsModal: true,
    })
  },

  revoke: async (tenantId: string, payload: IEntitlementRequest): Promise<void> => {
    await apiClient.post(`/tenant/${tenantId}/entitlement/revoke`, payload, {
      skipPermissionsModal: true,
    })
  },

  startTrial: async (tenantId: string, payload: IEntitlementRequest): Promise<void> => {
    await apiClient.post(`/tenant/${tenantId}/entitlement/start-trial`, payload, {
      skipPermissionsModal: true,
    })
  },

  /**
   * Preview del cambio a un plan preferencial. El backend resuelve los módulos
   * contratados del tenant; sólo se necesita el plan destino (`to_plan_key`).
   */
  previewPreferentialSwap: async (
    tenantId: string,
    payload: { to_plan_key: string },
  ): Promise<IPreferentialSwapPreview> => {
    const { data } = await apiClient.post<unknown>(
      `/tenant/${tenantId}/preferential-swap/preview`,
      payload,
      { skipPermissionsModal: true },
    )
    return preferentialSwapPreviewSchema.parse(unwrap(data))
  },

  applyPreferentialSwap: async (
    tenantId: string,
    payload: IPreferentialSwapRequest,
  ): Promise<void> => {
    await apiClient.post(`/tenant/${tenantId}/preferential-swap`, payload, {
      skipPermissionsModal: true,
    })
  },
}
