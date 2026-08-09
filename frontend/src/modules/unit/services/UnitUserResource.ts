import type { IUnitUser, UnitUserFormValues } from '@/modules/unit/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const UnitUserResource = createCrudApi<IUnitUser, UnitUserFormValues & { unit_id?: string }>({
  basePath: '/unit_user',
})
