import type {
  AnnouncementReader,
  AnnouncementReadSummary,
  IAnnouncement,
} from '@/modules/announcement/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'
import type { PaginatedResponse } from '@/shared/interfaces/Entity'

/** Parámetros de la lista paginada de lectores (server-side filter + search). */
export interface AnnouncementReadersParams {
  status?: 'all' | 'read' | 'unread'
  search?: string
  page?: number
  per_page?: number
}

export const AnnouncementResource = createCrudApi<
  IAnnouncement,
  Record<string, unknown>,
  Record<string, unknown>,
  {
    publish: (_id: string) => Promise<IAnnouncement>
    archive: (_id: string) => Promise<IAnnouncement>
    markAsRead: (_id: string) => Promise<void>
    board: () => Promise<IAnnouncement[]>
    uploadMedia: (_id: string, _file: File) => Promise<unknown>
    deleteMedia: (_id: string, _mediaId: string) => Promise<void>
    preview: (_payload: {
      target_type: string
      target_selector?: Record<string, unknown> | null
    }) => Promise<number>
    readSummary: (_id: string) => Promise<AnnouncementReadSummary>
    readers: (
      _id: string,
      _params?: AnnouncementReadersParams
    ) => Promise<PaginatedResponse<AnnouncementReader>>
  }
>({
  basePath: '/announcement',
  customMethods: () => ({
    preview: async (payload) => {
      const { data } = await apiClient.post<{ count: number }>('/announcement/preview', payload)
      return data.count
    },
    board: async () => {
      const { data } = await apiClient.get<{ data: IAnnouncement[] }>('/announcement/board')
      return data.data
    },
    uploadMedia: async (id, file) => {
      const formData = new FormData()
      formData.append('file', file)
      const { data } = await apiClient.post(`/announcement/${id}/media`, formData)
      return data
    },
    deleteMedia: async (id, mediaId) => {
      await apiClient.delete(`/announcement/${id}/media/${mediaId}`)
    },
    publish: async (id) => {
      const { data } = await apiClient.post<{ data: IAnnouncement }>(`/announcement/${id}/publish`)
      return data.data
    },
    archive: async (id) => {
      const { data } = await apiClient.post<{ data: IAnnouncement }>(`/announcement/${id}/archive`)
      return data.data
    },
    markAsRead: async (id) => {
      await apiClient.post(`/announcement/${id}/read`)
    },
    // Conteos agregados de lectura. Custom route (no Orion): devuelve el objeto directo.
    readSummary: async (id) => {
      const { data } = await apiClient.get<AnnouncementReadSummary>(
        `/announcement/${id}/read-summary`
      )
      return data
    },
    // Lista paginada de lectores con filtro/búsqueda server-side. Devuelve el envelope
    // paginado directo ({ data, meta }), NO envuelto en otro `data`.
    readers: async (id, params) => {
      const { data } = await apiClient.get<PaginatedResponse<AnnouncementReader>>(
        `/announcement/${id}/readers`,
        { params }
      )
      return data
    },
  }),
})
