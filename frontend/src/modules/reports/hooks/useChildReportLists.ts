import type { IEdiConcentrateRow, IGeneralReportRow } from '@/modules/reports/schema'
import {
  EdiConcentrateResource,
  GeneralReportResource,
} from '@/modules/reports/services/ChildReportResource'
import {
  useEdiConcentrateStore,
  useGeneralReportStore,
} from '@/modules/reports/store/childReportStores'
import { useBaseList } from '@/shared/hooks/useBaseList'

/** S4 — Concentrado EDI: one row per child with a counted Prueba EDI. */
export function useEdiConcentrateList() {
  return useBaseList<IEdiConcentrateRow>({
    store: useEdiConcentrateStore,
    resource: EdiConcentrateResource,
    autoInit: true,
  })
}

/** S4 — Reporte general: census of children under 5. */
export function useGeneralReportList() {
  return useBaseList<IGeneralReportRow>({
    store: useGeneralReportStore,
    resource: GeneralReportResource,
    autoInit: true,
  })
}
