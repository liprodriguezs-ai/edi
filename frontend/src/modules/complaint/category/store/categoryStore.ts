import type { IComplaintCategory } from '@/modules/complaint/category/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useComplaintCategoryStore = createEntityStore<IComplaintCategory>('complaintCategory')
