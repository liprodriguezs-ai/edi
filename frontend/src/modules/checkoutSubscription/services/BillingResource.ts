import { apiClient } from '@/shared/api/apiClient'
import {
  planCatalogSchema,
  planQuoteSchema,
  type IPlanCatalog,
  type IPlanCheckoutRequest,
  type IPlanQuote,
  type IPlanQuoteRequest,
} from '@/modules/checkoutSubscription/schema'

/**
 * Cliente del modelo de facturación por PLAN × MÓDULO (billing v2).
 * Reusa el `apiClient` (interceptores de auth/tenant). Las respuestas se validan
 * con Zod para fallar temprano si el contrato cambia.
 *
 *  - GET  /billing/plan-catalog  (público)
 *  - POST /billing/plan-quote    (público)  → { plan_key, modules[], interval }
 *  - POST /billing/checkout      (auth, solo account_owner) → { url }
 */
export const BillingResource = {
  getCatalog: async (): Promise<IPlanCatalog> => {
    const { data } = await apiClient.get<unknown>('/billing/plan-catalog')
    return planCatalogSchema.parse(data)
  },

  quote: async (payload: IPlanQuoteRequest): Promise<IPlanQuote> => {
    const { data } = await apiClient.post<unknown>('/billing/plan-quote', payload)
    return planQuoteSchema.parse(data)
  },

  /**
   * Crea la sesión de Stripe Checkout y devuelve la URL de redirección.
   * `skipPermissionsModal` evita que el interceptor 403 abra el PermissionsModal
   * global: un 403 aquí (not_account_owner) es REGLA DE NEGOCIO, no falta de
   * permiso — se maneja inline en la UI.
   */
  checkout: async (payload: IPlanCheckoutRequest): Promise<string> => {
    const { data } = await apiClient.post<{ url: string }>('/billing/checkout', payload, {
      skipPermissionsModal: true,
    })
    return data.url
  },
}
