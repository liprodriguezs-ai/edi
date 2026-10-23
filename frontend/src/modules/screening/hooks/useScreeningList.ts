import type { IScreening } from '@/modules/screening/schema'
import { ScreeningResource } from '@/modules/screening/services/ScreeningResource'
import { useScreeningStore } from '@/modules/screening/store/screeningStore'
import { useBaseList } from '@/shared/hooks/useBaseList'

/** REQ-SUI-002 — server-paginated list, includes limited to the API allowlist. */
export function useScreeningList() {
  return useBaseList<IScreening>({
    store: useScreeningStore,
    resource: ScreeningResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'created_at', direction: 'desc' }],
      includes: [
        { relation: 'visit' },
        { relation: 'child' },
        { relation: 'screening_status' },
        { relation: 'result_color' },
        { relation: 'situation' },
      ],
    },
  })
}
