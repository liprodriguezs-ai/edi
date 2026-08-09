import type { IUserProfile } from '@/modules/userProfile/schema'
import { createEntityStore } from '@/shared/store/EntityStore'

export const useUserProfileStore = createEntityStore<IUserProfile>('userProfile')
