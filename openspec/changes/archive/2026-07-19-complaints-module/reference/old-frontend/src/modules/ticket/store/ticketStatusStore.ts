import type { ITicketStatus } from '@/modules/ticket/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useTicketStatusStore = createEntityStore<ITicketStatus>('ticketStatus')
