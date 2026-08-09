import type { ICobranzaPayload } from '@/modules/dashboards/cobranza/schema'
import { apiClient } from '@/shared/api/apiClient'

/**
 * Resource for the Cobranza dashboard.
 * Only exposes fetch() — no phantom CRUD endpoints.
 */
export const CobranzaResource = {
  fetch: async (): Promise<ICobranzaPayload> => {
    const { data } = await apiClient.get<ICobranzaPayload>('/collections/aging')
    return data
  },
}
