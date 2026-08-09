import type { ITicketImportance } from '@/modules/ticket/schema'
import { TicketImportanceResource } from '@/modules/ticket/services/TicketImportanceResource'
import { useTicketImportanceStore } from '@/modules/ticket/store/ticketImportanceStore'
import { useBaseList } from '@/shared/hooks/useBaseList'

export function useTicketImportance() {
  return useBaseList<ITicketImportance>({
    store: useTicketImportanceStore,
    resource: TicketImportanceResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'name', direction: 'asc' }],
      limit: 50,
    },
  })
}
