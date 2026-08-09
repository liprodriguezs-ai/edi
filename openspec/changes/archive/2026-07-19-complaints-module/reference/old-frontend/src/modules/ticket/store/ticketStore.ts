import type { ITicket } from '@/modules/ticket/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useTicketStore = createEntityStore<ITicket>('ticket')
