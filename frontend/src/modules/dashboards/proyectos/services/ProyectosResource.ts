import type { IProyectosPayload } from '@/modules/dashboards/proyectos/schema'
import { apiClient } from '@/shared/api/apiClient'

/**
 * Resource for the Proyectos Comunitarios dashboard.
 * Only exposes fetch() — no phantom CRUD endpoints.
 */
export const ProyectosResource = {
  fetch: async (): Promise<IProyectosPayload> => {
    const { data } = await apiClient.get<IProyectosPayload>('/projects/funding-summary')
    return data
  },
}
