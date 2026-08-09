import type { INotificationUser } from '@/modules/notification/schema'
import { apiClient } from '@/shared/api/apiClient'
import { createCrudApi } from '@/shared/api/resourceApi'
import { unreadCountFromSearch } from '@/shared/notifications/unreadCounter'

/**
 * Per-user notification center (§17.2). Built on the shared `createCrudApi`
 * factory plus the custom center actions the bell needs:
 * - `searchUnread`  → cheap badge count via Orion search `meta.total`.
 * - `searchRecent`  → the dropdown list (last 10, with the notification relation).
 * - `markAllRead`   → bulk "marcar todas leídas".
 * - `markRead`      → mark a single row read (PATCH, partial).
 */
interface NotificationUserCustomMethods {
  searchUnread: () => Promise<number>
  searchRecent: () => Promise<INotificationUser[]>
  markAllRead: () => Promise<void>
  markRead: (_id: string) => Promise<void>
}

export const NotificationUserResource = createCrudApi<
  INotificationUser,
  INotificationUser,
  Record<string, unknown>,
  NotificationUserCustomMethods
>({
  basePath: '/notification_user',
  customMethods: (basePath) => ({
    searchUnread: async () => {
      const { data } = await apiClient.post<{ meta?: { total?: number } }>(
        `${basePath}/search?limit=1`,
        { filters: [{ field: 'is_read', operator: '=', value: false }] },
      )
      return unreadCountFromSearch(data)
    },
    searchRecent: async () => {
      const { data } = await apiClient.post<{ data: INotificationUser[] }>(
        `${basePath}/search?limit=10`,
        {
          includes: [{ relation: 'notification' }],
          sort: [{ field: 'created_at', direction: 'desc' }],
        },
      )
      return data.data
    },
    markAllRead: async () => {
      await apiClient.post(`${basePath}/mark-all-read`)
    },
    markRead: async (id: string) => {
      await apiClient.patch(`${basePath}/${id}`, { is_read: true })
    },
  }),
})
