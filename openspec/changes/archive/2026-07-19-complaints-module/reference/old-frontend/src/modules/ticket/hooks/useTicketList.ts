import type { ITicket } from '@/modules/ticket/schema'
import { TicketResource } from '@/modules/ticket/services/TicketResource'
import { useTicketStore } from '@/modules/ticket/store/ticketStore'
import { useBaseList } from '@/shared/hooks/useBaseList'

export function useTicketList() {
  return useBaseList<ITicket>({
    store: useTicketStore,
    resource: TicketResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'created_at', direction: 'desc' }],
      includes: [
        { relation: 'ticket_status' },
        { relation: 'ticket_importance' },
        { relation: 'user' },
        { relation: 'client' },
        { relation: 'client.user' },
        { relation: 'assignments' },
        { relation: 'assignments.user' },
      ],
    },
  })
}
