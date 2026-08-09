import type { IResultadosPayload } from '@/modules/dashboards/resultados/schema'
import { apiClient } from '@/shared/api/apiClient'

/**
 * Resource for the Estado de Resultados dashboard.
 * Only exposes fetch() — no phantom CRUD endpoints.
 */
export const ResultadosResource = {
  fetch: async (months = 12): Promise<IResultadosPayload> => {
    const { data } = await apiClient.get<IResultadosPayload>(
      '/treasury/income-expense-series',
      { params: { months } },
    )
    return data
  },
}
