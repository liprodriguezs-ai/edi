import type { INotificationUser } from '@/modules/notification/schema'
import { apiClient } from '@/shared/api/apiClient'

export const NotificationResource = {
  /** Obtiene las notificaciones del usuario (§17.2). Orion search con limit en query. */
  search: async (): Promise<INotificationUser[]> => {
    const { data } = await apiClient.post<{ data: INotificationUser[] }>(
      '/notification_user/search?limit=50',
      {
        includes: [{ relation: 'notification' }],
        sort: [{ field: 'created_at', direction: 'desc' }],
      },
    )
    return data.data
  },

  /** Marca todas las notificaciones del usuario como leídas. */
  markAllRead: (): Promise<unknown> => apiClient.post('/notification_user/mark-all-read'),

  /** Marca una notificación específica como leída. */
  markRead: (id: string): Promise<unknown> =>
    apiClient.patch(`/notification_user/${id}`, { is_read: true }),
}
