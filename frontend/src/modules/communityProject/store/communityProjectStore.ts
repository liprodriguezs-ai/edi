import type { ICommunityProject } from '@/modules/communityProject/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useCommunityProjectStore = createEntityStore<ICommunityProject>('communityProject')
