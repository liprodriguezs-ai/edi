import type {
  IComplaintCategory,
  ComplaintCategoryFormValues,
} from '@/modules/complaint/category/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const CategoryResource = createCrudApi<IComplaintCategory, ComplaintCategoryFormValues>({
  basePath: '/complaint_category',
})
