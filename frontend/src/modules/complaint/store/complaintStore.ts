import type { IComplaint } from '@/modules/complaint/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useComplaintStore = createEntityStore<IComplaint>('complaint')
