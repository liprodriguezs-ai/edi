import type { IAmenity, IAmenityAvailabilityResponse } from '@/modules/amenity/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export const AmenityResource = createCrudApi<
  IAmenity,
  Record<string, unknown>,
  Record<string, unknown>,
  {
    uploadMedia: (_id: string, _file: File) => Promise<unknown>
    deleteMedia: (_id: string, _mediaId: string) => Promise<void>
    /** S14 — `GET /amenity/{id}/availability?date=…&end_date=…` (rango ≤ 31 días). */
    availability: (
      _id: string,
      _date: string,
      _endDate?: string
    ) => Promise<IAmenityAvailabilityResponse>
  }
>({
  basePath: '/amenity',
  customMethods: () => ({
    uploadMedia: async (id, file) => {
      const formData = new FormData()
      formData.append('file', file)
      const { data } = await apiClient.post(`/amenity/${id}/media`, formData)
      return data
    },
    deleteMedia: async (id, mediaId) => {
      await apiClient.delete(`/amenity/${id}/media/${mediaId}`)
    },
    availability: async (id, date, endDate) => {
      const { data } = await apiClient.get<{ data: IAmenityAvailabilityResponse }>(
        `/amenity/${id}/availability`,
        { params: { date, end_date: endDate } }
      )
      return data.data
    },
  }),
})
