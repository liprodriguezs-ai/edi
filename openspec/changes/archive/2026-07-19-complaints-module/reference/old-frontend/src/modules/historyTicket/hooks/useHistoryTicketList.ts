import type { IHistoryTicket } from '@/modules/historyTicket/schema'
import { HistoryTicketResource } from '@/modules/historyTicket/services/HistoryTicketResource'
import { useHistoryTicketStore } from '@/modules/historyTicket/store/historyTicketStore'
import { useBaseList, type OverrideParams } from '@/shared/hooks/useBaseList'
import type { ISearchParams } from '@/shared/interfaces/list.types'

export function useHistoryTicketList({ overrideParams }: { overrideParams?: ISearchParams }) {
  return useBaseList<IHistoryTicket>({
    store: useHistoryTicketStore,
    resource: HistoryTicketResource,
    autoInit: true,
    overrideParams: overrideParams as OverrideParams,
    initialSearch: {
      sort: [{ field: 'created_at', direction: 'desc' }],
      includes: [{ relation: 'user' }, { relation: 'historyable' }],
    },
  })
}
