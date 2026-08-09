import type { IUnitType, UnitTypeFormValues } from '@/modules/unitType/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const UnitTypeResource = createCrudApi<IUnitType, UnitTypeFormValues>({
  basePath: '/unit_type',
})
