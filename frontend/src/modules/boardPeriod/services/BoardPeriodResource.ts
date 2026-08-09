import type { IBoardPeriod } from '@/modules/boardPeriod/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export interface StartPeriodPayload {
  name: string
  start_date: string
  end_date: string
  notes?: string
  auto_close_current?: boolean
  members: Array<{ user_id: string; board_role_id: string; unit_id?: string }>
}

export const BoardPeriodResource = createCrudApi<IBoardPeriod, Record<string, unknown>, Record<string, unknown>, {
  startPeriod: (_payload: StartPeriodPayload) => Promise<IBoardPeriod>
  closePeriod: (_id: string) => Promise<IBoardPeriod>
  uploadActa: (_id: string, _file: File) => Promise<IBoardPeriod>
}>({
  basePath: '/board_period',
  customMethods: () => ({
    startPeriod: async (payload) => {
      const { data } = await apiClient.post<{ data: IBoardPeriod }>('/board_period/start', payload)
      return data.data
    },
    closePeriod: async (id) => {
      const { data } = await apiClient.post<{ data: IBoardPeriod }>(`/board_period/${id}/close`)
      return data.data
    },
    uploadActa: async (id, file) => {
      const formData = new FormData()
      formData.append('file', file)
      const { data } = await apiClient.post<{ data: IBoardPeriod }>(`/board_period/${id}/media`, formData)
      return data.data
    },
  }),
})
