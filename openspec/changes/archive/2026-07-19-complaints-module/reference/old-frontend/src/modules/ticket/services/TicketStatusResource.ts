import type { ITicketStatus } from '@/modules/ticket/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const TicketStatusResource = createCrudApi<ITicketStatus>({
  basePath: '/ticket_status',
})
