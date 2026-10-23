import type { IFollowUp } from '@/modules/followUp/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useFollowUpStore = createEntityStore<IFollowUp>('follow_up')
