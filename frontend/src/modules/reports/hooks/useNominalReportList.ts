import type { IScreening } from '@/modules/screening/schema'
import { NominalReportResource } from '@/modules/reports/services/NominalReportResource'
import { useNominalReportStore } from '@/modules/reports/store/nominalReportStore'
import { useBaseList } from '@/shared/hooks/useBaseList'

/** REQ-RPT-006/011 — server-paginated nominal listing, PII surface. */
export function useNominalReportList() {
  return useBaseList<IScreening>({
    store: useNominalReportStore,
    resource: NominalReportResource,
    autoInit: true,
    initialSearch: {
      sort: [{ field: 'visit.visit_date', direction: 'desc' }],
      includes: [
        { relation: 'child' },
        { relation: 'child.sex' },
        { relation: 'visit' },
        { relation: 'visit.health_center' },
        { relation: 'applied_age_group' },
        { relation: 'result_color' },
        { relation: 'situation' },
      ],
    },
  })
}
