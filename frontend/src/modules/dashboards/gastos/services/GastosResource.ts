import type { IGastosPayload } from '@/modules/dashboards/gastos/schema'
import { apiClient } from '@/shared/api/apiClient'

/**
 * Resource for the Gastos dashboard.
 * Only exposes fetch() — no phantom CRUD endpoints.
 */
export const GastosResource = {
  fetch: async (months = 12): Promise<IGastosPayload> => {
    const { data } = await apiClient.get<IGastosPayload>(
      '/treasury/expenses-breakdown',
      { params: { months } },
    )
    return data
  },
}
