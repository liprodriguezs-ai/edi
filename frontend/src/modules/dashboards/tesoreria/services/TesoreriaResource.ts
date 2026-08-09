import type { ITesoreriaPayload } from '@/modules/dashboards/tesoreria/schema'
import { apiClient } from '@/shared/api/apiClient'

/**
 * Resource for the Tesorería dashboard.
 * Only exposes fetch() — no phantom CRUD endpoints.
 */
export const TesoreriaResource = {
  fetch: async (months = 12): Promise<ITesoreriaPayload> => {
    const { data } = await apiClient.get<ITesoreriaPayload>(
      '/treasury/cashflow-series',
      { params: { months } },
    )
    return data
  },
}
