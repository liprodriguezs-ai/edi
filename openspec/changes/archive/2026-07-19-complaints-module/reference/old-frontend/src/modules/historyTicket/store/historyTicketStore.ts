import type { IHistoryTicket } from '@/modules/historyTicket/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useHistoryTicketStore = createEntityStore<IHistoryTicket>('historyTicket')
