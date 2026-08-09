import type { IUser } from '@/modules/user/schema'
import type { IEntity } from '@/shared/interfaces/Entity'

export interface IHistoryTicket extends IEntity {
  user_id?: string
  ticket_id: string
  historyable_type?: string
  historyable_id?: string
  action: string
  description?: string
  old_value?: string
  new_value?: string
  user?: IUser
  historyable?: IEntity
}
