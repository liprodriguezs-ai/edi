import type { IFollowUp } from '@/modules/followUp/schema'
import { FollowUpResource } from '@/modules/followUp/services/FollowUpResource'
import { useFollowUpStore } from '@/modules/followUp/store/followUpStore'
import { useBaseList } from '@/shared/hooks/useBaseList'

/** REQ-FUP-009 — server-paginated inbox, default sort due_date asc. */
export function useFollowUpList() {
  return useBaseList<IFollowUp>({
    store: useFollowUpStore,
    resource: FollowUpResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'due_date', direction: 'asc' }],
      includes: [{ relation: 'child' }, { relation: 'follow_up_type' }, { relation: 'follow_up_status' }],
    },
  })
}
