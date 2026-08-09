import type { IHistoryTicket } from '@/modules/historyTicket/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const HistoryTicketResource = createCrudApi<IHistoryTicket>({
  basePath: '/history_ticket',
})
