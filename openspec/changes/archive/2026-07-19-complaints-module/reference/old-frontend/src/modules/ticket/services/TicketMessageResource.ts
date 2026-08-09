import type { ITicketMessage, TicketMessageFormValues } from '@/modules/ticket/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const TicketMessageResource = createCrudApi<ITicketMessage, TicketMessageFormValues>({
  basePath: '/ticket_message',
})
