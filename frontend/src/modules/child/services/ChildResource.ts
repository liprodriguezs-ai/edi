import type { ChildFormValues, IChild } from '@/modules/child/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const ChildResource = createCrudApi<IChild, ChildFormValues>({
  basePath: '/child',
})
