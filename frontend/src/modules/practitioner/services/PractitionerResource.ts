import type { IPractitionerProfile, PractitionerFormValues } from '@/modules/practitioner/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const PractitionerResource = createCrudApi<IPractitionerProfile, PractitionerFormValues>({
  basePath: '/practitioner_profile',
})
