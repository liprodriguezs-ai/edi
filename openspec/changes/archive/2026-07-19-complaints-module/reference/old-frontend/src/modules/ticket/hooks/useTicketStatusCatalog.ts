import type { ITicketStatus } from '@/modules/ticket/schema'
import { TicketStatusResource } from '@/modules/ticket/services/TicketStatusResource'
import { useTicketStatusStore } from '@/modules/ticket/store/ticketStatusStore'
import { useBaseList } from '@/shared/hooks/useBaseList'

export function useTicketStatusCatalog() {
  return useBaseList<ITicketStatus>({
    store: useTicketStatusStore,
    resource: TicketStatusResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'order', direction: 'asc' }],
      limit: 50,
    },
  })
}
