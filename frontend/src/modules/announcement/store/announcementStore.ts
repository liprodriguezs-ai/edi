import type { IAnnouncement } from '@/modules/announcement/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useAnnouncementStore = createEntityStore<IAnnouncement>('announcement')
