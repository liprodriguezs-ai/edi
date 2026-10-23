import type { IUser, UserFormValues } from '@/modules/user/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'

export type NotificationChannel = 'push' | 'email' | 'sms'
export type NotificationChannelMap = Partial<Record<NotificationChannel, boolean>>

export interface NotificationPreferences {
  channels: Record<string, NotificationChannelMap>
  quiet_hours_start: string | null
  quiet_hours_end: string | null
}

export interface NotificationPreferencesPayload {
  channels: Record<string, NotificationChannelMap>
  quiet_hours_start?: string | null
  quiet_hours_end?: string | null
}

export const UserResource = createCrudApi<IUser, UserFormValues, Record<string, unknown>, {
  uploadProfileImage: (_file: File) => Promise<{ profile_image_url: string | null }>
  deleteProfileImage: () => Promise<{ profile_image_url: null }>
  anonymize: (_id: string) => Promise<{ id: string; name: string }>
  revokeSessions: (_id: string) => Promise<{ message: string }>
  getNotificationPreferences: () => Promise<NotificationPreferences>
  updateNotificationPreferences: (_payload: NotificationPreferencesPayload) => Promise<NotificationPreferences>
  // UserController no expone sortableBy — cualquier sort es 422 en Orion
  searchByTerm: (_term: string) => Promise<IUser[]>
}>({
  basePath: '/user',
  customMethods: (basePath) => ({
    uploadProfileImage: async (file) => {
      const formData = new FormData()
      formData.append('profile_image', file)
      const { data } = await apiClient.post<{ data: { profile_image_url: string | null } }>('/user/uploadProfileImage', formData)
      return data.data
    },
    deleteProfileImage: async () => {
      const { data } = await apiClient.post<{ data: { profile_image_url: null } }>('/user/deleteProfileImage')
      return data.data
    },
    // Derecho al olvido (§18.3.4). El backend exige update.user + rol admin y
    // rechaza anonimizar la propia cuenta o al último propietario de la organización.
    anonymize: async (id) => {
      const { data } = await apiClient.post<{ data: { id: string; name: string } }>(`${basePath}/${id}/anonymize`)
      return data.data
    },
    revokeSessions: async (id) => {
      const { data } = await apiClient.post<{ message: string }>(`${basePath}/${id}/revoke-sessions`)
      return data
    },
    getNotificationPreferences: async () => {
      const { data } = await apiClient.get<{ data: NotificationPreferences }>(`${basePath}/notification-preferences`)
      return data.data
    },
    updateNotificationPreferences: async (payload) => {
      const { data } = await apiClient.put<{ data: NotificationPreferences }>(`${basePath}/notification-preferences`, payload)
      return data.data
    },
    // UserController no expone sortableBy — cualquier sort es 422 en Orion
    searchByTerm: async (term) => {
      const { data } = await apiClient.post<{ data: IUser[] }>(`${basePath}/search?limit=8`, {
        search: { value: term },
      })
      return data.data
    },
  }),
})
