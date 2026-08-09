import type { IVisit, VisitFormValues } from '@/modules/visit/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const VisitResource = createCrudApi<IVisit, VisitFormValues>({
  basePath: '/visit',
})
