import type { IUnitRelationship, UnitRelationshipFormValues } from '@/modules/unitRelationship/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const UnitRelationshipResource = createCrudApi<IUnitRelationship, UnitRelationshipFormValues>({
  basePath: '/unit_relationship',
})
