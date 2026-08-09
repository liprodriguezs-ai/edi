import type { IUnitStatus, UnitStatusFormValues } from '@/modules/unitStatus/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const UnitStatusResource = createCrudApi<IUnitStatus, UnitStatusFormValues>({
  basePath: '/unit_status',
})
