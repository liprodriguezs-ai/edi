import type { ITicketImportance } from '@/modules/ticket/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useTicketImportanceStore = createEntityStore<ITicketImportance>('ticketImportance')
