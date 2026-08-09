import type { IUnitFeature, UnitFeatureFormValues } from '@/modules/unitFeature/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const UnitFeatureResource = createCrudApi<IUnitFeature, UnitFeatureFormValues>({
  basePath: '/unit_feature',
})
