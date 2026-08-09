import type { ITicketImportance } from '@/modules/ticket/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const TicketImportanceResource = createCrudApi<ITicketImportance>({
  basePath: '/ticket_importance',
})
