import type { ChildCaregiverFormValues, IChildCaregiver } from '@/modules/caregiver/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const ChildCaregiverResource = createCrudApi<IChildCaregiver, ChildCaregiverFormValues>({
  basePath: '/child_caregiver',
})
