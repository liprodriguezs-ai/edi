import type { IComplaintPriority } from '@/modules/complaint/priority/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useComplaintPriorityStore = createEntityStore<IComplaintPriority>('complaintPriority')
