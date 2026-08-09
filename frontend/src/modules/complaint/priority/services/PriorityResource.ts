import type {
  IComplaintPriority,
  ComplaintPriorityFormValues,
} from '@/modules/complaint/priority/schema'
import { createCrudApi } from '@/shared/api/resourceApi'

export const PriorityResource = createCrudApi<IComplaintPriority, ComplaintPriorityFormValues>({
  basePath: '/complaint_priority',
})
