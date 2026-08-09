import type { HealthCenterFormValues, IHealthCenter } from '@/modules/healthCenter/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const HealthCenterResource = createCrudApi<IHealthCenter, HealthCenterFormValues>({
  basePath: '/health_center',
})
