import type { IScreening } from '@/modules/screening/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

/**
 * Orion resource over `/api/reports/nominal` (design D6/D8). Read-only
 * reporting surface: only `list`/`search`/`show`/`report` are used —
 * `create`/`update`/`remove` exist on the generic CRUD shape but the
 * backend never registers those actions for this resource.
 */
export const NominalReportResource = createCrudApi<IScreening>({
  basePath: '/reports/nominal',
})
