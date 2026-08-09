import type { ISystem } from '@/modules/system/schema'
import { apiClient } from '@/shared/api/apiClient'

export const SystemResource = {
  /** Lista todos los sistemas disponibles (GET /system). */
  list: async (params?: { limit?: number }): Promise<ISystem[]> => {
    const { data } = await apiClient.get<{ data: ISystem[] }>('/system', {
      params: { limit: 50, ...params },
    })
    return data.data
  },
}
