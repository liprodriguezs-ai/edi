import type { ITower, TowerFormValues } from '@/modules/tower/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const TowerResource = createCrudApi<ITower, TowerFormValues>({
  basePath: '/tower',
})
