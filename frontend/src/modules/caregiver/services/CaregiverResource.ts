import type { CaregiverFormValues, ICaregiver } from '@/modules/caregiver/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const CaregiverResource = createCrudApi<ICaregiver, CaregiverFormValues>({
  basePath: '/caregiver',
})
