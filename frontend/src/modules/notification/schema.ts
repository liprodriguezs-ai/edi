import type { IEntity } from '@/shared/interfaces/Entity'

export interface INotificationUser extends IEntity {
  is_read: boolean
  notification_id?: string
  notification?: {
    id: string
    title?: string
    message?: string
    click_action?: string | null
    date?: string | null
  }
}
